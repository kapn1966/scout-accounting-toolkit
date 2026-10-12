# 403TO Transaction Inbox

Shortcode: `[403to_transaction_inbox]` (legacy `[403to_payment_review]` and `[403to_inbox_review]` still work).

Register of OFX transactions for Operating, CG Funds and GC Visa, with supporting evidence underneath each one: Interac e-Transfer emails for deposits and Gravity Forms expense claims for payments. Tabs: Transactions, Expense Claims (and Tools, from the Transaction Inbox Tools plugin).

## How matching works (0.8.0)

| Bank transaction | Evidence considered | Rule |
|---|---|---|
| Operating deposit | Interac e-Transfers | Same amount, within 7 days. Payer name in the bank text ranks first. |
| GC Visa charge | Claims paid by **Charge on 403TO credit card** | Same amount, receipt up to 10 days before posting. |
| Operating payment | **Reimbursement** (and invoice) claims | Same amount, or the exact sum of 2–4 open claims from the person named in the bank description. Receipt up to 120 days before payment. |
| CG Funds payment | Invoice claims | Same amount, receipt up to 120 days before payment. |

Evidence that is already matched is never offered again. Accepting expense claims also fills in the category from each claim. A combined payment becomes a split with one line per claim. Claim categories that are not current Wave accounts are underlined on the Expense Claims tab and must be chosen by hand.

Categories come from the Wave chart of accounts found by Gravity Forms Wave Sync (**Forms → Wave Sync → Discover Accounts**), so names always match Wave exactly. Archived and "Uncategorized" accounts are left out. A built-in list is used if no accounts have been discovered.

## Changelog

### 0.8.0
- Matches expense claims to GC Visa charges and to Operating reimbursements, including one payment covering several claims.
- GC Visa transactions now get evidence suggestions.
- A transaction can be matched to more than one piece of evidence.
- Already-matched evidence is no longer suggested for other transactions.
- Category list and suggestions use exact Wave account names, which fixes uniform and section suggestions that stopped matching after the chart of accounts changed.
- Accepting claims sets the category (or a split) from the claims.
- Evidence lines show the claim number, claimant, payee, amount, date, category and receipt links.

### 0.7.1
- Transactions and Expense Claims tabs. Every claim stays visible with MATCHED/UNMATCHED.

### 0.6.x
- Renamed from Payment Review. Ajax category save, split modal, evidence acceptance and bulk "Accept selected", with no-JavaScript fallbacks.

### 0.5.x
- Unified register for Operating, CG Funds and GC Visa.
