-- Scout Accounting Toolkit: widen stored Wave transaction IDs.
-- Run once on existing installations after migrations 001-003. Safe to run
-- more than once. Back up the database first.

ALTER TABLE inbox_observations
  MODIFY COLUMN wave_transaction_id varchar(255) DEFAULT NULL;

ALTER TABLE inbox_reviews
  MODIFY COLUMN wave_transaction_id varchar(255) DEFAULT NULL;

ALTER TABLE sat_wave_export_items
  MODIFY COLUMN wave_transaction_id varchar(255) DEFAULT NULL;

ALTER TABLE sat_wave_export_attempts
  MODIFY COLUMN wave_transaction_id varchar(255) DEFAULT NULL;
