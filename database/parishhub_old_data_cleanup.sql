-- PARISHHUB OLD DATA CLEANUP — DRY RUN / REVIEW ONLY
-- Generated from the read-only audit snapshot at 2026-10-04 01:57:26 UTC.
-- This script deliberately ROLLBACKs. It must not permanently delete data.
-- A separate FINAL EXECUTION script requires explicit owner approval and is
-- not prepared or executed in this task.
-- No accounts, roles, configuration, financial rows, or Storage objects are
-- deleted by this SQL. Storage cleanup is a separate, owner-approved task.
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
    v_donation_count INTEGER;
    v_transaction_count INTEGER;
    v_receipt_count INTEGER;
    v_document_count INTEGER;
    v_exact_document_count INTEGER;
    v_funeral_form_count INTEGER;
    v_exact_funeral_form_count INTEGER;
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
          AND a.service_id = 8
    ) THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 no longer matches the audited runtime-test row';
    END IF;

    SELECT COUNT(*) INTO v_payment_count
    FROM payments
    WHERE appointment_id = 184;
    IF v_payment_count <> 0 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % payment row(s)', v_payment_count;
    END IF;

    SELECT COUNT(*) INTO v_donation_count
    FROM donations
    WHERE appointment_id = 184;
    IF v_donation_count <> 0 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % donation row(s)', v_donation_count;
    END IF;

    SELECT COUNT(*) INTO v_transaction_count
    FROM transactions t
    JOIN payments p ON p.payment_id = t.payment_id
    WHERE p.appointment_id = 184;
    IF v_transaction_count <> 0 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % linked transaction row(s)', v_transaction_count;
    END IF;

    SELECT COUNT(*) INTO v_receipt_count
    FROM official_receipts r
    JOIN payments p ON p.payment_id = r.payment_id
    WHERE p.appointment_id = 184;
    IF v_receipt_count <> 0 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % linked official receipt row(s)', v_receipt_count;
    END IF;

    SELECT COUNT(*) INTO v_document_count
    FROM uploaded_documents
    WHERE appointment_id = 184;
    IF v_document_count <> 2 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % document row(s), expected 2', v_document_count;
    END IF;

    SELECT COUNT(*) INTO v_exact_document_count
    FROM uploaded_documents
    WHERE appointment_id = 184
      AND document_id IN (270, 271);
    IF v_exact_document_count <> 2 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 does not own exactly documents 270 and 271';
    END IF;

    SELECT COUNT(*) INTO v_funeral_form_count
    FROM generated_funeral_forms
    WHERE appointment_id = 184;
    IF v_funeral_form_count <> 1 THEN
        RAISE EXCEPTION 'Precondition failed: appointment 184 has % Funeral form row(s), expected 1', v_funeral_form_count;
    END IF;

    SELECT COUNT(*) INTO v_exact_funeral_form_count
    FROM generated_funeral_forms
    WHERE generated_form_id = 1
      AND appointment_id = 184
      AND document_id = 271
      AND form_type = 'katin_awan_paglubong';
    IF v_exact_funeral_form_count <> 1 THEN
        RAISE EXCEPTION 'Precondition failed: Funeral form 1 is not the audited form for appointment 184';
    END IF;

    -- The only current ON DELETE CASCADE children of appointments are
    -- appointment-owned transaction/detail tables. No identity/account table
    -- may be a cascading child of appointments.
    IF EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class child ON child.oid = c.conrelid
        JOIN pg_namespace child_ns ON child_ns.oid = child.relnamespace
        WHERE c.contype = 'f'
          AND c.confrelid = 'public.appointments'::regclass
          AND c.confdeltype = 'c'
          AND child_ns.nspname = 'public'
          AND child.relname NOT IN (
              'donations', 'generated_baptism_forms', 'generated_funeral_forms',
              'generated_wedding_forms', 'mass_intentions', 'payments',
              'uploaded_documents'
          )
    ) THEN
        RAISE EXCEPTION 'Precondition failed: unexpected cascading child of appointments exists';
    END IF;
END $$;

-- Explicit audited ID only. FK cascades remove the two owned document rows and
-- the one owned generated Funeral form. No sequence is reset.
DELETE FROM appointments
WHERE appointment_id = 184;

-- Post-delete verification runs inside this transaction, then the entire
-- dry run is undone. These checks prove what the approved execution would
-- remove without leaving a permanent change.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM appointments WHERE appointment_id = 184) THEN
        RAISE EXCEPTION 'Dry-run verification failed: appointment 184 still exists';
    END IF;
    IF EXISTS (SELECT 1 FROM uploaded_documents WHERE document_id IN (270, 271)) THEN
        RAISE EXCEPTION 'Dry-run verification failed: document 270 or 271 still exists';
    END IF;
    IF EXISTS (SELECT 1 FROM generated_funeral_forms WHERE generated_form_id = 1) THEN
        RAISE EXCEPTION 'Dry-run verification failed: Funeral form 1 still exists';
    END IF;
    IF EXISTS (SELECT 1 FROM payments WHERE appointment_id = 184) THEN
        RAISE EXCEPTION 'Dry-run verification failed: payment rows appeared';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM transactions t
        JOIN payments p ON p.payment_id = t.payment_id
        WHERE p.appointment_id = 184
    ) THEN
        RAISE EXCEPTION 'Dry-run verification failed: linked transactions appeared';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM official_receipts r
        JOIN payments p ON p.payment_id = r.payment_id
        WHERE p.appointment_id = 184
    ) THEN
        RAISE EXCEPTION 'Dry-run verification failed: linked receipts appeared';
    END IF;
END $$;

ROLLBACK;

-- FINAL EXECUTION — REQUIRES OWNER APPROVAL
-- Do not convert this review script to COMMIT casually. A separately
-- approved execution script must repeat the preconditions immediately before
-- DELETE, take the required database/storage backups, perform the same
-- post-delete verification, and only then COMMIT. The execution version must
-- not delete Storage objects, reset sequences, or touch account/config tables.
