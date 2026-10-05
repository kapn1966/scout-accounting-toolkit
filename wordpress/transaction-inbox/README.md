#### 403TO Transaction Inbox 0.7.1
- Adds Transactions and Expense Claims tabs.
- Lists every Gravity Forms claim independently of transaction matching.
- Shows receipt links, submitted categories, and MATCHED or UNMATCHED state.
- Leaves the Transactions workflow unchanged.

### 403TO Transaction Inbox 0.7.1

- Matches expense transactions to Gravity Forms evidence by amount and date.
- Uses the Gravity Forms mapped category as the initial expense category suggestion.
- Displays receipt links from Gravity Forms evidence.
- Uses separate income and expense category lists from the current Wave chart of accounts.
- Retains the 0.6.7 Ajax, bulk acceptance, split modal, and no-JavaScript fallback behavior.

### Fixes in 0.6.7

- Renders split modals after the transaction table instead of inside the table body.
- Prevents browser HTML table repair from moving modal summary and control elements outside their split form.
- Restores the initial two split lines and live allocated/remaining math.

### 403TO Transaction Inbox 0.6.7

- Bumps plugin version to 0.6.7.
- Uses file modification times for JS/CSS cache busting to avoid stale 0.6.7 assets.
- Retains 0.6.7 Ajax and modal fixes.

## 403TO Transaction Inbox 0.6.7

`[403to_transaction_inbox]` renders the compact, row-oriented transaction register for Operating, CG Funds, and GC Visa observations. Categories remain inline, splits use a modal, and related evidence appears directly below its transaction.

The legacy `[403to_payment_review]` and `[403to_inbox_review]` shortcodes remain registered for backward compatibility.

### Changes in 0.6.7
- Renames the plugin, plugin directory, main PHP file, class, asset handles, action names, and primary filters from Payment Review to Transaction Inbox.
- Adds `[403to_transaction_inbox]` as the preferred shortcode.
- Keeps legacy shortcodes and legacy filter hooks working during migration.
- Accepts suggested evidence through authenticated WordPress Ajax, avoiding a page reload and scroll jump.
- Updates the accepted evidence row in place and removes alternative candidate rows after success.
- Keeps the existing non-Ajax POST handler as a fallback when JavaScript or `fetch()` is unavailable.
- Retains the 0.5.3 unified Operating, CG Funds, and GC Visa register behavior.

### Upgrade
1. Deactivate and delete the old `403to-payment-review` plugin after taking a backup.
2. Upload and activate `403to-transaction-inbox-0.6.7.zip`.
3. Existing pages may continue using a legacy shortcode, but `[403to_transaction_inbox]` is preferred.
4. No new database migration is required when the v0.5.0 inbox tables already exist.


### Fixes in 0.6.7
- Uses the live `inbox_allocations.export_account` column for category and split storage.
- Removes invalid nested forms from the register. Transaction action forms are now valid HTML, while row checkboxes remain associated with a separate bulk-action form.
- Adds an explicit `accept-match` button class and retains submit-event interception for Ajax acceptance.
- Broadens section-to-category suggestions to recognize normalized section names and common recipient/mailbox payload fields.


### Changes in 0.6.7
- Replaces the bulk action and category selectors with one **Accept selected** button.
- For each selected transaction, accepts the displayed top evidence candidate when available and saves the category currently displayed in that row.
- Reports accepted matches, saved categories, skipped rows, and errors after bulk processing.
- Reworks split validation using integer cents and tolerant parsing of dollar signs, spaces, and comma decimal separators.
- Shows a live split state: balanced and ready, incomplete line, or split does not balance.
- Uses an explicit submit button for split saving and refreshes the validator whenever the dialog opens or its fields change.


### Changes in 0.6.7
- Corrects the split amount regular expressions so numeric values are recognized.
- Initializes and continuously updates split allocated, remaining, and validation status values.
- Saves individual categories through authenticated WordPress Ajax without refreshing the page.
- Processes **Accept selected** through Ajax without refreshing the page.
- Saves balanced splits through Ajax, closes the modal, and updates the transaction status in place.
- Keeps all existing server-side POST handlers as a no-JavaScript fallback.


### Fixes in 0.6.7
- Corrects the JavaScript amount regular expressions in the actual packaged asset.
- Loads Transaction Inbox JavaScript and CSS for every authenticated user with the review capability, rather than depending on shortcode detection in `post_content`.
- Defers interface setup until the DOM is ready, supporting shortcodes rendered by page builders, templates, and cached layouts.
- Restores split modal opening and live split validation.
- Restores Ajax interception for category Save, Accept selected, split Save, and individual evidence acceptance.
