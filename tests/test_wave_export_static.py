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
UPGRADE = (ROOT / "database/migrations/003-wave-export-preview.sql").read_text()
DOC = (ROOT / "docs/WAVE_EXPORT.md").read_text().lower()

assert "Version: 1.1.1" in BOOT and "SAT_VERSION', '1.1.1" in BOOT
assert "payment_message" in EXPORT and "claim_purpose" in EXPORT
assert "'notes' =>" in EXPORT
assert "action_discard" in EXPORT
assert "class SAT_Wave_Export" in EXPORT
assert "SAT_Wave_Export::instance()" in BOOT
assert "[sat_inbox]" in (ROOT / "README.md").read_text()
assert [p.name for p in (ROOT / "wordpress").iterdir() if p.is_dir()] == ["scout-accounting-toolkit"]

# Unified implementation only: no legacy classes, constants, options or action names.
for forbidden in ("403to", "TO403", "GF_Wave", "GFWAVE", "TO403_PAYMENTS"):
    assert forbidden not in EXPORT, forbidden

# Persistent schema is present for fresh installs, upgrades and setup checks.
tables = (
    "sat_wave_export_batches",
    "sat_wave_export_items",
    "sat_wave_export_sources",
    "sat_wave_export_lines",
    "sat_wave_export_attempts",
)
for table in tables:
    assert table in INSTALL, table
    assert table in UPGRADE, table
    assert table in DB, table
assert "UNIQUE KEY uq_sat_wave_external_id" in INSTALL
assert "UNIQUE KEY uq_sat_wave_source_observation" in INSTALL
assert "wave_external_id" in INSTALL and "last_export_error" in INSTALL

# Workflow and safety markers.
assert "Ready for Wave" in INBOX
assert "render_selection_form" in INBOX
assert "transfer_plan" in EXPORT
assert "'kind' => 'transfer'" in EXPORT
assert "'DECREASE' : 'INCREASE'" in EXPORT
assert "hash('sha256'" in EXPORT and "'SAT-'" in EXPORT
assert "recover_stale_exports" in EXPORT
assert "record_failure" in EXPORT and "record_success" in EXPORT
assert "Download preview CSV" in EXPORT and "Download result CSV" in EXPORT
assert "sat-wave-preview-edit" in EXPORT and "sat-wave-export-one" in EXPORT
assert "Save descriptions and notes" in EXPORT
assert "sat_wave_export_attempts" in EXPORT
assert "form.matches('#sat-bulk-form')" in JS

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
    "ofx export",
):
    assert excluded in DOC, excluded

print("Unified SAT Wave export static acceptance checks: OK")
