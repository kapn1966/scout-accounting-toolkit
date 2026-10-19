# Scout Accounting Toolkit (SAT)

SAT helps Scout groups review bank and card transactions, Interac e-Transfer
evidence and volunteer expense claims before preparing reviewed transactions
for Wave Accounting.

## Components

- **WordPress plugin:** transaction inbox, Expense Claims, review tools,
  receipt OCR, Wave connection and settings.
- **Interac importer:** command-line email importer and weekly report.
- **Separate SAT database:** stores imported observations, review state and
  export audit records.

## High-level workflow

Bank and card statements enter the inbox as transactions. Interac messages and
expense claims provide evidence that reviewers can match to those transactions.
Reviewers categorize, split, pair transfers or exclude records as appropriate.

- **Inbox:** review imported transactions and match supporting evidence.
- **Expense Claims:** review claims and receipts; corrections are audited and
  do not change the original submission.
- **Wave:** prepare frozen previews for ordinary transactions, then export one
  item at a time or send eligible items sequentially. Retries require checking
  Wave when the outcome is uncertain.
- **Transfers:** eligible accepted transfers use a separate account-specific
  OFX workflow; they are not sent through the Wave API.

See [WAVE_EXPORT.md](docs/WAVE_EXPORT.md) for preview, retry and transfer
operations.

## Requirements

- WordPress 7.1.3 or newer.
- PHP 8.1 or newer.
- MariaDB or MySQL.
- PHP `ZipArchive` for transfer OFX and CG Funds report ZIP downloads; PHP
  `fileinfo` for receipt checks.
- Gravity Forms for expense claims.
- Tesseract and `pdftoppm` for optional receipt OCR.
- The PHP IMAP extension for the Interac importer.

## Documentation

- [Fresh installation](docs/INSTALL.md)
- [Supported upgrade path and schema policy](docs/UPGRADING.md)
- [Wave export operations](docs/WAVE_EXPORT.md)
- [Development architecture and conventions](docs/DEVELOPMENT.md)
- [Current backlog](docs/BACKLOG.md)
- [Release procedure](docs/RELEASING.md)
- [Sensitive-data and vulnerability-reporting policy](SECURITY.md)

## Licence

GPL-3.0-or-later.
