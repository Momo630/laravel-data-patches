<?php

namespace Momo630\LaravelDataPatches\Console\Commands;

use Illuminate\Console\Command;
use Momo630\LaravelDataPatches\Contracts\RequiresS3DataFiles;
use Momo630\LaravelDataPatches\Support\DataPatchResolver;
use Momo630\LaravelDataPatches\Support\S3DataPatchUploader;
use RuntimeException;

class DataPatchUploadCmd extends Command
{
    protected $signature = 'app:data-patch:upload {patch} {source} {--dry-run}';

    protected $description = 'Upload declared, checksum-pinned data patch inputs without running the patch.';

    public function handle(DataPatchResolver $resolver, S3DataPatchUploader $uploader): int
    {
        try {
            $patch = $resolver->resolve($this->argument('patch'));
            if (! $patch instanceof RequiresS3DataFiles) {
                throw new RuntimeException('Upload requires a patch implementing RequiresS3DataFiles.');
            }
            $files = $uploader->upload($patch, $this->argument('source'), $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'Dry run: no objects written.' : 'Upload complete.');
        $this->table(['Path', 'Disk', 'Bucket', 'Key', 'SHA-256', 'Status'], array_map(
            fn (array $file): array => array_values($file), $files,
        ));

        return self::SUCCESS;
    }
}
