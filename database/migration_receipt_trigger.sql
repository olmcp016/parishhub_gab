-- Migration: Auto-generate official receipt numbers via a DB trigger
-- Target: PostgreSQL (Supabase)
-- Run this AFTER taking a full database backup.
-- This migration is idempotent: safe to re-run.
--
-- WHAT THIS DOES:
--   A BEFORE INSERT trigger on official_receipts sets receipt_number
--   automatically using the format: OR-{YEAR}-{payment_id zero-padded to 6 digits}
--   e.g. OR-2026-000042
--
--   PHP no longer needs to build the receipt number string; it just inserts
--   (payment_id, issued_by) and reads the generated number back via RETURNING.

BEGIN;

CREATE OR REPLACE FUNCTION fn_set_receipt_number()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.receipt_number IS NULL OR NEW.receipt_number = '' THEN
        NEW.receipt_number :=
            'OR-' || to_char(CURRENT_DATE, 'YYYY') || '-'
            || LPAD(NEW.payment_id::text, 6, '0');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Drop the old trigger if it exists (allows re-running this migration)
DROP TRIGGER IF EXISTS trg_set_receipt_number ON official_receipts;

CREATE TRIGGER trg_set_receipt_number
    BEFORE INSERT ON official_receipts
    FOR EACH ROW
    EXECUTE FUNCTION fn_set_receipt_number();

COMMIT;
