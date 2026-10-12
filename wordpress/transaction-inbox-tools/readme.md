## Transaction Inbox Tools

Stable tag: 0.2.0
Requires PHP: 7.4
License: GPL-3.0-or-later

Adds a Tools tab to the Transaction Inbox. Administrators only.

### Features
1. **Inbox summary** – counts and date ranges per source, flagging records older than the cut-over date.
2. **Cut-over dates** – one start date per source (Operating, CG Funds, GC Visa, Interac, expense claims). Preview and delete records before each date. OFX uploads skip transactions before the date.
3. **OFX import** – upload one or more OFX/QFX files, preview new / already imported / skipped / rejected counts, then import. Writes the same records as `ingest/ofx/ofx-ingest.php`, so CLI and web imports are interchangeable. Account mapping is edited on the page (copy it from `config.php`).
4. **Wave balance check** – compares Wave's current balance for each bank and credit-card account with the latest OFX ledger balance, less imported transactions not yet exported to Wave. Read-only.
5. **Importer status** – recent import batches, last Interac IMAP UID, and a warning when the hourly Interac run has failed or stopped.
6. **Gravity Forms backfill** – imports or refreshes expense claims from a chosen date. Only inbox records change; an entry's Wave export status is left alone.
- **Reset inbox** – deletes all observations, allocations, matches and reviews. Import batch history is kept.
- **Wave export** – planning notes only.

### Requirements
- Transaction Inbox 0.7.1+
- Gravity Forms Wave Sync 0.7.1+ (Wave token, business and discovered accounts; backfill)
- `TO403_PAYMENTS_DB_*` constants in `wp-config.php`
- Database user needs SELECT, INSERT, UPDATE on `inbox_import_batches`. SELECT on `processor_state` is optional (shows the last IMAP UID).

### Safety
Back up MariaDB before using cut-over cleanup or reset. Always preview first.

### Changelog

#### 0.2.0
- Per-source cut-over dates with preview and cleanup.
- OFX upload with preview, cut-over filtering and batch history.
- Read-only Wave balance check.
- Importer status panel with Interac health warning.
- Backfill writes inbox records only and no longer resets Gravity Forms entry Wave metadata.
- Results shown on the page instead of in the URL.
- Uses the `TO403_PAYMENTS_DB_*` connection directly.

#### 0.1.1
- Reset and purge no longer read or delete `inbox_import_batches`.

#### 0.1.0
- First Tools tab: summary, Gravity Forms backfill, single-date purge, reset, planning panels.
