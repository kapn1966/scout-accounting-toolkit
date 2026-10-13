# Changelog

## 1.1.1
- Wave descriptions now use matched evidence: the Interac payment message for income, and the claim description (with payee) for expense claims. Bank text is used only when nothing richer is matched.
- Notes carry the audit trail: payer, section and Interac reference; claim number, request ID and claimant; bank FITID; SAT observation number.
- Notes are sent to Wave's notes field instead of being appended to the description.
- Added **Discard preview** for previews where no export has been attempted, so transactions can be previewed again.
- No database migration required.

## 1.1.0
- Added persistent Wave export previews selected from Ready for Wave.
- Frozen Wave business, source transaction, account, split and transfer data are stored in dedicated SAT batch tables.
- Added editable descriptions, complementary notes and line notes with validation against active Wave accounts.
- Matched transfers are grouped as one export item; split allocations remain separate line items.
- Added one-at-a-time direct Wave export with deterministic external IDs and duplicate source protection.
- Added per-item attempts, retry-safe partial failures, interrupted-request recovery, batch history, and preview/result CSV downloads.
- Added `database/migrations/003-wave-export-preview.sql`; fresh installs include the same tables in `001-install.sql`.
- Explicitly excludes scheduled export, taxes, contact matching, receipt upload to Wave, Wave update/delete operations, bulk API mutation and OFX export.

## 1.0.0
- One plugin, Scout Accounting Toolkit, replaces Transaction Inbox 0.9.0, Transaction Inbox Tools 0.2.4, Gravity Forms Wave Sync 0.7.1 and Receipt OCR. Inbox, Tools and matching behave as before.
- All names use the `sat` prefix: classes, options, roles, capability, shortcode (`[sat_inbox]`), CSS, JavaScript, wp-config constants. Old settings and roles are carried across once.
- Group-specific values moved into settings: OFX account numbers and Wave accounts, card numbers for OCR, known merchants, uniform account and keywords, section → income accounts, Interac section aliases.
- One settings page (Settings → Scout Accounting) and one database connection shared by every part.
- Admin notices for missing database settings, tables or Wave token.
- Interac importer and weekly report use the inbox only; the legacy `payments` table is no longer used.
- Removed: per-entry Export to Wave, command-line OFX ingester, hidden balance check.
- Complete database install migration and an upgrade migration.
- Code reformatted to PSR-12 style; one PHP minimum (8.0).

Earlier history is in the 0.x plugin readmes in git history.
