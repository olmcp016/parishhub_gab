-- ==============================================================================
-- MIGRATION: Service Process Alignment Corrections
-- ==============================================================================
--
-- Note: This is designed for PostgreSQL / Supabase compatibility.
-- Make sure to run this against the Supabase database.
--
-- ==============================================================================

-- 1. Alter appointments table to allow NULL for appointment_date and appointment_time
-- Confirmation and First Communion no longer require these fields at booking time.
ALTER TABLE appointments ALTER COLUMN appointment_date DROP NOT NULL;
ALTER TABLE appointments ALTER COLUMN appointment_time DROP NOT NULL;

-- 2. Insert Wake/Haya service if missing.
-- Uses an idempotent INSERT ... SELECT pattern.
INSERT INTO services (service_name, category, description, fee, requirements)
SELECT 'Misa sa Haya / Misa sa Sementeryo atol sa Sumad sa Kamatayon', 'Wake', 'Mass for the dead during wake or at the cemetery during death anniversary.', 1500, ''
WHERE NOT EXISTS (
    SELECT 1 FROM services WHERE category = 'Wake'
);

-- 3. Update First Communion fee
-- Set base_fee to 0 for First Communion.
UPDATE services 
SET fee = 0 
WHERE category = 'First Communion';

UPDATE service_fee_rules 
SET base_fee = 0 
WHERE category = 'First Communion';

-- 4. Update Wedding Requirements
UPDATE services 
SET requirements = 'Groom''s Baptismal Certificate, Bride''s Baptismal Certificate, Groom''s Confirmation Certificate, Bride''s Confirmation Certificate, Sponsors'' Baptismal Certificate, Marriage License, Pre-Cana Seminar Certificate, CENOMAR' 
WHERE category = 'Wedding';

-- 5. Update Baptism Requirements
UPDATE services 
SET requirements = 'Child Certificate of Live Birth, Sponsors'' Baptismal Certificate, Parents'' Marriage Contract (if married)' 
WHERE category = 'Baptism';

-- 6. Update Confirmation Requirements
UPDATE services 
SET requirements = 'Child Baptismal Certificate, Sponsor Confirmation Certificate' 
WHERE category = 'Confirmation';
