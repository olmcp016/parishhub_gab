-- Migration: Secretary website notifications + email delivery log.
-- Run AFTER migration_requirements_review.sql. Safe to run more than once.

-- 1) Website notifications can point at the appointment they are about, so a
--    Secretary clicking "New Booking Received" / "Updated Requirements
--    Submitted" in the notification bell opens that appointment.
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS appointment_id INT DEFAULT NULL
    REFERENCES appointments(appointment_id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread ON notifications (user_id, is_read);

-- 2) Delivery log for the emails sent to guests / parishioners
--    (includes/email-log.php). dedupe_key is unique, so the same email
--    (one per revision request) is never sent twice; a failed one can be
--    retried and is updated in place. No credentials are stored here.
CREATE TABLE IF NOT EXISTS email_logs (
    email_log_id      SERIAL PRIMARY KEY,
    dedupe_key        VARCHAR(255) NOT NULL UNIQUE,
    notification_type VARCHAR(40)  NOT NULL,   -- revision_request
    appointment_id    INT          DEFAULT NULL REFERENCES appointments(appointment_id) ON DELETE SET NULL,
    booking_reference VARCHAR(40)  DEFAULT NULL,
    recipient         VARCHAR(150) NOT NULL,
    subject           VARCHAR(255) NOT NULL,
    body_html         TEXT         NOT NULL,   -- kept so a failed email can be retried exactly
    status            VARCHAR(20)  NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed')),
    attempts          INT          NOT NULL DEFAULT 0,
    failure_reason    TEXT         DEFAULT NULL,
    last_attempt_at   TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    sent_at           TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at        TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_email_logs_appointment ON email_logs (appointment_id);
