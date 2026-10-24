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
--
-- INSTRUCTIONS:
--   1. Take a full Supabase backup before running (Dashboard > Project Settings > Database).
--   2. Run Section 0 (READ-ONLY counts) first and review the numbers.
--   3. Run Sections 1-5 inside the transaction if counts look correct.
--   4. Check the final SELECT counts at the end.
--   5. COMMIT if correct, or ROLLBACK to undo everything.
--
-- SUPABASE STORAGE NOTE:
--   This script deletes uploaded_documents metadata rows only.
--   Physical files in the 'parish-documents' bucket are NOT auto-deleted by SQL.
--   After committing, manually delete orphaned files via:
--     Supabase Dashboard > Storage > parish-documents > delete files
--     matching the deleted document rows' file paths.
-- =============================================================================


-- =============================================================================
-- SECTION 0: DRY-RUN PREVIEW (Read-only, safe to run anytime)
-- Review these counts BEFORE running the DELETE sections below.
-- =============================================================================

-- How many appointments will be removed?
SELECT
    st.status_name,
    COUNT(*) AS appointment_count
FROM appointments a
JOIN appointment_status st ON a.status_id = st.status_id
WHERE a.status_id IN (3, 7)  -- 3 = Rejected, 7 = Cancelled
GROUP BY st.status_name;

-- How many are confirmed test appointments (known dummy data)?
SELECT COUNT(*) AS known_test_appointments
FROM appointments
WHERE guest_name IN ('Runtime Test Guest', 'Test Guest', 'Demo User', 'Test User')
   OR guest_email LIKE '%@example.test'
   OR guest_email LIKE '%@test.com'
   OR guest_reference LIKE 'PH-TEST%';

-- How many expired booking drafts exist?
SELECT 'wedding_booking_drafts expired' AS label, COUNT(*) AS cnt
FROM wedding_booking_drafts WHERE expires_at < NOW()
UNION ALL
SELECT 'baptism_booking_drafts expired', COUNT(*)
FROM baptism_booking_drafts WHERE expires_at < NOW();

-- Which appointments will be deleted? (review list before proceeding)
SELECT
    a.appointment_id,
    a.guest_reference,
    COALESCE(a.guest_name, u.firstname || ' ' || u.lastname) AS requester,
    s.service_name,
    s.category,
    st.status_name,
    a.created_at,
    a.appointment_date
FROM appointments a
JOIN services s ON a.service_id = s.service_id
JOIN appointment_status st ON a.status_id = st.status_id
LEFT JOIN parishioners par ON a.parishioner_id = par.parishioner_id
LEFT JOIN users u ON par.user_id = u.user_id
WHERE a.status_id IN (3, 7)
   OR a.guest_name IN ('Runtime Test Guest', 'Test Guest', 'Demo User', 'Test User')
   OR a.guest_email LIKE '%@example.test'
ORDER BY a.created_at ASC;


-- =============================================================================
-- ACTUAL CLEANUP — WRAPPED IN TRANSACTION
-- Review Section 0 output first! Only proceed if counts look right.
-- =============================================================================

BEGIN;

-- SAFETY GUARD: Never delete appointments with verified payments
-- This CTE collects appointment IDs that are safe to delete.
WITH safe_to_delete AS (
    SELECT a.appointment_id
    FROM appointments a
    WHERE
        -- Target: Cancelled or Rejected
        a.status_id IN (3, 7)
        -- EXCLUDE any that have a verified payment (paid records are kept)
        AND a.appointment_id NOT IN (
            SELECT DISTINCT appointment_id FROM payments
            WHERE payment_status = 'verified'
        )
        -- EXCLUDE any that are Completed (status_id = 6)
        AND a.status_id != 6

    UNION

    -- Target: Known synthetic test appointments by fingerprint
    SELECT a.appointment_id
    FROM appointments a
    WHERE (
        a.guest_name IN ('Runtime Test Guest', 'Test Guest', 'Demo User', 'Test User')
        OR a.guest_email LIKE '%@example.test'
        OR (a.guest_reference = 'PH-7A02B8' AND a.guest_name = 'Runtime Test Guest')
    )
    AND a.appointment_id NOT IN (
        SELECT DISTINCT appointment_id FROM payments WHERE payment_status = 'verified'
    )
)

-- STEP 1: Preview what will be deleted (still inside transaction, safe)
SELECT
    a.appointment_id,
    a.guest_reference,
    COALESCE(a.guest_name, 'Parishioner') AS name,
    s.category,
    st.status_name
FROM appointments a
JOIN services s ON a.service_id = s.service_id
JOIN appointment_status st ON a.status_id = st.status_id
WHERE a.appointment_id IN (SELECT appointment_id FROM safe_to_delete)
ORDER BY a.appointment_id;


-- STEP 2: Delete expired booking drafts (standalone tables, no FK cascade needed)
DELETE FROM wedding_booking_drafts
WHERE expires_at < NOW()
  AND appointment_id IS NULL;  -- Only fully-unlinked expired drafts

DELETE FROM baptism_booking_drafts
WHERE expires_at < NOW()
  AND appointment_id IS NULL;


-- STEP 3: Delete notifications for appointments being removed
-- (has FK: appointment_id ON DELETE SET NULL, but safe to clean up explicitly)
DELETE FROM notifications
WHERE appointment_id IN (
    SELECT a.appointment_id FROM appointments a
    WHERE a.status_id IN (3, 7)
      AND a.appointment_id NOT IN (
          SELECT DISTINCT appointment_id FROM payments WHERE payment_status = 'verified'
      )
);


-- STEP 4: Delete the appointments themselves
-- Cascade will auto-delete:
--   uploaded_documents, payments (unverified), issued_certificates,
--   generated_baptism_forms, generated_wedding_forms, generated_funeral_forms,
--   mass_intentions, official_receipts, notifications, precana_requirements
DELETE FROM appointments
WHERE status_id IN (3, 7)
  AND appointment_id NOT IN (
      SELECT DISTINCT appointment_id FROM payments WHERE payment_status = 'verified'
  );

-- Also delete the confirmed runtime-test appointment
DELETE FROM appointments
WHERE guest_name = 'Runtime Test Guest'
  AND guest_email = 'runtime-test@example.test'
  AND appointment_id NOT IN (
      SELECT DISTINCT appointment_id FROM payments WHERE payment_status = 'verified'
  );


-- STEP 5: Clean up orphaned activity logs (optional — comment out if you want to keep full history)
-- DELETE FROM activity_logs
-- WHERE created_at < NOW() - INTERVAL '90 days';


-- STEP 6: Verify the result
SELECT 'Remaining appointments by status' AS label;
SELECT st.status_name, COUNT(*) AS remaining
FROM appointments a
JOIN appointment_status st ON a.status_id = st.status_id
GROUP BY st.status_name
ORDER BY st.status_name;

SELECT 'Remaining wedding drafts (all)' AS label, COUNT(*) FROM wedding_booking_drafts;
SELECT 'Remaining baptism drafts (all)' AS label, COUNT(*) FROM baptism_booking_drafts;
SELECT 'Remaining uploaded_documents' AS label, COUNT(*) FROM uploaded_documents;


-- =============================================================================
-- COMMIT or ROLLBACK
-- If the SELECT above shows expected counts, run COMMIT.
-- If anything looks wrong, run ROLLBACK instead.
-- =============================================================================
-- COMMIT;
-- ROLLBACK;
