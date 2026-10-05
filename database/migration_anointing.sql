-- Migration: Add "Anointing of the Sick" (Hilog) service
-- PostgreSQL / Supabase compatible.
-- Run this on the live database before deploying the corresponding PHP changes.

-- 1. Add 'Wake' and 'Anointing' to the service_category ENUM type.
--    ALTER TYPE ... ADD VALUE is idempotent-safe with IF NOT EXISTS (Postgres 9.6+).
ALTER TYPE service_category ADD VALUE IF NOT EXISTS 'Wake';
ALTER TYPE service_category ADD VALUE IF NOT EXISTS 'Anointing';

-- 2. Add custom fields to appointments for Anointing of the Sick.
ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS requester_name VARCHAR(150) DEFAULT NULL;
ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS patient_name VARCHAR(150) DEFAULT NULL;

-- 3. Insert the new service (fee = 0 = Free).
INSERT INTO services (service_name, category, description, fee, requirements, duration_minutes, is_active)
SELECT 'Anointing of the Sick (Hilog)',
       'Anointing',
       'Pagbasbas ng langis at sakramento ng pag-aho para sa mga maysakit. This sacrament is administered to the seriously ill, elderly, or those in danger of death.',
       0.00,
       NULL,
       60,
       TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'Anointing'
);
