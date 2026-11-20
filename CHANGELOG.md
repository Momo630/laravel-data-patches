# Changelog

## Unreleased

- Add explicit `app:data-patch:upload` for reviewed `RequiresS3DataFiles` inputs, with private verified snapshots, streamed destination checks, dry-run plans, and conditional create-only PUTs. Uploads require an explicitly verified disk/bucket/endpoint and an IfNoneMatch-capable optional SDK; existing identical objects are skipped, conflicts never overwritten, and no patch or ledger operation runs.
- Add opt-in `RequiresS3DataFiles` with a named Laravel S3 disk, private temporary staging, mandatory reviewed SHA-256 checks, and transactional input provenance in a separate nullable `input_files` migration. Recorded S3 patches report changed declarations without storage reads; existing local and fileless patches retain their behavior.

- Add optional `RequiresDataFiles` and `HasDataPatchFiles` APIs, with required-file checks before preview or apply and no ledger entry on missing sources.
- Extract the generic data patch contract, runner, command, ledger, and migration into a standalone package.
