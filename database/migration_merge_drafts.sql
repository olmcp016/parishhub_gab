-- Migration: Merge wedding_booking_drafts + baptism_booking_drafts → appointment_drafts
-- Target: PostgreSQL (Supabase)
-- Run this AFTER taking a full database backup.
-- This migration is idempotent: safe to re-run.
--
-- NOTES:
--   • The two old tables had independent BIGSERIAL sequences, so their draft_ids
--     overlap (both have draft_id 1, 2, 3...). Wedding draft IDs are preserved.
--     Baptism draft IDs are reassigned; the migration remaps all FK references.
--   • uploaded_documents.baptism_draft_id is dropped; all baptism-owned docs
--     move to the shared draft_id column.

BEGIN;

-- ── 1. Create the unified table ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS appointment_drafts (
    draft_id                BIGSERIAL PRIMARY KEY,
    service_type            VARCHAR(20)  NOT NULL
        CHECK (service_type IN ('Wedding', 'Baptism')),
    parishioner_id          INT          NULL
        REFERENCES parishioners(parishioner_id) ON DELETE CASCADE,
    guest_access_token_hash CHAR(64)     NULL,
    guest_name              VARCHAR(255) NULL,
    guest_email             VARCHAR(255) NULL,
    guest_phone             VARCHAR(30)  NULL,
    service_id              INT          NOT NULL
        REFERENCES services(service_id) ON DELETE RESTRICT,
    priest_id               INT          NULL
        REFERENCES priests(priest_id) ON DELETE SET NULL,
    appointment_date        DATE         NULL,
    appointment_time        TIME         NULL,
    schedule_type           VARCHAR(20)  NULL
        CHECK (schedule_type IS NULL OR schedule_type IN ('Regular', 'Special')),
    pss_claim               VARCHAR(20)  NULL
        CHECK (pss_claim IS NULL OR pss_claim IN ('pss', 'non_pss')),
    sponsor_count           INT          NULL
        CHECK (sponsor_count IS NULL OR sponsor_count BETWEEN 0 AND 100),
    wedding_sponsor_count   INT          NULL
        CHECK (wedding_sponsor_count IS NULL OR wedding_sponsor_count BETWEEN 0 AND 200),
    remarks                 TEXT         NULL,
    contact_phone           VARCHAR(30)  NULL,
    location_address        TEXT         NULL,
    status                  VARCHAR(20)  NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'finalized', 'expired', 'abandoned')),
    created_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at              TIMESTAMP WITH TIME ZONE NOT NULL
        DEFAULT (CURRENT_TIMESTAMP + INTERVAL '24 hours'),
    finalized_appointment_id INT         NULL
        REFERENCES appointments(appointment_id) ON DELETE RESTRICT,

    CONSTRAINT appointment_drafts_owner_check
        CHECK (
            (parishioner_id IS NOT NULL AND guest_access_token_hash IS NULL)
            OR
            (parishioner_id IS NULL AND guest_access_token_hash IS NOT NULL)
        ),
    CONSTRAINT appointment_drafts_guest_identity_check
        CHECK (
            parishioner_id IS NOT NULL
            OR (guest_name IS NOT NULL AND guest_phone IS NOT NULL)
        ),
    CONSTRAINT appointment_drafts_finalized_state_check
        CHECK (
            (status = 'finalized' AND finalized_appointment_id IS NOT NULL)
            OR
            (status <> 'finalized' AND finalized_appointment_id IS NULL)
        ),
    CONSTRAINT appointment_drafts_token_unique
        UNIQUE (guest_access_token_hash)
);

-- Temp column: tracks the original baptism_booking_drafts.draft_id during migration
ALTER TABLE appointment_drafts
    ADD COLUMN IF NOT EXISTS _old_baptism_draft_id BIGINT NULL;


-- ── 2. Migrate wedding drafts (preserve original draft_id values) ─────────────

INSERT INTO appointment_drafts (
    draft_id, service_type, parishioner_id, guest_access_token_hash,
    guest_name, guest_email, guest_phone, service_id, priest_id,
    appointment_date, appointment_time, schedule_type, pss_claim,
    sponsor_count, wedding_sponsor_count, remarks, contact_phone,
    location_address, status, created_at, updated_at, expires_at,
    finalized_appointment_id, _old_baptism_draft_id
)
SELECT
    draft_id, 'Wedding', parishioner_id, guest_access_token_hash,
    guest_name, guest_email, guest_phone, service_id, priest_id,
    appointment_date, appointment_time, schedule_type, pss_claim,
    sponsor_count, wedding_sponsor_count, remarks, contact_phone,
    location_address, status, created_at, updated_at, expires_at,
    finalized_appointment_id, NULL
FROM wedding_booking_drafts
ON CONFLICT (draft_id) DO NOTHING;

-- Advance the sequence past all wedding draft IDs
SELECT setval(
    pg_get_serial_sequence('appointment_drafts', 'draft_id'),
    COALESCE((SELECT MAX(draft_id) FROM appointment_drafts), 1)
);


