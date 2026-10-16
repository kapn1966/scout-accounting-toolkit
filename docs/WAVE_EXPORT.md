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
   - matched-transfer grouping for review only (transfers are handled separately; see below);
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

## Splits and transfer imports

A split remains one Wave transaction with multiple categorization lines whose amounts must equal the anchor amount.

Do not export accepted transfer matches through the API preview. Although SAT's preview can group both observations into one item, the public API cannot create the Wave transfer.

Transfers between Wave anchor accounts cannot be created through the public `moneyTransactionCreate` API. Wave's API documentation says its special handling for transfers between bank and credit-card accounts is not available through the API. SAT also tested using another anchor account as a line item with both `INCREASE` and `DECREASE` balance directions; Wave rejected both shapes. Creating the two sides as ordinary API transactions with temporary income and expense categories succeeded, but those categories had to be manually removed before Wave allowed the sides to be matched as a transfer. That is not an acceptable workflow.

The confirmed design is to generate one transfer-only OFX file per account. Each file contains that account's side as an uncategorized transaction; users import each file into its corresponding Wave account and then match the two transactions using Wave's normal transfer workflow. The generated mini-statements omit `LEDGERBAL`, which would otherwise copy a balance for the original full statement. FITIDs are deterministic and distinct per leg, and original posting dates and signed amounts are preserved.

A controlled proof succeeded for accepted match 103 between CG Funds and GC Visa: CAD 816.99, both sides dated October 6, 2026. The bank OFXSGML and credit-card OFX XML files imported uncategorized, and Wave matched them as a transfer. This developer-only proof handles one accepted match through WP-CLI; it performs no Wave API mutation or database write. The serializer/parser round-trip tests and focused PHP lint pass. The proof consists of `wordpress/scout-accounting-toolkit/includes/class-sat-ofx-writer.php`, `scripts/generate-transfer-ofx.php`, and `tests/test_ofx_writer.php`. This confirms the approach, not a finished user-facing or bulk export workflow.

## Idempotency and failures

External IDs are deterministic SHA-256-derived values based on the selected Wave business and canonical source keys. A unique database key prevents the same external ID from entering another batch, and each source observation can belong to only one preview item.

Before a remote call, SAT locks the item and records an attempt. Export item statuses are `pending`, `exporting`, `failed` and `exported`; batch statuses are `preview`, `partial`, `exporting` and `completed`. Success stores the Wave transaction ID on the item, attempt, reviews and source observations. Failure affects only that item, retains the error, and leaves the other batch items available.

A request left in `exporting` for more than 15 minutes is recovered as a retryable interrupted attempt without changing its external ID. On recovery, the item is marked `failed`, the attempt is marked `interrupted`, and the preview remains available to retry with the same external ID. Because any network timeout can leave the remote outcome uncertain, confirm the transaction in Wave before retrying an interrupted item. The stable external ID is retained for duplicate protection.

## Validation

SAT blocks export when any of these checks fail:

- Wave token or selected business is missing or changed from the frozen business;
- the anchor account or a categorization account is missing, archived, or no longer resolves to a current Wave account ID;
- the anchor account subtype does not match the bank/card source type (`CASH_AND_BANK` vs `CREDIT_CARD`);
- date, amount, direction, description or external ID is invalid;
- line amounts do not balance to the anchor amount, or a line has an invalid `INCREASE`/`DECREASE` direction;
- a frozen source is excluded, already marked exported with the same external ID (requiring reconciliation), or was exported by a different item;
- a transfer or ordinary item has the wrong number of frozen sources;
- a transfer item's two sources have the same `source_system`, or are not equal and opposite;
- the frozen source IDs no longer match the item’s saved `source_observation_ids`.

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
- user-facing or bulk transfer-OFX export (a developer-only single-match proof exists; see above).
