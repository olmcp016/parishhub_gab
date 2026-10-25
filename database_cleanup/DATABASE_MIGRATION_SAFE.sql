-- =============================================================================
-- ParishHub — SAFE DATABASE SYNCHRONIZATION MIGRATION
-- Generated: 2026-10-25 by the database cleanup audit.
-- Target: PostgreSQL 15+ (Supabase)
--
-- *** REVIEW BEFORE RUNNING. NOT EXECUTED AGAINST PRODUCTION BY THE AUDIT. ***
--
-- WHAT THIS DOES
--   PART A (runs by default) — additive, idempotent, no data is deleted or changed:
--     A1. Re-add parishioners.baptism_date / confirmation_date if missing
--         (secretary/parishioner-detail.php still SELECTs them; cleanup_audit.sql
--         dropped them, which breaks that page).
--     A2. Drop two redundant indexes, each an exact duplicate of a UNIQUE
--         constraint's own index. Uniqueness and lookup speed are unchanged.
--     A3. Add indexes on frequently-joined foreign-key columns.
--     A4. Block deletion/renaming of the two system placeholder accounts
--         (guest@ / walkin-donor@parishhub.internal), which anchor every guest
--         and walk-in record through ON DELETE CASCADE.
--   PART B (commented out, OPT-IN) — protect financial and certificate records
--         from cascading deletes. Requires the PHP guard described below FIRST.
--   PART C (commented out, OPT-IN) — archive tables with no code dependency by
--         MOVING them to an "archive" schema (fully reversible; nothing dropped).
--   PART D (commented out, OPT-IN, recommended) — enable Row Level Security
--         (deny-all) so the public Supabase REST API cannot read the tables.
--
-- BEFORE RUNNING
--   1. Take a backup (see DATABASE_CLEANUP_PLAN.md §6):
--        pg_dump "$SUPABASE_DB_URL" --format=custom --no-owner --file=parishhub_pre_cleanup.dump
--      and download a Supabase dashboard backup if your plan provides one.
--   2. Run DATABASE_PREFLIGHT_CHECK.sql and save its output.
--   3. Run this file in a maintenance window. Part A takes brief table locks
--      while indexes build; at parish data volumes this is seconds. For zero
--      write-blocking, run the A3 statements one at a time OUTSIDE the
--      transaction with CREATE INDEX CONCURRENTLY instead.
--   4. Re-run DATABASE_PREFLIGHT_CHECK.sql and compare the row_counts group —
--      every count must be identical.
--
-- ROLLBACK: DATABASE_ROLLBACK.sql (Part A section reverses exactly what A did).
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- A0. Guard — refuse to run on a database that is not at the expected state.
--     The current code requires the consolidated tables; if they are missing,
--     the original merge migrations must be applied first (see cleanup plan §4).
-- -----------------------------------------------------------------------------
DO $$
BEGIN
    IF to_regclass('public.generated_forms') IS NULL THEN
        RAISE EXCEPTION 'generated_forms is missing — apply database/migration_merge_forms.sql first. Nothing was changed.';
    END IF;
    IF to_regclass('public.appointment_drafts') IS NULL THEN
        RAISE EXCEPTION 'appointment_drafts is missing — apply database/migration_merge_drafts.sql first. Nothing was changed.';
    END IF;
    IF to_regclass('public.parishioners') IS NULL OR to_regclass('public.appointments') IS NULL THEN
        RAISE EXCEPTION 'Core tables missing — this is not a ParishHub database. Nothing was changed.';
    END IF;
END $$;

-- -----------------------------------------------------------------------------
-- A1. parishioners.baptism_date / confirmation_date
--     Added ONLY if absent, and tagged with a marker comment so the rollback
--     removes a column only when this script created it AND it is still empty.
--     (Alternative fix: remove the two columns from the SELECT in
--      secretary/parishioner-detail.php:31 — see DATABASE_SYSTEM_MISMATCHES.md M-02.)
-- -----------------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'public' AND table_name = 'parishioners' AND column_name = 'baptism_date') THEN
        ALTER TABLE public.parishioners ADD COLUMN baptism_date DATE;
        COMMENT ON COLUMN public.parishioners.baptism_date IS 'database_cleanup 2026-10-25: re-added (read by secretary/parishioner-detail.php)';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'public' AND table_name = 'parishioners' AND column_name = 'confirmation_date') THEN
        ALTER TABLE public.parishioners ADD COLUMN confirmation_date DATE;
        COMMENT ON COLUMN public.parishioners.confirmation_date IS 'database_cleanup 2026-10-25: re-added (read by secretary/parishioner-detail.php)';
    END IF;
END $$;

