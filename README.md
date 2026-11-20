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

Composer auto-discovers the service provider. Run `php artisan migrate` to create or update the patch ledger. Existing installations that recorded the original `2026_09_08_000000_create_data_patch_applications_table` migration will skip it.

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

## Opt-in S3 source files

Existing local-file and fileless patches need no S3 configuration or SDK. To opt a file-backed patch into S3, implement `Momo630\LaravelDataPatches\Contracts\RequiresS3DataFiles` instead of `RequiresDataFiles`, keep `HasDataPatchFiles` and the existing absolute `requiredFiles()` paths, and add `s3Files()`:

```php
// Keep the reviewed SHA-256 in code; replace this placeholder before running.
private const SOURCE_SHA256 = '<reviewed 64-character SHA-256>';

public function s3Files(): array
{
    return [
        'products.csv' => [
            'key' => 'patches/'.self::NAME.'/products.csv',
            'sha256' => self::SOURCE_SHA256,
        ],
    ];
}
```

Each map key is a relative local path used by `dataPath()`. Each object key is the full bucket-relative S3 key and must be below `patches/<NAME>/`. Paths must have no absolute prefix, backslashes, colons, empty segments, `.` or `..` segments, or control characters. A SHA-256 of exactly 64 hexadecimal characters is mandatory for every object; uppercase hashes are accepted and normalized. Calculate it from the separately reviewed source (for example, `sha256sum products.csv`), then commit that expected value with the patch. Never derive the expected hash from the object during execution. All `requiredFiles()` must be covered by the declarations; any additional declared assets are also downloaded and verified. The package does not discover or fetch undeclared dynamic references.

The consuming application supplies its normal Laravel S3 filesystem adapter, named disk, bucket, endpoint and credentials. The package adds no direct AWS dependency. Explicitly select that disk in `config/data-patches.php` (the provider merges the remaining defaults):

```php
return [
    's3_disk' => 'patch-inputs',
];
```

The disk must use Laravel's `s3` driver and have a bucket. Its optional `root` prefix must contain the declared full object keys; the runner removes that prefix only when calling the Laravel adapter. The default `s3_disk` is `null`, so ordinary patches keep their local behavior. Configure credentials and access restrictions in the host application; the runner only reads the exact declared keys and never lists, uploads, or deletes S3 objects.

For preview and apply, the runner creates a separate private temporary directory for that patch invocation, streams each declared object into it, and computes SHA-256 from the same bytes. Only after every file matches its expected hash does it call `preview()` or begin the apply transaction. Missing objects, malformed declarations, incomplete required-file coverage, or checksum mismatches fail without callbacks or ledger writes. There is no fallback to local copies. Keep parsers using `dataPath()`; the trait redirects those absolute paths to the staged files while the runner owns the binding. The runner clears that binding and removes staged files on success and exceptions, including dry runs. Patch callbacks remain responsible for validating source contents and dynamic references.

Run `php artisan migrate` after updating the package. The new, separate `2026_11_20_000000_add_input_files_to_data_patch_applications_table` migration adds nullable JSON `input_files` without changing the original ledger migration, existing rows, or `result` payload. Successful S3 runs record a list of `{path, disk, bucket, key, sha256}` objects in the same transaction as the patch. Paths are stable relative paths, and hashes are the verified actual values. Dry runs and failed applications stay unrecorded.

A recorded patch is always blocked before storage I/O. For recorded S3 metadata, the runner also compares current declarations, disk and bucket against the ledger, ignoring declaration order. It reports added, removed or changed paths without downloading objects, running callbacks, or changing history. Legacy rows with `input_files = null` retain their existing recorded-patch guard; the runner does not invent provenance for them.

## Releases

Keep `CHANGELOG.md` updated and tag releases using Semantic Versioning. Breaking changes to the patch contract, command, or ledger semantics require a major version; use minor versions for backward-compatible features and patch versions for fixes.
