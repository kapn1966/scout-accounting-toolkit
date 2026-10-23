# Release procedure

This is the reusable maintainer procedure for preparing, validating and
verifying a SAT release. Packaging, tagging, pushing and deployment are
separate actions and require authorization.

## Prepare and validate

1. Select the intended semantic version. Update both the plugin header
   `Version:` value and `SAT_VERSION` to the same value.
2. Update `CHANGELOG.md` with the release changes and `docs/UPGRADING.md` with
   the current supported upgrade instructions.
3. Run the validation commands below, review the complete diff and ensure the
   working tree contains only intended release changes.
4. Commit the validated source before packaging. Do not build from uncommitted
   working files.

## Build and verify

1. From the exact clean commit, record the full commit ID and rerun validation.
   Check the committed-source builder:

   ```bash
   python3 scripts/build-release.py --check
   ```
2. Build into a directory outside the checkout:

   ```bash
   python3 scripts/build-release.py --output-dir /path/to/releases
   ```

   The builder reads committed Git objects and checks the plugin version
   values, changelog, source cleanliness and package contents.
3. Retain the generated ZIP and `.sha256` sidecar. Verify the checksum from the
   output directory:

   ```bash
   sha256sum -c scout-accounting-toolkit-VERSION-COMMIT.zip.sha256
   ```

4. Install the exact ZIP in an authorized acceptance environment. Verify the
   installed files against committed source:

   ```bash
   python3 scripts/build-release.py --verify-installed /path/to/wp-content/plugins/scout-accounting-toolkit
   ```

   The source commit must be available locally. Verification checks package
   metadata, the complete file list and file bytes.
5. Perform only authorized smoke tests. Check preview creation, preview
   actions, save/revalidation, staged Ready exclusion and untouched-preview
   discard. Wave export and transfer import acceptance require their own
   explicit authorization; follow [WAVE_EXPORT.md](WAVE_EXPORT.md) and verify
   uncertain remote outcomes before retrying.
6. If a fix is needed, make a new commit and repeat validation, packaging and
   acceptance. Never modify a built package in place.

## Tag, deploy and record provenance

After the tested commit and package are verified, tag that exact commit only
when authorized. Push the commit and tag only when authorized, and verify the
tag resolves to the package metadata's full commit. Deploy the tested artifact
only when authorized.

Record the environment, timestamp, operator, tag, full source commit, ZIP
checksum and verification/smoke-test results in the deployment record. A
version string alone is not release provenance.

## Validation

Run from the repository root:

```bash
find wordpress/scout-accounting-toolkit -name '*.php' -exec php -l {} \;
php -l tests/test_wave_export.php
php -l tests/test_transfer_ofx.php
php -l tests/test_ofx_writer.php
php -l tests/test_expense_report.php
php -l tests/test_income_view.php
php -l tests/test_claim_corrections.php
php tests/test_wave_export.php
php tests/test_transfer_ofx.php
php tests/test_ofx_writer.php
php tests/test_expense_report.php
php tests/test_income_view.php
php tests/test_claim_corrections.php
python3 -B tests/test_wave_export_static.py
python3 -B tests/test_release.py
git diff --check
```

The expense-form receipt-rule browser harness is
`tests/test_expense_form_receipts.html`; the claim-correction dialog harness is
`tests/test_claim_corrections.html`. Serve the repository root and confirm each
page reports `PASS`. These harnesses do not replace authorized acceptance in
the actual WordPress environment.

The PHP behavioral tests use WordPress stubs, a fake Wave client and an
in-memory fake PDO. They do not call Wave or a database engine and do not prove
concurrent database locking or deployed iframe appearance.
