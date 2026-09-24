<?php

namespace Momo630\LaravelDataPatches\Contracts;

interface DataPatch
{
    public const NAME = '';

    public const DESCRIPTION = '';

    public function preview(): array;

    public function apply(): array;
}