-- ── 3. Migrate baptism drafts (new IDs; track old IDs for FK remapping) ──────

INSERT INTO appointment_drafts (
    service_type, parishioner_id, guest_access_token_hash,
    guest_name, guest_email, guest_phone, service_id, priest_id,
    appointment_date, appointment_time, schedule_type, pss_claim,
    sponsor_count, wedding_sponsor_count, remarks, contact_phone,
    location_address, status, created_at, updated_at, expires_at,
    finalized_appointment_id, _old_baptism_draft_id
)
SELECT
    'Baptism', parishioner_id, guest_access_token_hash,
    guest_name, guest_email, guest_phone, service_id, priest_id,
    appointment_date, appointment_time, schedule_type, pss_claim,
    sponsor_count, NULL, remarks, contact_phone,
    location_address, status, created_at, updated_at, expires_at,
    finalized_appointment_id, draft_id
FROM baptism_booking_drafts
WHERE NOT EXISTS (
    SELECT 1 FROM appointment_drafts ad
    WHERE ad._old_baptism_draft_id = baptism_booking_drafts.draft_id
      AND ad.service_type = 'Baptism'
);


-- ── 4. Remap uploaded_documents rows owned by baptism drafts ─────────────────
--    Move baptism_draft_id value → draft_id (using the new appointment_drafts id)

-- Drop old FK constraints FIRST before updating the columns
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploaded_documents_baptism_draft_fk'
               AND conrelid = 'public.uploaded_documents'::regclass) THEN
        ALTER TABLE uploaded_documents DROP CONSTRAINT uploaded_documents_baptism_draft_fk;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploaded_documents_owner_check_v2'
               AND conrelid = 'public.uploaded_documents'::regclass) THEN
        ALTER TABLE uploaded_documents DROP CONSTRAINT uploaded_documents_owner_check_v2;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploaded_documents_draft_fk'
               AND conrelid = 'public.uploaded_documents'::regclass) THEN
        ALTER TABLE uploaded_documents DROP CONSTRAINT uploaded_documents_draft_fk;
    END IF;
END $$;

UPDATE uploaded_documents ud
SET draft_id          = ad.draft_id,
    baptism_draft_id  = NULL
FROM appointment_drafts ad
WHERE ad._old_baptism_draft_id = ud.baptism_draft_id
  AND ad.service_type = 'Baptism'
  AND ud.baptism_draft_id IS NOT NULL;


-- ── 5. Remap generated_forms.draft_id for Baptism rows ───────────────────────

UPDATE generated_forms gf
SET draft_id = ad.draft_id
FROM appointment_drafts ad
WHERE ad._old_baptism_draft_id = gf.draft_id
  AND ad.service_type = 'Baptism'
  AND gf.service_category = 'Baptism'
  AND gf.draft_id IS NOT NULL;


-- ── 6. Clean up temp column ───────────────────────────────────────────────────

ALTER TABLE appointment_drafts DROP COLUMN IF EXISTS _old_baptism_draft_id;


-- ── 7. Update uploaded_documents constraints ─────────────────────────────────

-- Drop the baptism_draft_id column (all rows already migrated to draft_id)
ALTER TABLE uploaded_documents DROP COLUMN IF EXISTS baptism_draft_id;

-- Restore the owner check (now only appointment_id vs draft_id)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploaded_documents_owner_check'
                   AND conrelid = 'public.uploaded_documents'::regclass) THEN
        ALTER TABLE uploaded_documents
            ADD CONSTRAINT uploaded_documents_owner_check
            CHECK ((appointment_id IS NOT NULL) <> (draft_id IS NOT NULL));
    END IF;
END $$;

-- Add unified FK: uploaded_documents.draft_id → appointment_drafts(draft_id)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uploaded_documents_draft_fk'
                   AND conrelid = 'public.uploaded_documents'::regclass) THEN
        ALTER TABLE uploaded_documents
            ADD CONSTRAINT uploaded_documents_draft_fk
            FOREIGN KEY (draft_id)
            REFERENCES appointment_drafts(draft_id)
            ON DELETE CASCADE;
    END IF;
END $$;


-- ── 8. Add FK on generated_forms.draft_id → appointment_drafts ───────────────

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'generated_forms_draft_fk'
                   AND conrelid = 'public.generated_forms'::regclass) THEN
        ALTER TABLE generated_forms
            ADD CONSTRAINT generated_forms_draft_fk
            FOREIGN KEY (draft_id)
            REFERENCES appointment_drafts(draft_id)
            ON DELETE CASCADE;
    END IF;
END $$;


-- ── 9. Drop old tables ────────────────────────────────────────────────────────

DROP TABLE IF EXISTS wedding_booking_drafts CASCADE;
DROP TABLE IF EXISTS baptism_booking_drafts  CASCADE;


COMMIT;
