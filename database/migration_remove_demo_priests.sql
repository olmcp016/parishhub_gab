-- Remove the two demo priests that were present in older parishhub_full.sql
-- dumps. This is intentionally narrow: all three identifiers must match.
BEGIN;

CREATE TEMP TABLE demo_priests_to_remove (
    priest_id INT PRIMARY KEY,
    user_id INT
) ON COMMIT DROP;

INSERT INTO demo_priests_to_remove (priest_id, user_id)
SELECT priest_id, user_id
FROM priests
WHERE (full_name = 'Fr. Antonio Villanueva'
       AND contact_number = '09201234567'
       AND email = 'frantonio@parishhub.local')
   OR (full_name = 'Fr. Michael Ramos'
       AND contact_number = '09211234567'
       AND email = 'frmichael@parishhub.local');

-- appointments.priest_id uses ON DELETE SET NULL, so historical appointment
-- rows remain intact without retaining these demo priest records.
DELETE FROM priests
WHERE priest_id IN (SELECT priest_id FROM demo_priests_to_remove);

-- Remove only the exact linked demo login accounts. Unrelated accounts are
-- never selected by this statement.
DELETE FROM users
WHERE user_id IN (SELECT user_id FROM demo_priests_to_remove WHERE user_id IS NOT NULL)
  AND email IN ('frantonio@parishhub.local', 'frmichael@parishhub.local');

COMMIT;
