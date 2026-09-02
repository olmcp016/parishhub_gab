-- ==========================================================
-- PARISHHUB — Migration: Scheduling Upgrade
-- Run this ONCE against your EXISTING parishhub database via
-- phpMyAdmin's Import tab (or the SQL tab, paste and run).
-- It is safe — it only adds a new nullable column and does not
-- touch or delete any existing data.
-- ==========================================================
USE parishhub;

-- Add date_of_death, needed to compute the 9-day mourning period for
-- Funeral Mass bookings. Skipped automatically if it already exists.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointments'
      AND COLUMN_NAME = 'date_of_death'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE appointments ADD COLUMN date_of_death DATE DEFAULT NULL AFTER cancelled_reason',
    'SELECT "date_of_death column already exists — nothing to do." AS notice'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
