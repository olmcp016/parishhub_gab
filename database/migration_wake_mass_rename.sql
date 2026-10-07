-- Migration: Rename "Misa sa Haya" to "Wake Mass / Death Anniversary"
-- and set fee to PHP 1,500.
-- PostgreSQL / Supabase compatible.

UPDATE services
SET service_name = 'Wake Mass / Death Anniversary',
    description  = 'Mass for the dead during the wake or at the cemetery on the death anniversary.',
    fee          = 1500.00
WHERE category = 'Wake';
