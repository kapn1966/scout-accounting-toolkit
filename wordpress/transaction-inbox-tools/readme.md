# 403TO Transaction Inbox Tools
Contributors: 403to
Stable tag: 0.1.1
Requires PHP: 7.4

Adds a Tools tab to the existing 403TO Transaction Inbox without replacing or changing its Transactions and Expense Claims workflows.

##Features:
- Inbox counts grouped by source system.
- Gravity Forms backfill preview and import from a selected date, using the installed Gravity Forms Wave Sync import routine.
- Cut-over cleanup preview and deletion for observations before a selected date.
- Full inbox reset preview and protected reset action.
- Interac email and OFX import planning panels.
- Wave export planning panel only. No Wave export is implemented.

##Requirements:
- Existing 403TO Transaction Inbox plugin.
- Gravity Forms and Gravity Forms Wave Sync 0.7.1+ for claim backfill.
- Administrators only.

##Database access:
The add-on first attempts to reuse the existing Transaction Inbox PDO connection. If that connection is not exposed, define TO403_INBOX_DSN, TO403_INBOX_DB_USER and TO403_INBOX_DB_PASSWORD in wp-config.php.

##Safety:
Back up MariaDB before using purge or reset. Preview the affected counts first.

## Changelog
### 0.1.1 
- Reset and date-based purge no longer read or delete inbox_import_batches.
- Import batch history is preserved as audit history.
- Avoids requiring SELECT or DELETE permissions on inbox_import_batches.
