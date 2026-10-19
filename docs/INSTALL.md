# Installing the Scout Accounting Toolkit

## 1. Database

Create a database (separate from WordPress) and two users, then create the tables:

```bash
mysql -e "CREATE DATABASE scout_payments CHARACTER SET utf8mb4"
mysql scout_payments < database/migrations/001-install.sql
```

Grants are listed at the end of `001-install.sql`: one user for WordPress, one for the command-line importer.

## 2. WordPress plugin

1. Prepare a commit-identified release ZIP using [RELEASING.md](RELEASING.md), then install it under Plugins → Add New → Upload. Verify the installed files against its source commit before acceptance. A manual folder copy is suitable for development but does not itself establish release provenance.
   Transfer OFX and CGaming report ZIP downloads require the PHP ZipArchive extension; the CGaming report also uses the PHP fileinfo extension to verify receipt types.
2. Add to `wp-config.php`:
   ```php
   define('SAT_DB_HOST', 'localhost');
   define('SAT_DB_NAME', 'scout_payments');
   define('SAT_DB_USER', 'sat_web');
   define('SAT_DB_PASSWORD', '...');
   define('SAT_WAVE_TOKEN', '...');   // Wave full-access token
   ```
3. Activate the plugin. Then, under **Settings → Scout Accounting**:
   - **Wave**: discover your business, then discover accounts.
   - **Expense form**: choose the Gravity Form and map its fields. Category choices must be exact Wave account names. Map **Payment method** and **Receipt upload** to enable the receipt-count rule: Invoice and Credit Card claims accept exactly one receipt, Reimburse a Scouter claims one or more. Gravity Forms validation enforces it on the server; the browser check only helps the claimant.
   - **Bank accounts**: one line per account in your OFX files (ACCTID, source, bank or credit card, name, Wave account).
   - **Receipt OCR** (optional): needs `tesseract` and `pdftoppm` on the server.
   - **Inbox**: uniform keywords and account, and section → income account mapping. The section names here also fill the CGaming report's **Section or Program** column when a claim category starts with `<section> - `.
4. Put `[sat_inbox]` on a private page. Administrators and the **Treasurer (Scout Accounting)** role can use it; the Tools tab is administrators only.

The plugin shows an admin notice if a database setting, table or the Wave token is missing.

## 3. Interac importer (command line)

```bash
sudo mkdir -p /opt/scout-accounting-toolkit
sudo cp -r config ingest /opt/scout-accounting-toolkit/
cd /opt/scout-accounting-toolkit
sudo cp config/config.example.php config/config.php && sudo nano config/config.php
sudo chmod 640 config/config.php
php ingest/interac-email/process.php      # first run by hand
```

Needs the PHP IMAP extension (`php8.x-imap`). Cron (use the versioned PHP binary so upgrades cannot switch it):

```cron
59 * * * * cd /opt/scout-accounting-toolkit/ingest/interac-email && /usr/bin/php8.4 process.php >> logs/cron.log 2>&1
0 12 * * 1 cd /opt/scout-accounting-toolkit/ingest/interac-email && /usr/bin/php8.4 weekly-report.php >> logs/cron.log 2>&1
```

The Tools tab reads `logs/cron.log` to show importer health; set its path on the Tools tab if it differs, and make sure the web server can read it.

## 4. Check

- Tools tab → Inbox summary shows your sources after the first imports.
- Upload one OFX file per account through Tools → OFX import.
- Submit a test expense claim and look for it on the Expense Claims tab. **Edit** it, change the Payee, save, and confirm the row shows **Corrected** without reloading; **Use source** in the dialog restores the imported value. As an Invoice or Credit Card claim, a second receipt must be refused with a message on the receipt field.
- On Expense Claims → **CG Funds monthly report**, choose a month and download the receipts ZIP. It contains `CGaming expenses - <Month> <Year>.csv` and one file per included claim under `receipts/`.
- Categorize a small test transaction, create a Wave preview from **Ready for Wave**, download the preview CSV, then use **Export one**. Confirm the deterministic external ID and transaction ID in the result CSV.

Wave export details and limitations: [WAVE_EXPORT.md](WAVE_EXPORT.md).
