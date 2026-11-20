<?php

namespace Momo630\LaravelDataPatches\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Momo630\LaravelDataPatches\Contracts\DataPatch;
use Momo630\LaravelDataPatches\Contracts\RequiresDataFiles;
use Momo630\LaravelDataPatches\Contracts\RequiresS3DataFiles;
use Momo630\LaravelDataPatches\Models\DataPatchApplication;
use RuntimeException;

class DataPatchRunner
{
    public function run(DataPatch $patch, bool $dryRun = false): array
    {
        [$name, $description] = $this->metadata($patch);
        $recorded = DataPatchApplication::query()->find($name);
        if ($recorded !== null) {
            if ($recorded->input_files !== null) {
                $this->checkRecordedInputs($patch, $name, $recorded->input_files);
            }
            throw new RuntimeException("Data patch {$name} has already been recorded.");
        }

        $sources = $patch instanceof RequiresS3DataFiles ? new S3DataPatchFiles($patch, $name) : null;
        try {
            $inputFiles = $sources?->stage();
            if ($patch instanceof RequiresDataFiles) {
                foreach ($patch->requiredFiles() as $file) {
                    if (! is_string($file) || $file === '' || ! preg_match('~^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)~', $file)) {
                        throw new RuntimeException("Data patch {$name} declares an invalid required file path: ".var_export($file, true).'.');
                    }
                    if (! is_file($file) || ! is_readable($file)) {
                        throw new RuntimeException("Data patch {$name} requires a readable file: {$file}.");
                    }
                }
            }

            if ($dryRun) {
                return ['outcome' => 'dry_run', 'result' => $patch->preview()];
            }

            return DB::transaction(function () use ($patch, $name, $description, $inputFiles): array {
                try {
                    $application = DataPatchApplication::query()->create([
                        'patch_id' => $name,
                        'description' => $description,
                        'outcome' => 'running',
                        'recorded_at' => now('UTC'),
                        ...($inputFiles === null ? [] : ['input_files' => $inputFiles]),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw new RuntimeException("Data patch {$name} has already been recorded.");
                }

                $result = $patch->apply();
                $outcome = $result['outcome'] ?? 'applied';
                $payload = $result['result'] ?? $result;
                $application->update([
                    'outcome' => $outcome,
                    'result' => $payload,
                    'applied_at' => $outcome === 'applied' ? now('UTC') : null,
                ]);

                return ['outcome' => $outcome, 'result' => $payload];
            });
        } finally {
            $sources?->cleanup();
        }
    }

    private function checkRecordedInputs(DataPatch $patch, string $name, array $recorded): void
    {
        try {
            $current = $patch instanceof RequiresS3DataFiles ? (new S3DataPatchFiles($patch, $name))->metadata() : [];
        } catch (RuntimeException $exception) {
            throw new RuntimeException("Data patch {$name} has already been recorded; its input files differ from the recorded metadata: ".$exception->getMessage(), 0, $exception);
        }
        $recorded = $this->normalizeInputFiles($recorded);
        $current = $this->normalizeInputFiles($current);
        $differences = [];
        foreach (array_unique([...array_keys($recorded), ...array_keys($current)]) as $path) {
            if (! isset($recorded[$path])) {
                $differences[] = "added: {$path}";
            } elseif (! isset($current[$path])) {
                $differences[] = "removed: {$path}";
            } elseif ($recorded[$path] !== $current[$path]) {
                $differences[] = "changed: {$path}";
            }
        }
        if ($differences !== []) {
            throw new RuntimeException("Data patch {$name} has already been recorded; its input files differ from the recorded metadata (".implode('; ', $differences).').');
        }
    }

    private function normalizeInputFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $file) {
            ksort($file);
            $normalized[$file['path']] = $file;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return array{string, string} */
    private function metadata(DataPatch $patch): array
    {
        if (! is_string($patch::NAME) || $patch::NAME === '' || ! is_string($patch::DESCRIPTION) || $patch::DESCRIPTION === '') {
            throw new RuntimeException('Invalid data patch metadata: '.$patch::class.'.');
        }

        return [$patch::NAME, $patch::DESCRIPTION];
    }
}
