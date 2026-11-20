<?php

namespace Momo630\LaravelDataPatches\Contracts;

interface RequiresS3DataFiles extends RequiresDataFiles
{
    /** @return array<string, array{key: string, sha256: string}> Relative local paths mapped to reviewed S3 objects. */
    public function s3Files(): array;

    public function setDataPatchSourceDirectory(?string $directory): void;
}
