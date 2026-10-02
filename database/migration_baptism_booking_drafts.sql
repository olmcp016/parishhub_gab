-- Baptism booking drafts and generated parish forms
-- REVIEW ONLY. DO NOT EXECUTE automatically.
-- Depends on:
--   1. migration_document_review.sql
--   2. migration_wedding_generated_forms.sql
--   3. migration_wedding_booking_drafts.sql
--
-- This migration deliberately leaves generated_wedding_forms and
-- wedding_booking_drafts unchanged. Baptism receives separate staging tables
-- to minimize regression risk for existing Wedding data.

BEGIN;

CREATE TABLE IF NOT EXISTS baptism_booking_drafts (
    draft_id BIGSERIAL PRIMARY KEY,
    parishioner_id INT NULL
        REFERENCES parishioners(parishioner_id)
        ON DELETE CASCADE,
    guest_access_token_hash CHAR(64) NULL,
    guest_name VARCHAR(255) NULL,
    guest_email VARCHAR(255) NULL,
    guest_phone VARCHAR(30) NULL,
    service_id INT NOT NULL
        REFERENCES services(service_id)
        ON DELETE RESTRICT,
    priest_id INT NULL
        REFERENCES priests(priest_id)
        ON DELETE SET NULL,
    appointment_date DATE NULL,
    appointment_time TIME NULL,
    schedule_type VARCHAR(20) NULL,
    pss_claim VARCHAR(20) NULL,
    sponsor_count INT NULL,
    remarks TEXT NULL,
    contact_phone VARCHAR(30) NULL,
    location_address TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '24 hours'),
    finalized_appointment_id INT NULL
        REFERENCES appointments(appointment_id)
        ON DELETE RESTRICT,

    CONSTRAINT baptism_booking_drafts_owner_check
        CHECK (
            (parishioner_id IS NOT NULL AND guest_access_token_hash IS NULL)
            OR
            (parishioner_id IS NULL AND guest_access_token_hash IS NOT NULL)
        ),
    CONSTRAINT baptism_booking_drafts_guest_identity_check
        CHECK (
            parishioner_id IS NOT NULL
            OR (guest_name IS NOT NULL AND guest_phone IS NOT NULL)
        ),
    CONSTRAINT baptism_booking_drafts_status_check
        CHECK (status IN ('draft', 'finalized', 'expired', 'abandoned')),
    CONSTRAINT baptism_booking_drafts_schedule_type_check
        CHECK (schedule_type IS NULL OR schedule_type IN ('Regular', 'Special')),
    CONSTRAINT baptism_booking_drafts_pss_claim_check
        CHECK (pss_claim IS NULL OR pss_claim IN ('pss', 'non_pss')),
    CONSTRAINT baptism_booking_drafts_sponsor_count_check
        CHECK (sponsor_count IS NULL OR sponsor_count BETWEEN 0 AND 100),
    CONSTRAINT baptism_booking_drafts_finalized_state_check
        CHECK (
            (status = 'finalized' AND finalized_appointment_id IS NOT NULL)
            OR
            (status <> 'finalized' AND finalized_appointment_id IS NULL)
        ),
    CONSTRAINT baptism_booking_drafts_token_unique
        UNIQUE (guest_access_token_hash)
);

ALTER TABLE public.baptism_booking_drafts
    ENABLE ROW LEVEL SECURITY;

ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS baptism_draft_id BIGINT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_baptism_draft_fk'
    ) THEN
        ALTER TABLE uploaded_documents
            ADD CONSTRAINT uploaded_documents_baptism_draft_fk
            FOREIGN KEY (baptism_draft_id)
            REFERENCES baptism_booking_drafts(draft_id)
            ON DELETE CASCADE;
    END IF;
END $$;

-- Existing rows must already satisfy the original appointment/draft ownership
-- rule. Replacing it extends the rule to the Baptism owner without changing
-- any existing row values.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_owner_check'
    ) THEN
        ALTER TABLE uploaded_documents DROP CONSTRAINT uploaded_documents_owner_check;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_owner_check_v2'
    ) THEN
        ALTER TABLE uploaded_documents
            ADD CONSTRAINT uploaded_documents_owner_check_v2
            CHECK (
                (CASE WHEN appointment_id IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN draft_id IS NOT NULL THEN 1 ELSE 0 END)
                + (CASE WHEN baptism_draft_id IS NOT NULL THEN 1 ELSE 0 END) = 1
            );
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS generated_baptism_forms (
    generated_form_id BIGSERIAL PRIMARY KEY,
    appointment_id INT NULL
        REFERENCES appointments(appointment_id)
        ON DELETE CASCADE,
    draft_id BIGINT NULL
        REFERENCES baptism_booking_drafts(draft_id)
        ON DELETE CASCADE,
    form_type VARCHAR(60) NOT NULL
        CHECK (form_type IN ('katin_awan_bunyag', 'cluster_clearance_baptism_sponsor')),
    form_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    document_id INT NULL
        REFERENCES uploaded_documents(document_id)
        ON DELETE SET NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'generated', 'pending_review', 'approved', 'rejected')),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT generated_baptism_forms_owner_check
        CHECK (
            (CASE WHEN appointment_id IS NOT NULL THEN 1 ELSE 0 END)
            + (CASE WHEN draft_id IS NOT NULL THEN 1 ELSE 0 END) = 1
        ),
    CONSTRAINT generated_baptism_forms_appointment_type_unique
        UNIQUE (appointment_id, form_type)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_generated_baptism_forms_draft_type
    ON generated_baptism_forms(draft_id, form_type)
    WHERE draft_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_baptism_booking_drafts_finalized_appointment_unique
    ON baptism_booking_drafts(finalized_appointment_id)
    WHERE finalized_appointment_id IS NOT NULL;

-- Add Baptism generated form identifiers to the existing document metadata
-- constraint without changing any existing document_source values.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_generated_form_type_check'
    ) THEN
        ALTER TABLE uploaded_documents DROP CONSTRAINT uploaded_documents_generated_form_type_check;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_generated_form_type_check_v2'
    ) THEN
        ALTER TABLE uploaded_documents
            ADD CONSTRAINT uploaded_documents_generated_form_type_check_v2
            CHECK (
                generated_form_type IS NULL
                OR generated_form_type IN (
                    'matrimony_application',
                    'cluster_clearance',
                    'wedding_sponsor_clearance',
                    'katin_awan_bunyag',
                    'cluster_clearance_baptism_sponsor'
                )
            );
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_baptism_booking_drafts_parishioner
    ON baptism_booking_drafts(parishioner_id);

CREATE INDEX IF NOT EXISTS idx_baptism_booking_drafts_guest_token
    ON baptism_booking_drafts(guest_access_token_hash);

CREATE INDEX IF NOT EXISTS idx_baptism_booking_drafts_status_expiry
    ON baptism_booking_drafts(status, expires_at);

CREATE INDEX IF NOT EXISTS idx_baptism_booking_drafts_finalized_appointment
    ON baptism_booking_drafts(finalized_appointment_id);

CREATE INDEX IF NOT EXISTS idx_uploaded_documents_baptism_draft
    ON uploaded_documents(baptism_draft_id);

CREATE INDEX IF NOT EXISTS idx_generated_baptism_forms_draft
    ON generated_baptism_forms(draft_id);

CREATE INDEX IF NOT EXISTS idx_generated_baptism_forms_appointment
    ON generated_baptism_forms(appointment_id);

CREATE INDEX IF NOT EXISTS idx_generated_baptism_forms_document
    ON generated_baptism_forms(document_id);

COMMIT;
