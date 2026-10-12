-- Upgrade an existing database (created by the separate 0.x plugins) to 1.0.0.
-- Back up first:  mysqldump scout_payments > scout_payments-before-1.0.sql
-- Safe to run more than once.

-- 1. The older duplicate of exclusion_reason. Copy anything in it, then drop it.
--    (Skip both statements if SHOW COLUMNS FROM inbox_reviews has no excluded_reason.)
UPDATE inbox_reviews SET exclusion_reason = excluded_reason
  WHERE exclusion_reason IS NULL AND excluded_reason IS NOT NULL;
ALTER TABLE inbox_reviews DROP COLUMN IF EXISTS excluded_reason;

-- 2. Make sure the importer has a starting position.
INSERT IGNORE INTO processor_state (state_key, state_value) VALUES ('last_processed_uid', '0');

-- 3. The legacy payments tables are no longer written or read by anything in 1.0.0.
--    Keep them until you are satisfied, then remove them:
-- DROP TABLE payment_allocations;
-- DROP TABLE payments;
