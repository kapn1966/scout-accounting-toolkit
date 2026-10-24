# Development

## Purpose

This document describes the durable SAT architecture and conventions needed to
change it safely. For current unfinished work, see [BACKLOG.md](BACKLOG.md).
Installation, upgrade, release and operator procedures live in their own
guides.

## Repository map

| Path | Responsibility |
|---|---|
| `wordpress/scout-accounting-toolkit/` | WordPress plugin: entry point, application classes, assets |
| `ingest/interac-email/` | Command-line Interac importer and weekly report |
| `ingest/bootstrap.php` | Shared command-line configuration and database startup |
| `config/config.example.php` | Example private importer configuration |
| `database/migrations/001-install.sql` | Complete schema for fresh installations |
| `database/migrations/002-transfer-ofx-batches.sql` | Development-schema delta for transfer OFX batches |
| `database/migrations/003-claim-corrections.sql` | Claim-correction tables for pre-existing schemas |
| `tests/` | PHP behavioral tests, static checks and browser harnesses |
| `scripts/build-release.py` | Committed-source plugin package builder and installed-file verifier |
| `docs/` | Human-facing installation, operations, development and release guidance |

The WordPress plugin and command-line importer share the separate SAT database.
The plugin uses the `SAT_DB_*` constants in `wp-config.php`; the importer uses
the private `config/config.php`.

## Application model

SAT's main responsibilities are divided across these classes:

| Component | Responsibility |
|---|---|
| `SAT_Plugin` | Bootstrap, activation and roles |
| `SAT_Settings` | Plugin settings and category alias resolution |
| `SAT_DB` | Shared PDO connection |
| `SAT_Upgrade` | One-time migration of settings and roles from the former 0.x plugins |
| `SAT_Admin` | Admin navigation, shared admin heading/version and Settings administration |
| `SAT_Inbox` | Inbox and Expense Claims UI, matching, allocations, transfers and exclusions |
| `SAT_Tools` | Dedicated Tools admin page: import status, OFX upload, claim backfill, cut-over and reset tools |
| `SAT_Expense_Form` | Gravity Forms claim ingestion and receipt-count validation |
| `SAT_Expense_Report` | Expense Claims filtering and CG Funds report |
| `SAT_Claim_Corrections` | Effective claim values, audited corrections and source reconciliation |
| `SAT_Wave` | Wave API client and business/account discovery |
| `SAT_Wave_Export` | Frozen ordinary Wave previews and per-item export lifecycle |
| `SAT_Transfer_OFX` | Transfer eligibility, frozen OFX batches, confirmation and cancellation |
| `SAT_OFX_Parser` | OFX/QFX parsing for Tools imports |
| `SAT_Receipt_OCR` | Optional receipt OCR suggestions |

The core model distinguishes **transactions** from **evidence**. OFX statement
rows are transactions. Interac messages and expense claims are evidence
matched to a transaction. Matches and category allocations inform review but
do not change imported evidence into a transaction.

## Database model

The SAT database is separate from WordPress. The primary records are:

- `inbox_observations`: imported transactions and evidence, uniquely identified
  by source system and source key.
- `inbox_matches`: evidence-to-transaction links and paired transfers.
- `inbox_allocations`: categorized lines for a bank transaction.
- `inbox_reviews`: review/export status and related audit state.
- `inbox_import_batches` and `processor_state`: import history and importer
  progress.
- `sat_wave_export_*`: ordinary export batches, items, sources, lines and
  attempts.
- `sat_transfer_ofx_*`: frozen transfer batches, leg/file snapshots and
  reservations.
- `sat_observation_corrections` and
  `sat_observation_correction_events`: active claim corrections and append-only
  correction history.

`payments` and `payment_allocations` are legacy tables, unused by current
application behavior. Do not remove them as incidental cleanup: first establish
their contents, backup and retention requirements.

## Durable design invariants

- Imported source data is not overwritten to express a review decision.
- A transaction is excluded only with a reason; accepted transfers are handled
  by the dedicated transfer route, not ordinary exclusion or API export.
- Cleanup/reset must protect exported observations, ordinary preview sources,
  transfer reservations and their matched evidence. A mixed protected/unprotected
  operation must not partially delete records.
- Export attempts and outcomes are audit records. Do not erase or rewrite
  attempted history to make an operation retryable.
- Group-specific accounts, categories and matching details belong in settings,
  not hard-coded application behavior.
- Payment-method classification is shared through
  `SAT_Expense_Form::payment_kind()`. Do not make feature-specific exact-label
  comparisons against stored form choices.
- Vendor-specific receipt extraction may prioritize an explicit transaction or
  order-date label, but must not globally reinterpret event or booking dates.

## Claim corrections

An effective claim value is the active SAT correction when one exists;
otherwise it is the imported value. Resolve this consistently through
`SAT_Claim_Corrections`; consumers must not invent separate correction
precedence rules. Corrections apply to Expense Date, Payee, Description and
Amount only.

Corrections do not alter imported payloads or Gravity Forms entries. Backfill
reconciles corrections with refreshed source values:

