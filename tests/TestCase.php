<?php

namespace Tests;

use Momo630\LaravelDataPatches\LaravelDataPatchesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelDataPatchesServiceProvider::class];
    }
}
