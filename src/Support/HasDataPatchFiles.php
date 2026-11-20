<?php

namespace Momo630\LaravelDataPatches\Support;

trait HasDataPatchFiles
{
    private ?string $dataPatchSourceDirectory = null;

    public function setDataPatchSourceDirectory(?string $directory): void
    {
        $this->dataPatchSourceDirectory = $directory;
    }

    protected function dataDirectory(): string
    {
        return $this->dataPatchSourceDirectory ?? storage_path('app/private/data-patches/'.static::DATA_DIRECTORY);
    }

    protected function dataPath(string $relative): string
    {
        return ($this->dataPatchSourceDirectory ?? $this->dataDirectory()).'/'.$relative;
    }
}
