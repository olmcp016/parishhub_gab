-- =============================================================================
-- ParishHub — ROLLBACK for DATABASE_MIGRATION_SAFE.sql
-- Generated: 2026-10-25 by the database cleanup audit.
--
-- Each part reverses the matching part of the migration. Run only the parts
-- you actually applied. No part deletes user, appointment, payment, receipt,
-- certificate, notification or log data.
--
-- If anything goes wrong beyond what these statements cover, restore the
-- backup taken before the migration:
--   pg_restore --clean --if-exists --no-owner -d "$SUPABASE_DB_URL" parishhub_pre_cleanup.dump
-- (Prefer restoring into a NEW Supabase project / branch first and verifying,
--  then switching the app's DB_HOST, rather than overwriting production.)
-- =============================================================================

-- =============================================================================
-- PART A rollback
-- =============================================================================
BEGIN;

-- A4: remove the guest-placeholder protection trigger.
DROP TRIGGER IF EXISTS trg_protect_guest_placeholder ON public.users;
DROP FUNCTION IF EXISTS public.fn_protect_guest_placeholder();

-- A3: remove only the indexes the migration created.
DROP INDEX IF EXISTS public.idx_cleanup_appointments_parishioner;
DROP INDEX IF EXISTS public.idx_cleanup_appointments_service;
DROP INDEX IF EXISTS public.idx_cleanup_appointments_priest_date;
DROP INDEX IF EXISTS public.idx_cleanup_payments_appointment;
DROP INDEX IF EXISTS public.idx_cleanup_transactions_payment;
DROP INDEX IF EXISTS public.idx_cleanup_uploaded_docs_appointment;
DROP INDEX IF EXISTS public.idx_cleanup_donations_appointment;
DROP INDEX IF EXISTS public.idx_cleanup_donations_project;
DROP INDEX IF EXISTS public.idx_cleanup_mass_intentions_appointment;
DROP INDEX IF EXISTS public.idx_cleanup_notifications_appointment;
DROP INDEX IF EXISTS public.idx_cleanup_drafts_parishioner;
DROP INDEX IF EXISTS public.idx_cleanup_activity_logs_user_created;

-- A2: recreate the two redundant indexes exactly as they were.
CREATE INDEX IF NOT EXISTS idx_appt_guest_reference ON public.appointments USING btree (guest_reference);
CREATE INDEX IF NOT EXISTS idx_prot_email ON public.password_reset_otps USING btree (email);

-- A1: drop the re-added parishioner columns ONLY if this cleanup created them
--     (marker comment present) AND no value has been stored since. Otherwise
--     they are left in place so no data can be lost.
DO $$
DECLARE
    col text;
    marker text;
    has_data boolean;
BEGIN
    FOREACH col IN ARRAY ARRAY['baptism_date','confirmation_date'] LOOP
        SELECT col_description('public.parishioners'::regclass, a.attnum) INTO marker
        FROM pg_attribute a
        WHERE a.attrelid = 'public.parishioners'::regclass AND a.attname = col AND NOT a.attisdropped;
        IF marker LIKE 'database_cleanup 2026-10-25:%' THEN
            EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.parishioners WHERE %I IS NOT NULL)', col) INTO has_data;
            IF has_data THEN
                RAISE NOTICE 'Keeping parishioners.% — it now contains data.', col;
            ELSE
                EXECUTE format('ALTER TABLE public.parishioners DROP COLUMN %I', col);
                RAISE NOTICE 'Dropped parishioners.% (added by cleanup, still empty).', col;
            END IF;
        ELSE
            RAISE NOTICE 'Keeping parishioners.% — it existed before the cleanup.', col;
        END IF;
    END LOOP;
END $$;

COMMIT;

-- =============================================================================
-- PART B rollback (only if Part B was applied) — restores the original CASCADE rules
-- =============================================================================
-- BEGIN;
-- ALTER TABLE public.appointments
--     DROP CONSTRAINT appointments_parishioner_id_fkey,
--     ADD  CONSTRAINT appointments_parishioner_id_fkey FOREIGN KEY (parishioner_id)
--          REFERENCES public.parishioners(parishioner_id) ON DELETE CASCADE;
-- ALTER TABLE public.payments
--     DROP CONSTRAINT payments_appointment_id_fkey,
--     ADD  CONSTRAINT payments_appointment_id_fkey FOREIGN KEY (appointment_id)
--          REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
-- ALTER TABLE public.official_receipts
--     DROP CONSTRAINT official_receipts_payment_id_fkey,
--     ADD  CONSTRAINT official_receipts_payment_id_fkey FOREIGN KEY (payment_id)
--          REFERENCES public.payments(payment_id) ON DELETE CASCADE;
-- ALTER TABLE public.transactions
--     DROP CONSTRAINT transactions_payment_id_fkey,
--     ADD  CONSTRAINT transactions_payment_id_fkey FOREIGN KEY (payment_id)
--          REFERENCES public.payments(payment_id) ON DELETE CASCADE;
-- ALTER TABLE public.donations
--     DROP CONSTRAINT donations_appointment_id_fkey,
--     ADD  CONSTRAINT donations_appointment_id_fkey FOREIGN KEY (appointment_id)
--          REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
-- ALTER TABLE public.issued_certificates
--     DROP CONSTRAINT issued_certificates_appointment_id_fkey,
--     ADD  CONSTRAINT issued_certificates_appointment_id_fkey FOREIGN KEY (appointment_id)
--          REFERENCES public.appointments(appointment_id) ON DELETE CASCADE;
-- COMMIT;

-- =============================================================================
-- PART C rollback (only if Part C was applied) — moves archived tables back intact
-- =============================================================================
-- BEGIN;
-- DO $$
-- DECLARE t text;
-- BEGIN
--     FOREACH t IN ARRAY ARRAY['permissions','role_permissions','reports','chat_messages'] LOOP
--         IF to_regclass('archive.' || t) IS NOT NULL AND to_regclass('public.' || t) IS NULL THEN
--             EXECUTE format('ALTER TABLE archive.%I SET SCHEMA public', t);
--             RAISE NOTICE 'Restored archive.% -> public.%', t, t;
--         END IF;
--     END LOOP;
-- END $$;
-- COMMIT;

-- =============================================================================
-- PART D rollback (only if Part D was applied) — disables RLS only on tables
-- that Part D enabled (identified by its marker comment)
-- =============================================================================
-- BEGIN;
-- DO $$
-- DECLARE t text; cmt text;
-- BEGIN
--     FOR t, cmt IN SELECT c.relname, obj_description(c.oid, 'pg_class') FROM pg_class c
--                   JOIN pg_namespace n ON n.oid = c.relnamespace
--                   WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relrowsecurity LOOP
--         IF cmt LIKE '%database_cleanup 2026-10-25: RLS enabled%' THEN
--             EXECUTE format('ALTER TABLE public.%I DISABLE ROW LEVEL SECURITY', t);
--             cmt := nullif(regexp_replace(cmt, '( \| )?database_cleanup 2026-10-25: RLS enabled', ''), '');
--             EXECUTE format('COMMENT ON TABLE public.%I IS %L', t, cmt);
--         END IF;
--     END LOOP;
-- END $$;
-- COMMIT;
