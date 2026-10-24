# Database schema policy

`database/migrations/001-install.sql` is the authoritative complete schema for
fresh installations. SAT has no automatic schema runner. Apply only the
documented migration for a supported upgrade; do not treat development deltas
as a general historical migration chain. See [DEVELOPMENT.md](DEVELOPMENT.md)
for the schema workflow.

## 1.6.1 through 1.6.4 to 1.6.5

No database migration is required. Replace the plugin files using the site's
normal update process. Version 1.6.5 corrects purchase/refund anchors for configured
credit-card sources mapped to Wave `LIABILITY / CREDIT_CARD` accounts.
Existing frozen items are not silently changed: save/revalidate the same
preview to explicitly repair eligible pending/failed purchase anchors to
`WITHDRAWAL` and refund anchors to `DEPOSIT`.
External IDs, attempt history, ordering and exported items are preserved.
Card category directions are not rewritten: expense purchases retain `INCREASE`
and refunds retain `DECREASE`; incorrect category treatment remains blocked by
validation. Accepted payment transfers retain their OFX workflow. See
[Wave export recovery](WAVE_EXPORT.md#validation-failures-and-recovery).

The Send all request-destination fix from 1.6.4 and the blocking processing
dialog and warnings remain in place. Before resuming an affected preview, review
its recorded item and attempt state and verify any uncertain outcome in Wave;
do not infer from the modal alone that nothing was exported.
A lost response requires reviewing the existing preview's recorded item/attempt
state, not resubmitting the batch.

## 1.4.0 to 1.5.0

The 1.5.0 claim-correction feature requires the correction tables. Back up the
SAT database and retain the current plugin files before starting.

1. Back up the database, for example:

   ```bash
   mysqldump --single-transaction scout_payments > scout_payments-before-1.5.0.sql
   ```

   Store the backup securely and confirm it is usable under the site's backup
   procedure.
2. Apply the migration to the SAT database:

   ```bash
   mysql scout_payments < database/migrations/003-claim-corrections.sql
   ```

   It creates the empty `sat_observation_corrections` and
   `sat_observation_correction_events` tables. It does not modify claims,
   imported payloads or Gravity Forms entries.
3. Replace the plugin with the 1.5.0 plugin files and activate/update it using
   the site's normal WordPress process.
4. Confirm the plugin shows no missing-table notice. Open Expense Claims and
   confirm imported values display. On a suitable test claim, save a correction
   and verify the corrected indicator and audit behavior. Do not use live
   accounting records as destructive test data.

Migration `003-claim-corrections.sql` uses `CREATE TABLE IF NOT EXISTS`; it
creates empty tables and is rerunnable. There is no automatic schema runner.
The migration may be applied immediately before or after plugin replacement.

If the correction tables are missing, the plugin reports the missing tables;
Expense Claims continues to show imported values with editing disabled, and
claim imports and backfills continue without corrections to reconcile. Apply
migration 003, then reload the page.
