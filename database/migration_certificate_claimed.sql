-- Migration: "Claimed" lifecycle closure for issued certificates
-- (Certificate of Baptism / Marriage / Confirmation / Death)
-- PostgreSQL / Supabase compatible. Safe to re-run.
--
-- issued_certificates.status already allowed 'claimed' (see
-- migration_issued_certificates.sql), but nothing in the app ever set it and
-- there was no timestamp/actor to record the pick-up. This closes that gap.

ALTER TABLE issued_certificates
    ADD COLUMN IF NOT EXISTS claimed_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS claimed_by_user_id BIGINT DEFAULT NULL
        REFERENCES users(user_id) ON DELETE SET NULL;
