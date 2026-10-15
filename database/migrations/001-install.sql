-- Scout Accounting Toolkit 1.1.0: fresh installation.
-- Creates every table the plugin and the Interac importer use.
-- Run once against an empty database, e.g.:
--   mysql scout_payments < database/migrations/001-install.sql

CREATE TABLE IF NOT EXISTS processor_state (
  state_key   varchar(50)  NOT NULL,
  state_value varchar(255) NOT NULL,
  PRIMARY KEY (state_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO processor_state (state_key, state_value) VALUES ('last_processed_uid', '0');

CREATE TABLE IF NOT EXISTS inbox_import_batches (
  batch_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_system   varchar(32)  NOT NULL,
  source_filename varchar(255) DEFAULT NULL,
  rows_imported   int(10) unsigned NOT NULL DEFAULT 0,
  rows_rejected   int(10) unsigned NOT NULL DEFAULT 0,
  notes           text DEFAULT NULL,
  imported_at     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (batch_id),
  KEY idx_batch_source (source_system),
  KEY idx_batch_imported (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS inbox_observations (
  observation_id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  import_batch_id           bigint(20) unsigned DEFAULT NULL,
  source_system             varchar(32)  NOT NULL,
  source_key                varchar(255) NOT NULL,
  observation_status        varchar(20)  NOT NULL DEFAULT 'new',
  transaction_date          date DEFAULT NULL,
  amount                    decimal(10,2) DEFAULT NULL,
  direction                 varchar(8) DEFAULT NULL,
  description               text DEFAULT NULL,
  payments_reference_number varchar(32) DEFAULT NULL,
  gf_entry_id               bigint(20) unsigned DEFAULT NULL,
  wave_transaction_id       varchar(255) DEFAULT NULL,
  payload_json              longtext DEFAULT NULL,
  created_at                datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (observation_id),
  UNIQUE KEY uq_source (source_system, source_key),
  KEY idx_obs_date (transaction_date),
  KEY idx_obs_amount (amount),
  KEY idx_obs_payment (payments_reference_number),
  KEY idx_obs_gf (gf_entry_id),
  KEY idx_obs_wave (wave_transaction_id),
  KEY idx_obs_batch (import_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS inbox_matches (
  match_id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  left_observation_id  bigint(20) unsigned NOT NULL,
  right_observation_id bigint(20) unsigned NOT NULL,
  confidence           varchar(10) NOT NULL,
  created_at           datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (match_id),
  UNIQUE KEY uq_match (left_observation_id, right_observation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS inbox_allocations (
  allocation_id       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  bank_observation_id bigint(20) unsigned NOT NULL,
  line_number         int(10) unsigned NOT NULL,
  export_account      varchar(255) NOT NULL,
  amount              decimal(10,2) NOT NULL,
  allocation_note     varchar(255) DEFAULT NULL,
  created_by          bigint(20) unsigned DEFAULT NULL,
  created_at          datetime NOT NULL DEFAULT current_timestamp(),
  updated_at          datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (allocation_id),
  UNIQUE KEY uq_inbox_allocation_line (bank_observation_id, line_number),
  KEY idx_inbox_allocation_bank (bank_observation_id),
  CONSTRAINT chk_inbox_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS inbox_reviews (
  bank_observation_id bigint(20) unsigned NOT NULL,
  review_status       varchar(20) NOT NULL DEFAULT 'new',
  exclusion_reason    varchar(255) DEFAULT NULL,
  final_description   varchar(255) DEFAULT NULL,
  wave_transaction_id varchar(255) DEFAULT NULL,
  wave_external_id    varchar(100) DEFAULT NULL,
  last_export_error   text DEFAULT NULL,
  exported_at         datetime DEFAULT NULL,
  reviewed_by         bigint(20) unsigned DEFAULT NULL,
  reviewed_at         datetime DEFAULT NULL,
  created_at          datetime NOT NULL DEFAULT current_timestamp(),
  updated_at          datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (bank_observation_id),
  KEY idx_inbox_review_status (review_status),
  KEY idx_inbox_review_wave (wave_transaction_id),
  KEY idx_inbox_review_external (wave_external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_wave_export_batches (
  batch_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_uuid      varchar(64) NOT NULL,
  batch_status    varchar(20) NOT NULL DEFAULT 'preview',
  item_count      int(10) unsigned NOT NULL DEFAULT 0,
  exported_count  int(10) unsigned NOT NULL DEFAULT 0,
  failed_count    int(10) unsigned NOT NULL DEFAULT 0,
  created_by      bigint(20) unsigned DEFAULT NULL,
  created_at      datetime NOT NULL DEFAULT current_timestamp(),
  updated_at      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  completed_at    datetime DEFAULT NULL,
  PRIMARY KEY (batch_id),
  UNIQUE KEY uq_sat_wave_batch_uuid (batch_uuid),
  KEY idx_sat_wave_batch_status (batch_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_wave_export_items (
  item_id                bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id               bigint(20) unsigned NOT NULL,
  sort_order             int(10) unsigned NOT NULL,
  item_kind              varchar(20) NOT NULL DEFAULT 'transaction',
  source_observation_ids text NOT NULL,
  source_label           varchar(255) NOT NULL,
  wave_business_id       varchar(255) DEFAULT NULL,
  wave_business_name     varchar(255) DEFAULT NULL,
  transaction_date       date NOT NULL,
  anchor_source          varchar(32) NOT NULL,
  anchor_account_id      varchar(255) DEFAULT NULL,
  anchor_account_name    varchar(255) DEFAULT NULL,
  anchor_direction       varchar(16) NOT NULL,
  amount                 decimal(10,2) NOT NULL,
  description            varchar(255) NOT NULL,
  item_note              varchar(255) DEFAULT NULL,
  external_id            varchar(100) NOT NULL,
  item_status            varchar(20) NOT NULL DEFAULT 'pending',
  validation_errors      longtext DEFAULT NULL,
  wave_transaction_id    varchar(255) DEFAULT NULL,
  last_error             text DEFAULT NULL,
  attempt_count          int(10) unsigned NOT NULL DEFAULT 0,
  attempted_at           datetime DEFAULT NULL,
  exported_at            datetime DEFAULT NULL,
  created_at             datetime NOT NULL DEFAULT current_timestamp(),
  updated_at             datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (item_id),
  UNIQUE KEY uq_sat_wave_external_id (external_id),
  UNIQUE KEY uq_sat_wave_batch_order (batch_id, sort_order),
  KEY idx_sat_wave_item_batch (batch_id, item_status),
  KEY idx_sat_wave_transaction (wave_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_wave_export_sources (
  source_id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id          bigint(20) unsigned NOT NULL,
  observation_id   bigint(20) unsigned NOT NULL,
  source_system    varchar(32) NOT NULL,
  source_key       varchar(255) NOT NULL,
  transaction_date date DEFAULT NULL,
  amount           decimal(10,2) DEFAULT NULL,
  description      varchar(255) DEFAULT NULL,
  created_at       datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (source_id),
  UNIQUE KEY uq_sat_wave_source_observation (observation_id),
  KEY idx_sat_wave_source_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_wave_export_lines (
  line_id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id              bigint(20) unsigned NOT NULL,
  line_number          int(10) unsigned NOT NULL,
  source_allocation_id bigint(20) unsigned DEFAULT NULL,
  account_id           varchar(255) DEFAULT NULL,
  account_name         varchar(255) NOT NULL,
  amount               decimal(10,2) NOT NULL,
  balance_direction    varchar(16) NOT NULL DEFAULT 'INCREASE',
  line_note            varchar(255) DEFAULT NULL,
  created_at           datetime NOT NULL DEFAULT current_timestamp(),
  updated_at           datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (line_id),
  UNIQUE KEY uq_sat_wave_item_line (item_id, line_number),
  KEY idx_sat_wave_line_item (item_id),
  CONSTRAINT chk_sat_wave_line_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_wave_export_attempts (
  attempt_id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id             bigint(20) unsigned NOT NULL,
  attempt_number      int(10) unsigned NOT NULL,
  attempt_status      varchar(20) NOT NULL DEFAULT 'started',
  external_id         varchar(100) NOT NULL,
  wave_transaction_id varchar(255) DEFAULT NULL,
  error_message       text DEFAULT NULL,
  started_by          bigint(20) unsigned DEFAULT NULL,
  started_at          datetime NOT NULL DEFAULT current_timestamp(),
  finished_at         datetime DEFAULT NULL,
  PRIMARY KEY (attempt_id),
  UNIQUE KEY uq_sat_wave_attempt_number (item_id, attempt_number),
  KEY idx_sat_wave_attempt_status (attempt_status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Database users (adjust names and passwords):
--   WordPress plugin:
--     GRANT SELECT, INSERT, UPDATE, DELETE ON scout_payments.* TO 'sat_web'@'localhost';
--   Interac importer and weekly report:
--     GRANT SELECT, INSERT, UPDATE ON scout_payments.* TO 'sat_ingest'@'localhost';
