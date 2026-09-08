-- ==========================================================
-- PARISHHUB — Migration: Rejection Reason
-- Postgres/Supabase only. Safe to run more than once.
--
-- Adds a dedicated rejection_reason column (mirrors cancelled_reason)
-- so rejecting an appointment no longer overwrites the parishioner's
-- own booking remarks. Also auto-approves any legacy Mass Intention /
-- Donation appointments still stuck in Pending from before those
-- categories were made auto-approve-on-submit (no-op if none exist).
-- ==========================================================

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(255) DEFAULT NULL;

UPDATE appointments a
SET status_id = 2, approved_at = COALESCE(a.approved_at, NOW())
FROM services s
WHERE a.service_id = s.service_id
  AND s.category IN ('Mass Intention', 'Donation')
  AND a.status_id = 1;
