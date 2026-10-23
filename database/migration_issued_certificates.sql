-- Migration: Official Parish Certificate Generation & Issuance
-- (Certificate of Baptism / Marriage / Confirmation / Death)
-- PostgreSQL / Supabase compatible. Safe to re-run.

CREATE TABLE IF NOT EXISTS issued_certificates (
    issued_certificate_id BIGSERIAL PRIMARY KEY,
    -- The appointment this certificate record belongs to: the completed
    -- sacrament appointment itself for a first-time issuance (FLOW 1), or the
    -- "Certificate Request" appointment for a re-issuance (FLOW 2).
    appointment_id INT NOT NULL REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    -- FLOW 2 only: the original completed sacrament appointment this reissued
    -- copy was matched against via "Search & Autofill from Parish Records".
    -- NULL when typed in manually from a pre-system physical record.
    source_appointment_id INT DEFAULT NULL REFERENCES appointments(appointment_id) ON DELETE SET NULL,
    certificate_type VARCHAR(20) NOT NULL
        CHECK (certificate_type IN ('Baptism', 'Marriage', 'Confirmation', 'Death')),
    -- Canonical church registry reference (the physical book this sacrament was recorded in).
    book_no VARCHAR(50) DEFAULT NULL,
    page_no VARCHAR(50) DEFAULT NULL,
    line_no VARCHAR(50) DEFAULT NULL, -- "Line" (Marriage) / "Serial No." (Baptism) / "Register No." (Confirmation) / "Folio No." (Death)
    registry_date DATE DEFAULT NULL,
    -- FLOW 2 only: why the parishioner needs this certified copy.
    certificate_purpose VARCHAR(150) DEFAULT NULL,
    -- All the certificate's printed fields (names, dates, parents, sponsors, etc.) — one
    -- flexible JSON blob, same pattern as generated_forms.form_data, since the four
    -- certificate types have very different field sets.
    form_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    document_id INT DEFAULT NULL REFERENCES uploaded_documents(document_id) ON DELETE SET NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'generated', 'ready_for_pickup', 'claimed')),
    issued_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    issued_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- One certificate record per appointment (re-generating updates the same row in place,
-- same convention as generated_forms).
CREATE UNIQUE INDEX IF NOT EXISTS idx_issued_certificates_appointment
    ON issued_certificates(appointment_id);

CREATE INDEX IF NOT EXISTS idx_issued_certificates_source
    ON issued_certificates(source_appointment_id);

CREATE INDEX IF NOT EXISTS idx_issued_certificates_document
    ON issued_certificates(document_id);

DROP TRIGGER IF EXISTS set_timestamp_issued_certificates ON issued_certificates;
CREATE TRIGGER set_timestamp_issued_certificates
BEFORE UPDATE ON issued_certificates
FOR EACH ROW EXECUTE FUNCTION trigger_set_timestamp();
