BEGIN;

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS requirements_snapshot JSONB NULL;

ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS document_source VARCHAR(20) NOT NULL DEFAULT 'uploaded';

ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS generated_form_type VARCHAR(50) NULL;

CREATE TABLE IF NOT EXISTS generated_wedding_forms (
    generated_form_id SERIAL PRIMARY KEY,
    appointment_id INT NOT NULL REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    form_type VARCHAR(50) NOT NULL CHECK (form_type IN ('matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance')),
    form_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    document_id INT NULL REFERENCES uploaded_documents(document_id) ON DELETE SET NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'generated', 'pending_review', 'approved', 'rejected')),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (appointment_id, form_type)
);

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_document_source_check'
    ) THEN
        ALTER TABLE uploaded_documents ADD CONSTRAINT uploaded_documents_document_source_check
            CHECK (document_source IN ('uploaded', 'generated'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_generated_form_type_check'
    ) THEN
        ALTER TABLE uploaded_documents ADD CONSTRAINT uploaded_documents_generated_form_type_check
            CHECK (generated_form_type IS NULL OR generated_form_type IN ('matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_generated_wedding_forms_appointment ON generated_wedding_forms(appointment_id);
CREATE INDEX IF NOT EXISTS idx_generated_wedding_forms_document ON generated_wedding_forms(document_id);
CREATE INDEX IF NOT EXISTS idx_uploaded_documents_generated_type ON uploaded_documents(generated_form_type);

COMMIT;
