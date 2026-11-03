# Laravel Data Patches

Laravel package for explicitly run, one-off data patches with a unique execution ledger. Patches are never auto-run; Laravel's migration command only creates the ledger table.

## Requirements

PHP `^8.2` and Illuminate Console, Database, and Support `^12.0` or `^13.8`.

## Install from private GitHub

Add the private VCS repository to the consuming application's `composer.json`, using its normal GitHub credentials:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Momo630/laravel-data-patches" }
  ]
}
```

For the initial untagged repository, install the development branch:

```sh
composer require momo630/laravel-data-patches:dev-main
```

After the first `v1.0.0` tag, use the stable constraint:

```sh
composer require momo630/laravel-data-patches:^1.0
```

Composer auto-discovers the service provider. Run `php artisan migrate` to create the patch ledger. Existing installations that recorded the original `2026_09_08_000000_create_data_patch_applications_table` migration will skip it.

## ClubEx cutover

After adding the package, remove ClubEx's app-level `DataPatchCmd`, `DataPatchRunner`, `DataPatchApplication`, `DataPatch` contract, and the app copy of `2026_09_08_000000_create_data_patch_applications_table.php`. Change each concrete patch to import `Momo630\LaravelDataPatches\Contracts\DataPatch`, and keep only this package's `app:data-patch` command. Its migration keeps the same basename and schema, so installations that already recorded it skip the package copy. Never add data-patch execution to deployment; run patches explicitly.

## Use

Create a class in `app/DataPatches` implementing `Momo630\LaravelDataPatches\Contracts\DataPatch`. Give it nonempty, unique `NAME` and `DESCRIPTION` constants and implement `preview(): array` and `apply(): array`.

```php
<?php

namespace App\DataPatches;

use Momo630\LaravelDataPatches\Contracts\DataPatch;

class RebuildSearchIndex implements DataPatch
{
    public const NAME = 'rebuild-search-index';
    public const DESCRIPTION = 'Rebuild the search index';

    public function preview(): array
    {
        return ['records' => 42];
    }

    public function apply(): array
    {
        // Apply the patch and return a JSON-serializable result.
        return ['updated' => 42];
    }
}
```

Run `php artisan app:data-patch rebuild-search-index --dry-run` to preview, or run without `--dry-run` to apply it once. Production requires `--force`. Publish `data-patches-config` to override the patch directory (`path`) or class namespace (`namespace`) in `config/data-patches.php`.

## Patches with source files

File-backed patches implement `Momo630\LaravelDataPatches\Contracts\RequiresDataFiles`, which extends `DataPatch` with `requiredFiles(): array`. Declare known mandatory primary source files as absolute paths. Each file-backed patch owns a subdirectory under `storage/app/private/data-patches/`; the optional `HasDataPatchFiles` trait supplies `dataDirectory()` and `dataPath($relative)` using the patch's `DATA_DIRECTORY` constant.

```php
<?php

namespace App\DataPatches;

use Momo630\LaravelDataPatches\Contracts\RequiresDataFiles;
use Momo630\LaravelDataPatches\Support\HasDataPatchFiles;

class ImportProducts implements RequiresDataFiles
{
    use HasDataPatchFiles;

    public const NAME = 'import-products';
    public const DESCRIPTION = 'Import products from a reviewed source';

    protected const DATA_DIRECTORY = 'import-products';

    public function requiredFiles(): array
    {
        return [$this->dataPath('products.csv')];
    }

    public function preview(): array
    {
        // Read $this->dataPath('products.csv') and report proposed changes.
        return ['records' => 42];
    }

    public function apply(): array
    {
        // Read $this->dataPath('products.csv') and apply the patch.
        return ['updated' => 42];
    }
}
```

Provision the source separately at `storage/app/private/data-patches/import-products/products.csv`. Keep operational source files private and out of Git; ensure the consuming application's `.gitignore` excludes `/storage/app/private/data-patches/`.

Before previewing or starting the apply transaction, the runner checks only the explicitly declared files: each path must be nonempty, absolute, and a readable regular file. Missing or unreadable declared files raise an error containing the patch name and full filename; `app:data-patch` exits unsuccessfully without running the patch or writing its ledger entry. The patch must still validate file contents and dynamically referenced files, such as assets listed in a manifest. Failures in these patch checks abort execution; apply failures roll back the database transaction and its ledger entry. Applying a patch does not call `preview()`. Already recorded patches are rejected before checking files.

Ordinary patches that implement only `DataPatch` need no data directory. The existing `data-patches.path` configuration still locates PHP patch classes.

## Releases

Keep `CHANGELOG.md` updated and tag releases using Semantic Versioning. Breaking changes to the patch contract, command, or ledger semantics require a major version; use minor versions for backward-compatible features and patch versions for fixes.
