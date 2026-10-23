-- PARISHHUB — Migration: Add outside_priest_name for visiting/guest priests
-- Allows parishioners/guests to request a visiting priest ("dili atoa nga pari")
-- for Marriage, Funeral, and Baptism services, automatically titled with "Fr.".

BEGIN;

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS outside_priest_name TEXT DEFAULT NULL;
ALTER TABLE appointments ALTER COLUMN outside_priest_name TYPE TEXT;
ALTER TABLE appointment_drafts ADD COLUMN IF NOT EXISTS outside_priest_name TEXT DEFAULT NULL;
ALTER TABLE appointment_drafts ALTER COLUMN outside_priest_name TYPE TEXT;

COMMIT;
