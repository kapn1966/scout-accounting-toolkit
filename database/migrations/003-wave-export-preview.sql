-- Scout Accounting Toolkit 1.1.0: persistent Wave export preview batches.
-- Run once when upgrading from 1.0.0. Safe to run more than once on the
-- supported MariaDB/MySQL versions. Back up the database first.

ALTER TABLE inbox_reviews
  ADD COLUMN IF NOT EXISTS wave_external_id varchar(100) DEFAULT NULL AFTER wave_transaction_id,
  ADD COLUMN IF NOT EXISTS last_export_error text DEFAULT NULL AFTER wave_external_id;

CREATE TABLE IF NOT EXISTS sat_wave_export_batches (
  batch_id       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_uuid     varchar(64) NOT NULL,
  batch_status   varchar(20) NOT NULL DEFAULT 'preview',
  item_count     int(10) unsigned NOT NULL DEFAULT 0,
  exported_count int(10) unsigned NOT NULL DEFAULT 0,
  failed_count   int(10) unsigned NOT NULL DEFAULT 0,
  created_by     bigint(20) unsigned DEFAULT NULL,
  created_at     datetime NOT NULL DEFAULT current_timestamp(),
  updated_at     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  completed_at   datetime DEFAULT NULL,
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
  wave_transaction_id    varchar(100) DEFAULT NULL,
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
  attempt_id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id            bigint(20) unsigned NOT NULL,
  attempt_number     int(10) unsigned NOT NULL,
  attempt_status     varchar(20) NOT NULL DEFAULT 'started',
  external_id        varchar(100) NOT NULL,
  wave_transaction_id varchar(100) DEFAULT NULL,
  error_message      text DEFAULT NULL,
  started_by         bigint(20) unsigned DEFAULT NULL,
  started_at         datetime NOT NULL DEFAULT current_timestamp(),
  finished_at        datetime DEFAULT NULL,
  PRIMARY KEY (attempt_id),
  UNIQUE KEY uq_sat_wave_attempt_number (item_id, attempt_number),
  KEY idx_sat_wave_attempt_status (attempt_status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
