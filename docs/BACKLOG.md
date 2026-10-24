# Backlog

Future SAT product and technical work only. Operational accounting tasks,
completed acceptance work, and historical release verification do not belong
here.

## Later

- **Delegate selected Tools functions:** Allow the Treasurer role to use safe
  operational tools such as OFX upload while keeping destructive cleanup and
  reset operations restricted.

- **Pre-export reconciliation:** Explore a simple check that compares SAT's
  imported/exported position at a common statement date before large catch-up
  exports. Do not compare balances from different dates.

- **Review receipt OCR accuracy:** Evaluate extraction against sanitized
  examples before changing OCR rules.

- **Clarify SAT identity in WordPress admin:** Prefer full "Scout Accounting
  Toolkit" in navigation and add a concise shared description that SAT
  prepares, reviews and exports financial data but is not the accounting
  system. Validate the menu label at narrow sidebar widths.

- **Wave Send All live progress:** The current Send All request commits each
  item attempt/result while the batch is running, so read-only polling should
  be viable without changing the Wave mutation path. Add run identity and
  explicit terminal/stop state so progress can report accurate
  processed/total/succeeded/failed counts without confusing earlier attempts
  or enabling retries. Confirm concurrent polling works on the production
  hosting stack before implementation.

## Technical debt

- **Schema cleanup:** Review legacy `bank_observation_id`, `payments`, and
  `payment_allocations` names/tables before the next fresh-schema redesign.

- **Importer failure notifications:** Surface unattended importer failures so
  missing imports do not depend on someone noticing missing data.
