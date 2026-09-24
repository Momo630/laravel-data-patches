# Repository guidance

- Keep the package compatible with the PHP and Illuminate constraints in `composer.json`; do not include application-specific patches.
- Preserve the patch contract, command, config keys, ledger schema, and migration filename. Record breaking changes in `CHANGELOG.md` and release them under a new major version.
- Never run data patches automatically during installation or deployment.

## Checks

- `composer validate --strict`
- `composer test`
