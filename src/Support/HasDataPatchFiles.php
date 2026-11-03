<?php

namespace Momo630\LaravelDataPatches\Support;

trait HasDataPatchFiles
{
    protected function dataDirectory(): string
    {
        return storage_path('app/private/data-patches/'.static::DATA_DIRECTORY);
    }

    protected function dataPath(string $relative): string
    {
        return $this->dataDirectory().'/'.$relative;
    }
}
