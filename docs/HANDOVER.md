# Scout Accounting Toolkit: handover notes

Context for anyone, human or AI, picking up development. Upload this file together with a fresh ZIP of the repo at the start of a new session.

Last updated for **SAT 1.4.0** (October 2026). This describes the source release candidate, not evidence of a tagged package or deployment.

## What SAT is

SAT gets a Scout group's money records into Wave Accounting with less manual work. Bank and credit-card statements, Interac e-Transfer notifications and volunteer expense claims all land in one **transaction inbox**. There they are matched, categorized and reviewed, then exported to Wave from a reviewed preview.

It was built for the 403rd Toronto Sea Scout Group but is intended to be usable by other groups. Group-specific values belong in settings, never in code.

## Repository layout

| Path | Contents |
|---|---|
| `wordpress/scout-accounting-toolkit/` | The single WordPress plugin. Entry file `scout-accounting-toolkit.php`; classes in `includes/class-sat-*.php`; CSS and JS in `assets/` |
| `ingest/interac-email/` | `process.php` (hourly Interac importer) and `weekly-report.php`, both command line |
| `ingest/bootstrap.php` | Shared CLI start-up: loads `config/config.php` from the folder above `ingest/` and opens the database |
| `config/config.example.php` | Template for the CLI config. The real `config.php` is git-ignored |
| `database/migrations/` | `001-install.sql` (complete fresh-install schema), `002-transfer-ofx-batches.sql` (delta from the original 001 schema) and `003-claim-corrections.sql` (claim correction tables for databases created before them) |
| `docs/` | Installation, schema, Wave export, release and banking guidance, plus this file |
| `examples/gravity-forms/` | Reference expense claim form export |
| `tests/` | Behavioural Wave/transfer lifecycle and cleanup tests; OFX writer/parser/ZIP tests; Expense Claims/CGaming report tests; claim correction tests; browser harnesses for the expense-form receipt rule and the correction dialog; static checks; release packaging tests |
| `scripts/build-release.py` | Deterministic package builder from clean committed Git objects, with installed-file verification |
| `scripts/generate-transfer-ofx.php` | Retained developer WP-CLI utility to regenerate an existing frozen transfer batch; not included in plugin packages |

### Plugin classes

| Class | Role |
|---|---|
| `SAT_Plugin` (entry file) | Boot, activation, roles |
| `SAT_Settings` | All settings in one option, `sat_settings`, plus category alias resolution |
| `SAT_DB` | Shared PDO connection from the `SAT_DB_*` wp-config constants |
| `SAT_Upgrade` | One-time migration of settings and roles from the old 0.x plugins. The only place old names appear |
| `SAT_Admin` | Settings → Scout Accounting page |
| `SAT_Wave` | Wave GraphQL client, business and account discovery, `create_money_transaction` |
| `SAT_Inbox` | `[sat_inbox]` shortcode: Transactions, Expense Claims, matching, splits, transfers, exclude |
| `SAT_Tools` | Tools tab: summary, importer status, cut-over, OFX upload, Gravity Forms backfill, reset |
| `SAT_OFX_Parser` | OFX/QFX parsing for the Tools upload |
| `SAT_Expense_Form` | Gravity Forms submission → inbox observation; name and email prefill; server-side receipt-count rule and its client script (`assets/expense-form.js`) |
| `SAT_Expense_Report` | Expense Claims sorting/filtering and the CG Funds monthly report: one selection for table, summary, CSV and private receipt ZIP |
| `SAT_Claim_Corrections` | Audited corrections of a claim's Expense Date, Payee, Description and Amount: the shared effective-value resolver, validation, backfill reconciliation, cleanup removal, the Expense Claims dialog's Ajax endpoints and markup (`assets/claim-corrections.js`) |
| `SAT_Receipt_OCR` | Receipt OCR suggestions on the expense form (Tesseract, pdftoppm) |
| `SAT_Wave_Export` | Wave export tab: frozen previews, validation, one-at-a-time export, history, CSV |
| `SAT_Transfer_OFX` | Transfers to Wave tab: eligibility, frozen account-specific OFX batches, ZIP download, confirmation and cancellation |

### Database (separate from WordPress, named `scout_payments`)

