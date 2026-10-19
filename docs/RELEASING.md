# Release checklist

Version 1.4.0 adds Expense Claims sorting and filtering, a monthly CGaming
expense report with CSV and receipt ZIP generation, and conditional
single-receipt validation for Invoice and Credit Card claims. It also includes
the backward-compatible Wave Send all workflow and export safety improvements
introduced in 1.3.0.


Version 1.3.0 includes the backward-compatible Send all feature and export safety
fixes. Source preparation alone does not mean it has been packaged or deployed.
Commit, tag, push, installation and Wave/database operations require explicit
authorization.

1. Finalize scope, both identical plugin version values, changelog, handover and
   schema/upgrade notes. No new schema migration is needed from 1.2.0.
2. Run validation below, review the diff, then commit the release changes when
   authorized. Do not build a release from uncommitted working files.
3. From that exact clean commit, rerun validation and
   `python3 scripts/build-release.py --check`. Record the full commit ID.
4. Build into an existing directory outside the checkout:
   `python3 scripts/build-release.py --output-dir /path/to/releases`.
   The builder reads committed Git objects only, refuses dirty/untracked input
   and existing artifacts, checks header/constant equality and the changelog,
   and rejects unexpected plugin members. Only the entry PHP file, production
   classes and CSS/JS assets are allowed. Scripts, tests, credentials, examples
   and generated OFX files are not part of the plugin ZIP.
5. Retain the ZIP and its `.sha256` sidecar. Verify using
   `sha256sum -c scout-accounting-toolkit-VERSION-COMMIT.zip.sha256`
   from the output directory. Each ZIP includes `sat-build.json` with version,
   full source commit and per-file SHA-256 hashes. Rebuilding the same commit
   with the same Python/zlib toolchain produces the same ZIP bytes.
6. Install this exact ZIP into an authorized acceptance environment. Verify the
   copied/installed plugin without loading WordPress:
   `python3 scripts/build-release.py --verify-installed /path/to/wp-content/plugins/scout-accounting-toolkit`.
   The source commit must be available in the local repository. Verification
   compares metadata, the complete file list and bytes against committed
   source, rejecting missing/extra/modified files and symlinks.
7. Smoke-test preview creation, actions in the actual iframe, Save and
   revalidation, staged Ready exclusion, and untouched-preview discard.
   Separately authorize controlled Wave and transfer-import acceptance; use
   [WAVE_EXPORT.md](WAVE_EXPORT.md), preserve attempts, and check remote outcomes
   before retrying uncertain calls. Do not claim live acceptance from unit tests.
8. If any fix is needed, make a new commit and repeat validation, build and
   acceptance. Never modify a built package in place.
9. When authorized, tag the tested commit as `vMAJOR.MINOR.PATCH` and push that
   commit and tag. Confirm the tag resolves to the build metadata's full commit.
10. Deploy the tested artifact only when authorized. Record environment,
    timestamp, operator, tag, full commit, ZIP checksum and verification/smoke
    results in the deployment record. A version string alone is not provenance.

## Validation

Run from the repository root:

```bash
find wordpress/scout-accounting-toolkit -name '*.php' -exec php -l {} \;
php -l tests/test_wave_export.php
php -l tests/test_transfer_ofx.php
php -l tests/test_ofx_writer.php
php -l tests/test_expense_report.php
php tests/test_wave_export.php
php tests/test_transfer_ofx.php
php tests/test_ofx_writer.php
php tests/test_expense_report.php
python3 -B tests/test_wave_export_static.py
python3 -B tests/test_release.py
git diff --check
```

The expense-form receipt rule (`assets/expense-form.js`) has a browser test:
serve the repository root (`python3 -m http.server 8765 --bind 127.0.0.1`), open
`http://127.0.0.1:8765/tests/test_expense_form_receipts.html`, and expect the
page title `PASS <n>`.

The PHP tests use WordPress stubs, a fake Wave client and an in-memory fake PDO;
they never call Wave or a database. They cover sequential exports, accounting
directions, transfer lifecycle writes and rollback, and cleanup protection.
They do not prove database-engine locking under concurrent sessions or deployed
iframe appearance. Release tests check the actual Git archive layout, synthetic
committed-object fixtures and temporary copied plugin files/package fixtures;
they do not create a distributable release.

For old manually copied plugins without `sat-build.json`, provenance cannot be
inferred from `SAT_VERSION`. Establish the source revision by comparison or
replace with a verified package during an authorized deployment.