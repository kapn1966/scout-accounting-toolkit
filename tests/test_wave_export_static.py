#!/usr/bin/env python3
"""Static acceptance checks for the unified SAT Wave export implementation."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wordpress/scout-accounting-toolkit"
EXPORT = (PLUGIN / "includes/class-sat-wave-export.php").read_text()
WAVE = (PLUGIN / "includes/class-sat-wave.php").read_text()
INBOX = (PLUGIN / "includes/class-sat-inbox.php").read_text()
BOOT = (PLUGIN / "scout-accounting-toolkit.php").read_text()
DB = (PLUGIN / "includes/class-sat-db.php").read_text()
JS = (PLUGIN / "assets/inbox.js").read_text()
INSTALL = (ROOT / "database/migrations/001-install.sql").read_text()
DOC = (ROOT / "docs/WAVE_EXPORT.md").read_text().lower()
TRANSFER = (PLUGIN / "includes/class-sat-transfer-ofx.php").read_text()
TRANSFER_MIGRATION = (ROOT / "database/migrations/002-transfer-ofx-batches.sql").read_text()

assert "Version: " in BOOT and "SAT_VERSION" in BOOT
assert "payment_message" in EXPORT and "claim_purpose" in EXPORT
assert "'notes' =>" in EXPORT
assert "action_discard" in EXPORT
assert "class SAT_Wave_Export" in EXPORT
assert "SAT_Wave_Export::instance()" in BOOT
assert "class-sat-transfer-ofx.php" in BOOT
assert "SAT_Transfer_OFX::instance()" in BOOT
assert "SAT_Transfer_OFX::instance()" in INBOX
assert "[sat_inbox]" in (ROOT / "README.md").read_text()
assert [p.name for p in (ROOT / "wordpress").iterdir() if p.is_dir()] == ["scout-accounting-toolkit"]

# Unified implementation only: no legacy classes, constants, options or action names.
for forbidden in ("403to", "TO403", "GF_Wave", "GFWAVE", "TO403_PAYMENTS"):
    assert forbidden not in EXPORT, forbidden

# The authoritative fresh-install schema contains all persistent workflow tables.
tables = (
    "sat_wave_export_batches",
    "sat_wave_export_items",
    "sat_wave_export_sources",
    "sat_wave_export_lines",
    "sat_wave_export_attempts",
    "sat_transfer_ofx_batches",
    "sat_transfer_ofx_batch_matches",
    "sat_transfer_ofx_legs",
    "sat_transfer_ofx_batch_files",
    "sat_transfer_ofx_reservations",
)
for table in tables:
    assert table in INSTALL, table
    assert table in DB, table
for table in tables[5:]:
    assert table in TRANSFER_MIGRATION, table
assert "UNIQUE KEY uq_sat_wave_external_id" in INSTALL
assert "UNIQUE KEY uq_sat_wave_source_observation" in INSTALL
assert "wave_external_id" in INSTALL and "last_export_error" in INSTALL

# Workflow and safety markers.
assert "Ready for Wave" in INBOX
assert "'transfers' => 'Transfers to Wave'" in INBOX
assert "matched_transfer_observation_ids" in INBOX
assert "serialize_statement" in TRANSFER
assert "ZipArchive" in TRANSFER
assert 'class="check" name="selected_matches[]"' in TRANSFER
assert "sat-transfer-batch-%d-account-%02d.ofx" in TRANSFER
assert "export_route" in INSTALL and "transfer_ofx_batch_id" in INSTALL
assert "render_selection_form" in INBOX
assert "transfer_plan" in EXPORT
assert "'kind' => 'transfer'" in EXPORT
assert "Matched transfers cannot be sent through the Wave API." in TRANSFER
assert "is_eligible_transfer_observation" not in EXPORT + TRANSFER
# M1/M2: both preview creation (ready_source) and export validation (validate_item) use the shared block rule,
# which checks reservations and every accepted payment-account match.
assert EXPORT.count("SAT_Transfer_OFX::instance()->api_block_reason(") == 2
assert "SELECT 1 FROM sat_transfer_ofx_reservations WHERE observation_id = ?" in TRANSFER
assert "Both sides of a matched transfer are included" not in EXPORT
assert "render_attention($classified['attention'])" in TRANSFER
# M3: transfer exclusions are computed for every view, not only Ready for Wave.
assert "$transfer_ids = SAT_Transfer_OFX::instance()->matched_transfer_observation_ids();" in INBOX
assert "SAT_Transfer_OFX::inbox_placement(" in INBOX
# M4/M5: explicit cancellation safety and full batch contents.
assert "empty($_POST['cancel_safe'])" in TRANSFER and 'name="cancel_safe"' in TRANSFER
assert "leave it Generated" in TRANSFER
assert "both account files" not in TRANSFER and "all account files" in TRANSFER
assert "<th>Source account</th><th>Destination account</th><th>Amount</th><th>Currency</th>" in TRANSFER
# L2: temporary ZIP files are cleaned up at shutdown as well as immediately.
assert "self::register_temporary_cleanup($temporary);" in TRANSFER and "register_shutdown_function" in TRANSFER
# Batch file counts come from the complete set of frozen legs, and download uses the tested bundle builder.
assert "foreach (self::account_file_plan($batch_id, $frozen_legs) as $file)" in TRANSFER
assert "$account_groups" not in TRANSFER
assert "return self::bundle_entries($batch, $files, $legs, $this->transfers($batch_id));" in TRANSFER
assert "'DECREASE' : 'INCREASE'" in EXPORT
assert "hash('sha256'" in EXPORT and "'SAT-'" in EXPORT
assert "recover_stale_exports" in EXPORT
assert "record_failure" in EXPORT and "record_success" in EXPORT
assert "Download preview CSV" in EXPORT and "Download result CSV" in EXPORT
assert "sat-wave-preview-edit" in EXPORT and "sat-wave-export-one" in EXPORT
assert "Save descriptions and notes" in EXPORT
assert "sat_wave_export_attempts" in EXPORT
assert "form.matches('#sat-bulk-form')" in JS

# Line directions follow Wave's debit/credit semantics, not the amount sign; Interac names the payer.
assert "self::balancing_line_balance($anchor ?? [], $direction, $account ?? [])" in EXPORT
assert "$is_income ? 'INCREASE' : 'DECREASE'" not in EXPORT
assert "array_push($errors, ...$this->double_entry_check($item, $lines));" in EXPORT
assert "One-sided transaction" in EXPORT
assert "$part = $this->interac_purpose($evidence);" in EXPORT
assert "## accounting direction" in DOC

# Exactly one singular Wave mutation path; no bulk/update/delete/scheduled path.
assert WAVE.count("moneyTransactionCreate(input: $input)") == 1
assert EXPORT.count("create_money_transaction(") == 1
for forbidden in (
    "moneyTransactionsCreate",
    "moneyTransactionPatch",
    "moneyTransactionDelete",
    "wp_schedule_event",
    "wp_schedule_single_event",
    "customerId",
    "vendorId",
    "'taxes'",
    "'receipt'",
):
    assert forbidden not in EXPORT + WAVE, forbidden

# Explicit scope exclusions are documented.
for excluded in (
    "scheduled export",
    "tax handling",
    "customer or vendor matching",
    "receipt upload to wave",
    "bulk api mutation",
):
    assert excluded in DOC, excluded
assert "transfers to wave" in DOC
assert "account-specific ofx file" in DOC

print("Unified SAT Wave export static acceptance checks: OK")
