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

The default description comes from the bank transaction. The default item note is deliberately complementary: it identifies supporting Gravity Forms claims, Interac payer/section evidence, or the OFX FITID. Split line notes preserve reviewer-entered allocation notes or identify the split position.

Wave's standard money-transaction mutation has one transaction description but no general-purpose line-note field. SAT therefore appends the editable item note to the Wave description. Line notes remain in SAT batch history and both CSV files.

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
