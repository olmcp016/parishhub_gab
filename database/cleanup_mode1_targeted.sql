-- =============================================================================
-- PARISHHUB — MODE 1: TARGETED CLEANUP (Safe, Selective Purge)
-- Target:  PostgreSQL / Supabase
-- Purpose: Remove cancelled, rejected, and confirmed test/obsolete data only.
--          All real/legitimate records (paid, completed, active) are preserved.
--
-- PROTECTED (NEVER TOUCHED):
--   users, roles, parishioners, priests, priest_unavailability
--   services, service_schedules, service_fee_rules
--   locations, settings, payment_methods, appointment_status
--   Any appointment with a verified payment or completed status
-- =============================================================================

BEGIN;

-- STEP 1: Create a temporary list of appointments that are safe to delete
-- Target: Cancelled (status 7), Rejected (status 3), and known test guest accounts
-- EXCLUDE: Any appointment that has a verified payment or is already completed (status 6)
CREATE TEMP TABLE temp_safe_appointments ON COMMIT DROP AS
SELECT a.appointment_id
FROM appointments a
WHERE (
    a.status_id IN (3, 7) -- 3 = Rejected, 7 = Cancelled
    OR a.guest_name IN ('Runtime Test Guest', 'Test Guest', 'Demo User', 'Test User')
    OR a.guest_email LIKE '%@example.test'
    OR a.guest_email LIKE '%@test.com'
    OR a.guest_reference LIKE 'PH-TEST%'
)
AND a.appointment_id NOT IN (
    SELECT DISTINCT appointment_id FROM payments WHERE payment_status = 'verified'
)
AND a.status_id != 6;

-- STEP 2: Safely delete expired booking drafts IF the tables exist
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'wedding_booking_drafts') THEN
        EXECUTE 'DELETE FROM wedding_booking_drafts WHERE expires_at < NOW() AND appointment_id IS NULL;';
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'baptism_booking_drafts') THEN
        EXECUTE 'DELETE FROM baptism_booking_drafts WHERE expires_at < NOW() AND appointment_id IS NULL;';
    END IF;
END $$;

-- STEP 3: Delete notifications linked to the target appointments
DELETE FROM notifications 
WHERE appointment_id IN (SELECT appointment_id FROM temp_safe_appointments);

-- STEP 4: Delete the target appointments
-- (Foreign Key CASCADE will automatically clean up associated uploaded_documents,
-- unverified payments, issued_certificates, generated forms, and donations)
DELETE FROM appointments 
WHERE appointment_id IN (SELECT appointment_id FROM temp_safe_appointments);

-- STEP 5: Verification check of remaining appointments
SELECT 
    st.status_name, 
    COUNT(*) AS remaining_appointments
FROM appointments a
JOIN appointment_status st ON a.status_id = st.status_id
GROUP BY st.status_name
ORDER BY st.status_name;

COMMIT;
