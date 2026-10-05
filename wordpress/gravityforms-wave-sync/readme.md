=== Gravity Forms Wave Sync ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.7.1
License: GPLv2 or later

== Installation ==
1. Upload and activate the ZIP.
2. Add define( 'GFWAVE_ACCESS_TOKEN', 'your-token' ); to wp-config.php.
3. Open Forms > Wave Sync.
4. Test the connection, then click Discover Businesses.
5. Select the correct business, configure the form and field mappings, and save.

== Scope ==
Version 0.3 discovers Wave businesses and accounts, resolves exact account-name mappings to Wave account IDs, and stores workflow metadata. It does not yet create Wave transactions or upload receipts.

== 0.3.0 ==
* Adds Discover Accounts for the selected Wave business.
* Stores account ID, name, type, subtype, and archived status.
* Automatically resolves category and anchor mappings by exact active account name.

== 0.3.1 ==
* Fetches every page of the Wave chart of accounts instead of only the first page.
* Uses a page size of 100, merges pages by stable account ID, and preserves the existing interface and mappings.

== 0.4.0 ==
* Adds a manually confirmed live test-transaction form.
* Uses selected discovered anchor and categorization account IDs.
* Records the returned Wave transaction ID and external ID.
* Does not automatically export Gravity Forms entries.

== 0.5.0 ==
* Adds manual per-entry Wave export on the Gravity Forms entry detail page.
* Credit-card entries use GC Visa and reimbursements use Operating Acct.
* Invoice entries require a choice of Operating Acct or CG Funds.
* Prevents duplicate export after a Wave transaction ID has been saved.
* Adds entry notes, export status, error metadata, and returned Wave transaction IDs.

== 0.6.1 ==
* Adds a configurable Wave external ID field mapping.
* Automatically exports new credit-card receipts to GC Visa.
* Automatically exports new reimbursements to Operating Acct.
* Leaves invoices requiring payment for manual export from Operating Acct or CG Funds.
* Uses the mapped external ID value exactly as stored in Gravity Forms.

== 0.6.2 ==
* Adds the mapped request ID visibly to Wave transaction descriptions.
* Description format: Payee (req1234) - Brief description.
* Keeps the same request ID as the Wave external ID.

== 0.7.0 ==
- Adds configured submissions to Transaction Inbox as expense evidence.
- Stops automatic Wave export on submission while retaining manual per-entry export.
- Stores category, payment method, payee, description, request ID, submitter fields, and receipt URLs in the evidence payload.
- Uses an idempotent source key based on Gravity Forms form and entry IDs.

== 0.7.1 ==
- Uses the submitted Gravity Forms category directly as the Wave account name.
- Creates or updates the claim's single inbox allocation during every import.
