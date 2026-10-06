-- PARISHHUB — Password Reset OTP table
-- PostgreSQL syntax. Safe to run multiple times.
-- Stores one pending OTP per email address (UPSERT on conflict).

CREATE TABLE IF NOT EXISTS password_reset_otps (
    id          BIGSERIAL    PRIMARY KEY,
    email       VARCHAR(150) NOT NULL,
    otp_hash    VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ  NOT NULL,
    attempts    SMALLINT     NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT prot_one_per_email UNIQUE (email)
);

CREATE INDEX IF NOT EXISTS idx_prot_email ON password_reset_otps (email);
