<?php

namespace Tests\Feature;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\Filesystem;
use Mockery;
use Momo630\LaravelDataPatches\Contracts\DataPatch;
use Momo630\LaravelDataPatches\Contracts\RequiresS3DataFiles;
use Momo630\LaravelDataPatches\Support\DataPatchResolver;
use Momo630\LaravelDataPatches\Support\DataPatchRunner;
use Momo630\LaravelDataPatches\Support\HasDataPatchFiles;
use Momo630\LaravelDataPatches\Support\S3DataPatchUploader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;

class S3DataPatchUploaderTest extends TestCase
{
    private string $directory;

    private array $commands = [];

    private array $patches = [];

    private $onRequest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/data-patch-upload-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config([
            'data-patches.s3_disk' => 'patch-inputs',
            'data-patches.s3_upload_verified_target' => ['disk' => 'patch-inputs', 'bucket' => 'private-inputs', 'endpoint' => 'https://uploads.example.test'],
            'filesystems.disks.patch-inputs' => ['driver' => 's3', 'bucket' => 'private-inputs'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->patches as $patch) {
            $this->assertNull($patch->binding);
            foreach ($patch->directories as $directory) {
                $this->assertDirectoryDoesNotExist($directory);
            }
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_upload_uses_private_pinned_snapshots_conditional_put_and_actual_full_key(): void
    {
        $patch = $this->fixturePatch();
        $source = $this->source('an-editable-name.json', S3UploadTestPatch::CONTENTS);
        config([
            'filesystems.disks.patch-inputs.root' => 'patches/s3-upload-test',
            'filesystems.disks.patch-inputs.options' => ['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => 'reviewed-key'],
        ]);
        $this->mockS3([$this->missing(), new Result]);
        $this->onRequest = function () use ($source, $patch): void {
            // Editing the original after pinning cannot change the uploaded bytes.
            file_put_contents($source, 'changed after snapshot');
            $this->assertSame(0700, fileperms($patch->binding) & 0777);
            $this->assertSame(0700, fileperms($patch->binding.'/source') & 0777);
            $this->assertSame(0600, fileperms($patch->binding.'/source/products.json') & 0777);
        };

        $result = app(S3DataPatchUploader::class)->upload($patch, $source);

        $this->assertSame(['GetObject', 'PutObject'], array_column($this->commands, 'name'));
        $put = $this->commands[1];
        $this->assertSame('*', $put['condition']);
        $this->assertSame(S3UploadTestPatch::CONTENTS, $put['body']);
        $this->assertSame(S3UploadTestPatch::KEY, $put['args']['Key']);
        $this->assertSame('private-inputs', $put['args']['Bucket']);
        $this->assertSame('aws:kms', $put['args']['ServerSideEncryption']);
        $this->assertSame('reviewed-key', $put['args']['SSEKMSKeyId']);
        $this->assertSame('uploaded', $result[0]['status']);
        $this->assertSame(hash('sha256', S3UploadTestPatch::CONTENTS), $result[0]['sha256']);
    }

    public function test_all_local_hashes_are_checked_before_storage_io(): void
    {
        $patch = $this->fixturePatch(true);
        $this->source('source/products.json', 'unreviewed bytes');
        $this->source('assets/photo.bin', 'reviewed asset');
        Storage::shouldReceive('disk')->never();

        $this->assertFails($patch, $this->directory, 'SHA-256 mismatch');
    }

    public function test_directory_upload_uses_only_declared_paths_and_preflights_the_whole_batch(): void
    {
        $patch = $this->fixturePatch(true);
        $this->source('source/products.json', S3UploadTestPatch::CONTENTS);
        $this->source('assets/photo.bin', 'reviewed asset');
        $this->source('extra-secret.txt', 'never upload this');
        $this->mockS3([$this->missing(), $this->missing(), new Result, new Result]);

        $result = app(S3DataPatchUploader::class)->upload($patch, $this->directory);

        $this->assertSame(['GetObject', 'GetObject', 'PutObject', 'PutObject'], array_column($this->commands, 'name'));
        $this->assertSame(['uploaded', 'uploaded'], array_column($result, 'status'));
        $this->assertSame(['reviewed asset', S3UploadTestPatch::CONTENTS], array_column(array_slice($this->commands, 2), 'body'));
    }

    public function test_later_remote_conflict_prevents_every_write_in_the_batch(): void
    {
        $patch = $this->fixturePatch(true);
        $this->source('source/products.json', S3UploadTestPatch::CONTENTS);
        $this->source('assets/photo.bin', 'reviewed asset');
        $this->mockS3([$this->missing(), $this->object('conflicting remote bytes')]);

        $this->assertFails($patch, $this->directory, 'S3 object conflicts');
        $this->assertSame(['GetObject', 'GetObject'], array_column($this->commands, 'name'));
    }

    public function test_exact_remote_bytes_are_hashed_skipped_and_stream_closed(): void
    {
        $patch = $this->fixturePatch();
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        $stream = Utils::streamFor(S3UploadTestPatch::CONTENTS);
        config(['filesystems.disks.patch-inputs.options' => ['SSECustomerAlgorithm' => 'AES256', 'SSECustomerKey' => base64_encode(str_repeat('x', 32))]]);
        $this->mockS3([new Result(['Body' => $stream])]);

        $result = app(S3DataPatchUploader::class)->upload($patch, $source);

        $this->assertSame('unchanged', $result[0]['status']);
        $this->assertSame(['GetObject'], array_column($this->commands, 'name'));
        $this->assertSame(['stream' => true, 'decode_content' => false], $this->commands[0]['args']['@http']);
        $this->assertSame('AES256', $this->commands[0]['args']['SSECustomerAlgorithm']);
        $this->assertFalse($stream->isReadable());
    }

    public function test_dry_run_reports_current_remote_status_without_verification_attestation_or_puts(): void
    {
        $patch = $this->fixturePatch(true);
        $this->source('source/products.json', S3UploadTestPatch::CONTENTS);
        $this->source('assets/photo.bin', 'reviewed asset');
        config(['data-patches.s3_upload_verified_target' => null]);
        $this->mockS3([$this->missing(), $this->object(S3UploadTestPatch::CONTENTS)]);

        $result = app(S3DataPatchUploader::class)->upload($patch, $this->directory, true);

        $this->assertSame(['would_upload', 'unchanged'], array_column($result, 'status'));
        $this->assertSame(['GetObject', 'GetObject'], array_column($this->commands, 'name'));
    }

    public function test_unverified_or_mismatched_targets_fail_before_remote_io(): void
    {
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        foreach ([null, ['disk' => 'other', 'bucket' => 'private-inputs', 'endpoint' => 'https://uploads.example.test'], ['disk' => 'patch-inputs', 'bucket' => 'other', 'endpoint' => 'https://uploads.example.test'], ['disk' => 'patch-inputs', 'bucket' => 'private-inputs', 'endpoint' => 'https://other.example.test']] as $target) {
            config(['data-patches.s3_upload_verified_target' => $target]);
            $this->mockS3([]);
            $this->assertFails($this->fixturePatch(), $source, 'Conditional uploads are disabled');
        }
        $this->assertSame([], $this->commands);
    }

    public function test_read_only_public_or_reserved_options_cannot_be_bypassed(): void
    {
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        foreach ([['read-only' => true], ['visibility' => 'public'], ['options' => ['ACL' => 'public-read']], ['options' => ['IfNoneMatch' => 'unsafe']], ['options' => ['unknown-encryption-setting' => 'required']]] as $extra) {
            config(['filesystems.disks.patch-inputs' => $extra + ['driver' => 's3', 'bucket' => 'private-inputs']]);
            $this->mockS3([]);
            $this->assertFails($this->fixturePatch(), $source, $extra === ['read-only' => true] ? 'read-only' : (isset($extra['options']) && ! isset($extra['options']['ACL']) ? 'unsupported or reserved' : 'private S3'));
        }
        $this->assertSame([], $this->commands);
    }

    public function test_sdk_without_conditional_put_support_fails_before_remote_io(): void
    {
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        $provider = \Aws\Api\ApiProvider::defaultProvider();
        $this->mockS3([], ['api_provider' => function ($type, $service, $version) use ($provider) {
            $model = $provider($type, $service, $version);
            if ($type === 'api') {
                unset($model['shapes']['PutObjectRequest']['members']['IfNoneMatch']);
            }

            return $model;
        }]);

        $this->assertFails($this->fixturePatch(), $source, 'Update the optional AWS SDK');
        $this->assertSame([], $this->commands);
    }

    public function test_403_and_missing_bucket_are_never_treated_as_missing_objects(): void
    {
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        foreach ([[403, 'AccessDenied'], [404, 'NoSuchBucket'], [404, 'NotFound']] as [$status, $code]) {
            $this->mockS3([$this->s3Error($status, $code)]);
            $this->assertFails($this->fixturePatch(), $source, 'Could not verify S3 object');
        }
        $this->assertSame(['GetObject', 'GetObject', 'GetObject'], array_column($this->commands, 'name'));
    }

    public function test_412_race_only_skips_after_rechecking_identical_bytes(): void
    {
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        $this->mockS3([$this->missing(), $this->s3Error(412, 'PreconditionFailed'), $this->object(S3UploadTestPatch::CONTENTS)]);

        $result = app(S3DataPatchUploader::class)->upload($this->fixturePatch(), $source);

        $this->assertSame('unchanged', $result[0]['status']);
        $this->assertSame(['GetObject', 'PutObject', 'GetObject'], array_column($this->commands, 'name'));
        $this->assertSame('*', $this->commands[1]['condition']);
    }

    public function test_412_race_with_different_or_missing_bytes_fails_without_retrying_the_put(): void
    {
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        foreach ([$this->object('other content'), $this->missing()] as $response) {
            $this->mockS3([$this->missing(), $this->s3Error(412, 'PreconditionFailed'), $response]);
            $this->assertFails($this->fixturePatch(), $source, 'Concurrent S3 object conflicts');
        }
        $this->assertSame(['GetObject', 'PutObject', 'GetObject', 'GetObject', 'PutObject', 'GetObject'], array_column($this->commands, 'name'));
    }

    public function test_409_and_uncertain_write_failure_abort_safely_and_keep_earlier_objects_retryable(): void
    {
        $patch = $this->fixturePatch(true);
        $this->source('source/products.json', S3UploadTestPatch::CONTENTS);
        $this->source('assets/photo.bin', 'reviewed asset');
        $this->mockS3([$this->missing(), $this->missing(), new Result, $this->s3Error(409, 'ConditionalRequestConflict')]);

        $this->assertFails($patch, $this->directory, 'uncertain outcome');
        $this->mockS3([$this->object('reviewed asset'), $this->missing(), new Result]);
        $result = app(S3DataPatchUploader::class)->upload($patch, $this->directory);
        $this->assertSame(['unchanged', 'uploaded'], array_column($result, 'status'));

        $this->mockS3([$this->missing(), new RuntimeException('connection lost')]);
        $this->assertFails($this->fixturePatch(), $this->directory.'/source/products.json', 'uncertain outcome');
        $this->assertSame(4, count(array_filter($this->commands, fn (array $command): bool => $command['name'] === 'PutObject')));
    }

    public function test_missing_paths_symlinks_and_a_single_file_for_multiple_inputs_are_rejected_locally(): void
    {
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        Storage::shouldReceive('disk')->never();
        $this->assertFails($this->fixturePatch(true), $source, 'require a source directory');
        $this->assertFails($this->fixturePatch(), $this->directory, 'Missing or escaped');
        mkdir($this->directory.'/source', 0700);
        symlink($source, $this->directory.'/source/products.json');
        $this->assertFails($this->fixturePatch(), $this->directory, 'symlink');
        unlink($this->directory.'/source/products.json');
        rmdir($this->directory.'/source');
        symlink($this->directory, $this->directory.'/source');
        $this->assertFails($this->fixturePatch(), $this->directory, 'symlink');
        $this->assertFails($this->fixturePatch(), $this->directory.'/source.json-missing', 'readable regular file');
    }

    public function test_source_symlinks_with_trailing_slashes_dot_segments_or_descendants_fail_before_s3(): void
    {
        $this->source('target/source/products.json', S3UploadTestPatch::CONTENTS);
        symlink($this->directory.'/target', $this->directory.'/link');
        Storage::shouldReceive('disk')->never();

        foreach (['link', 'link/', 'link/.', 'link/./', 'link/../target', 'link/source', 'link/source/products.json'] as $source) {
            $this->assertFails($this->fixturePatch(), $this->directory.'/'.$source, 'symlink', true);
        }
        $previous = getcwd();
        try {
            chdir($this->directory);
            $this->assertFails($this->fixturePatch(), './link/.', 'symlink', true);
            $this->assertFails($this->fixturePatch(), 'link/source/products.json', 'symlink', true);
        } finally {
            chdir($previous);
        }
    }

    public function test_plain_directory_suffixes_and_relative_regular_files_remain_supported(): void
    {
        $this->source('target/source/products.json', S3UploadTestPatch::CONTENTS);
        foreach (['target/', 'target/source/../.'] as $source) {
            $this->mockS3([$this->missing()]);
            $result = app(S3DataPatchUploader::class)->upload($this->fixturePatch(), $this->directory.'/'.$source, true);
            $this->assertSame('would_upload', $result[0]['status']);
        }
        $previous = getcwd();
        try {
            chdir($this->directory);
            $this->mockS3([$this->missing()]);
            $result = app(S3DataPatchUploader::class)->upload($this->fixturePatch(), './target/source/products.json', true);
            $this->assertSame('would_upload', $result[0]['status']);
        } finally {
            chdir($previous);
        }
        $this->assertSame(['GetObject', 'GetObject', 'GetObject'], array_column($this->commands, 'name'));
    }

    public function test_required_coverage_and_conflicting_aliases_are_validated_before_storage_io(): void
    {
        Storage::shouldReceive('disk')->never();
        $patch = $this->fixturePatch();
        $patch->required = ['undeclared.json'];
        $this->assertFails($patch, $this->directory, 'absent from the S3 declarations');
        $patch = $this->fixturePatch(true);
        $patch->files['assets/photo.bin']['key'] = S3UploadTestPatch::KEY;
        $this->assertFails($patch, $this->directory, 'Conflicting SHA-256 declarations');
    }

    public function test_upload_command_resolves_patch_and_prints_plan_without_running_patch_or_ledger(): void
    {
        $patch = $this->fixturePatch();
        $source = $this->source('source.json', S3UploadTestPatch::CONTENTS);
        $resolver = Mockery::mock(DataPatchResolver::class);
        $resolver->expects('resolve')->with('s3-upload-test')->andReturn($patch);
        $this->app->instance(DataPatchResolver::class, $resolver);
        $runner = Mockery::mock(DataPatchRunner::class);
        $runner->shouldNotReceive('run');
        $this->app->instance(DataPatchRunner::class, $runner);
        $this->mockS3([$this->missing()]);

        // No database is provisioned: upload must be independent of the ledger.
        $this->artisan('app:data-patch:upload', ['patch' => 's3-upload-test', 'source' => $source, '--dry-run' => true])
            ->expectsOutputToContain('Dry run: no objects written.')
            ->expectsOutputToContain('would_upload')
            ->assertSuccessful();
    }

    public function test_upload_command_rejects_unknown_and_non_s3_patches(): void
    {
        $this->artisan('app:data-patch:upload missing /unused --no-interaction')
            ->expectsOutputToContain('Unknown data patch: missing.')
            ->assertFailed();
        $resolver = Mockery::mock(DataPatchResolver::class);
        $resolver->expects('resolve')->with('local')->andReturn(new class implements DataPatch
        {
            public const NAME = 'local';
            public const DESCRIPTION = 'local only';
            public function preview(): array { throw new RuntimeException('Must not preview.'); }
            public function apply(): array { throw new RuntimeException('Must not apply.'); }
        });
        $this->app->instance(DataPatchResolver::class, $resolver);
        $this->artisan('app:data-patch:upload local /unused --no-interaction')
            ->expectsOutputToContain('requires a patch implementing RequiresS3DataFiles')
            ->assertFailed();
    }

    private function fixturePatch(bool $multiple = false): S3UploadTestPatch
    {
        $patch = new S3UploadTestPatch;
        if ($multiple) {
            $patch->files['assets/photo.bin'] = ['key' => 'patches/s3-upload-test/photo.bin', 'sha256' => hash('sha256', 'reviewed asset')];
            $patch->required[] = 'assets/photo.bin';
        }
        $this->patches[] = $patch;

        return $patch;
    }

    private function source(string $path, string $contents): string
    {
        $path = $this->directory.'/'.$path;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function mockS3(array $responses, array $clientOptions = []): void
    {
        $mock = new MockHandler($responses);
        $client = new S3Client($clientOptions + [
            'version' => '2006-03-01', 'region' => 'us-east-1', 'endpoint' => 'https://uploads.example.test',
            'credentials' => ['key' => 'test-only', 'secret' => 'test-only'], 'use_path_style_endpoint' => true, 'retries' => 0,
            'handler' => function ($command, $request) use ($mock) {
                $this->commands[] = ['name' => $command->getName(), 'args' => $command->toArray(), 'body' => (string) $request->getBody(), 'condition' => $request->getHeaderLine('If-None-Match')];
                if ($this->onRequest !== null) {
                    ($this->onRequest)();
                }

                return $mock($command, $request);
            },
        ]);
        $configuration = config('filesystems.disks.patch-inputs');
        $adapter = new FlysystemS3Adapter($client, $configuration['bucket'], $configuration['root'] ?? '');
        $disk = new AwsS3V3Adapter(new Filesystem($adapter), $adapter, $configuration, $client);
        Storage::shouldReceive('disk')->once()->with('patch-inputs')->andReturn($disk);
    }

    private function missing(): S3Exception
    {
        return $this->s3Error(404, 'NoSuchKey');
    }

    private function s3Error(int $status, string $code): S3Exception
    {
        return new S3Exception($code, new Command('test'), ['code' => $code, 'response' => new Response($status)]);
    }

    private function object(string $contents): Result
    {
        return new Result(['Body' => Utils::streamFor($contents)]);
    }

    private function assertFails(S3UploadTestPatch $patch, string $source, string $message, bool $dryRun = false): void
    {
        try {
            app(S3DataPatchUploader::class)->upload($patch, $source, $dryRun);
            $this->fail('An unsafe upload was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}

class S3UploadTestPatch implements RequiresS3DataFiles
{
    use HasDataPatchFiles {
        setDataPatchSourceDirectory as private bind;
    }

    public const NAME = 's3-upload-test';

    public const DESCRIPTION = 'upload fixtures only';

    public const CONTENTS = '{"sku":"P-42"}';

    public const KEY = 'patches/s3-upload-test/products.json';

    protected const DATA_DIRECTORY = self::NAME;

    public array $files = ['source/products.json' => ['key' => self::KEY, 'sha256' => '']];

    public array $required = ['source/products.json'];

    public array $directories = [];

    public ?string $binding = null;

    public function __construct()
    {
        $this->files['source/products.json']['sha256'] = hash('sha256', self::CONTENTS);
    }

    public function s3Files(): array
    {
        return $this->files;
    }

    public function requiredFiles(): array
    {
        return array_map(fn (string $path): string => $this->dataPath($path), $this->required);
    }

    public function setDataPatchSourceDirectory(?string $directory): void
    {
        $this->binding = $directory;
        if ($directory !== null) {
            $this->directories[] = $directory;
        }
        $this->bind($directory);
    }

    public function preview(): array
    {
        throw new RuntimeException('Upload must never preview a patch.');
    }

    public function apply(): array
    {
        throw new RuntimeException('Upload must never apply a patch.');
    }
}
