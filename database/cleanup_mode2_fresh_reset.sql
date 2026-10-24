-- =============================================================================
-- PARISHHUB — MODE 2: CLEAN TRANSACTIONAL RESET (Fresh Start for New Workflows)
-- Target:  PostgreSQL / Supabase
-- Purpose: Wipes all test transactional data (appointments, payments, certificates,
--          documents, receipts, notifications) while keeping 100% of:
--          - users, roles, parishioners, priests, priest_unavailability
--          - services, service_schedules, service_fee_rules
--          - locations (chapels, barangays)
--          - settings, payment_methods, appointment_status
--
-- NOTE: Uses RESTART IDENTITY to reset auto-increment counters back to #1.
-- Safe: Checks whether each table exists before attempting to truncate.
-- =============================================================================

BEGIN;

DO $$
DECLARE
    tbl text;
    tables_to_truncate text[] := ARRAY[
        'official_receipts',
        'transactions',
        'payments',
        'issued_certificates',
        'uploaded_documents',
        'donations',
        'mass_intentions',
        'notifications',
        'activity_logs',
        'wedding_booking_drafts',
        'baptism_booking_drafts',
        'generated_wedding_forms',
        'generated_funeral_forms',
        'generated_forms',
        'precana_requirements',
        'chat_messages',
        'appointments'
    ];
BEGIN
    FOREACH tbl IN ARRAY tables_to_truncate
    LOOP
        IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = tbl) THEN
            EXECUTE format('TRUNCATE TABLE %I RESTART IDENTITY CASCADE;', tbl);
        END IF;
    END LOOP;
END $$;

-- Verify master data remains 100% intact
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

COMMIT;
