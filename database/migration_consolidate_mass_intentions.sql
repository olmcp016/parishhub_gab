-- ==========================================================
-- PARISHHUB — Migration: Consolidate Mass Intentions into one service
-- Postgres/Supabase only. Safe to run more than once.
--
-- Previously there were 5 separate "Mass Intention - X" services
-- (Living/Dead/Thanksgiving/Healing/Birthday), each its own bookable
-- card, even though the actual intention type was ALSO chosen via a
-- dropdown inside the booking form itself — redundant and confusing.
--
-- This keeps service_id=1 as the one active "Mass Intentions" service
-- and deactivates the other 4 (not deleted — existing appointments
-- still correctly reference their original service_id/name for
-- historical accuracy; they just no longer appear as separate,
-- bookable options going forward).
-- ==========================================================

UPDATE services
SET service_name = 'Mass Intentions',
    description = 'Request a Mass to be offered for your intention — for a living or deceased loved one, in thanksgiving, for healing, or for a birthday blessing. Choose the specific intention type when you enter your details.',
    requirements = NULL
WHERE service_id = 1;

UPDATE services
SET is_active = FALSE
WHERE category = 'Mass Intention' AND service_id != 1;
