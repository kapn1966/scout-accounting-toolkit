## Transaction Inbox Tools

Stable tag: 0.2.2
Requires PHP: 7.4
License: GPL-3.0-or-later

Adds a Tools tab to the Transaction Inbox. Administrators only.

### Features
1. **Inbox summary** – counts and date ranges per source, flagging records older than the cut-over date.
2. **Importer status** – Interac importer health (last IMAP UID, last database run, and the end of the cron log, so failures before the database is reached are caught), plus the six most recent import batches with a one-line summary. More batches are under "Show more"; full notes appear on hover.
3. **Cut-over dates** – one start date per source. Preview and delete records before each date. OFX uploads skip transactions before the date.
4. **OFX import** – the way bank and credit-card statements are imported. Upload one or more OFX/QFX files, preview new / already imported / skipped / rejected counts, then import. Account mapping is edited on the page.
6. **Wave balance check** – compares Wave's current balance for each bank and credit-card account with the latest OFX ledger balance, less imported transactions not yet exported to Wave. Read-only.
5. **Gravity Forms backfill** – imports or refreshes expense claims from a chosen date. Only inbox records change; an entry's Wave export status is left alone.
- **Reset inbox** – deletes all observations, allocations, matches and reviews. Import batch history is kept.
- **Wave export** – planning notes only.

### Requirements
- Transaction Inbox 0.8.0+
- Gravity Forms Wave Sync 0.7.1+ (Wave token, business and discovered accounts; backfill)
- `TO403_PAYMENTS_DB_*` constants in `wp-config.php`
- Database user: SELECT, INSERT, UPDATE on `inbox_import_batches`; SELECT on `processor_state`.
- Web server read access to the Interac cron log (default `/opt/scout-accounting-toolkit/ingest/interac-email/logs/cron.log`; changeable on the page).

### Safety
Back up MariaDB before using cut-over cleanup or reset. Always preview first.

### Changelog

#### 0.2.2
- Inbox summary, Importer status, Cut-over dates and Reset inbox can be rolled up. Importer status, Cut-over dates and Reset start closed.
- A badge on the closed Importer status card shows when the Interac importer is failing or quiet, or a recent import failed.
- Gravity Forms backfill is now card 5 and Wave balance check card 6.
- The cron log's earlier warning is labelled as resolved when the importer is healthy.

#### 0.2.1
- Importer status moved to position 2 and made full width.
- Interac health now reads the cron log as well as the database, so failures such as a missing config file are reported.
- Status table shows 6 batches by default with "Show more", one-line summaries, and full notes on hover.
- OFX card and readme describe web upload as the only OFX route; the command-line ingester is retired.

#### 0.2.0
- Per-source cut-over dates, OFX upload, Wave balance check, importer status, safer Gravity Forms backfill.

#### 0.1.1
- Reset and purge no longer read or delete `inbox_import_batches`.

#### 0.1.0
- First Tools tab.
