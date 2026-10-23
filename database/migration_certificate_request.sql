-- Migration: Add "Certificate" category and "Certificate Request" service
-- PostgreSQL / Supabase compatible. Safe to re-run.

-- 1. Add 'Certificate' to service_category ENUM type if not exists
ALTER TYPE service_category ADD VALUE IF NOT EXISTS 'Certificate';

-- 2. Insert the Certificate Request service if not exists
INSERT INTO services (service_name, category, description, fee, requirements, duration_minutes, is_active)
SELECT 'Certificate Request',
       'Certificate',
       'Official church certificate issuance (Baptismal, Confirmation, Death, or Marriage Certificate).',
       100.00,
       NULL,
       15,
       TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'Certificate' OR service_name = 'Certificate Request'
);
