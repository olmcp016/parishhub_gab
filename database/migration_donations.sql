-- ==========================================================
-- PARISHHUB — Migration: Donations
-- Postgres/Supabase only (this project's live database). Safe to run
-- more than once — every statement is idempotent and none of it
-- touches or deletes existing data.
--
-- Adds:
--   1. A new 'Donation' service_category enum value
--   2. A `donations` detail table (donor name/email/purpose/message),
--      extending `appointments` the same way `mass_intentions` does
--   3. A 'PayPal' payment method
--   4. A single "General Donation" service row (fee is always
--      voluntary/parishioner-entered, so fee = 0.00)
--   5. A `donation_enabled` setting flag (default on) that the
--      secretary can toggle to show/hide the Donate button
--
-- IMPORTANT: run the ALTER TYPE statement on its own (Postgres does
-- not allow ADD VALUE inside a DO block or the same transaction as
-- statements that use the new value) before running the rest.
-- ==========================================================

ALTER TYPE service_category ADD VALUE IF NOT EXISTS 'Donation';

CREATE TABLE IF NOT EXISTS donations (
    donation_id SERIAL PRIMARY KEY,
    appointment_id INT NOT NULL REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    donor_name VARCHAR(150),
    donor_email VARCHAR(150),
    purpose VARCHAR(50),
    message TEXT
);

INSERT INTO payment_methods (method_name) VALUES ('PayPal')
ON CONFLICT (method_name) DO NOTHING;

INSERT INTO services (service_name, category, description, fee, requirements, duration_minutes, is_active)
SELECT 'General Donation', 'Donation', 'Support our parish through a voluntary donation — no fixed amount.', 0.00, NULL, 10, TRUE
WHERE NOT EXISTS (SELECT 1 FROM services WHERE category = 'Donation');

INSERT INTO settings (setting_key, setting_value) VALUES ('donation_enabled', '1')
ON CONFLICT (setting_key) DO NOTHING;
