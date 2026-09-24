<?php

namespace Momo630\LaravelDataPatches\Console\Commands;

use Illuminate\Console\Command;
use Momo630\LaravelDataPatches\Contracts\DataPatch;
use Momo630\LaravelDataPatches\Support\DataPatchRunner;
use RuntimeException;

class DataPatchCmd extends Command
{
    protected $signature = 'app:data-patch {name} {--dry-run} {--force}';

    protected $description = 'Run a named data patch.';

    public function handle(DataPatchRunner $runner): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Production requires --force.');

            return self::FAILURE;
        }

        try {
            $result = $runner->run($this->resolve($this->argument('name')), $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['outcome']);
        $this->line(json_encode($result['result']));

        return self::SUCCESS;
    }

    private function resolve(string $name): DataPatch
    {
        $path = rtrim(config('data-patches.path'), DIRECTORY_SEPARATOR);
        $namespace = trim(config('data-patches.namespace'), '\\');
        $patches = [];
        foreach (glob($path.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $class = ($namespace === '' ? '' : $namespace.'\\').basename($file, '.php');
            $patch = app()->make($class);
            if (! $patch instanceof DataPatch || ! is_string($class::NAME) || $class::NAME === '' || ! is_string($class::DESCRIPTION) || $class::DESCRIPTION === '') {
                throw new RuntimeException("Invalid data patch: {$class}.");
            }
            if (isset($patches[$class::NAME])) {
                throw new RuntimeException('Duplicate data patch name.');
            }
            $patches[$class::NAME] = $patch;
        }
        if (! isset($patches[$name])) {
            throw new RuntimeException("Unknown data patch: {$name}.");
        }

        return $patches[$name];
    }
}
