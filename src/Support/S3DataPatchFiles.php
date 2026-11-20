<?php

namespace Momo630\LaravelDataPatches\Support;

use Illuminate\Support\Facades\Storage;
use Momo630\LaravelDataPatches\Contracts\RequiresS3DataFiles;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class S3DataPatchFiles
{
    private ?string $directory = null;

    private string $root = '';

    public function __construct(private RequiresS3DataFiles $patch, private string $name) {}

    /** @return list<array{path: string, disk: string, bucket: string, key: string, sha256: string}> */
    public function metadata(): array
    {
        if (! $this->safePath($this->name) || str_contains($this->name, '/')) {
            throw new RuntimeException("Data patch {$this->name} requires a safe single-segment patch name.");
        }
        $declarations = $this->patch->s3Files();
        if ($declarations === []) {
            return [];
        }
        $disk = config('data-patches.s3_disk');
        $configuration = is_string($disk) && $disk !== '' ? (config('filesystems.disks', [])[$disk] ?? null) : null;
        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 's3' || ! is_string($configuration['bucket'] ?? null) || $configuration['bucket'] === '') {
            throw new RuntimeException("Data patch {$this->name} requires an explicitly configured S3 disk and bucket (data-patches.s3_disk).");
        }

        $root = $configuration['root'] ?? '';
        if (! is_string($root) || ($root !== '' && ! $this->safePath(trim($root, '/')))) {
            throw new RuntimeException("Data patch {$this->name} has an invalid S3 disk root.");
        }
        $this->root = trim($root, '/');
        $files = [];
        foreach ($declarations as $path => $declaration) {
            if (! is_string($path) || ! $this->safePath($path) || ! is_array($declaration)
                || ! is_string($declaration['key'] ?? null) || ! $this->safePath($declaration['key'])
                || ! str_starts_with($declaration['key'], "patches/{$this->name}/")
                || ! is_string($declaration['sha256'] ?? null) || ! preg_match('/\A[0-9a-fA-F]{64}\z/', $declaration['sha256'])) {
                throw new RuntimeException("Data patch {$this->name} declares an invalid S3 file: ".var_export($path, true).'.');
            }
            if ($this->root !== '' && ! str_starts_with($declaration['key'], $this->root.'/')) {
                throw new RuntimeException("Data patch {$this->name} declares an S3 key outside the disk root: {$declaration['key']}.");
            }
            $files[$path] = ['path' => $path, 'disk' => $disk, 'bucket' => $configuration['bucket'], 'key' => $declaration['key'], 'sha256' => strtolower($declaration['sha256'])];
        }
        ksort($files, SORT_STRING);

        return array_values($files);
    }

    /** @return list<array{path: string, disk: string, bucket: string, key: string, sha256: string}> */
    public function stage(): array
    {
        $files = $this->metadata();
        if ($files === []) {
            throw new RuntimeException("Data patch {$this->name} must declare S3 source files.");
        }
        $this->directory = sys_get_temp_dir().'/laravel-data-patch-'.bin2hex(random_bytes(16));
        if (! @mkdir($this->directory, 0700)) {
            throw new RuntimeException("Data patch {$this->name} could not create a private source directory.");
        }
        $this->patch->setDataPatchSourceDirectory($this->directory);
        $stagedPaths = array_map(fn (array $file): string => $this->directory.'/'.$file['path'], $files);
        $requiredFiles = $this->patch->requiredFiles();
        if ($requiredFiles === []) {
            throw new RuntimeException("Data patch {$this->name} must declare required S3 source files.");
        }
        foreach ($requiredFiles as $file) {
            if (! is_string($file) || ! in_array($file, $stagedPaths, true)) {
                throw new RuntimeException("Data patch {$this->name} requires a file absent from its S3 declarations: ".var_export($file, true).'.');
            }
        }

        try {
            $disk = Storage::disk($files[0]['disk']);
        } catch (Throwable $exception) {
            throw new RuntimeException("Data patch {$this->name} could not open its S3 disk.", 0, $exception);
        }
        foreach ($files as &$file) {
            $file['sha256'] = $this->download($disk, $file);
        }

        return $files;
    }

    public function cleanup(): void
    {
        try {
            $this->patch->setDataPatchSourceDirectory(null);
        } finally {
            if ($this->directory !== null && is_link($this->directory)) {
                unlink($this->directory);
            } elseif ($this->directory !== null && is_dir($this->directory)) {
                $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    if ($entry->isDir() && ! $entry->isLink()) {
                        rmdir($entry->getPathname());
                    } else {
                        unlink($entry->getPathname());
                    }
                }
                rmdir($this->directory);
            }
            $this->directory = null;
        }
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && ! preg_match('/[\\\\:\x00-\x1f\x7f]/', $path)
            && ! array_intersect(explode('/', $path), ['', '.', '..']);
    }

    private function download($disk, array $file): string
    {
        $source = $target = null;
        try {
            $key = $this->root === '' ? $file['key'] : substr($file['key'], strlen($this->root) + 1);
            try {
                $source = $disk->readStream($key);
            } catch (Throwable $exception) {
                throw new RuntimeException("Data patch {$this->name} could not read S3 object {$file['key']}.", 0, $exception);
            }
            if (! is_resource($source)) {
                throw new RuntimeException("Data patch {$this->name} could not read S3 object {$file['key']}.");
            }
            $path = $this->directory.'/'.$file['path'];
            $parent = dirname($path);
            if (! is_dir($parent) && ! @mkdir($parent, 0700, true)) {
                throw new RuntimeException("Data patch {$this->name} could not create a source subdirectory.");
            }
            $target = @fopen($path, 'xb');
            if ($target === false || ! chmod($path, 0600)) {
                throw new RuntimeException("Data patch {$this->name} could not create source file {$file['path']}.");
            }
            $hash = hash_init('sha256');
            while (! feof($source)) {
                $bytes = fread($source, 1048576);
                if ($bytes === false || ($bytes === '' && ! feof($source))) {
                    throw new RuntimeException("Data patch {$this->name} could not finish reading S3 object {$file['key']}.");
                }
                hash_update($hash, $bytes);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($target, substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException("Data patch {$this->name} could not finish writing source file {$file['path']}.");
                    }
                    $offset += $written;
                }
            }
            if (! fflush($target)) {
                throw new RuntimeException("Data patch {$this->name} could not finish writing source file {$file['path']}.");
            }
            $sha256 = hash_final($hash);
            if (! hash_equals($file['sha256'], $sha256)) {
                throw new RuntimeException("Data patch {$this->name} SHA-256 mismatch for {$file['path']} ({$file['key']}).");
            }

            return $sha256;
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
