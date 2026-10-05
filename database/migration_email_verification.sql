-- PARISHHUB — Email Verification Migration
-- Adds verification_token and token_expires_at columns to users.
-- Re-adds email_verified_at if it was dropped by cleanup_audit.sql.
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE checks).
-- Also pre-marks all existing accounts as verified so no one gets locked out.



ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS verification_token VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS token_expires_at   TIMESTAMP   DEFAULT NULL;

-- Pre-verify every account that already exists so existing users are not
-- suddenly blocked on their next login.
UPDATE users
SET email_verified_at = created_at
WHERE email_verified_at IS NULL;