- `inbox_observations`: everything imported. `source_system` is `ofx_operating`, `ofx_cg_funds`, `ofx_visa`, `interac_email` or `gravity_forms`. Unique on `(source_system, source_key)`, so re-imports refresh rather than duplicate.
- `inbox_matches`: evidence ↔ transaction links, and transfer pairs.
- `inbox_allocations`: category lines per bank transaction (`bank_observation_id`).
- `inbox_reviews`: review status (`ready`, `excluded`, `exported`), exclusion reason, Wave IDs.
- `inbox_import_batches`: import history. `processor_state`: the Interac importer's `last_processed_uid`.
- `sat_wave_export_*`: batches, items, sources, lines and attempts for Wave export.
- `sat_observation_corrections`: the active SAT correction per claim and field (`transaction_date`, `payee`, `description`, `amount`), with the imported value captured when it was made and its source-conflict state. `sat_observation_correction_events`: append-only correction history.
- `sat_transfer_ofx_*`: frozen transfer batches, accepted match snapshots, OFX leg/file snapshots and reservations. Generated batches reserve observations until confirmed or cancelled.
- `payments`, `payment_allocations`: legacy and unused by current code. Retain until their actual contents and backup/retention needs have been checked; dropping tables is not part of routine upgrades.

## Production environment

- WordPress site with Gravity Forms. Inbox page uses `[sat_inbox]`.
- wp-config constants: `SAT_DB_HOST`, `SAT_DB_NAME`, `SAT_DB_USER`, `SAT_DB_PASSWORD`, `SAT_WAVE_TOKEN`.
- The WordPress database user `scout_payments_review` has `SELECT, INSERT, UPDATE, DELETE` on `scout_payments.*`.
- Server `control03`: CLI tools in `/opt/scout-accounting-toolkit/` (`config/`, `ingest/`). Cron runs as **root** and calls the versioned binary `/usr/bin/php8.4`. PHP 8.4 needs `php8.4-imap` for the importer.
- The Tools tab reads `ingest/interac-email/logs/cron.log` for importer health. The web server needs read access.
- Wave's bank feed is **off** for all accounts. The inbox is the only intended route into Wave.
- Cash on Hand and PayPal are archived in Wave.

## Accounting state at handover

- Last recorded state (not reverified by source tests): Operating and CG Funds were reconciled in Wave to Aug 31, 2026.
- The GC Visa reconciliation for the period ending Jul 27, 2026 has an unresolved issue, and Wave's Visa balance does not reflect the card statement. This needs fixing in Wave before Visa exports are trusted.
- September transactions were entered in Wave by hand while the inbox was being built. Choose cut-over dates per account on the Tools tab, and exclude anything already in Wave, before exporting.
- The synthetic batch-18 test proves repair of frozen one-sided lines while retaining external IDs and failed attempts. It does not establish the disposition of actual preview batch 18. Check its real status and Wave outcome before any authorized retry.

## Key design decisions

