BEGIN;

CREATE TABLE IF NOT EXISTS generated_funeral_forms (
    generated_form_id BIGSERIAL PRIMARY KEY,
    appointment_id INT NOT NULL
        REFERENCES appointments(appointment_id)
        ON DELETE CASCADE,
    form_type VARCHAR(60) NOT NULL
        CHECK (form_type = 'katin_awan_paglubong'),
    form_data JSONB NOT NULL DEFAULT '{}'::jsonb,
    document_id INT NULL
        REFERENCES uploaded_documents(document_id)
        ON DELETE SET NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'generated', 'rejected')),
    rejection_reason TEXT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT funeral_forms_uniq UNIQUE (appointment_id, form_type)
);

COMMIT;
