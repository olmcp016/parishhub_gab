BEGIN;

ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS review_status VARCHAR(20) NULL;
ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;
ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS reviewed_by INT NULL
        REFERENCES users(user_id) ON DELETE SET NULL;
ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP WITH TIME ZONE NULL;
ALTER TABLE uploaded_documents
    ADD COLUMN IF NOT EXISTS superseded_by INT NULL
        REFERENCES uploaded_documents(document_id) ON DELETE SET NULL;

UPDATE uploaded_documents
SET review_status = CASE WHEN verified THEN 'approved' ELSE 'pending' END
WHERE review_status IS NULL;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.uploaded_documents'::regclass
          AND conname = 'uploaded_documents_review_status_check'
    ) THEN
        ALTER TABLE uploaded_documents
        ADD CONSTRAINT uploaded_documents_review_status_check
        CHECK (review_status IN ('pending', 'approved', 'rejected'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_uploaded_documents_review_status
    ON uploaded_documents(review_status);
CREATE INDEX IF NOT EXISTS idx_uploaded_documents_superseded_by
    ON uploaded_documents(superseded_by);

COMMIT;
