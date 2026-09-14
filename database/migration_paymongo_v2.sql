-- A PayMongo-verified payment is confirmed by the gateway/system, not a
-- human staff member — issued_by must be able to be NULL for that case.
ALTER TABLE official_receipts ALTER COLUMN issued_by DROP NOT NULL;
