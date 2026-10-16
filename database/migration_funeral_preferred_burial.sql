-- PARISHHUB — Funeral: family's preferred burial / Funeral Mass schedule.
--
-- Burial and Funeral Mass are one schedule:
--   preferred_burial_date / preferred_burial_time  the family's request (reference only)
--   appointment_date / appointment_time            the final schedule, set by the Secretary
-- Both columns are nullable; existing Funeral records show "Not provided".
-- The booking fields stay hidden until both columns exist.
-- Safe to run more than once.

BEGIN;

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS preferred_burial_date DATE DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS preferred_burial_time TIME DEFAULT NULL;

COMMIT;

-- Verification (expect 2 rows, both nullable):
-- SELECT column_name, data_type, is_nullable
--   FROM information_schema.columns
--  WHERE table_name = 'appointments'
--    AND column_name IN ('preferred_burial_date', 'preferred_burial_time');
--
-- Existing data untouched (expect 0 before any new Funeral booking):
-- SELECT COUNT(*) FROM appointments WHERE preferred_burial_date IS NOT NULL OR preferred_burial_time IS NOT NULL;

-- Rollback (removes the family's preferred schedule values; the confirmed schedule is unaffected):
-- BEGIN;
-- ALTER TABLE appointments DROP COLUMN IF EXISTS preferred_burial_time;
-- ALTER TABLE appointments DROP COLUMN IF EXISTS preferred_burial_date;
-- COMMIT;
