<?php

namespace Momo630\LaravelDataPatches\Console\Commands;

use Illuminate\Console\Command;
use Momo630\LaravelDataPatches\Support\DataPatchResolver;
use Momo630\LaravelDataPatches\Support\DataPatchRunner;
use RuntimeException;

class DataPatchCmd extends Command
{
    protected $signature = 'app:data-patch {name} {--dry-run} {--force}';

    protected $description = 'Run a named data patch.';

    public function handle(DataPatchRunner $runner, DataPatchResolver $resolver): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Production requires --force.');

            return self::FAILURE;
        }

        try {
            $result = $runner->run($resolver->resolve($this->argument('name')), $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['outcome']);
        $this->line(json_encode($result['result']));

        return self::SUCCESS;
    }
}
