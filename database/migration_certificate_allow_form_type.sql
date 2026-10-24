-- =============================================================================
-- Migration: Allow 'official_certificate' in uploaded_documents
-- Fixes: "The certificate could not be generated. Please try again."
-- PostgreSQL / Supabase compatible. Safe to re-run.
-- =============================================================================

DO $$
DECLARE
    c record;
BEGIN
    -- Drop any existing generated_form_type check constraints
    FOR c IN (
        SELECT conname
        FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname LIKE 'uploaded_documents_generated_form_type_check%'
    ) LOOP
        EXECUTE format('ALTER TABLE uploaded_documents DROP CONSTRAINT %I;', c.conname);
    END LOOP;

    -- Add updated constraint including 'official_certificate'
    ALTER TABLE uploaded_documents
        ADD CONSTRAINT uploaded_documents_generated_form_type_check_v4
        CHECK (
            generated_form_type IS NULL
            OR generated_form_type IN (
                'matrimony_application',
                'cluster_clearance',
                'wedding_sponsor_clearance',
                'katin_awan_bunyag',
                'cluster_clearance_baptism_sponsor',
                'katin_awan_paglubong',
                'official_certificate'
            )
        );
END $$;
