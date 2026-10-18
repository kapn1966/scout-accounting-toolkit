# Wave export previews

Scout Accounting Toolkit exports reviewed inbox transactions through persistent preview batches. It never schedules exports and never submits a bulk Wave mutation.

## Workflow

1. Open `[sat_inbox]` and choose **Ready for Wave**.
2. Select transactions and click **Create Wave preview**.
3. SAT freezes the selected data into a persistent batch:
   - Wave business ID and name;
   - source observation IDs, source keys, dates, amounts and descriptions;
   - anchor and categorization account IDs and names;
   - split lines;
   - source observations, accounts, splits, descriptions and notes; accepted transfers are handled separately.
   - description, item note and line notes;
   - deterministic external ID.
4. Open **Wave export**, review the validation results, edit descriptions or notes, and save to revalidate.
5. Use the action bar above the preview table: **Send all ready items to Wave** sends every unexported item in order, or click **Export one** in an item's header to send just that item.
6. Continue successful, pending or failed items independently. Download the preview CSV before export and the result CSV for the final audit record.

Once a transaction is frozen into a preview item, whatever that item's status (pending, exporting, failed or exported), it no longer appears or counts in **Ready for Wave** and cannot be added to another preview. Continue or retry it from its existing preview. Discarding a preview releases its transactions back to Ready for Wave.

### Send all ready items to Wave

**Send all** processes the preview's items one at a time in preview order. It reuses the exact per-item path of **Export one**: the same validation, attempt record, stale-attempt recovery, deterministic external ID and single `moneyTransactionCreate` request per item. It is a sequence of independent requests, not a bulk mutation, and is not atomic across Wave calls.

- Items already exported are skipped and never resent.
- Processing stops at the first item that fails validation, is already being exported, or gets a Wave error or uncertain response. Later items are not attempted.
- Items exported before the failure stay exported in Wave and SAT.
- Running Send all again resumes with the remaining unexported items, starting with the failed item. A failed item is retried with the same external ID, so confirm in Wave that it was not created first. Use **Retry one** on an individual item for targeted recovery.
- The notice reports how many items were exported, failed and remain unattempted.
- The button is disabled while any unexported item has stored validation errors, or after preview fields are edited but not saved.

## Descriptions and notes

The default description says what the money was for, using the richest matched evidence:

- **Interac income:** the payer and the payment message they typed, as `from <payer> - <message>` (for example *from PENG FEN LIU - Daniel Yuan*). With no message it is `from <payer>`; with no payer, the message alone. A message that already starts with the payer's name does not repeat it.
- **Expense claims:** the claim description, prefixed by the payee when the payee is not already mentioned. A payment covering several claims joins their descriptions with `; `.
- **Transfers:** `Transfer: source to destination`.
- **Anything else:** the cleaned bank or card text.

The default note is the audit trail: Interac payer, section and reference number; claim number, request ID and claimant; the bank FITID; and the SAT observation number. Split line notes preserve reviewer-entered allocation notes or identify the split position.

These defaults are set when a preview is created. Existing previews keep their frozen text; discard and recreate a preview to pick up new defaults.

The description and note are sent to Wave's separate transaction **description** and **notes** fields. Wave's money-transaction mutation has no general-purpose line-note field, so line notes remain in SAT batch history and both CSV files.

### Discarding a preview

A preview can be discarded with **Discard preview** until any export is attempted. Its transactions return to Ready for Wave and can be previewed again. Once an item has been attempted or exported, the batch is kept as an audit record.

Discard, Save and export initiation take the same transactional batch lock, so
discard cannot pass its untouched check while another request starts an export.
The lock is released before calling Wave; the persisted attempt/exporting state
then prevents discard while the remote result is outstanding. Cleanup/reset
also refuse export-owned sources and their matched evidence instead of deleting
the source records beneath a preview or transfer batch.

## Splits and transfer imports

A split remains one Wave transaction with multiple categorization lines whose amounts must equal the anchor amount.

Do not export accepted transfer matches through the API preview. Any accepted match between two configured payment accounts is hard-blocked from Ready for Wave and from Wave API preview creation and export, even when it fails OFX eligibility; it never falls back to the API. Observations owned by a generated or confirmed transfer OFX batch (a `sat_transfer_ofx_reservations` row) are also rejected. Accepted payment-account transfers that fail OFX eligibility appear under **Needs attention** in **Transfers to Wave** with the specific reason.

Transfers between Wave anchor accounts cannot be created through the public `moneyTransactionCreate` API. Wave's API documentation says its special handling for transfers between bank and credit-card accounts is not available through the API. SAT also tested using another anchor account as a line item with both `INCREASE` and `DECREASE` balance directions; Wave rejected both shapes. Creating the two sides as ordinary API transactions with temporary income and expense categories succeeded, but those categories had to be manually removed before Wave allowed the sides to be matched as a transfer. That is not an acceptable workflow.

The confirmed design is to generate one transfer-only OFX file per account. Each file contains that account's side as an uncategorized transaction; users import each file into its corresponding Wave account and then match the two transactions using Wave's normal transfer workflow. The generated mini-statements omit `LEDGERBAL`, which would otherwise copy a balance for the original full statement. FITIDs are deterministic and distinct per leg, and original posting dates and signed amounts are preserved.

