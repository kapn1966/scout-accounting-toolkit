# Database schema workflow

SAT is new development with no deployed installations requiring a sequential
history of database upgrades. `database/migrations/001-install.sql` is the
authoritative complete schema for a fresh installation, including the frozen
transfer-OFX batch tables. Fresh installations should run 001 only.

For a development database created from the original 001 schema, apply
`database/migrations/002-transfer-ofx-batches.sql` to add the transfer-batch
tables and review-route columns. This migration is a direct delta from 001; it
does not depend on removed historical migration files or change the plugin's
upgrade runner. Acceptance databases may also be recreated or updated manually
after the schema design is reviewed.

`database/migrations/003-claim-corrections.sql` adds the expense claim
correction tables to a database created before they were part of 001. It only
creates two empty tables (`CREATE TABLE IF NOT EXISTS`), so running it again is
harmless and it never reads or changes existing claims.

## 1.2.0 to 1.3.0

No database migration is required. Replace the plugin using the verified release
artifact described in [RELEASING.md](RELEASING.md); no live deployment is implied
by preparing the source version.

Cleanup and reset now refuse any selected exported record, Wave preview source
or transfer reservation, including matched evidence of protected transactions.
The entire operation is refused before deleting anything. Discard an untouched
preview or safely cancel a generated transfer batch only when appropriate; do
not remove attempted/exported audit records to make cleanup succeed.

Pending/failed ordinary previews can be saved to repair old accounting line
directions without changing amounts, external IDs or attempt history. Verify
the remote outcome before any retry. Actual batch 18, Visa reconciliation and
account cut-over dates still need operational verification, not another schema
migration.

## 1.3.0 to 1.4.0

No database migration is required. New expense claims store the Gravity Forms
submission time (`submitted_at_gmt`) in their existing payload. For claims
imported earlier, SAT reads it from the Gravity Forms entry, read-only, when
Expense Claims is displayed or a report is built. Running the Tools tab
**Expense claim backfill** stores it on the refreshed claims; that is optional,
and it also refreshes receipt references from Gravity Forms.

The CGaming ZIP refuses any included claim without exactly one readable local
receipt. Check claims from older forms before relying on a month's report.

## 1.4.0 to 1.5.0

Apply `database/migrations/003-claim-corrections.sql` once to the SAT database
(for example `mysql scout_payments < database/migrations/003-claim-corrections.sql`)
before or immediately after replacing the plugin. It creates
`sat_observation_corrections` and `sat_observation_correction_events`; no claim,
payload or Gravity Forms entry is changed and no corrections are created.

There is no automatic schema runner. Until the tables exist, the plugin shows
the usual missing-table admin notice, Expense Claims shows imported values with
Edit disabled, and claim imports and backfills continue normally (there are no
corrections to reconcile).

The WordPress database user needs only its existing `SELECT, INSERT, UPDATE,
DELETE` grant. Correction history rows are never updated or deleted by SAT.