- If the imported value is unchanged, the correction remains active.
- If the source becomes equal to the correction, the correction is in agreement.
- If the source changes to a third value, the correction remains effective and
  is marked for review.

The reviewer can retain the correction, accept the imported value or save a new
correction. A changed claim amount never changes its transaction, match,
allocations or Wave amount automatically. If a correction causes the matched
amount to disagree, show the discrepancy rather than silently rebalancing it.

Correction events are append-only, including removal events when claim records
are deleted. Every correction writer locks the claim observation row before
reading or changing correction state. Preserve that lock order; correction
saves also reject stale dialogs using imported-value and correction-version
state.

## Wave and transfer boundaries

Ordinary Wave exports use persistent frozen preview batches. A preview captures
the selected source values, accounts, allocations, descriptions, notes and
deterministic external IDs. Existing previews do not silently absorb later
source changes. Pending or failed items may be explicitly repaired and
revalidated; attempted outcomes remain auditable.

Each ordinary Wave item is sent separately. Retries reuse the deterministic
external ID. A timeout or interrupted request can have an uncertain remote
outcome: verify Wave before retrying. Never treat a local failure as proof that
Wave did not create the transaction.

Accepted transfers between configured payment accounts are hard-blocked from
ordinary Wave API export, including when they are ineligible for transfer OFX.
Eligible transfers use frozen, account-specific OFX files and require explicit
confirmation after import and matching. A generated transfer batch reserves
its source observations. Cancellation is safe only when none of its transfers
remain imported in Wave.

Discards, saves and export initiation must serialize on the same batch lock.
The export attempt is persisted before the remote Wave request; the lock is not
held across that network request. Cleanup/reset must not remove records owned
by a preview or transfer batch.

Accounting directions are determined from the anchor direction and Wave
account type, not just the sign of the amount. Every Wave request must contain
both debit and credit sides and balance. The verified `LIABILITY / CREDIT_CARD`
behavior requires `WITHDRAWAL` to credit the liability for a purchase and
`DEPOSIT` to debit it for a refund, contrary to the published generic liability
mapping; never extend this exception to other liability subtypes.

Explicit Save/revalidation can repair a pending/failed card anchor
using the frozen source sign under the existing batch lock. It does not change
card category directions, external IDs or attempts, and skips exported items.
The send path validates but never repairs a frozen direction.
Validation checks the frozen card source sign as well as debit/credit balance:
two reversed accounting sides can balance while giving the wrong economics.
See [WAVE_EXPORT.md](WAVE_EXPORT.md)
for the operator-facing direction examples and recovery guidance.

## Schema workflow

`001-install.sql` is the authoritative complete schema for a fresh installation.
Update it directly when changing the intended schema. Do not rely on a
sequential historical migration chain or an automatic schema runner.

Keep a numbered delta only when a supported existing development schema needs
an explicit transition. Migrations `002` and `003` serve that development or
upgrade purpose; they do not replace the fresh-install schema. Migration `003`
creates empty correction tables with `CREATE TABLE IF NOT EXISTS` and is
rerunnable. Document supported upgrade paths in [UPGRADING.md](UPGRADING.md).

Schema changes must preserve existing data, keys, constraints and least-
privilege database grants. Review fresh-install and supported-upgrade behavior
separately.

## Testing strategy

The PHP behavioral tests exercise WordPress handlers with stubs, fake Wave
clients and an in-memory fake PDO. They cover important lifecycle behavior,
accounting directions, transfer writes/rollback, cleanup protection, reports
and claim corrections. They do not establish real database-engine constraints
or concurrent InnoDB locking behavior, nor do they call Wave.

Static checks verify source-level requirements. Browser harnesses cover selected
client interactions but do not prove the appearance or behavior of the actual
WordPress theme, iframe, Gravity Forms AJAX validation or production account.
Release tests validate package construction and fixtures, not a real deployment.
Use controlled real-database and authorized environment acceptance where those
boundaries matter.

## Validation commands

Run relevant checks for the changed area. The full release validation set is
maintained in [RELEASING.md](RELEASING.md). Common PHP and test commands include:

```bash
find wordpress/scout-accounting-toolkit -name '*.php' -exec php -l {} \;
php -l tests/test_wave_export.php
php -l tests/test_transfer_ofx.php
php -l tests/test_ofx_writer.php
php -l tests/test_expense_report.php
php -l tests/test_claim_corrections.php
php -l tests/test_receipt_ocr.php
php tests/test_wave_export.php
php tests/test_transfer_ofx.php
php tests/test_ofx_writer.php
php tests/test_expense_report.php
php tests/test_claim_corrections.php
php tests/test_receipt_ocr.php
python3 -B tests/test_wave_export_static.py
python3 -B tests/test_release.py
git diff --check
```

## Development and documentation conventions

- Trace relevant call sites and preserve established transaction, retry and
  audit behavior.
- Do not put credentials, financial records or real claimant information in
  fixtures. Use synthetic or deliberately sanitized data.
- Do not perform live Wave mutations, database writes, deployments or
  destructive operations without explicit authorization.
- Use the project documentation according to its ownership in
  [`.github/copilot-instructions.md`](../.github/copilot-instructions.md).
