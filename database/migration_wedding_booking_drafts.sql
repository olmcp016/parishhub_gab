-- Wedding booking drafts
--
-- Depends on:
--   1. migration_document_review.sql
--   2. migration_wedding_generated_forms.sql
--
-- This migration only adds staging structure. It does not create appointments,
-- upload files, move Storage objects, or alter existing appointment/document
-- ownership. Existing rows remain appointment-owned.

BEGIN;

CREATE TABLE IF NOT EXISTS wedding_booking_drafts (
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
    wedding_sponsor_count INT NULL,
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

    CONSTRAINT wedding_booking_drafts_owner_check
        CHECK (
            (parishioner_id IS NOT NULL AND guest_access_token_hash IS NULL)
            OR
            (parishioner_id IS NULL AND guest_access_token_hash IS NOT NULL)
        ),
    CONSTRAINT wedding_booking_drafts_guest_identity_check
        CHECK (
            parishioner_id IS NOT NULL
            OR (guest_name IS NOT NULL AND guest_phone IS NOT NULL)
        ),
    CONSTRAINT wedding_booking_drafts_status_check
        CHECK (status IN ('draft', 'finalized', 'expired', 'abandoned')),
    CONSTRAINT wedding_booking_drafts_schedule_type_check
        CHECK (schedule_type IS NULL OR schedule_type IN ('Regular', 'Special')),
    CONSTRAINT wedding_booking_drafts_pss_claim_check
        CHECK (pss_claim IS NULL OR pss_claim IN ('pss', 'non_pss')),
    CONSTRAINT wedding_booking_drafts_sponsor_count_check
        CHECK (sponsor_count IS NULL OR sponsor_count BETWEEN 0 AND 100),
    CONSTRAINT wedding_booking_drafts_wedding_sponsor_count_check
        CHECK (wedding_sponsor_count IS NULL OR wedding_sponsor_count BETWEEN 0 AND 200),
    CONSTRAINT wedding_booking_drafts_finalized_state_check
        CHECK (
            (status = 'finalized' AND finalized_appointment_id IS NOT NULL)
            OR
            (status <> 'finalized' AND finalized_appointment_id IS NULL)
        ),
    CONSTRAINT wedding_booking_drafts_token_or_parishioner_unique
        UNIQUE (guest_access_token_hash)
);

ALTER TABLE uploaded_documents
    ALTER COLUMN appointment_id DROP NOT NULL;

ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS draft_id BIGINT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_draft_fk'
    ) THEN
        ALTER TABLE uploaded_documents
            ADD CONSTRAINT uploaded_documents_draft_fk
            FOREIGN KEY (draft_id)
            REFERENCES wedding_booking_drafts(draft_id)
            ON DELETE CASCADE;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_owner_check'
    ) THEN
        ALTER TABLE uploaded_documents
            ADD CONSTRAINT uploaded_documents_owner_check
            CHECK ((appointment_id IS NOT NULL) <> (draft_id IS NOT NULL));
    END IF;
END $$;

ALTER TABLE generated_wedding_forms
    ALTER COLUMN appointment_id DROP NOT NULL;

ALTER TABLE generated_wedding_forms
    ADD COLUMN IF NOT EXISTS draft_id BIGINT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.generated_wedding_forms'::regclass
          AND conname = 'generated_wedding_forms_draft_fk'
    ) THEN
        ALTER TABLE generated_wedding_forms
            ADD CONSTRAINT generated_wedding_forms_draft_fk
            FOREIGN KEY (draft_id)
            REFERENCES wedding_booking_drafts(draft_id)
            ON DELETE CASCADE;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.generated_wedding_forms'::regclass
          AND conname = 'generated_wedding_forms_owner_check'
    ) THEN
        ALTER TABLE generated_wedding_forms
            ADD CONSTRAINT generated_wedding_forms_owner_check
            CHECK ((appointment_id IS NOT NULL) <> (draft_id IS NOT NULL));
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS idx_generated_wedding_forms_draft_type
    ON generated_wedding_forms(draft_id, form_type)
    WHERE draft_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_wedding_booking_drafts_parishioner
    ON wedding_booking_drafts(parishioner_id);

CREATE INDEX IF NOT EXISTS idx_wedding_booking_drafts_status_expiry
    ON wedding_booking_drafts(status, expires_at);

CREATE INDEX IF NOT EXISTS idx_uploaded_documents_draft
    ON uploaded_documents(draft_id);

CREATE INDEX IF NOT EXISTS idx_generated_wedding_forms_draft
    ON generated_wedding_forms(draft_id);

COMMIT;
