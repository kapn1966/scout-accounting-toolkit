# Changelog

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
