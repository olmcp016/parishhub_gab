-- Migration: Auto-log appointment status changes to activity_logs via a DB trigger
-- Target: PostgreSQL (Supabase)
-- Run this AFTER taking a full database backup.
-- This migration is idempotent: safe to re-run.
--
-- WHAT THIS DOES:
--   Whenever appointments.status_id changes, this AFTER UPDATE trigger inserts a
--   row into activity_logs automatically. This acts as a safety net — even if a
--   future code path changes a status without calling logActivity(), the audit
--   trail is still written.
--
--   The PHP logActivity() calls in secretary/appointment-detail.php are kept in
--   place. They write human-readable messages and capture the staff actor's
--   user_id. The trigger writes a compact machine-readable log as a fallback for
--   any code path that does not call logActivity().
--
--   The trigger uses NEW.approved_by as the actor (the staff user who last
--   actioned the appointment). For status transitions that don't set approved_by
--   (e.g. auto-cancel, parishioner cancellation) this will be NULL, which is
--   valid and expected for activity_logs.user_id.

BEGIN;

-- ── 1. Status name lookup helper ─────────────────────────────────────────────
--   Resolves a status_id to its name so the log message is human-readable
--   without a JOIN in the trigger body.

CREATE OR REPLACE FUNCTION fn_appointment_status_name(p_status_id INT)
RETURNS TEXT
LANGUAGE sql
STABLE
AS $$
    SELECT status_name FROM appointment_status WHERE status_id = p_status_id;
$$;


-- ── 2. Trigger function ───────────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION fn_log_appointment_status_change()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_old_name TEXT;
    v_new_name TEXT;
BEGIN
    -- Only fire on actual status changes
    IF OLD.status_id IS NOT DISTINCT FROM NEW.status_id THEN
        RETURN NEW;
    END IF;

    v_old_name := fn_appointment_status_name(OLD.status_id);
    v_new_name := fn_appointment_status_name(NEW.status_id);

    INSERT INTO activity_logs (user_id, action, module)
    VALUES (
        NEW.approved_by,
        'Appointment #' || NEW.appointment_id
            || ' status changed: ' || COALESCE(v_old_name, OLD.status_id::text)
            || ' → ' || COALESCE(v_new_name, NEW.status_id::text),
        'Appointments'
    );

    RETURN NEW;
END;
$$;


-- ── 3. Attach trigger to appointments table ───────────────────────────────────

DROP TRIGGER IF EXISTS trg_appointment_status_log ON appointments;

CREATE TRIGGER trg_appointment_status_log
    AFTER UPDATE OF status_id ON appointments
    FOR EACH ROW
    EXECUTE FUNCTION fn_log_appointment_status_change();


COMMIT;
