-- ParishHub: classify managed locations without changing existing records.
ALTER TABLE locations
    ADD COLUMN IF NOT EXISTS location_category VARCHAR(20) NOT NULL DEFAULT 'other';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.locations'::regclass
          AND conname = 'locations_category_check'
    ) THEN
        ALTER TABLE locations
            ADD CONSTRAINT locations_category_check
            CHECK (location_category IN ('barangay', 'school', 'chapel', 'other'));
    END IF;
END $$;
