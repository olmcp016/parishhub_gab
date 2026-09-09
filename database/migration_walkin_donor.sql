-- ==========================================================
-- PARISHHUB — Migration: Walk-in Donor placeholder account
-- Postgres/Supabase only. Safe to run more than once.
--
-- Manual donations recorded by staff (Secretary/Admin) for someone who
-- isn't a registered parishioner still need a valid parishioner_id
-- (appointments.parishioner_id is NOT NULL). This creates one inert,
-- login-disabled placeholder account for staff-recorded donations with
-- no linked parishioner — the actual donor's name/email is still
-- captured in donations.donor_name / donor_email either way.
-- ==========================================================

INSERT INTO users (role_id, firstname, lastname, email, password, status)
SELECT 1, 'Walk-in', 'Donor', 'walkin-donor@parishhub.internal',
       '$2y$10$invalidhashinvalidhashinvalidhashinvalidhashinvalidha', 'inactive'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'walkin-donor@parishhub.internal');

INSERT INTO parishioners (user_id)
SELECT user_id FROM users WHERE email = 'walkin-donor@parishhub.internal'
AND NOT EXISTS (
    SELECT 1 FROM parishioners p
    JOIN users u ON p.user_id = u.user_id
    WHERE u.email = 'walkin-donor@parishhub.internal'
);
