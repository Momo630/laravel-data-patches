<?php

namespace Momo630\LaravelDataPatches\Support;

use Momo630\LaravelDataPatches\Contracts\DataPatch;
use RuntimeException;

class DataPatchResolver
{
    public function resolve(string $name): DataPatch
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