- **One plugin.** The four 0.x plugins were merged in 1.0.0. Prefix is `sat` everywhere.
- **Transactions vs evidence.** OFX rows are the transactions. Interac emails and expense claims are evidence matched to them.
- **Matching rules:** Interac to Operating deposits, same amount within 7 days. Card claims to GC Visa within 10 days. Reimbursement and invoice claims to Operating, including the exact sum of 2–4 claims from the person named in the bank text, within 120 days. Transfers are opposite amounts in two of our accounts within 5 days.
- **Transfers** are matched, never excluded. Suggestions appear only under the outgoing (negative) side; the receiving row is hidden in Needs attention while represented. Accepting categorizes both sides.
- **Exclude** is only for things that must never reach Wave, and always has a reason.
- **Categories** are the Wave chart of accounts, discovered from Wave. Form labels can differ through aliases such as `Venturers | Sea Venturers`.
- **Wave API cannot read transactions.** `Transaction` exposes only `id`. Account balances are readable, as of today only. A balance check was removed and is planned to return as a pre-export step.
- **Wave export** goes through a frozen preview, then one item at a time, either with **Export one** or with **Send all ready items to Wave**, which sends unexported items sequentially in preview order and stops at the first failure. Observations in any existing preview item are hidden from Ready for Wave until the preview is discarded. Deterministic external IDs are reused on retry. No bulk mutation, scheduling, taxes, contacts, or receipt upload. Accepted transfers are isolated in **Transfers to Wave** and exported only as frozen account-specific OFX bundles; explicit confirmation is required after both sides are imported and matched in Wave. The workflow does not call the Wave API.
- **Descriptions** come from the richest evidence: `from <payer> - <payment message>` for Interac income, or the claim description with payee. **Notes** carry the audit trail and go to Wave's notes field.
- **Accounting direction** follows Wave's documented debit/credit mapping: each categorization line is placed opposite the anchor (asset `DEPOSIT` = debit, liability `DEPOSIT` = credit), and `INCREASE`/`DECREASE` is chosen from the line account's Wave type. Validation rejects one-sided payloads before export. Saving a preview recalculates line directions for pending and failed items. See `WAVE_EXPORT.md`.
- **Previews** freeze their text. Discard and recreate a preview to pick up new defaults.
- **Audit protection:** cleanup/reset lock selected observations and matched partners, check all selected chunks before deleting anything, and refuse Wave preview sources, transfer reservations and exported records. Discard, save and export initiation lock the same preview batch before checking or changing it. Attempted previews remain audit records.
- **SAT's OFX statement import** is web-only. The command-line OFX ingester was retired.
- **Payment method classification** is one shared substring rule (`SAT_Expense_Form::payment_kind`): `card`, `reimburs`, `invoice`, otherwise other. Stored values include older wording such as "Charge on 403TO credit card", "reimbursement of Scouter (please avoid this)" and "Invoice already paid", so never match exact option text. Matching, Expense Claims filters, the CGaming report and the receipt-count rule all use it.
- **CG Funds monthly report** (Expense Claims, unreleased): every claim whose Expense Date falls in the chosen calendar month, except Reimburse a Scouter, whether matched or not. It deliberately does not infer funding from matched accounts or transfers; the treasurer removes non-CGaming exceptions from the CSV. CSV columns are fixed (see `SAT_Expense_Report::CSV_COLUMNS`):
  - Submission Date comes from the Gravity Forms `date_created`, saved in the payload on import, or read from the GF entry for older claims.
  - Section or Program is filled only when the submitted category starts with a section name configured in Inbox settings.
  - Expense Category is the submitted category.
  - There is no Tax column, because no tax field is stored.
  The ZIP is built in a private temporary file and refuses the whole report unless every included claim has exactly one readable local receipt inside the Gravity Forms upload folder.
