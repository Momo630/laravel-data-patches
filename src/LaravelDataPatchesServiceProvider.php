<?php

namespace Momo630\LaravelDataPatches;

use Illuminate\Support\ServiceProvider;
use Momo630\LaravelDataPatches\Console\Commands\DataPatchCmd;
use Momo630\LaravelDataPatches\Console\Commands\DataPatchUploadCmd;

class LaravelDataPatchesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/data-patches.php', 'data-patches');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([DataPatchCmd::class, DataPatchUploadCmd::class]);
            $this->publishes([
                __DIR__.'/../config/data-patches.php' => config_path('data-patches.php'),
            ], 'data-patches-config');
        }
    }
}
