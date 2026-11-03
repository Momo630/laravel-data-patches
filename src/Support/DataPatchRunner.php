<?php

namespace Momo630\LaravelDataPatches\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Momo630\LaravelDataPatches\Contracts\DataPatch;
use Momo630\LaravelDataPatches\Contracts\RequiresDataFiles;
use Momo630\LaravelDataPatches\Models\DataPatchApplication;
use RuntimeException;

class DataPatchRunner
{
    public function run(DataPatch $patch, bool $dryRun = false): array
    {
        [$name, $description] = $this->metadata($patch);
        if (DataPatchApplication::query()->whereKey($name)->exists()) {
            throw new RuntimeException("Data patch {$name} has already been recorded.");
        }

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

        return DB::transaction(function () use ($patch, $name, $description): array {
            try {
                $application = DataPatchApplication::query()->create([
                    'patch_id' => $name,
                    'description' => $description,
                    'outcome' => 'running',
                    'recorded_at' => now('UTC'),
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
