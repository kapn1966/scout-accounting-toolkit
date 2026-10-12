# 403TO Transaction Inbox

Shortcode: `[403to_transaction_inbox]` (legacy `[403to_payment_review]` and `[403to_inbox_review]` still work).

Register of OFX transactions for Operating, CG Funds and GC Visa, with supporting evidence underneath each one: Interac e-Transfer emails for deposits, Gravity Forms expense claims for payments, and the other side of transfers between our own accounts. Tabs: Transactions, Expense Claims (and Tools, from the Transaction Inbox Tools plugin).

## Review views

| View | Shows |
|---|---|
| **Needs attention** (default) | Not yet fully categorized. Rows that become ready turn green and move on the next refresh. |
| **Ready for Wave** | Category lines add up to the amount. This is the queue for Wave export. |
| **Excluded** | Will not be sent to Wave, with the reason. Reopen to put one back. |
| **All** | Everything, including exported. |

Filters: account, income only / expenses only, and month. Filters are kept in the page address.

A transaction is ready when its category lines add up to its amount, whether it has one line or several. Status shows **READY**, **READY · SPLIT** or **READY · TRANSFER**.

## How matching works

| Bank transaction | Evidence considered | Rule |
|---|---|---|
| Operating deposit | Interac e-Transfers | Same amount, within 7 days. Payer name in the bank text ranks first. |
| GC Visa charge | Claims paid by **Charge on 403TO credit card** | Same amount, receipt up to 10 days before posting. |
| Operating payment | **Reimbursement** (and invoice) claims | Same amount, or the exact sum of 2–4 open claims from the person named in the bank description. Receipt up to 120 days before payment. |
| CG Funds payment | Invoice claims | Same amount, receipt up to 120 days before payment. |
| Any account | **Transfer** | The opposite amount in another of our accounts within 5 days (e.g. Operating −500 and GC Visa +500). |

Accepting a transfer links both sides and categorizes each as the other account (Operating Acct, CG Funds or GC Visa), so both show READY · TRANSFER. If the other side is not in the inbox, choose the account under "Transfer to or from" in the category list. When Wave export is built, a matched pair is sent as one Wave transaction.

**Exclude** is for transactions that must never reach Wave: duplicates, tests, or items already entered in Wave before the cut-over. A reason is required, any evidence match is released, and it can be reopened. Transfers are not excluded; they are matched.

Categories come from the Wave chart of accounts found by Gravity Forms Wave Sync (**Forms → Wave Sync → Discover Accounts**). Transfer account names follow the Wave account mapping saved on the Tools tab, or default to Operating Acct, CG Funds and GC Visa.

## Changelog

### 0.9.0
- Needs attention / Ready for Wave / Excluded / All views with counts.
- Filters for income or expenses and for month, alongside account.
- Splits whose lines add up now show READY · SPLIT (previously stuck on SPLIT).
- Transfers between Operating, CG Funds and GC Visa are suggested and matched as pairs; accepting categorizes both sides.
- Our own accounts appear in the category list under "Transfer to or from".
- Exclude with a reason, and Reopen. Exported transactions can no longer be edited.
- Faster page: categories and reviews load in one query.

### 0.8.0
- Expense claims matched to GC Visa charges and Operating reimbursements, including combined payments.
- Categories use exact Wave account names; claim categories fill in on acceptance.

### 0.7.1
- Transactions and Expense Claims tabs.

### 0.6.x
- Renamed from Payment Review. Ajax saves, split modal, bulk accept.