-- -----------------------------------------------------------------------------
-- A1b. appointments.payment_deadline (MISSING in preflight check)
--     Enables payment deadline tracking calculated upon appointment approval.
-- -----------------------------------------------------------------------------
ALTER TABLE public.appointments ADD COLUMN IF NOT EXISTS payment_deadline TIMESTAMP WITH TIME ZONE DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_appointments_payment_deadline ON public.appointments (payment_deadline);

-- -----------------------------------------------------------------------------
-- A2. Redundant duplicate indexes — dropped only while the UNIQUE constraint
--     that provides an identical index still exists.
-- -----------------------------------------------------------------------------
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'appointments_guest_reference_key' AND contype = 'u') THEN
        DROP INDEX IF EXISTS public.idx_appt_guest_reference;      -- == appointments_guest_reference_key (guest_reference)
    END IF;
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'prot_one_per_email' AND contype = 'u') THEN
        DROP INDEX IF EXISTS public.idx_prot_email;                -- == prot_one_per_email (email)
    END IF;
END $$;

-- -----------------------------------------------------------------------------
-- A3. Indexes on foreign-key columns used in the app's JOINs and WHERE clauses.
--     Every name starts with idx_cleanup_ so the rollback can remove exactly these.
-- -----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_cleanup_appointments_parishioner   ON public.appointments (parishioner_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_appointments_service       ON public.appointments (service_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_appointments_priest_date   ON public.appointments (priest_id, appointment_date);
CREATE INDEX IF NOT EXISTS idx_cleanup_payments_appointment       ON public.payments (appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_transactions_payment       ON public.transactions (payment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_uploaded_docs_appointment  ON public.uploaded_documents (appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_donations_appointment      ON public.donations (appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_donations_project          ON public.donations (project_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_mass_intentions_appointment ON public.mass_intentions (appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_notifications_appointment  ON public.notifications (appointment_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_drafts_parishioner         ON public.appointment_drafts (parishioner_id);
CREATE INDEX IF NOT EXISTS idx_cleanup_activity_logs_user_created ON public.activity_logs (user_id, created_at);

-- -----------------------------------------------------------------------------
-- A4. Protect the system placeholder accounts.
--     Every guest booking, donation and Mass intention is stored under ONE
--     login-disabled placeholder (users.email = 'guest@parishhub.internal',
--     see migration_guest_access.sql / includes/auth.php guestParishionerId()),
--     and every walk-in donation under 'walkin-donor@parishhub.internal'
--     (migration_walkin_donor.sql). admin/users.php can delete any user, and the
--     FK chain is ON DELETE CASCADE, so deleting either row would erase ALL of
--     those appointments, payments, receipts, documents and certificates. This
--     trigger blocks only deleting or renaming those @parishhub.internal system
--     accounts (renaming would also break the code's lookup by email).
--     No other user, query or workflow is affected.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_protect_guest_placeholder()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.email LIKE '%@parishhub.internal'
       AND (TG_OP = 'DELETE' OR NEW.email IS DISTINCT FROM OLD.email) THEN
        RAISE EXCEPTION 'System placeholder account % cannot be deleted or renamed: guest and walk-in bookings, payments and certificates depend on it.', OLD.email
            USING ERRCODE = 'restrict_violation';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$;

DROP TRIGGER IF EXISTS trg_protect_guest_placeholder ON public.users;
CREATE TRIGGER trg_protect_guest_placeholder
    BEFORE DELETE OR UPDATE OF email ON public.users
    FOR EACH ROW EXECUTE FUNCTION public.fn_protect_guest_placeholder();

COMMIT;

-- =============================================================================
-- PART B — OPT-IN: protect payment, receipt, donation and certificate records
-- =============================================================================
-- WHY: these FKs are ON DELETE CASCADE. admin/users.php ("delete" action) runs
--   DELETE FROM users WHERE user_id = ?  with no history check, which cascades
--   users -> parishioners -> appointments -> payments -> official_receipts,
--   transactions, donations, mass_intentions, uploaded_documents,
--   generated_forms, issued_certificates. One click erases financial and
--   certificate history.
-- PREREQUISITE (do this FIRST, or deleting a parishioner with history will show
--   a database error instead of a friendly message): in admin/users.php, before
--   the DELETE, refuse when the user has appointments and offer
--   "Deactivate" (UPDATE users SET status = 'inactive') instead. See
--   DATABASE_SYSTEM_MISMATCHES.md M-05.
-- Uncomment the block below only after that PHP change is deployed.
-- -----------------------------------------------------------------------------
-- BEGIN;
-- ALTER TABLE public.appointments
--     DROP CONSTRAINT appointments_parishioner_id_fkey,
--     ADD  CONSTRAINT appointments_parishioner_id_fkey FOREIGN KEY (parishioner_id)
--          REFERENCES public.parishioners(parishioner_id) ON DELETE RESTRICT;
-- ALTER TABLE public.payments
--     DROP CONSTRAINT payments_appointment_id_fkey,
--     ADD  CONSTRAINT payments_appointment_id_fkey FOREIGN KEY (appointment_id)
--          REFERENCES public.appointments(appointment_id) ON DELETE RESTRICT;
-- ALTER TABLE public.official_receipts
--     DROP CONSTRAINT official_receipts_payment_id_fkey,
--     ADD  CONSTRAINT official_receipts_payment_id_fkey FOREIGN KEY (payment_id)
--          REFERENCES public.payments(payment_id) ON DELETE RESTRICT;
-- ALTER TABLE public.transactions
--     DROP CONSTRAINT transactions_payment_id_fkey,
--     ADD  CONSTRAINT transactions_payment_id_fkey FOREIGN KEY (payment_id)
--          REFERENCES public.payments(payment_id) ON DELETE RESTRICT;
-- ALTER TABLE public.donations
--     DROP CONSTRAINT donations_appointment_id_fkey,
--     ADD  CONSTRAINT donations_appointment_id_fkey FOREIGN KEY (appointment_id)
--          REFERENCES public.appointments(appointment_id) ON DELETE RESTRICT;
-- ALTER TABLE public.issued_certificates
--     DROP CONSTRAINT issued_certificates_appointment_id_fkey,
--     ADD  CONSTRAINT issued_certificates_appointment_id_fkey FOREIGN KEY (appointment_id)
--          REFERENCES public.appointments(appointment_id) ON DELETE RESTRICT;
-- COMMIT;

-- =============================================================================
-- PART C — OPT-IN: archive tables with no code dependency (no data deleted)
-- =============================================================================
-- Tables are MOVED (ALTER TABLE ... SET SCHEMA) with their data, constraints,
-- and indexes intact. Moving back (DATABASE_ROLLBACK.sql Part C) restores them
-- exactly. Only uncomment after the owner approves and the preflight shows the
-- row counts you expect. Tables already dropped by cleanup_audit.sql are skipped.
--   chat_messages    — no SQL reference; the chatbot answers from services data and stores nothing.
--   permissions, role_permissions — RBAC uses requireRole()/STAFF_ROLES; never queried.
--   reports          — report pages compute live from payments/appointments; never written.
-- NOT archived: requirements (still referenced by uploaded_documents.requirement_id FK).
-- -----------------------------------------------------------------------------
-- BEGIN;
-- CREATE SCHEMA IF NOT EXISTS archive;
-- DO $$
-- DECLARE t text;
-- BEGIN
--     FOREACH t IN ARRAY ARRAY['chat_messages','role_permissions','permissions','reports'] LOOP
--         IF to_regclass('public.' || t) IS NOT NULL AND to_regclass('archive.' || t) IS NULL THEN
--             EXECUTE format('ALTER TABLE public.%I SET SCHEMA archive', t);
--             RAISE NOTICE 'Archived public.% -> archive.%', t, t;
--         END IF;
--     END LOOP;
-- END $$;
-- REVOKE ALL ON SCHEMA archive FROM anon, authenticated;
-- COMMIT;

-- =============================================================================
-- PART D — OPT-IN (recommended): close the Supabase REST/Data API exposure
-- =============================================================================
-- WHY: no public table has Row Level Security enabled. Supabase serves every
--   public table through its auto-generated REST API, and by default grants the
--   "anon" and "authenticated" API roles access to them. The anon key is a
--   publishable key by design, so anyone with the project URL and that key can
--   read users (including password hashes), appointments, payments, etc.
-- APP IMPACT: none expected. ParishHub never uses the REST API or supabase-js
--   (verified: no references in PHP/JS); PHP connects with PDO as the role that
--   OWNS these tables, and RLS does not apply to a table's owner unless FORCE ROW
--   LEVEL SECURITY is set (it is not set here). Document storage uses the Storage
--   API with the secret key, which is unaffected.
-- CONFIRM FIRST: in DATABASE_PREFLIGHT_CHECK.sql output, group "rls", every row
--   must show owner = the role in your DB_USER (normally postgres). If any table
--   is owned by a different role, do not apply this part.
-- Enabling RLS with NO policies = deny-all for anon/authenticated.
-- -----------------------------------------------------------------------------
-- BEGIN;
-- DO $$
-- DECLARE t text;
-- BEGIN
--     FOR t IN SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
--              WHERE n.nspname = 'public' AND c.relkind = 'r' AND NOT c.relrowsecurity LOOP
--         EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t);
--         EXECUTE format('COMMENT ON TABLE public.%I IS %L', t,
--                        coalesce(obj_description(format('public.%I', t)::regclass, 'pg_class') || ' | ', '')
--                        || 'database_cleanup 2026-10-25: RLS enabled');
--     END LOOP;
-- END $$;
-- COMMIT;
