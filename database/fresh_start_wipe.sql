-- ============================================================
-- PARISHHUB — FRESH START WIPE (Supabase / PostgreSQL)
-- Deletes ALL test/transactional data.
-- PRESERVES: roles, settings, services, requirements,
--            appointment_status, payment_methods, priests,
--            and ALL non-Parishioner staff accounts.
-- WARNING: This is IRREVERSIBLE. Take a backup first.
-- ============================================================

-- Use CASCADE to safely truncate tables while bypassing foreign key issues
TRUNCATE TABLE 
  generated_funeral_forms,
  generated_wedding_forms,
  generated_baptism_forms,
  wedding_booking_drafts,
  baptism_booking_drafts,
  transactions,
  official_receipts,
  payments,
  donations,
  uploaded_documents,
  mass_intentions,
  appointments,
  parishioners,
  chat_messages,
  notifications,
  activity_logs,
  reports,
  app_sessions,
  projects,
  announcements,
  calendar,
  events 
CASCADE;

-- ── Delete Parishioner user accounts ─────────────────────
-- role_id = 1 = Parishioner. The guest@parishhub.internal
-- placeholder is kept so guest bookings continue to work.
DELETE FROM users
WHERE role_id = 1
  AND email != 'guest@parishhub.internal';

-- ── Re-seed the guest parishioner placeholder ─────────────
-- The TRUNCATE above wiped the parishioners table. If the
-- guest user row still exists in `users`, rebuild its
-- parishioner record so guest bookings have a valid FK.
INSERT INTO parishioners (user_id)
SELECT user_id FROM users WHERE email = 'guest@parishhub.internal'
ON CONFLICT DO NOTHING;

SELECT 'Fresh start complete. Staff accounts, services, priests, and settings preserved.' AS status;
