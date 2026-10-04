-- PARISHHUB OLD DATA CLEANUP — REVIEW ONLY
-- Generated from the read-only audit snapshot at 2026-10-04 01:57:26 UTC.
-- DO NOT EXECUTE until the owner explicitly approves appointment 184.
-- No Storage objects are deleted by this SQL. Handle Storage separately only
-- after backing up the two local files and verifying the DB deletion.
--
-- Expected DB cascade graph if approved:
-- appointments(184)
--   -> uploaded_documents(270, 271)
--   -> generated_funeral_forms(1)
-- There are no payments, transactions, donations, or receipts for appointment 184.

BEGIN;

DO $$
DECLARE
    v_payment_count INTEGER;
    v_document_count INTEGER;
    v_funeral_form_count INTEGER;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM appointments a
        WHERE a.appointment_id = 184
          AND a.guest_reference = 'PH-7A02B8'
          AND a.guest_name = 'Runtime Test Guest'
          AND a.guest_email = 'runtime-test@example.test'
          AND a.appointment_date IS NULL
          AND a.appointment_time IS NULL
          AND a.status_id = 1
    ) THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 no longer matches the audited runtime-test row';
    END IF;

    SELECT COUNT(*) INTO v_payment_count
    FROM payments
    WHERE appointment_id = 184;
    IF v_payment_count <> 0 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % payment row(s)', v_payment_count;
    END IF;

    SELECT COUNT(*) INTO v_document_count
    FROM uploaded_documents
    WHERE appointment_id = 184;
    IF v_document_count <> 2 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % document row(s), expected 2', v_document_count;
    END IF;

    SELECT COUNT(*) INTO v_funeral_form_count
    FROM generated_funeral_forms
    WHERE appointment_id = 184;
    IF v_funeral_form_count <> 1 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % Funeral form row(s), expected 1', v_funeral_form_count;
    END IF;
END $$;

-- Explicit audited ID only. FK cascades remove the two owned document rows and
-- the one owned generated Funeral form. No sequence is reset.
DELETE FROM appointments
WHERE appointment_id = 184;

COMMIT;
