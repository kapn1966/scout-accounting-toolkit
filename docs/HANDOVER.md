# Scout Accounting Toolkit: handover notes

Context for anyone, human or AI, picking up development. Upload this file together with a fresh ZIP of the repo at the start of a new session.

Last updated for **SAT 1.1.1** (October 2026).

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
| `database/migrations/` | `001-install.sql` (fresh install), `002-upgrade-to-1.0.sql`, `003-wave-export-preview.sql` |
| `docs/` | `INSTALL.md`, `UPGRADING.md`, `WAVE_EXPORT.md`, `BANKS.md`, this file |
| `examples/gravity-forms/` | Reference expense claim form export |
| `tests/test_wave_export_static.py` | Static checks for the Wave export code (`python3 tests/test_wave_export_static.py`) |

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
| `SAT_Expense_Form` | Gravity Forms submission → inbox observation; name and email prefill |
| `SAT_Receipt_OCR` | Receipt OCR suggestions on the expense form (Tesseract, pdftoppm) |
| `SAT_Wave_Export` | Wave export tab: frozen previews, validation, one-at-a-time export, history, CSV |

### Database (separate from WordPress, named `scout_payments`)

- `inbox_observations`: everything imported. `source_system` is `ofx_operating`, `ofx_cg_funds`, `ofx_visa`, `interac_email` or `gravity_forms`. Unique on `(source_system, source_key)`, so re-imports refresh rather than duplicate.
- `inbox_matches`: evidence ↔ transaction links, and transfer pairs.
- `inbox_allocations`: category lines per bank transaction (`bank_observation_id`).
- `inbox_reviews`: review status (`ready`, `excluded`, `exported`), exclusion reason, Wave IDs.
- `inbox_import_batches`: import history. `processor_state`: the Interac importer's `last_processed_uid`.
- `sat_wave_export_*`: batches, items, sources, lines and attempts for Wave export.
- `payments`, `payment_allocations`: legacy and unused. Safe to drop when convenient.

## Production environment

- WordPress site with Gravity Forms. Inbox page uses `[sat_inbox]`.
- wp-config constants: `SAT_DB_HOST`, `SAT_DB_NAME`, `SAT_DB_USER`, `SAT_DB_PASSWORD`, `SAT_WAVE_TOKEN`.
- The WordPress database user `scout_payments_review` has `SELECT, INSERT, UPDATE, DELETE` on `scout_payments.*`.
- Server `control03`: CLI tools in `/opt/scout-accounting-toolkit/` (`config/`, `ingest/`). Cron runs as **root** and calls the versioned binary `/usr/bin/php8.4`. PHP 8.4 needs `php8.4-imap` for the importer.
- The Tools tab reads `ingest/interac-email/logs/cron.log` for importer health. The web server needs read access.
- Wave's bank feed is **off** for all accounts. The inbox is the only intended route into Wave.
- Cash on Hand and PayPal are archived in Wave.

## Accounting state at handover

- Operating and CG Funds were reconciled in Wave to Jul 31, 2026.
- The GC Visa reconciliation for the period ending Jul 27, 2026 has an unresolved issue, and Wave's Visa balance does not reflect the card statement. This needs fixing in Wave before Visa exports are trusted.
- September transactions were entered in Wave by hand while the inbox was being built. Choose cut-over dates per account on the Tools tab, and exclude anything already in Wave, before exporting.

## Key design decisions

- **One plugin.** The four 0.x plugins were merged in 1.0.0. Prefix is `sat` everywhere.
- **Transactions vs evidence.** OFX rows are the transactions. Interac emails and expense claims are evidence matched to them.
- **Matching rules:** Interac to Operating deposits, same amount within 7 days. Card claims to GC Visa within 10 days. Reimbursement and invoice claims to Operating, including the exact sum of 2–4 claims from the person named in the bank text, within 120 days. Transfers are opposite amounts in two of our accounts within 5 days.
- **Transfers** are matched, never excluded. Suggestions appear only under the outgoing (negative) side; the receiving row is hidden in Needs attention while represented. Accepting categorizes both sides.
- **Exclude** is only for things that must never reach Wave, and always has a reason.
- **Categories** are the Wave chart of accounts, discovered from Wave. Form labels can differ through aliases such as `Venturers | Sea Venturers`.
- **Wave API cannot read transactions.** `Transaction` exposes only `id`. Account balances are readable, as of today only. A balance check was removed and is planned to return as a pre-export step.
- **Wave export** goes through a frozen preview, then one item at a time. Deterministic external IDs are reused on retry. No bulk mutation, scheduling, taxes, contacts, receipt upload or OFX export.
- **Descriptions** come from the richest evidence: the Interac payment message, or the claim description with payee. **Notes** carry the audit trail and go to Wave's notes field.
- **Previews** freeze their text. Discard and recreate a preview to pick up new defaults.
- **OFX import** is web-only. The command-line OFX ingester was retired.

## Working conventions

- **Changes are delivered as git patches** made with `git format-patch` against the current `develop`, plus a full ZIP as fallback.
  - Before each change, upload a fresh ZIP of the repo directly into the chat. OneDrive copies lose PHP content.
  - Commit or stash local edits first, then apply with `git am file.patch`. If it fails, `git am --abort` and report the message.
  - Commits include `Co-authored-by: Microsoft Copilot <copilot@microsoft.com>`.
- **Commit messages:** conventional one-line subject, e.g. `fix(inbox): ...`, `feat(wave): ...`. Avoid `"` characters, which interfere with `git commit -m`.
- **Before deploying:** run `php -l` on changed PHP files. The AI environment has no PHP, so it can only check syntax statically.
- **Database changes** come as numbered migrations in `database/migrations/`, with `001-install.sql` kept in step. Grants are stated explicitly.
- **Testing new export features:** preview first, then export one small item, and check it in Wave.

## Open items

1. Test 1.1.1: discard preview batches #1 and #2, create a new preview, and check descriptions and notes.
2. First real Wave export of a single low-risk transaction. Confirm the description, notes, external ID and the Wave transaction ID stored in SAT.
3. Confirm how Wave handles a matched transfer created with `moneyTransactionCreate` between two anchor accounts.
4. Fix the GC Visa reconciliation in Wave and decide the Visa cut-over date.
5. Let the Treasurer role use selected Tools, such as OFX upload, while keeping cut-over cleanup and reset admin-only.
6. Interac importer: send an immediate email on failure, and replace the `imap_*` functions with a library so PHP upgrades don't break it.
7. Expense form: consider a Section + Expense type pair of fields instead of one long category list.
8. Bring back the Wave balance check as a pre-export step.
9. Rename `bank_observation_id` to `observation_id` in a future migration, and drop the legacy `payments` tables.
