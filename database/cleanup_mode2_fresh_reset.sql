-- =============================================================================
-- PARISHHUB — MODE 2: CLEAN TRANSACTIONAL RESET (Fresh Start for New Workflows)
-- Target:  PostgreSQL / Supabase
-- Purpose: Wipes all test transactional data (appointments, payments, certificates,
--          documents, drafts, receipts, notifications) while keeping 100% of:
--          - users, roles, parishioners, priests, priest_unavailability
--          - services, service_schedules, service_fee_rules
--          - locations (chapels, barangays)
--          - settings, payment_methods, appointment_status
--
-- NOTE: Uses RESTART IDENTITY to reset auto-increment counters back to #1.
-- =============================================================================

BEGIN;

-- 1. Truncate all transactional tables safely using RESTART IDENTITY CASCADE
-- This guarantees Foreign Key dependencies are handled cleanly and resets IDs to 1.
TRUNCATE TABLE
    wedding_booking_drafts,
    baptism_booking_drafts,
    official_receipts,
    transactions,
    payments,
    issued_certificates,
    uploaded_documents,
    donations,
    mass_intentions,
    notifications,
    activity_logs,
    appointments
RESTART IDENTITY CASCADE;

-- Also truncate optional/specialized generated form tables if they exist
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'generated_wedding_forms') THEN
        EXECUTE 'TRUNCATE TABLE generated_wedding_forms RESTART IDENTITY CASCADE;';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'generated_funeral_forms') THEN
        EXECUTE 'TRUNCATE TABLE generated_funeral_forms RESTART IDENTITY CASCADE;';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'generated_forms') THEN
        EXECUTE 'TRUNCATE TABLE generated_forms RESTART IDENTITY CASCADE;';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'precana_requirements') THEN
        EXECUTE 'TRUNCATE TABLE precana_requirements RESTART IDENTITY CASCADE;';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'chat_messages') THEN
        EXECUTE 'TRUNCATE TABLE chat_messages RESTART IDENTITY CASCADE;';
    END IF;
END $$;

-- 2. Verify all master data remains 100% intact
SELECT 'VERIFICATION: Master & User Records Preserved' AS status;
SELECT 'users' AS table_name, COUNT(*) AS count FROM users
UNION ALL
SELECT 'parishioners', COUNT(*) FROM parishioners
UNION ALL
SELECT 'priests', COUNT(*) FROM priests
UNION ALL
SELECT 'services', COUNT(*) FROM services
UNION ALL
SELECT 'service_schedules', COUNT(*) FROM service_schedules
UNION ALL
SELECT 'service_fee_rules', COUNT(*) FROM service_fee_rules
UNION ALL
SELECT 'locations', COUNT(*) FROM locations
UNION ALL
SELECT 'settings', COUNT(*) FROM settings;

-- 3. Verify transactional tables are completely reset to 0
SELECT 'VERIFICATION: Transactional Records Cleaned' AS status;
SELECT 'appointments' AS table_name, COUNT(*) AS count FROM appointments
UNION ALL
SELECT 'payments', COUNT(*) FROM payments
UNION ALL
SELECT 'issued_certificates', COUNT(*) FROM issued_certificates
UNION ALL
SELECT 'uploaded_documents', COUNT(*) FROM uploaded_documents
UNION ALL
SELECT 'wedding_booking_drafts', COUNT(*) FROM wedding_booking_drafts
UNION ALL
SELECT 'baptism_booking_drafts', COUNT(*) FROM baptism_booking_drafts
UNION ALL
SELECT 'notifications', COUNT(*) FROM notifications;

-- Commit to apply changes
COMMIT;
