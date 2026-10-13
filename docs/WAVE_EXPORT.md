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
   - matched-transfer grouping;
   - description, item note and line notes;
   - deterministic external ID.
4. Open **Wave export**, review the validation results, edit descriptions or notes, and save to revalidate.
5. Click **Export one** on one valid item. There is no export-all control.
6. Continue successful, pending or failed items independently. Download the preview CSV before export and the result CSV for the final audit record.

## Descriptions and notes

The default description says what the money was for, using the richest matched evidence:

- **Interac income:** the payment message the payer typed (for example *Uniform payment for Josie*).
- **Expense claims:** the claim description, prefixed by the payee when the payee is not already mentioned. A payment covering several claims joins their descriptions with `; `.
- **Transfers:** `Transfer: source to destination`.
- **Anything else:** the cleaned bank or card text.

The default note is the audit trail: Interac payer, section and reference number; claim number, request ID and claimant; the bank FITID; and the SAT observation number. Split line notes preserve reviewer-entered allocation notes or identify the split position.

These defaults are set when a preview is created. Existing previews keep their frozen text; discard and recreate a preview to pick up new defaults.

The description and note are sent to Wave's separate transaction **description** and **notes** fields. Wave's money-transaction mutation has no general-purpose line-note field, so line notes remain in SAT batch history and both CSV files.

### Discarding a preview

A preview can be discarded with **Discard preview** until any export is attempted. Its transactions return to Ready for Wave and can be previewed again. Once an item has been attempted or exported, the batch is kept as an audit record.

## Splits and matched transfers

A split remains one Wave transaction with multiple categorization lines whose amounts must equal the anchor amount.

When either side of an accepted matched transfer is selected, SAT includes both sides automatically and freezes them as one transfer item. The withdrawal side is the anchor and the receiving bank/card account is the single line item. Wave documents special handling for transfers between anchor accounts; verify this behaviour in the target Wave business before production use. If Wave rejects the mutation, SAT retains the error and the item can be retried with the same external ID.

## Idempotency and failures

External IDs are deterministic SHA-256-derived values based on the selected Wave business and canonical source keys. A unique database key prevents the same external ID from entering another batch, and each source observation can belong to only one preview item.

Before a remote call, SAT locks the item and records an attempt. Success stores the Wave transaction ID on the item, attempt, reviews and source observations. Failure affects only that item, retains the error, and leaves the other batch items available. A request left in `exporting` for more than 15 minutes becomes a retryable interrupted attempt without changing its external ID.

Because any network timeout can leave the remote outcome uncertain, confirm the transaction in Wave before retrying an interrupted item. The stable external ID is retained for duplicate protection.

## Validation

SAT blocks export when any of these checks fail:

- Wave token or selected business is missing or changed from the frozen business;
- anchor or categorization account is missing or archived;
- date, amount, direction, description or external ID is invalid;
- line amounts do not balance to the anchor amount;
- a frozen source is excluded or was exported by a different item;
- a transfer or ordinary item has the wrong number of frozen sources.

Saving the preview re-resolves Wave account IDs by the frozen account names, allowing account discovery to be repaired without rebuilding or silently changing the source amounts and allocations.

## Out of scope

This implementation does **not** provide:

- automatic or scheduled export;
- tax handling;
- customer or vendor matching;
- receipt upload to Wave;
- changes to already-exported Wave transactions;
- deletion of Wave transactions;
- bulk API mutation;
- OFX export.
