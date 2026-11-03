# Repository guidance

- Keep the package compatible with the PHP and Illuminate constraints in `composer.json`; do not include application-specific patches.
- Preserve the patch contract, command, config keys, ledger schema, and migration filename. Record breaking changes in `CHANGELOG.md` and release them under a new major version.
- Never run data patches automatically during installation or deployment.
- File-backed patches implement `RequiresDataFiles`, declare known mandatory primary sources, and own a subdirectory under `storage/app/private/data-patches/`; use `HasDataPatchFiles` with `DATA_DIRECTORY` for paths. Keep operational sources private and Git-ignored. Missing or unreadable declared required files must fail before preview, apply, or ledger writes. Each patch must validate contents and dynamic file references; apply failures roll back the database transaction and ledger entry. Fileless `DataPatch` implementations need no directory.

## Checks

- `composer validate --strict`
- `composer test`
