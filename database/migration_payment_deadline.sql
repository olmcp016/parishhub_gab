-- Migration: Add payment_deadline to appointments table
-- Enables tracking the payment deadline calculated upon appointment approval.
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS payment_deadline TIMESTAMP WITH TIME ZONE DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_appointments_payment_deadline ON appointments (payment_deadline);
