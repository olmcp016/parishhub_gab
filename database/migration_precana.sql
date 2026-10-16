-- Migration: Pre-Cana seminar for Wedding bookings (includes/precana.php).
--
-- precana_type    'Regular' (second Saturday, 8:00 AM, no fee) | 'Special' (preferred schedule, PHP 2,500)
-- precana_date    seminar date        (separate from the Wedding Ceremony appointment_date)
-- precana_time    seminar time        (separate from appointment_time)
-- precana_status  'scheduled'            Regular: fixed parish session
--                 'pending_confirmation' Special: the couple's preferred date/time, awaiting the Parish Office
--                 'proposed'             Special: an alternative date/time proposed by the Parish Office (not yet confirmed)
--                 'confirmed'            Special: officially confirmed by the Parish Office
--
-- Adds nullable columns only. Existing rows keep NULL ("booked before Pre-Cana
-- options") and are not changed. Safe to run more than once. The seminar fee is
-- not stored here: it is frozen into appointments.fee_snapshot at PSS verification.

BEGIN;

ALTER TABLE appointment_drafts ADD COLUMN IF NOT EXISTS precana_type VARCHAR(10) DEFAULT NULL;
ALTER TABLE appointment_drafts ADD COLUMN IF NOT EXISTS precana_date DATE DEFAULT NULL;
ALTER TABLE appointment_drafts ADD COLUMN IF NOT EXISTS precana_time TIME DEFAULT NULL;
ALTER TABLE appointment_drafts ADD COLUMN IF NOT EXISTS precana_status VARCHAR(20) DEFAULT NULL;

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS precana_type VARCHAR(10) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS precana_date DATE DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS precana_time TIME DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS precana_status VARCHAR(20) DEFAULT NULL;

DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['appointment_drafts', 'appointments'] LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = ('public.' || t)::regclass AND conname = t || '_precana_type_check') THEN
            EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (precana_type IS NULL OR precana_type IN (''Regular'', ''Special''))', t, t || '_precana_type_check');
        END IF;
        -- Always (re)created, so the allowed list is current even where an earlier draft of this file ran.
        EXECUTE format('ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I', t, t || '_precana_status_check');
        EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (precana_status IS NULL OR precana_status IN (''scheduled'', ''pending_confirmation'', ''proposed'', ''confirmed''))', t, t || '_precana_status_check');
        -- A seminar type always comes with its schedule and status.
        IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = ('public.' || t)::regclass AND conname = t || '_precana_complete_check') THEN
            EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (precana_type IS NULL OR (precana_date IS NOT NULL AND precana_time IS NOT NULL AND precana_status IS NOT NULL))', t, t || '_precana_complete_check');
        END IF;
    END LOOP;
END $$;

COMMIT;

-- Verify (expect 8 rows):
-- SELECT table_name, column_name FROM information_schema.columns
-- WHERE table_name IN ('appointments', 'appointment_drafts') AND column_name LIKE 'precana_%' ORDER BY 1, 2;
--
-- Rollback (only if needed; loses any Pre-Cana choices saved meanwhile):
-- BEGIN;
-- ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_precana_type_check, DROP CONSTRAINT IF EXISTS appointments_precana_status_check, DROP CONSTRAINT IF EXISTS appointments_precana_complete_check;
-- ALTER TABLE appointments DROP COLUMN IF EXISTS precana_type, DROP COLUMN IF EXISTS precana_date, DROP COLUMN IF EXISTS precana_time, DROP COLUMN IF EXISTS precana_status;
-- ALTER TABLE appointment_drafts DROP CONSTRAINT IF EXISTS appointment_drafts_precana_type_check, DROP CONSTRAINT IF EXISTS appointment_drafts_precana_status_check, DROP CONSTRAINT IF EXISTS appointment_drafts_precana_complete_check;
-- ALTER TABLE appointment_drafts DROP COLUMN IF EXISTS precana_type, DROP COLUMN IF EXISTS precana_date, DROP COLUMN IF EXISTS precana_time, DROP COLUMN IF EXISTS precana_status;
-- COMMIT;
