# Changelog

## 1.4.0
- Expense Claims: bookmarkable sorting (Expense Date, Submission Date, claimant, vendor, amount) and filters (month, claimant, payment method, match status), plus a Submitted column.
- Added the CG Funds monthly report: every claim in the chosen month except Reimburse a Scouter, with count, total and receipt summary. **Download receipts ZIP** produces `CGaming expenses - <Month> <Year>.zip` with the CSV and exactly one safely named receipt per claim. The ZIP is built privately and refused in full on any missing, duplicate, unsafe or extra receipt.
- Expense form: Invoice and Credit Card claims accept exactly one receipt, Reimburse a Scouter several. Gravity Forms validation enforces this on the server, and a browser script mirrors it without removing uploaded files.
- New claims store the Gravity Forms submission time in their payload; older claims read it from Gravity Forms. No database migration.
- changed base requirements to PHP 8.1 and WordPress 7.1.3

## 1.3.0
- Added Send all for ordinary Wave previews: sequential export in preview order, stopping on the first failure and resuming without resending completed items.
- Moved preview actions above the horizontal scroller and item export/retry actions beside each item's status.
- Removed staged preview transactions from Ready for Wave lists, counts and selections.
- Corrected accounting directions using Wave account types, added local debit/credit validation, and enabled in-place repair of pending/failed frozen lines without losing attempt history.
- Interac descriptions include payer and payment message, with tested fallbacks.
- Cleanup and reset now refuse export-owned observations and their matched evidence before deleting any records.
- Preview discard, editing and export initiation share a transactional batch lock.
- Added behavioural transfer lifecycle, rollback and cleanup tests, version-equality checks, and deterministic commit-based release packaging with installed-file verification.
- No database migration from 1.2.0. Release packaging, tagging, deployment and live accounting acceptance remain separate authorized operations.

## 1.2.0
- Added the user-facing transfer-only OFX workflow: frozen multi-transfer batches, deterministic account-specific files and ZIP manifest, explicit import/match confirmation, safe cancellation and retained history.
- Blocked accepted payment-account transfers from ordinary Wave API previews, including pairs that fail OFX eligibility.
- Added transfer eligibility, file-count, ZIP and serializer/parser tests.
- Fresh installations use `001-install.sql`; existing development schemas needing transfer tables use the direct `002-transfer-ofx-batches.sql` delta.

## 1.1.2
- Changed expense line directions to `DECREASE`. This blanket rule was superseded by the account-type debit/credit mapping in 1.3.0; do not use it as current accounting guidance.

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
- Originally added `database/migrations/003-wave-export-preview.sql` (subsequently removed). The authoritative fresh-install schema is now `001-install.sql`; do not follow the removed migration chain.
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
