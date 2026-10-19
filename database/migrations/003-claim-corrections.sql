-- Expense claim corrections: add the correction tables to a database created before they were
-- part of 001-install.sql. Fresh installations use 001-install.sql and do not run this file.
-- Creates two empty tables only; existing claims and payloads are not read or changed, and no
-- corrections are created. Safe to run again: existing tables are left as they are.

CREATE TABLE IF NOT EXISTS sat_observation_corrections (
  correction_id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  observation_id        bigint(20) unsigned NOT NULL,
  field_name            varchar(32) NOT NULL,
  corrected_value       varchar(255) NOT NULL,
  source_value          text NOT NULL,
  observed_source_value text NOT NULL,
  reason_code           varchar(32) NOT NULL DEFAULT 'other',
  source_state          varchar(16) NOT NULL DEFAULT 'clear',
  version               int(10) unsigned NOT NULL DEFAULT 1,
  created_by            bigint(20) unsigned DEFAULT NULL,
  created_at            datetime NOT NULL DEFAULT current_timestamp(),
  updated_by            bigint(20) unsigned DEFAULT NULL,
  updated_at            datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (correction_id),
  UNIQUE KEY uq_sat_correction_field (observation_id, field_name),
  KEY idx_sat_correction_state (source_state),
  CONSTRAINT chk_sat_correction_field CHECK (field_name IN ('transaction_date', 'payee', 'description', 'amount'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_observation_correction_events (
  event_id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  observation_id        bigint(20) unsigned NOT NULL,
  gf_entry_id           bigint(20) unsigned DEFAULT NULL,
  field_name            varchar(32) NOT NULL,
  event_type            varchar(32) NOT NULL,
  old_effective_value   text DEFAULT NULL,
  new_effective_value   text DEFAULT NULL,
  source_value          text DEFAULT NULL,
  captured_source_value text DEFAULT NULL,
  reason_code           varchar(32) DEFAULT NULL,
  created_by            bigint(20) unsigned DEFAULT NULL,
  created_at            datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (event_id),
  KEY idx_sat_correction_event_observation (observation_id, event_id),
  KEY idx_sat_correction_event_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
