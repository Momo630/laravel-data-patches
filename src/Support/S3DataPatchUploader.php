<?php

namespace Momo630\LaravelDataPatches\Support;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Momo630\LaravelDataPatches\Contracts\RequiresS3DataFiles;
use Psr\Http\Message\StreamInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class S3DataPatchUploader
{
    /** @return list<array{path: string, disk: string, bucket: string, key: string, sha256: string, status: string}> */
    public function upload(RequiresS3DataFiles $patch, string $source, bool $dryRun = false): array
    {
        $name = $patch::NAME;
        $files = (new S3DataPatchFiles($patch, $name))->metadata();
        if ($files === []) {
            throw new RuntimeException("Data patch {$name} must declare S3 source files.");
        }
        $keys = [];
        foreach ($files as $file) {
            if (isset($keys[$file['key']]) && $keys[$file['key']] !== $file['sha256']) {
                throw new RuntimeException("Conflicting SHA-256 declarations for S3 key {$file['key']}.");
            }
            $keys[$file['key']] = $file['sha256'];
        }

        $directory = sys_get_temp_dir().'/laravel-data-patch-upload-'.bin2hex(random_bytes(16));
        if (! @mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create a private upload snapshot directory.');
        }
        try {
            $patch->setDataPatchSourceDirectory($directory);
            $paths = array_map(fn (array $file): string => $directory.'/'.$file['path'], $files);
            $required = $patch->requiredFiles();
            if ($required === []) {
                throw new RuntimeException('Upload requires declared required S3 source files.');
            }
            foreach ($required as $path) {
                if (! is_string($path) || ! in_array($path, $paths, true)) {
                    throw new RuntimeException('A required file is absent from the S3 declarations: '.var_export($path, true).'.');
                }
            }
            $this->snapshot($files, $source, $directory);
            [$client, $options] = $this->target($files[0], $dryRun);

            // Check the whole batch before creating any object.
            foreach ($files as &$file) {
                $hash = $this->remoteHash($client, $file, $options);
                if ($hash !== null && ! hash_equals($file['sha256'], $hash)) {
                    throw new RuntimeException("S3 object conflicts with the pinned SHA-256: {$file['key']}. Nothing overwritten.");
                }
                $file['status'] = $hash === null ? 'would_upload' : 'unchanged';
            }
            unset($file);

            if (! $dryRun) {
                foreach ($files as &$file) {
                    if ($file['status'] === 'would_upload') {
                        $file['status'] = $this->put($client, $file, $directory.'/'.$file['path'], $options);
                    }
                }
                unset($file);
            }

            return $files;
        } finally {
            try {
                $patch->setDataPatchSourceDirectory(null);
            } finally {
                $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    if ($entry->isDir() && ! $entry->isLink()) {
                        rmdir($entry->getPathname());
                    } else {
                        unlink($entry->getPathname());
                    }
                }
                rmdir($directory);
            }
        }
    }

    private function snapshot(array $files, string $source, string $directory): void
    {
        // Check lexical ancestors before realpath hides links behind '/', '.' or '..'.
        $ancestors = [];
        $path = rtrim($source, '/'.DIRECTORY_SEPARATOR);
        while ($path !== '' && $path !== '.') {
            $ancestors[] = $path;
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }
        foreach (array_reverse($ancestors) as $path) {
            if (is_link($path)) {
                throw new RuntimeException('Upload source contains a symlink.');
            }
        }
        $root = realpath($source);
        if ($root === false || (! is_dir($root) && ! is_file($root))) {
            throw new RuntimeException('Upload source must be a readable regular file or directory, without a symlink.');
        }
        $singleFile = is_file($root);
        if ($singleFile && count($files) !== 1) {
            throw new RuntimeException('Multiple declared inputs require a source directory.');
        }
        foreach ($files as $file) {
            $path = $root;
            if (! $singleFile) {
                foreach (explode('/', $file['path']) as $segment) {
                    $path .= '/'.$segment;
                    if (is_link($path)) {
                        throw new RuntimeException("Upload source contains a symlink: {$file['path']}.");
                    }
                }
                $resolved = realpath($path);
                if ($resolved === false || ! str_starts_with($resolved, rtrim($root, '/').'/')) {
                    throw new RuntimeException("Missing or escaped upload source: {$file['path']}.");
                }
                $path = $resolved;
            }
            $target = $directory.'/'.$file['path'];
            if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0700, true)) {
                throw new RuntimeException('Could not create a private upload snapshot subdirectory.');
            }
            $this->copy($path, $target, $file);
        }
    }

    private function copy(string $path, string $target, array $file): void
    {
        $source = $snapshot = null;
        try {
            $before = @lstat($path);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000 || ! is_readable($path)) {
                throw new RuntimeException("Unreadable regular upload source: {$file['path']}.");
            }
            $source = @fopen($path, 'rb');
            $opened = is_resource($source) ? fstat($source) : false;
            if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino'] || ($opened['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException("Upload source changed while opening: {$file['path']}.");
            }
            $snapshot = @fopen($target, 'xb');
            if ($snapshot === false || ! chmod($target, 0600)) {
                throw new RuntimeException("Could not create private upload snapshot: {$file['path']}.");
            }
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($source)) {
                $bytes = fread($source, 1048576);
                if ($bytes === false || ($bytes === '' && ! feof($source))) {
                    throw new RuntimeException("Could not finish reading upload source: {$file['path']}.");
                }
                $size += strlen($bytes);
                if ($size > 5368709120) {
                    throw new RuntimeException('Upload inputs must fit a single S3 PUT (at most 5 GiB).');
                }
                hash_update($hash, $bytes);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($snapshot, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException("Could not finish writing upload snapshot: {$file['path']}.");
                    }
                    $offset += $written;
                }
            }
            if (! fflush($snapshot) || ! hash_equals($file['sha256'], hash_final($hash))) {
                throw new RuntimeException("Upload source SHA-256 mismatch or snapshot write failure: {$file['path']}.");
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }
        }
    }

    /** @return array{S3Client, array} */
    private function target(array $file, bool $dryRun): array
    {
        try {
            $disk = Storage::disk($file['disk']);
            if (! $disk instanceof AwsS3V3Adapter) {
                throw new RuntimeException('Upload requires the configured Laravel AwsS3V3Adapter and its optional S3 dependencies.');
            }
            $configuration = $disk->getConfig();
            $configured = config('filesystems.disks.'.$file['disk']);
            if ($configuration['bucket'] !== $file['bucket'] || ($configuration['root'] ?? '') !== ($configured['root'] ?? '')) {
                throw new RuntimeException('The resolved S3 adapter bucket or root differs from the declared disk configuration.');
            }
            $client = $disk->getClient();
            $input = $client->getApi()->getOperation('PutObject')->getInput();
            $options = $configuration['options'] ?? [];
            if (! is_array($options)) {
                throw new RuntimeException('S3 disk options must be an array.');
            }
            foreach ($options as $key => $value) {
                if (! is_string($key) || ! $input->hasMember($key) || in_array($key, ['Bucket', 'Key', 'Body', 'ContentLength', 'IfNoneMatch', 'WriteOffsetBytes'], true)) {
                    throw new RuntimeException('Upload cannot safely honor an unsupported or reserved S3 disk option.');
                }
            }
            if (! $dryRun) {
                if (! empty($configuration['read-only']) || ! empty($configured['read-only'])) {
                    throw new RuntimeException('Upload is disabled on a read-only S3 disk.');
                }
                if (($configuration['visibility'] ?? 'private') !== 'private' || ! in_array($options['ACL'] ?? 'private', ['private', 'bucket-owner-full-control'], true)) {
                    throw new RuntimeException('Upload requires private S3 visibility and ACL settings.');
                }
                if (! $input->hasMember('IfNoneMatch')) {
                    throw new RuntimeException('Update the optional AWS SDK: PutObject must support IfNoneMatch. No objects written.');
                }
                $verified = config('data-patches.s3_upload_verified_target');
                if (! is_array($verified) || ($verified['disk'] ?? null) !== $file['disk'] || ($verified['bucket'] ?? null) !== $file['bucket']
                    || ($verified['endpoint'] ?? null) !== rtrim((string) $client->getEndpoint(), '/')) {
                    throw new RuntimeException('Conditional uploads are disabled for this target. Verify this backend rejects a second PutObject with IfNoneMatch="*" without changing the object, then configure data-patches.s3_upload_verified_target with its disk, bucket and actual SDK endpoint. Use --dry-run to inspect without writes.');
                }
            }

            return [$client, $options];
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not open the configured Laravel S3 adapter; install its optional S3 dependencies and check its configuration.', 0, $exception);
        }
    }

    private function remoteHash(S3Client $client, array $file, array $options): ?string
    {
        $body = null;
        try {
            $readOptions = array_intersect_key($options, array_flip(['SSECustomerAlgorithm', 'SSECustomerKey', 'SSECustomerKeyMD5', 'RequestPayer', 'ExpectedBucketOwner']));
            $result = $client->getObject($readOptions + ['Bucket' => $file['bucket'], 'Key' => $file['key'], '@http' => ['stream' => true, 'decode_content' => false]]);
            $body = $result['Body'];
            if (! $body instanceof StreamInterface) {
                throw new RuntimeException("S3 did not return a readable stream: {$file['key']}.");
            }
            $hash = hash_init('sha256');
            while (! $body->eof()) {
                $bytes = $body->read(1048576);
                if ($bytes === '' && ! $body->eof()) {
                    throw new RuntimeException("Could not finish reading S3 object: {$file['key']}.");
                }
                hash_update($hash, $bytes);
            }

            return hash_final($hash);
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404 && $exception->getAwsErrorCode() === 'NoSuchKey') {
                return null;
            }
            throw new RuntimeException("Could not verify S3 object: {$file['key']}. No overwrite attempted.", 0, $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException("Could not verify S3 object: {$file['key']}. No overwrite attempted.", 0, $exception);
        } finally {
            if ($body instanceof StreamInterface) {
                $body->close();
            }
        }
    }

    private function put(S3Client $client, array $file, string $path, array $options): string
    {
        $body = @fopen($path, 'rb');
        if ($body === false) {
            throw new RuntimeException("Could not open upload snapshot: {$file['path']}.");
        }
        try {
            $client->putObject($options + ['Bucket' => $file['bucket'], 'Key' => $file['key'], 'Body' => $body, 'ContentLength' => fstat($body)['size'], 'IfNoneMatch' => '*']);

            return 'uploaded';
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 412) {
                $hash = $this->remoteHash($client, $file, $options);
                if ($hash !== null && hash_equals($file['sha256'], $hash)) {
                    return 'unchanged';
                }
                throw new RuntimeException("Concurrent S3 object conflicts with the pinned SHA-256: {$file['key']}. Nothing overwritten.", 0, $exception);
            }
            throw new RuntimeException("Conditional upload failed or has an uncertain outcome: {$file['key']}. Rerun to verify; existing objects are never overwritten.", 0, $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException("Conditional upload failed or has an uncertain outcome: {$file['key']}. Rerun to verify; existing objects are never overwritten.", 0, $exception);
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }
}
