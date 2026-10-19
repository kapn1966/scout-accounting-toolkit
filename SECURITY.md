# Security

SAT handles financial records, claimant information, receipt files, email
evidence and Wave credentials. Treat production and acceptance data as
sensitive.

## Keep out of source control

Never commit:

- Database, IMAP or Wave API credentials and tokens.
- `wp-config.php`, private importer configuration or environment-specific
  settings.
- Bank statements, OFX files, transaction data, receipts, claimant details or
  real email evidence.
- Database dumps, logs or screenshots containing sensitive information.

Tests, examples and other fixtures must use synthetic or deliberately
sanitized data. Do not assume removing names alone makes financial records safe
to publish.

## Report a vulnerability privately

Do not post vulnerability details, exploit steps or sensitive data in a public
issue or discussion. Use GitHub's private vulnerability-reporting feature for
this repository when available; otherwise contact the maintainers through a
private channel. Include the affected component, impact, reproduction details
and any suggested mitigation without attaching real accounting data or
credentials.

Supported software and dependencies must remain on versions that receive
security updates.

## If sensitive data is committed

1. Stop sharing or distributing the affected commit, package or artifact and
   notify the repository maintainer privately.
2. Revoke or rotate exposed credentials and tokens immediately; assess whether
   financial or personal data was accessed or distributed.
3. Preserve enough incident details to determine impact, but do not copy the
   exposed data into new reports or tickets.
4. Have the maintainer coordinate removal from active branches and history,
   caches or artifacts where feasible. Removing a file in a later commit does
   not remove it from repository history.

For AI-specific handling, see
[`.github/copilot-instructions.md`](.github/copilot-instructions.md).