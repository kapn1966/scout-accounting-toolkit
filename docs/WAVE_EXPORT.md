# Wave export operations

SAT prepares reviewed transactions as persistent Wave previews. Ordinary
transactions are sent individually; accepted transfers use the separate OFX
route. SAT has no scheduled export and never submits a bulk Wave mutation.
The workflow does not provide tax handling, customer or vendor matching, or
receipt upload to Wave; SAT does not use bulk API mutation.

## Ordinary preview workflow

1. In `[sat_inbox]`, open **Ready for Wave**, select reviewed transactions and
   create a Wave preview.
2. Open **Wave export** and review the frozen items and validation results.
   Descriptions and notes can be edited; save to revalidate before exporting.
3. Download the preview CSV for review or recordkeeping.
4. Use **Export one** to send one item, or **Send all ready items to Wave** to
   send unexported items sequentially in preview order.
5. Download the result CSV to retain the item and attempt outcomes.

Creating a preview freezes its business, sources, accounts, amounts,
allocations, descriptions, notes and external IDs. A transaction in a preview
is removed from Ready for Wave and cannot be added to another preview. Continue
it from the existing preview; discard an untouched preview to release its
transactions back to Ready.

### Export one and Send all

**Export one** sends a single item through the ordinary export path. **Send
all** repeats that same operation one item at a time; it is not atomic across
requests.

- Already-exported items are skipped.
- Processing stops at the first validation failure, concurrent export, Wave
  error or uncertain response. Later items are not attempted.
- Items successfully exported before a failure remain exported.
- A later Send all resumes with the remaining items; use **Retry one** for
  targeted recovery.
- Retries reuse the item's deterministic external ID, but that does not prove
  whether an uncertain request reached Wave. Check Wave before retrying to
  avoid creating a duplicate.

### Discard and retry safety

Discard is available only before any export attempt. Once an item has been
attempted, retain the batch as an audit record and repair/retry it in place.
Do not delete failed attempts or recreate an attempted item simply to clear its
history. If an item remains in `exporting` after an interruption, SAT can mark
it retryable after the stale-attempt interval; the remote outcome is still
uncertain, so check Wave first.

Save, discard and export initiation serialize on the same preview lock. SAT
records the attempt before the remote request. Cleanup/reset also refuse
export-owned sources and their matched evidence.

## Descriptions, notes and claim corrections

Defaults are based on the richest available evidence: Interac payer and
payment message for income, effective claim description and payee for claim
payments, a transfer description for transfer context, or cleaned bank/card
text otherwise. Notes provide supporting evidence such as claim, payer,
reference, FITID and SAT observation details.

The defaults are copied into the preview when it is created. Later changes to
source data do not silently update frozen preview text. A correction to a
matched claim's payee or description after an unexported preview is created is
flagged; the preview text stays frozen. If no export has been attempted,
discard and recreate the preview or edit its description. Attempted and
completed previews are not rewritten.

Claim corrections do not change the bank transaction, its categories or its
Wave amount. An amount correction that makes a claim disagree with its matched
transaction is a review issue, not an instruction for SAT to rebalance the
transaction. Review any discrepancy before export.

## Transfer routing and OFX

Do not submit accepted transfers through the Wave API. Accepted matches between
configured payment accounts are blocked from ordinary API previews, even when
they fail transfer OFX eligibility. Ineligible pairs appear under **Needs
attention** in **Transfers to Wave** with a reason; do not route them through
ordinary export.

For eligible transfers:

1. In **Transfers to Wave**, create a batch. SAT freezes the selected pairs and
   creates one account-specific OFX file (an OFX statement) for each affected
   account in a ZIP with a manifest.
2. Import each statement into its corresponding Wave account.
3. Match both sides of each transfer in Wave.
4. Confirm the batch only after every file has been imported and every transfer
   in the batch has been matched.

Partial imports may be completed later; leave the batch generated until all
transfers are imported and matched. Re-downloads use the frozen batch. Cancel
only if nothing from the batch was imported, or after every imported
transaction from it has been deleted from Wave. Cancellation releases the
observations for future action; importing a cancelled batch again can duplicate
accounting activity. Confirmed and cancelled batches remain in SAT's audit
history.

## Validation failures and recovery

Before export, SAT validates that the selected business and token are present,
the frozen accounts still resolve to active Wave accounts, source status and
ownership remain valid, dates and amounts are valid, allocations balance, and
the Wave accounting directions produce both debits and credits.

If validation fails:

- Read the item-specific reason and correct the underlying issue, such as
  discovering the current Wave accounts or repairing a preview description.
- Save the preview and confirm validation passes before exporting.
- For an accounting-direction issue on a pending or failed item, save and
  revalidate to recalculate line directions. This does not change the amount,
  accounts, anchor direction, external ID or attempt history.
- Do not remove source records or attempt history to bypass validation.

For a failed or interrupted remote request, inspect Wave for the transaction
before retrying. A timeout or local error can occur after Wave accepted the
request. Preserve the existing preview and external ID during recovery.

## Accounting direction

SAT preserves the anchor direction from the bank/card transaction sign, then
chooses each category line's direction using its Wave account type. `DEPOSIT`
is a debit on an asset anchor and a credit on a credit-card liability anchor;
`WITHDRAWAL` is the opposite. A category `INCREASE` follows the account type's
normal side (debit for expense, credit for income), and `DECREASE` is the
opposite. The amount sign alone does not determine the category direction.
Typical examples:

| Transaction | Anchor direction | Category line |
|---|---|---|
| Bank deposit to income | `DEPOSIT` | Income `INCREASE` |
| Bank withdrawal to expense | `WITHDRAWAL` | Expense `INCREASE` |
| Credit-card purchase to expense | `DEPOSIT` | Expense `INCREASE` |
| Credit-card refund to expense | `WITHDRAWAL` | Expense `DECREASE` |
| Bank deposit refunding an expense | `DEPOSIT` | Expense `DECREASE` |

Split lines are evaluated independently. If a Wave account's type is unknown or
changed, rediscover accounts and revalidate. Never change amounts to make a
direction check pass; confirm the accounting treatment and categories first.
