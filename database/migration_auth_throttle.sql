-- Migration: shared throttle table for login and guest status lookups (audit H-02, H-03).
-- Login lockouts used to live in the PHP session, so clearing a cookie reset them.
-- Counters now live here, keyed by email, IP, or a feature name.
-- PostgreSQL / Supabase compatible. Safe to re-run.

CREATE TABLE IF NOT EXISTS auth_throttle (
    throttle_key  VARCHAR(190) PRIMARY KEY,
    failures      INTEGER      NOT NULL DEFAULT 0,
    locked_until  TIMESTAMPTZ  NULL,
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_auth_throttle_locked_until ON auth_throttle (locked_until);
