<?php

namespace Momo630\LaravelDataPatches\Contracts;

interface RequiresDataFiles extends DataPatch
{
    /** @return list<string> Absolute paths to required source files. */
    public function requiredFiles(): array;
}