The production transfer workflow lists one row per eligible accepted transfer, freezes a selected set in a durable batch, and generates an account-specific OFX file for every affected configured account. The files and plain-text manifest are packaged in a ZIP. Files are regenerated from frozen data on every download; FITIDs, statement dates, ordering and account-file contents are deterministic. Generated batches remain awaiting confirmation until a reviewer explicitly confirms that all account files were imported and every transfer in the batch was matched in Wave. Partial imports are allowed: a reviewer may import one file or one side and finish matching later, leaving the batch Generated meanwhile. There is no per-transfer confirmation. Cancellation is safe only if nothing from the batch was imported into Wave, or after every imported transaction from the batch was deleted from Wave; cancelled transfers become actionable again and could otherwise be imported twice. Cancelling releases the batch's observations while preserving the batch record. Confirmation marks both observations exported through the `transfer_ofx` route.

The ZIP is built in a private temporary file and streamed only through the authenticated WordPress action. The manifest includes account labels, amounts, currencies and posting dates, but no account IDs/numbers or source keys. The workflow performs no Wave API mutation.

## Idempotency and failures

External IDs are deterministic SHA-256-derived values based on the selected Wave business and canonical source keys. A unique database key prevents the same external ID from entering another batch, and each source observation can belong to only one preview item.

Before a remote call, SAT locks the item and records an attempt. Export item statuses are `pending`, `exporting`, `failed` and `exported`; batch statuses are `preview`, `partial`, `exporting` and `completed`. Success stores the Wave transaction ID on the item, attempt, reviews and source observations. Failure affects only that item, retains the error, and leaves the other batch items available.

A request left in `exporting` for more than 15 minutes is recovered as a retryable interrupted attempt without changing its external ID. On recovery, the item is marked `failed`, the attempt is marked `interrupted`, and the preview remains available to retry with the same external ID. Because any network timeout can leave the remote outcome uncertain, confirm the transaction in Wave before retrying an interrupted item. The stable external ID is retained for duplicate protection.

## Accounting direction

Wave's `moneyTransactionCreate` resolves every request into debits and credits and rejects one that lacks at least one of each (`Transaction must have at least one debit and credit line item`). Wave's developer guide defines the mapping:

- Anchor `DEPOSIT` is a debit on an asset anchor (Cash and Bank) and a credit on a liability anchor (Credit Card); `WITHDRAWAL` is the opposite.
- Line `INCREASE` applies the amount on the line account type's normal side: debit for asset and expense, credit for income, liability and equity. `DECREASE` is the opposite. Contra subtypes `DISCOUNTS` and `DEPRECIATION_AND_AMORTIZATION` are inverted.

SAT keeps the anchor direction from the statement sign: money leaving a bank account is a `WITHDRAWAL`, and a card purchase is a `DEPOSIT` on the card liability. Each categorization line is then placed on the side opposite the anchor, and SAT chooses `INCREASE` or `DECREASE` from the line account's discovered Wave type and subtype. The amount sign alone never decides a line's direction. For example:

| Transaction | Anchor | Expense or income line |
|---|---|---|
| Bank deposit to income | `DEPOSIT` (debit bank) | income `INCREASE` (credit) |
| Bank withdrawal to expense | `WITHDRAWAL` (credit bank) | expense `INCREASE` (debit) |
| Credit-card purchase to expense | `DEPOSIT` (credit card) | expense `INCREASE` (debit) |
| Credit-card refund to expense | `WITHDRAWAL` (debit card) | expense `DECREASE` (credit) |
| Bank deposit refunding an expense | `DEPOSIT` (debit bank) | expense `DECREASE` (credit) |

Split lines are decided independently by the same rule. If an account's type is unknown, SAT stores no direction and validation asks for Wave accounts to be rediscovered.

## Validation

SAT blocks export when any of these checks fail:

- Wave token or selected business is missing or changed from the frozen business;
- the anchor account or a categorization account is missing, archived, or no longer resolves to a current Wave account ID;
- the anchor account subtype does not match the bank/card source type (`CASH_AND_BANK` vs `CREDIT_CARD`);
- date, amount, direction, description or external ID is invalid;
- line amounts do not balance to the anchor amount, or a line has an invalid `INCREASE`/`DECREASE` direction;
- the anchor and lines, interpreted with Wave's debit/credit rules and the current account types, are one-sided (all debits or all credits) or do not balance. Previews are checked when displayed as well as before export, so a preview frozen before this check is not shown as **Valid**;
- a frozen source is excluded, already marked exported with the same external ID (requiring reconciliation), or was exported by a different item;
- a frozen ordinary item has the wrong number of sources;
- a source observation belongs to an accepted transfer between payment accounts, or to a generated or confirmed transfer OFX batch (the dedicated transfer OFX route must be used);
- the frozen source IDs no longer match the item’s saved `source_observation_ids`.

Saving the preview re-resolves Wave account IDs by the frozen account names, allowing account discovery to be repaired without rebuilding or silently changing the source amounts and allocations. For pending and failed ordinary items, saving also recalculates each line's `INCREASE`/`DECREASE` direction under the accounting-direction rule above. Amounts, accounts, the anchor direction, the external ID and the attempt history are unchanged, and the notice reports how many line directions changed. This is how a preview frozen with an incorrect direction is repaired when it can no longer be discarded.

The batch-18 regression fixture demonstrates this repair; it is not a record
of an actual batch being repaired or exported. Inspect the real batch and Wave
outcome before an authorized recovery. Do not delete its failed attempt history.

## Out of scope

This implementation does **not** provide:

- automatic or scheduled export;
- tax handling;
- customer or vendor matching;
- receipt upload to Wave;
- changes to already-exported Wave transactions;
- deletion of Wave transactions;
- bulk API mutation;
- automatic transfer matching in Wave; users must import both account files and match the transactions there.
