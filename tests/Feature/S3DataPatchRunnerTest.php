<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Momo630\LaravelDataPatches\Contracts\RequiresS3DataFiles;
use Momo630\LaravelDataPatches\Models\DataPatchApplication;
use Momo630\LaravelDataPatches\Support\DataPatchRunner;
use Momo630\LaravelDataPatches\Support\HasDataPatchFiles;
use RuntimeException;
use Tests\TestCase;

class S3DataPatchRunnerTest extends TestCase
{
    use RefreshDatabase;

    private array $streams = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['data-patches.s3_disk' => 'patch-inputs', 'filesystems.disks.patch-inputs' => ['driver' => 's3', 'bucket' => 'private-inputs']]);
    }

    public function test_verified_input_is_read_and_recorded_without_changing_the_result(): void
    {
        $patch = new S3RunnerTestPatch;
        $patch->files['source/products.json']['sha256'] = strtoupper(hash('sha256', S3RunnerTestPatch::CONTENTS));
        $this->mockObjects([S3RunnerTestPatch::KEY => S3RunnerTestPatch::CONTENTS]);

        $result = app(DataPatchRunner::class)->run($patch);
        $application = DataPatchApplication::query()->findOrFail($patch::NAME);

        $this->assertSame(['outcome' => 'applied', 'result' => ['sku' => 'P-42']], $result);
        $this->assertSame($result['result'], $application->result);
        $this->assertSame([[
            'path' => 'source/products.json', 'disk' => 'patch-inputs', 'bucket' => 'private-inputs',
            'key' => S3RunnerTestPatch::KEY, 'sha256' => hash('sha256', S3RunnerTestPatch::CONTENTS),
        ]], $application->input_files);
        $this->assertSame([0700, 0700, 0600], $patch->permissions);
        $this->assertSame(0, $patch->previews);
        $this->assertSame(1, $patch->applies);
        $this->assertClean($patch);
    }

    public function test_preview_is_verified_isolated_and_unrecorded(): void
    {
        $patch = new S3RunnerTestPatch;
        $this->mockObjects([S3RunnerTestPatch::KEY => S3RunnerTestPatch::CONTENTS], 2);
        $runner = app(DataPatchRunner::class);

        $this->assertSame(['outcome' => 'dry_run', 'result' => ['sku' => 'P-42']], $runner->run($patch, true));
        $this->assertDatabaseCount('data_patch_applications', 0);
        $this->assertSame(0, $patch->applies);
        $this->assertClean($patch);
        $runner->run($patch);
        $this->assertNotSame($patch->directories[0], $patch->directories[1]);
        $this->assertClean($patch);
    }

    public function test_s3_disk_root_is_removed_only_for_the_adapter_read(): void
    {
        config(['filesystems.disks.patch-inputs.root' => 'patches/s3-runner-test']);
        $patch = new S3RunnerTestPatch;
        $this->mockObjects(['products.json' => S3RunnerTestPatch::CONTENTS]);

        app(DataPatchRunner::class)->run($patch);

        $this->assertSame(S3RunnerTestPatch::KEY, DataPatchApplication::query()->firstOrFail()->input_files[0]['key']);
        $this->assertClean($patch);
    }

    public function test_staging_takes_precedence_over_a_custom_local_directory(): void
    {
        $patch = new class extends S3RunnerTestPatch
        {
            protected function dataDirectory(): string
            {
                return sys_get_temp_dir().'/unused-local-data-patch-root';
            }

            public function apply(): array
            {
                return ['result' => json_decode(file_get_contents($this->sourcePath()), true, 512, JSON_THROW_ON_ERROR)];
            }
        };
        $this->mockObjects([S3RunnerTestPatch::KEY => S3RunnerTestPatch::CONTENTS]);

        $this->assertSame(['sku' => 'P-42'], app(DataPatchRunner::class)->run($patch)['result']);
        $this->assertSame(sys_get_temp_dir().'/unused-local-data-patch-root/source/products.json', $patch->sourcePath());
        foreach ($patch->directories as $directory) {
            $this->assertDirectoryDoesNotExist($directory);
        }
    }

    public function test_missing_s3_input_never_falls_back_to_a_local_copy(): void
    {
        $patch = new S3RunnerTestPatch;
        $local = $patch->requiredFiles()[0];
        mkdir(dirname($local), 0700, true);
        file_put_contents($local, S3RunnerTestPatch::CONTENTS);
        $this->mockObjects([S3RunnerTestPatch::KEY => null], 2);
        try {
            foreach ([true, false] as $dryRun) {
                $this->assertRunFails($patch, 'could not read S3 object', $dryRun);
                $this->assertSame(S3RunnerTestPatch::CONTENTS, file_get_contents($local));
                $this->assertClean($patch);
            }
        } finally {
            unlink($local);
            rmdir(dirname($local));
            rmdir(dirname(dirname($local)));
        }
    }

    public function test_checksum_mismatch_blocks_preview_and_apply(): void
    {
        $patch = new S3RunnerTestPatch;
        $this->mockObjects([S3RunnerTestPatch::KEY => '{"sku":"tampered"}'], 2);
        foreach ([true, false] as $dryRun) {
            $this->assertRunFails($patch, 'SHA-256 mismatch', $dryRun);
            $this->assertClean($patch);
        }
    }

    public function test_partial_download_is_cleaned_up_when_another_declared_object_is_missing(): void
    {
        $patch = new S3RunnerTestPatch;
        $extraKey = 'patches/'.$patch::NAME.'/asset.json';
        $patch->files['zz-assets/asset.json'] = ['key' => $extraKey, 'sha256' => hash('sha256', 'asset')];
        $this->mockObjects([S3RunnerTestPatch::KEY => S3RunnerTestPatch::CONTENTS, $extraKey => null]);

        $this->assertRunFails($patch, 'could not read S3 object');
        $this->assertClean($patch);
    }

    public function test_invalid_declarations_are_rejected_before_storage_access(): void
    {
        Storage::shouldReceive('disk')->never();
        $valid = (new S3RunnerTestPatch)->files;
        $source = $valid['source/products.json'];
        $declarations = [
            [],
            ['../products.json' => $source],
            ['/products.json' => $source],
            ['source/./products.json' => $source],
            ['source//products.json' => $source],
            ['C:\\products.json' => $source],
            ["source/\0products.json" => $source],
            ['source/products.json' => ['key' => S3RunnerTestPatch::KEY]],
            ['source/products.json' => ['key' => S3RunnerTestPatch::KEY, 'sha256' => 'wrong']],
            ['source/products.json' => ['key' => 'patches/another-patch/products.json', 'sha256' => $source['sha256']]],
            ['source/products.json' => ['key' => 'patches/s3-runner-test/../products.json', 'sha256' => $source['sha256']]],
        ];
        foreach ($declarations as $files) {
            $patch = new S3RunnerTestPatch;
            $patch->files = $files;
            $this->assertRunFails($patch, 'Data patch '.$patch::NAME);
            $this->assertClean($patch);
        }
    }

    public function test_unconfigured_or_non_s3_disk_fails_closed(): void
    {
        Storage::shouldReceive('disk')->never();
        foreach ([null, 'unknown', 'local'] as $disk) {
            config(['data-patches.s3_disk' => $disk]);
            $patch = new S3RunnerTestPatch;
            $this->assertRunFails($patch, 'explicitly configured S3 disk and bucket');
            $this->assertClean($patch);
        }
    }

    public function test_required_file_must_be_in_the_bound_s3_declarations(): void
    {
        Storage::shouldReceive('disk')->never();
        foreach ([[], ['source/undeclared.json'], ['/tmp/unbound-products.json']] as $required) {
            $patch = new S3RunnerTestPatch;
            $patch->required = $required;
            $this->assertRunFails($patch, 'Data patch '.$patch::NAME);
            $this->assertClean($patch);
        }
    }

    public function test_callback_failures_unbind_and_remove_staging_without_recording(): void
    {
        $this->mockObjects([S3RunnerTestPatch::KEY => S3RunnerTestPatch::CONTENTS], 3);
        foreach ([true, false] as $dryRun) {
            $patch = new S3RunnerTestPatch;
            $patch->fails = true;
            try {
                app(DataPatchRunner::class)->run($patch, $dryRun);
                $this->fail('The failing callback was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('expected callback failure', $exception->getMessage());
            }
            $this->assertDatabaseCount('data_patch_applications', 0);
            $this->assertClean($patch);
        }
        $patch->fails = false;
        $this->assertSame('applied', app(DataPatchRunner::class)->run($patch)['outcome']);
        $this->assertClean($patch);
    }

    public function test_repeat_compares_all_stable_metadata_without_storage_or_callbacks(): void
    {
        $patch = new S3RunnerTestPatch;
        $extraKey = 'patches/'.$patch::NAME.'/asset.json';
        $patch->files['zz-assets/asset.json'] = ['key' => $extraKey, 'sha256' => hash('sha256', 'asset')];
        $this->mockObjects([S3RunnerTestPatch::KEY => S3RunnerTestPatch::CONTENTS, $extraKey => 'asset']);
        $runner = app(DataPatchRunner::class);
        $runner->run($patch);
        $checks = $patch->fileChecks;
        $history = DataPatchApplication::query()->firstOrFail()->getAttributes();
        $original = $patch->files;

        $patch->files = array_reverse($original, true);
        $this->assertRecordedFailure($runner, $patch, 'has already been recorded.');
        $patch->files['source/products.json']['sha256'] = hash('sha256', 'changed');
        $this->assertRecordedFailure($runner, $patch, 'changed: source/products.json');
        $patch->files = $original;
        $patch->files['source/products.json']['key'] = 'patches/'.$patch::NAME.'/replaced.json';
        $this->assertRecordedFailure($runner, $patch, 'changed: source/products.json');
        $patch->files = $original;
        unset($patch->files['zz-assets/asset.json']);
        $this->assertRecordedFailure($runner, $patch, 'removed: zz-assets/asset.json');
        $patch->files = [];
        $this->assertRecordedFailure($runner, $patch, 'removed: source/products.json');
        $this->assertRecordedFailure($runner, $patch, 'removed: zz-assets/asset.json');
        $patch->files = $original + ['new.json' => ['key' => 'patches/'.$patch::NAME.'/new.json', 'sha256' => hash('sha256', 'new')]];
        $this->assertRecordedFailure($runner, $patch, 'added: new.json');
        $patch->files = $original;
        config(['filesystems.disks.patch-inputs.bucket' => 'other-bucket']);
        $this->assertRecordedFailure($runner, $patch, 'changed: source/products.json');
        config(['filesystems.disks.other-inputs' => ['driver' => 's3', 'bucket' => 'private-inputs'], 'data-patches.s3_disk' => 'other-inputs']);
        $this->assertRecordedFailure($runner, $patch, 'changed: source/products.json');

        $this->assertSame($checks, $patch->fileChecks);
        $this->assertSame(1, $patch->applies);
        $this->assertSame(0, $patch->previews);
        $this->assertSame($history, DataPatchApplication::query()->firstOrFail()->getAttributes());
        $this->assertClean($patch);
    }

    public function test_legacy_null_metadata_preserves_the_recorded_gate_without_reading_declarations(): void
    {
        $patch = new S3RunnerTestPatch;
        $patch->files = [];
        config(['data-patches.s3_disk' => null]);
        Storage::shouldReceive('disk')->never();
        DataPatchApplication::query()->create(['patch_id' => $patch::NAME, 'description' => $patch::DESCRIPTION, 'outcome' => 'applied', 'recorded_at' => now('UTC')]);

        $this->assertRecordedFailure(app(DataPatchRunner::class), $patch, 'has already been recorded.');

        $this->assertSame(0, $patch->declarationChecks);
        $this->assertSame(0, $patch->fileChecks);
        $this->assertNull(DataPatchApplication::query()->firstOrFail()->input_files);
    }

    private function mockObjects(array $objects, int $opens = 1): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('readStream')->times(count($objects) * $opens)->andReturnUsing(function (string $key) use ($objects) {
            $this->assertArrayHasKey($key, $objects);
            if ($objects[$key] === null) {
                return false;
            }
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $objects[$key]);
            rewind($stream);
            $this->streams[] = $stream;

            return $stream;
        });
        Storage::shouldReceive('disk')->times($opens)->with('patch-inputs')->andReturn($disk);
    }

    private function assertRunFails(S3RunnerTestPatch $patch, string $message, bool $dryRun = false): void
    {
        try {
            app(DataPatchRunner::class)->run($patch, $dryRun);
            $this->fail('The invalid source was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame(0, $patch->previews);
        $this->assertSame(0, $patch->applies);
        $this->assertDatabaseCount('data_patch_applications', 0);
    }

    private function assertRecordedFailure(DataPatchRunner $runner, S3RunnerTestPatch $patch, string $message): void
    {
        foreach ([true, false] as $dryRun) {
            try {
                $runner->run($patch, $dryRun);
                $this->fail('The recorded patch was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    private function assertClean(S3RunnerTestPatch $patch): void
    {
        foreach ($patch->directories as $directory) {
            $this->assertDirectoryDoesNotExist($directory);
        }
        $this->assertSame(storage_path('app/private/data-patches/s3-runner-test/source/products.json'), $patch->sourcePath());
        foreach ($this->streams as $stream) {
            $this->assertFalse(is_resource($stream));
        }
    }
}

class S3RunnerTestPatch implements RequiresS3DataFiles
{
    use HasDataPatchFiles {
        setDataPatchSourceDirectory as private bindSourceDirectory;
    }

    public const NAME = 's3-runner-test';

    public const DESCRIPTION = 'S3 runner test';

    public const KEY = 'patches/s3-runner-test/products.json';

    public const CONTENTS = '{"sku":"P-42"}';

    protected const DATA_DIRECTORY = 's3-runner-test';

    public array $files;

    public array $required = ['source/products.json'];

    public array $directories = [];

    public array $permissions = [];

    public int $previews = 0;

    public int $applies = 0;

    public int $fileChecks = 0;

    public int $declarationChecks = 0;

    public bool $fails = false;

    public function __construct()
    {
        $this->files = ['source/products.json' => ['key' => self::KEY, 'sha256' => hash('sha256', self::CONTENTS)]];
    }

    public function s3Files(): array
    {
        $this->declarationChecks++;

        return $this->files;
    }

    public function setDataPatchSourceDirectory(?string $directory): void
    {
        if ($directory !== null) {
            $this->directories[] = $directory;
        }
        $this->bindSourceDirectory($directory);
    }

    public function requiredFiles(): array
    {
        $this->fileChecks++;

        return array_map(fn (string $path): string => str_starts_with($path, '/') ? $path : $this->dataPath($path), $this->required);
    }

    public function sourcePath(): string
    {
        return $this->dataPath('source/products.json');
    }

    public function preview(): array
    {
        $this->previews++;

        return $this->readSource();
    }

    public function apply(): array
    {
        $this->applies++;

        return ['result' => $this->readSource()];
    }

    private function readSource(): array
    {
        if ($this->fails) {
            throw new RuntimeException('expected callback failure');
        }
        $this->permissions = array_map(fn (string $path): int => fileperms($path) & 0777, [$this->dataDirectory(), dirname($this->sourcePath()), $this->sourcePath()]);

        return json_decode(file_get_contents($this->sourcePath()), true, 512, JSON_THROW_ON_ERROR);
    }
}