- **Claim corrections** (unreleased): Expense Claims **Edit** corrects Expense Date, Payee, Description and Amount only. The effective value is the active correction, otherwise the imported value, and `SAT_Claim_Corrections::resolve()`/`effective_values()` is the only place that decides it. Consumers: Expense Claims table, sorting and month filter; CG Funds month, CSV, totals and receipt filenames; new Wave preview descriptions (payee/description only: Wave date and amount stay the bank's); inbox evidence text and new match suggestions (date window and amount). Existing matches, allocations, bank transactions, frozen previews and export history are never changed by a correction.
  - Imported payloads and Gravity Forms entries are never edited. The reason is stored per corrected field; the dialog's one selection (default `other`) applies to the fields changed in that save. Saving a value equal to the imported one removes the correction. New Description corrections cannot be blank; a blank imported description stays until corrected. Amount is limited only by its `decimal(10,2)` storage (99,999,999.99).
  - Backfill (`import_entry`) refreshes the source and reconciles corrections in the same transaction: unchanged source, agreement (source now equals the correction) or conflict (a third value). A conflict keeps the correction effective and shows **Correction review needed** until the reviewer keeps it, uses the imported value or saves a new one.
  - Every writer of a claim's corrections (save, reset, keep, import, cleanup) first locks the claim's `inbox_observations` row and then reads the corrections without `FOR UPDATE`, avoiding InnoDB gap-lock deadlocks between first corrections of different claims. Saves reject a stale dialog by a token over the imported values and correction IDs/versions.
  - A matched claim whose effective amount (or group sum) no longer equals its bank transaction shows **Amount differs from match**; nothing is rebalanced.
  - Cleanup/reset delete a claim's active corrections with an `observation_deleted` event; history is kept. Cut-over cleanup selects claims by effective Expense Date.
- **Receipt count:** Invoice and Credit Card claims allow exactly one receipt, Reimburse a Scouter one or more. This is enforced by `gform_field_validation_<form>_<receipt field>` and mirrored in the browser. Zero receipts remains Gravity Forms' required-field validation.

## Working conventions

- **Work from the current checkout.** Do not commit, tag, package or deploy without authorization. If patches are requested, generate them against the agreed base commit.
- **Commit messages:** conventional one-line subject, e.g. `fix(inbox): ...`, `feat(wave): ...`.
- **Before release:** run native PHP lint and every command in `RELEASING.md`. PHP is available in the current development environment.
- **Packages:** use the committed-source builder and record commit plus checksum. A version string alone cannot distinguish earlier untagged 1.2.0 builds. See `RELEASING.md`.
- **Database changes** update `database/migrations/001-install.sql` directly. A specific delta from the original 001 schema is kept when needed for development acceptance; no older migration chain or upgrade runner is required.
- **Testing new export features:** preview first, then export one small item, and check it in Wave.

## Open items

1. Record current operational acceptance evidence: inspect existing previews before discarding anything; check descriptions/notes and the actual disposition of batch 18. Attempted previews must be repaired in place, not deleted.
2. Confirm a controlled ordinary export on the intended release, including description, notes, external ID and the Wave transaction ID stored in SAT. Check Send all stop/resume and action visibility in the actual iframe; generated-HTML tests do not prove browser layout.
3. Run the transfer OFX manual acceptance test with representative bank-to-bank and bank-to-card pairs before production rollout. Include partial import left Generated, re-download, all-pairs confirmation, and cancellation only when nothing remains in Wave.
4. Fix the GC Visa reconciliation in Wave and decide the Visa cut-over date.
5. Let the Treasurer role use selected Tools, such as OFX upload, while keeping cut-over cleanup and reset admin-only.
6. Interac importer: send an immediate email on failure, and replace the `imap_*` functions with a library so PHP upgrades don't break it.
7. Expense form: consider a Section + Expense type pair of fields instead of one long category list.
8. Specify a date-aware pre-export reconciliation checkpoint; do not compare today's Wave balance with an older statement as if they shared a date.
9. Review the legacy `bank_observation_id` name and `payments` tables before any future fresh-schema cleanup.
10. OFX bundle filenames: Replace generic account-01 / account-02 names with deterministic, safe slugs based on configured account labels, for example gc-visa and cg-funds. Handle slug collisions without exposing account IDs or source keys, and keep the manifest aligned.
11. Show Ready for Wave filter counts as matching versus total (for example "62 of 91"), retaining the same staged-preview and transfer exclusions in both counts.
12. Add isolated real-database concurrency/constraint tests, mocked Wave HTTP/GraphQL boundary tests, and browser tests. Current PHP persistence tests use an in-memory fake PDO, not a database engine.
13. Commit, validate, package, tag and deploy only when separately authorized; retain the tested package checksum and deployment receipt.
14. CGaming report acceptance (Jump 2): test on a disposable copy with real receipts.
    - In the October 2026 local SQL copy, non-reimbursement claims from legacy form 2 dated May–September 2026 have no stored receipt URL, so those months' ZIPs will be refused.
    - Run the expense claim backfill on a copy first to see whether Gravity Forms still holds those receipts, then decide how to correct the claims that remain.
    - Also check the expense-form receipt rule in the real Gravity Forms page, including AJAX validation rerender. The browser harness uses a mock of Gravity Forms.
15. Claim corrections acceptance (Jump 2), on a disposable database first:
    - Apply `003-claim-corrections.sql` twice to a copy of the 1.4.0 schema and confirm both tables, the unique key and unchanged claim data. The PHP tests use a fake PDO; real-engine locking and constraints are unproven.
    - Correct entry 620 (Expense Date), 622 (Payee), 606 (`Easily` to `ESSO`) and the legacy combined Payee/Description of entry 579 and earlier where known; values are Keith's decision. Verify an Amount correction only on a synthetic or authorized record.
    - Run the expense claim backfill afterwards; then change a corrected field in a Gravity Forms test entry and backfill again to see **Correction review needed** and each outcome.
    - Check Expense Claims sorting/filters, the CG Funds CSV and receipt filenames, a newly created Wave preview and the dialog's keyboard behaviour in the real page and theme.
16. `scout_payments-test-copy.sql` is tracked in Git and contains real claim data. Decide whether to remove it from the repository and its history.

### Export lifecycle safety acceptance

Validated on October 17, 2026 against a disposable local WordPress site and a
MariaDB fixture database on shoe25.

Confirmed:

- ordinary unowned reset succeeds;
- Wave-preview-owned cleanup is refused;
- mixed protected/unprotected cleanup rolls back completely;
- mixed reset is refused without deleting any selected records;
- generated and confirmed transfer-batch observations are protected;
- discard and export initiation serialize on the same batch lock;
- discard-first prevents later export initiation;
- export-initiation-first causes discard to recheck and refuse;
- all synthetic fixtures were removed after testing.

The test used the real WordPress cleanup/reset/discard handlers with
administrator capability and valid nonces. No Wave calls or remote HTTP
requests were made.