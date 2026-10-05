-- Migration: Auto-notify parishioner on appointment status changes via a DB trigger
-- Target: PostgreSQL (Supabase)
-- Run this AFTER taking a full database backup.
-- This migration is idempotent: safe to re-run.
--
-- WHAT THIS DOES:
--   An AFTER UPDATE trigger on appointments inserts a notification into the
--   notifications table whenever appointments.status_id changes to a notifiable
--   status. This replaces the manual INSERT INTO notifications calls in PHP for
--   the Approved and Confirmed statuses, and also adds new automatic notifications
--   for Completed and Cancelled (which previously sent none).
--
-- STATUSES HANDLED BY THIS TRIGGER:
--   2 = Approved    → "Your appointment has been approved. Please proceed with payment."
--   5 = Confirmed   → "Your appointment is confirmed."
--   6 = Completed   → "Your appointment has been completed." (new — no PHP equivalent)
--   7 = Cancelled   → "Your appointment has been cancelled."  (new — no PHP equivalent)
--
-- STATUSES DELIBERATELY SKIPPED:
--   3 = Rejected    → PHP in secretary/appointment-detail.php keeps this notification
--                     because it includes the custom rejection reason text.
--   4 = Payment Verified → includes/functions.php:verifyPaymentAndIssueReceipt() already
--                          sends a notification; skipping here avoids a duplicate.
--
-- PHP CHANGES (already applied):
--   • Removed the Approve notification block (lines 234–238) from secretary/appointment-detail.php
--   • Removed the Confirm notification block (lines 364–371) from secretary/appointment-detail.php
--   • All other notification blocks are untouched.

BEGIN;

-- ── Trigger function ──────────────────────────────────────────────────────────

CREATE OR REPLACE FUNCTION fn_notify_appointment_status()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_user_id INT;
    v_title   TEXT;
    v_message TEXT;
BEGIN
    -- Only fire on actual status changes
    IF OLD.status_id IS NOT DISTINCT FROM NEW.status_id THEN
        RETURN NEW;
    END IF;

    -- Skip statuses handled by PHP or by another notification path:
    --   3 = Rejected        (PHP sends personalized message with rejection reason)
    --   4 = Payment Verified (functions.php:verifyPaymentAndIssueReceipt already notifies)
    IF NEW.status_id IN (3, 4) THEN
        RETURN NEW;
    END IF;

    -- Resolve the parishioner's website user_id
    SELECT u.user_id INTO v_user_id
    FROM parishioners p
    JOIN users u ON u.user_id = p.user_id
    WHERE p.parishioner_id = NEW.parishioner_id;

    IF v_user_id IS NULL THEN
        RETURN NEW;
    END IF;

    -- Map status to notification title and message
    v_title := CASE NEW.status_id
        WHEN 2 THEN 'Appointment Approved'
        WHEN 5 THEN 'Appointment Confirmed'
        WHEN 6 THEN 'Appointment Completed'
        WHEN 7 THEN 'Appointment Cancelled'
        ELSE NULL
    END;

    v_message := CASE NEW.status_id
        WHEN 2 THEN 'Your appointment #' || NEW.appointment_id
                    || ' has been approved. Please proceed with payment.'
        WHEN 5 THEN 'Your appointment #' || NEW.appointment_id
                    || ' is confirmed. We look forward to seeing you.'
        WHEN 6 THEN 'Your appointment #' || NEW.appointment_id
                    || ' has been completed. Thank you.'
        WHEN 7 THEN 'Your appointment #' || NEW.appointment_id
                    || ' has been cancelled.'
        ELSE NULL
    END;

    IF v_title IS NOT NULL THEN
        INSERT INTO notifications (user_id, type, category, title, message)
        VALUES (v_user_id, 'website', 'appointment', v_title, v_message);
    END IF;

    RETURN NEW;
END;
$$;


-- ── Attach trigger ────────────────────────────────────────────────────────────

DROP TRIGGER IF EXISTS trg_appointment_notify ON appointments;

CREATE TRIGGER trg_appointment_notify
    AFTER UPDATE OF status_id ON appointments
    FOR EACH ROW
    EXECUTE FUNCTION fn_notify_appointment_status();


COMMIT;
