# Backlog

Only unfinished work belongs here. Completed work and release history belong in
the changelog or Git history.

## Next

- Resolve the GC Visa reconciliation issue in Wave and decide the Visa
  cut-over date. Reconfirm the account's starting point before trusting exports.
- Obtain controlled ordinary Wave export evidence for a reviewed release,
  including the saved Wave transaction ID and confirmation of retry and
  Send all stop/resume behavior.
- Complete operational acceptance of transfer OFX with representative
  bank-to-bank and bank-to-card transfers, including partial import,
  re-download, confirmation and safe cancellation.
- Define a date-aware pre-export reconciliation checkpoint. Do not compare
  today's Wave balance with a statement balance from a different date.

## Later

- Review OCR rules and defer any changes until the extraction behavior is
  evaluated against suitable sanitized samples.
- Allow the Treasurer role to use selected Tools, such as OFX upload, while
  keeping cut-over cleanup and reset restricted.
- Improve transfer OFX filenames with safe deterministic slugs from configured
  account labels; handle collisions without exposing account IDs or source keys.
- Improve Ready for Wave counts to show matching versus total while preserving
  staged-preview and transfer exclusions consistently.

## Technical debt

- Review the legacy `bank_observation_id` name and `payments` /
  `payment_allocations` tables before any future fresh-schema cleanup.
- Add isolated real-database tests for constraints and concurrency, mocked
  Wave HTTP/GraphQL boundary tests, and browser tests for production-relevant
  interactions.
- Add importer failure notifications and replace direct `imap_*` calls with a
  maintained IMAP library.

## Operational follow-up

- Set account-specific cut-over dates and exclude transactions already entered
  in Wave before exporting.
- Verify the status and Wave outcome of existing attempted previews before any
  authorized retry. An interrupted or failed request is not proof that Wave did
  not create the transaction.
