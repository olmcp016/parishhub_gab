BEGIN;

CREATE TABLE IF NOT EXISTS service_fee_rules (
    fee_rule_id SERIAL PRIMARY KEY,
    service_category VARCHAR(50) NOT NULL,
    schedule_type VARCHAR(10) NOT NULL CHECK (schedule_type IN ('Regular','Special','Any')),
    pss_classification VARCHAR(20) NOT NULL CHECK (pss_classification IN ('pss','non_pss')),
    base_fee NUMERIC(10,2) NOT NULL DEFAULT 0,
    priest_stipend NUMERIC(10,2) NOT NULL DEFAULT 0,
    included_sponsors INT NOT NULL DEFAULT 0,
    additional_sponsor_fee NUMERIC(10,2) NOT NULL DEFAULT 0,
    UNIQUE (service_category, schedule_type, pss_classification)
);

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS pss_classification VARCHAR(20) NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS pss_claim VARCHAR(20) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS sponsor_count INT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS wedding_sponsor_count INT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS fee_snapshot JSONB DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS pss_verified_by INT NULL
    REFERENCES users(user_id) ON DELETE SET NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS pss_verified_at TIMESTAMP NULL;

DO $$ BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.appointments'::regclass
          AND conname = 'appointments_pss_classification_check'
    ) THEN
        ALTER TABLE appointments ADD CONSTRAINT appointments_pss_classification_check CHECK (pss_classification IS NULL OR pss_classification IN ('pending_verification', 'pss', 'non_pss'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.appointments'::regclass
          AND conname = 'appointments_pss_claim_check'
    ) THEN
        ALTER TABLE appointments ADD CONSTRAINT appointments_pss_claim_check CHECK (pss_claim IS NULL OR pss_claim IN ('pss', 'non_pss'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.appointments'::regclass
          AND conname = 'appointments_sponsor_count_check'
    ) THEN
        ALTER TABLE appointments ADD CONSTRAINT appointments_sponsor_count_check CHECK (sponsor_count IS NULL OR sponsor_count >= 0);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE connamespace = 'public'::regnamespace
          AND conrelid = 'public.appointments'::regclass
          AND conname = 'appointments_wedding_sponsor_count_check'
    ) THEN
        ALTER TABLE appointments ADD CONSTRAINT appointments_wedding_sponsor_count_check CHECK (wedding_sponsor_count IS NULL OR wedding_sponsor_count >= 0);
    END IF;
END $$;

-- Pricing rules are read by the server-side PHP application only.  Do not
-- expose this authoritative financial configuration to browser/API roles.
-- The migration executor/owner retains access; the application connection
-- must use that server-side role or an explicitly privileged server role.
REVOKE ALL PRIVILEGES ON TABLE service_fee_rules FROM PUBLIC;
REVOKE ALL PRIVILEGES ON TABLE service_fee_rules FROM anon;
REVOKE ALL PRIVILEGES ON TABLE service_fee_rules FROM authenticated;
GRANT SELECT ON TABLE service_fee_rules TO postgres;

INSERT INTO service_fee_rules (service_category, schedule_type, pss_classification, base_fee, priest_stipend, included_sponsors, additional_sponsor_fee)
VALUES
 ('Baptism','Regular','pss',0,0,2,100),
 ('Baptism','Regular','non_pss',1000,0,2,100),
 ('Baptism','Special','pss',1000,1000,2,100),
 ('Baptism','Special','non_pss',1500,1000,2,100),
 ('Wedding','Regular','pss',0,0,4,100),
 ('Wedding','Regular','non_pss',3000,0,4,100),
 ('Wedding','Special','pss',4000,2000,4,100),
 ('Wedding','Special','non_pss',8000,2000,4,100),
 ('Funeral','Any','pss',0,0,0,0),
 ('Funeral','Any','non_pss',2000,1500,0,0),
 ('Wake','Any','pss',0,1500,0,0),
 ('Wake','Any','non_pss',0,1500,0,0)
ON CONFLICT (service_category, schedule_type, pss_classification) DO UPDATE SET
 base_fee=EXCLUDED.base_fee, priest_stipend=EXCLUDED.priest_stipend,
 included_sponsors=EXCLUDED.included_sponsors, additional_sponsor_fee=EXCLUDED.additional_sponsor_fee;

COMMIT;
