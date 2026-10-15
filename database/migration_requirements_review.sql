-- Migration: requirements revision / re-review workflow (includes/requirements-review.php).
--
-- requirements_review_status
--   NULL                 -> derived: Pending Review, or Approved once every requirement is approved
--   'awaiting_revision'  -> the Secretary sent a revision request; waiting for the applicant
--   'ready_for_rereview' -> the applicant submitted updated requirements; waiting for the Secretary
-- revision_requested_at  -> when the last revision request was sent (used to tell which
--                           rejections still need to be sent, so no duplicate emails)
--
-- Safe to run more than once. Existing appointments keep NULL (no behaviour change).

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS requirements_review_status VARCHAR(30) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS revision_requested_at TIMESTAMP WITH TIME ZONE DEFAULT NULL;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'public.appointments'::regclass
          AND conname = 'appointments_requirements_review_status_check'
    ) THEN
        ALTER TABLE appointments ADD CONSTRAINT appointments_requirements_review_status_check
            CHECK (requirements_review_status IS NULL OR requirements_review_status IN ('awaiting_revision', 'ready_for_rereview'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_appointments_requirements_review_status
    ON appointments (requirements_review_status) WHERE requirements_review_status IS NOT NULL;
