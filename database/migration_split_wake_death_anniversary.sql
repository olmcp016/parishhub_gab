-- Split "Wake Mass / Death Anniversary" into two services (PostgreSQL / Supabase).
-- Run in order. Both services keep category 'Wake', so booking, scheduling and
-- fee rules behave the same for each. Safe to re-run.

-- 1. Rename the existing row to the wake-only service.
UPDATE services
SET service_name = 'Wake Mass',
    description  = 'Mass offered for the deceased during the wake, for the repose of their soul.'
WHERE category = 'Wake'
  AND service_name = 'Wake Mass / Death Anniversary';

-- 2. Add the new Death Anniversary service (skipped if it already exists).
INSERT INTO services (service_name, category, description, fee, requirements, duration_minutes, is_active)
SELECT 'Death Anniversary',
       'Wake',
       'Mass offered on the anniversary of the death of a loved one, at the church or at the cemetery.',
       1500.00,
       NULL,
       60,
       TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE service_name = 'Death Anniversary'
);
