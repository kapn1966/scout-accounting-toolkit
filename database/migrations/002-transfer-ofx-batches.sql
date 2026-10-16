-- Transfer-only OFX batches: upgrade a database created from the original 001-install.sql.
-- Fresh installations should use the complete schema in 001-install.sql and do not run this file.

ALTER TABLE inbox_reviews
  ADD COLUMN IF NOT EXISTS export_route varchar(32) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS transfer_ofx_batch_id bigint(20) unsigned DEFAULT NULL;

CREATE TABLE IF NOT EXISTS sat_transfer_ofx_batches (
  batch_id      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_status  varchar(20) NOT NULL DEFAULT 'generated',
  created_by    bigint(20) unsigned DEFAULT NULL,
  created_at    datetime NOT NULL DEFAULT current_timestamp(),
  generated_at  datetime DEFAULT NULL,
  confirmed_by  bigint(20) unsigned DEFAULT NULL,
  confirmed_at  datetime DEFAULT NULL,
  cancelled_by  bigint(20) unsigned DEFAULT NULL,
  cancelled_at  datetime DEFAULT NULL,
  PRIMARY KEY (batch_id),
  KEY idx_sat_transfer_batch_status (batch_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_transfer_ofx_batch_matches (
  batch_match_id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id                 bigint(20) unsigned NOT NULL,
  match_id                 bigint(20) unsigned NOT NULL,
  source_observation_id    bigint(20) unsigned NOT NULL,
  destination_observation_id bigint(20) unsigned NOT NULL,
  source_label             varchar(255) NOT NULL,
  destination_label        varchar(255) NOT NULL,
  amount                   decimal(10,2) NOT NULL,
  currency                 char(3) NOT NULL,
  source_date              date NOT NULL,
  destination_date         date NOT NULL,
  source_description       varchar(255) DEFAULT NULL,
  destination_description  varchar(255) DEFAULT NULL,
  created_at               datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (batch_match_id),
  UNIQUE KEY uq_sat_transfer_batch_match (batch_id, match_id),
  KEY idx_sat_transfer_match_batch (batch_id),
  KEY idx_sat_transfer_match_observations (source_observation_id, destination_observation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_transfer_ofx_legs (
  batch_leg_id       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_match_id     bigint(20) unsigned NOT NULL,
  observation_id     bigint(20) unsigned NOT NULL,
  source_system      varchar(32) NOT NULL,
  source_key         varchar(255) NOT NULL,
  account_id         varchar(255) NOT NULL,
  account_label      varchar(255) NOT NULL,
  account_type       varchar(20) NOT NULL,
  bank_id            varchar(64) DEFAULT NULL,
  branch_id          varchar(64) DEFAULT NULL,
  bank_account_type  varchar(32) DEFAULT NULL,
  currency           char(3) NOT NULL,
  transaction_date   date NOT NULL,
  amount             decimal(10,2) NOT NULL,
  fitid              varchar(24) NOT NULL,
  name               varchar(32) NOT NULL,
  memo               varchar(255) NOT NULL,
  created_at         datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (batch_leg_id),
  UNIQUE KEY uq_sat_transfer_match_observation (batch_match_id, observation_id),
  UNIQUE KEY uq_sat_transfer_batch_fitid (batch_match_id, fitid),
  KEY idx_sat_transfer_leg_account (account_id, transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sat_transfer_ofx_batch_files (
  batch_file_id     bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id          bigint(20) unsigned NOT NULL,
  account_id        varchar(255) NOT NULL,
  source_system     varchar(32) NOT NULL,
  filename          varchar(255) NOT NULL,
  transaction_count int(10) unsigned NOT NULL,
  created_at        datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (batch_file_id),
  UNIQUE KEY uq_sat_transfer_batch_account (batch_id, account_id),
  UNIQUE KEY uq_sat_transfer_batch_filename (batch_id, filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A reservation is present for every generated or confirmed batch leg.
-- It is deleted only when a generated batch is cancelled.
CREATE TABLE IF NOT EXISTS sat_transfer_ofx_reservations (
  observation_id bigint(20) unsigned NOT NULL,
  batch_id       bigint(20) unsigned NOT NULL,
  reserved_at    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (observation_id),
  KEY idx_sat_transfer_reservation_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
