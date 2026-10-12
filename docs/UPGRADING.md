# Upgrading from the 0.x plugins to 1.0.0

1.0.0 replaces four plugins with one: Transaction Inbox, Transaction Inbox Tools, Gravity Forms Wave Sync and Receipt OCR.

1. **Back up** the database and WordPress.
   ```bash
   mysqldump scout_payments > scout_payments-before-1.0.sql
   ```
2. **Update the database:** `mysql scout_payments < database/migrations/002-upgrade-to-1.0.sql` (read the notes in the file first).
3. **wp-config.php:** rename the constants.
   | Old | New |
   |---|---|
   | `TO403_PAYMENTS_DB_HOST` / `_NAME` / `_USER` / `_PASSWORD` | `SAT_DB_HOST` / `_NAME` / `_USER` / `_PASSWORD` |
   | `GFWAVE_ACCESS_TOKEN` | `SAT_WAVE_TOKEN` |
4. **Deactivate and delete** the four old plugins, then install and activate Scout Accounting Toolkit. On first load it copies the old settings across (Wave business and accounts, form mapping, OFX accounts and Wave mapping, cut-over dates, cron log path, OCR settings) and moves users from the old treasurer role to **Treasurer (Scout Accounting)**.
5. **Change the inbox page shortcode** from `[403to_transaction_inbox]` to `[sat_inbox]`.
6. **Check Settings → Scout Accounting**, especially the Inbox tab (uniform account and section mapping were previously built in).
7. **Command line:** replace `ingest/` with the new version. The importer no longer writes the legacy `payments` table, and the weekly report reads the inbox instead. Move any section fix-ups (such as Venturers → Sea Venturers) into `interac` → `section_aliases` in `config.php`.
8. Once everything works, delete the old options: `gf_wave_settings`, `to403_tools_settings`, `403to_receipt_ocr_settings`.

**Removed:** the per-entry Export to Wave box on Gravity Forms entries, the command-line OFX ingester (use Tools → OFX import), and the hidden Wave balance check (it will return with Wave export).
