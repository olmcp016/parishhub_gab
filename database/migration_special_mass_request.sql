-- Migration: Replace "Death Anniversary" with "Special Mass Request".
-- The service now covers any special intention (birthdays, thanksgiving,
-- anniversaries, healing, etc.), not only death anniversaries.
-- PostgreSQL / Supabase compatible. Safe to re-run.

UPDATE services
SET service_name = 'Special Mass Request',
    description  = 'Mass offered for special intentions such as birthdays, thanksgiving, wedding anniversaries, healing and recovery, death anniversaries, and other occasions.'
WHERE category = 'Wake'
  AND service_name = 'Death Anniversary';
