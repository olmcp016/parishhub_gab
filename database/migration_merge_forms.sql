-- Migration: Merge generated_wedding_forms + generated_baptism_forms + generated_funeral_forms
-- into a single generated_forms table.
-- Target: PostgreSQL (Supabase)
-- Run this AFTER taking a full database backup.
-- This migration is idempotent: safe to re-run.

BEGIN;

-- 1. Create the unified table
CREATE TABLE IF NOT EXISTS generated_forms (
    generated_form_id BIGSERIAL PRIMARY KEY,
    service_category  VARCHAR(20)  NOT NULL
        CHECK (service_category IN ('Wedding', 'Baptism', 'Funeral')),
    appointment_id    INT          NULL
        REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    draft_id          BIGINT       NULL,
    form_type         VARCHAR(60)  NOT NULL,
    form_data         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    document_id       INT          NULL
        REFERENCES uploaded_documents(document_id) ON DELETE SET NULL,
    status            VARCHAR(30)  NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','generated','pending_review','approved','rejected')),
    rejection_reason  TEXT         NULL,
    created_at        TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT generated_forms_owner_check
        CHECK (
            (CASE WHEN appointment_id IS NOT NULL THEN 1 ELSE 0 END)
            + (CASE WHEN draft_id IS NOT NULL THEN 1 ELSE 0 END) = 1
        )
);

-- Unique constraints as partial indexes (mirrors the old per-table UNIQUE constraints)
CREATE UNIQUE INDEX IF NOT EXISTS idx_generated_forms_appt_type
    ON generated_forms(appointment_id, form_type)
    WHERE appointment_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_generated_forms_draft_type
    ON generated_forms(draft_id, form_type)
    WHERE draft_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_generated_forms_document
    ON generated_forms(document_id);

CREATE INDEX IF NOT EXISTS idx_generated_forms_category
    ON generated_forms(service_category);

-- 2. Migrate existing data from the three old tables
-- (INSERT ... ON CONFLICT DO NOTHING makes this re-runnable)

INSERT INTO generated_forms
    (service_category, appointment_id, draft_id, form_type, form_data,
     document_id, status, rejection_reason, created_at, updated_at)
SELECT 'Wedding',
       appointment_id,
       draft_id,
       form_type,
       form_data,
       document_id,
       status,
       NULL AS rejection_reason,
       created_at,
       updated_at
FROM generated_wedding_forms
ON CONFLICT DO NOTHING;

INSERT INTO generated_forms
    (service_category, appointment_id, draft_id, form_type, form_data,
     document_id, status, rejection_reason, created_at, updated_at)
SELECT 'Baptism',
       appointment_id,
       draft_id,
       form_type,
       form_data,
       document_id,
       status,
       NULL AS rejection_reason,
       created_at,
       updated_at
FROM generated_baptism_forms
ON CONFLICT DO NOTHING;

INSERT INTO generated_forms
    (service_category, appointment_id, draft_id, form_type, form_data,
     document_id, status, rejection_reason, created_at, updated_at)
SELECT 'Funeral',
       appointment_id,
       NULL AS draft_id,
       form_type,
       form_data,
       document_id,
       status,
       rejection_reason,
       created_at,
       updated_at
FROM generated_funeral_forms
ON CONFLICT DO NOTHING;

-- 3. Drop old tables (CASCADE removes their indexes and constraints)
DROP TABLE IF EXISTS generated_wedding_forms CASCADE;
DROP TABLE IF EXISTS generated_baptism_forms  CASCADE;
DROP TABLE IF EXISTS generated_funeral_forms  CASCADE;

COMMIT;
