# Fresh installation

This guide covers a new SAT installation only. For the supported schema update
from SAT 1.4.0 to 1.5.0, see [UPGRADING.md](UPGRADING.md).

## Requirements

- WordPress 7.1.3 or newer and PHP 8.1 or newer.
- MariaDB or MySQL, with a separate SAT database.
- PHP `ZipArchive` for transfer OFX and CG Funds report ZIP downloads; PHP
  `fileinfo` for receipt-file checks.
- Gravity Forms for expense claims.
- Tesseract and `pdftoppm` when receipt OCR is enabled.
- The PHP IMAP extension for the Interac importer.

## Create the SAT database

Create a dedicated database and database users. Adjust names, host and
passwords to match the server:

```sql
CREATE DATABASE scout_payments CHARACTER SET utf8mb4;
CREATE USER 'sat_web'@'localhost' IDENTIFIED BY 'replace-with-secret';
CREATE USER 'sat_ingest'@'localhost' IDENTIFIED BY 'replace-with-different-secret';
GRANT SELECT, INSERT, UPDATE, DELETE ON scout_payments.* TO 'sat_web'@'localhost';
GRANT SELECT, INSERT, UPDATE ON scout_payments.* TO 'sat_ingest'@'localhost';
```

Run the complete fresh-install schema once:

```bash
mysql scout_payments < database/migrations/001-install.sql
```

`001-install.sql` is the authoritative fresh-install schema. Do not apply
development-only delta migrations to a fresh database.

## Install the plugin

Install the SAT plugin in WordPress using the site's normal plugin installation
process, then activate it.

Add the database connection and Wave token to `wp-config.php`, keeping actual
credentials out of source control:

```php
define('SAT_DB_HOST', 'localhost');
define('SAT_DB_NAME', 'scout_payments');
define('SAT_DB_USER', 'sat_web');
define('SAT_DB_PASSWORD', 'replace-with-secret');
define('SAT_WAVE_TOKEN', 'replace-with-wave-token');
```

In **Settings → Scout Accounting**:

- Discover the Wave business and accounts.
- Select the Gravity Form and map its fields for expense claims.
- Configure the OFX bank and card accounts, account types and corresponding
  Wave accounts.
- Configure inbox keywords, categories and section-to-income-account mappings.
- Configure receipt OCR if required.

Put `[sat_inbox]` on a private WordPress page. Restrict access to the intended
accounting users. The plugin displays an admin notice if required database
settings, tables or the Wave token are missing.

## Interac importer and cron

Install the `config/` and `ingest/` directories in the chosen server location.
Copy `config/config.example.php` to `config/config.php`, then set the database
and IMAP credentials and weekly-report settings. Keep `config.php` private and
out of source control. The importer database user needs only the grants shown
above.

Run the importer manually once from `ingest/interac-email/` using the PHP
binary with the IMAP extension installed. After confirming it works, configure
the hourly importer and weekly report in cron, using that same PHP binary and
an appropriate writable log path. For example, with `process.php` and
`weekly-report.php` in the working directory:

```cron
59 * * * * cd /opt/scout-accounting-toolkit/ingest/interac-email && /usr/bin/php process.php >> logs/cron.log 2>&1
0 12 * * 1 cd /opt/scout-accounting-toolkit/ingest/interac-email && /usr/bin/php weekly-report.php >> logs/cron.log 2>&1
```

Replace `/usr/bin/php` with the supported PHP binary that has IMAP enabled.
Ensure the web server can read the importer log if it is configured to display
importer health in Tools.

## Smoke check

1. Confirm the plugin has no missing-setting or missing-table notice.
2. Upload one representative OFX statement and verify its transactions appear
   in the inbox.
3. If claims are enabled, submit a test claim and verify it appears in Expense
   Claims; check the required receipt behavior for its payment method.
4. Verify the Interac importer completes and reports its result.
5. Create a small Wave preview and inspect it. Perform a live Wave export only
   in an authorized test or production environment.

See [WAVE_EXPORT.md](WAVE_EXPORT.md) for safe Wave and transfer operations and
[DEVELOPMENT.md](DEVELOPMENT.md) for repository and schema-development
conventions.
