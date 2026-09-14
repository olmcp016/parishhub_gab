-- ==========================================================
-- PARISHHUB — Migration: Guest (no-account) booking/donation support
-- Postgres/Supabase only. Safe to run more than once.
--
-- appointments.parishioner_id is NOT NULL, and dozens of existing
-- admin/secretary/treasurer queries INNER JOIN through it to a real
-- parishioners->users row — loosening that FK would require auditing
-- all of them. Instead, exactly like the existing walk-in-donor
-- pattern (migration_walkin_donor.sql), a single shared, login-disabled
-- placeholder parishioner satisfies the FK for ANY guest-submitted
-- appointment type (bookings, Mass Intentions, donations), while the
-- guest's real contact info and a public lookup code live in new
-- nullable columns directly on appointments.
-- ==========================================================

INSERT INTO users (role_id, firstname, lastname, email, password, status)
SELECT 1, 'Guest', 'Parishioner', 'guest@parishhub.internal',
       '$2y$10$invalidhashinvalidhashinvalidhashinvalidhashinvalidha', 'inactive'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'guest@parishhub.internal');

INSERT INTO parishioners (user_id)
SELECT user_id FROM users WHERE email = 'guest@parishhub.internal'
AND NOT EXISTS (
    SELECT 1 FROM parishioners p
    JOIN users u ON p.user_id = u.user_id
    WHERE u.email = 'guest@parishhub.internal'
);

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS guest_name VARCHAR(150) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS guest_email VARCHAR(150) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS guest_phone VARCHAR(20) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS guest_reference VARCHAR(20) DEFAULT NULL UNIQUE;
CREATE INDEX IF NOT EXISTS idx_appt_guest_reference ON appointments(guest_reference);
