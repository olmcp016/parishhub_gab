-- ==========================================================
-- Regular/Special scheduling, priest availability, and per-
-- requirement document tracking.
-- ==========================================================

CREATE TABLE IF NOT EXISTS service_schedules (
    schedule_id SERIAL PRIMARY KEY,
    service_id INT NOT NULL REFERENCES services(service_id) ON DELETE CASCADE,
    weekday SMALLINT NOT NULL CHECK (weekday BETWEEN 0 AND 6), -- 0=Sun..6=Sat, matches PHP date('w')
    occurrence SMALLINT DEFAULT NULL CHECK (occurrence BETWEEN 1 AND 5), -- NULL = every week
    slot_time TIME NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_service_schedules_service ON service_schedules(service_id);

CREATE TABLE IF NOT EXISTS priest_unavailability (
    unavailability_id SERIAL PRIMARY KEY,
    priest_id INT NOT NULL REFERENCES priests(priest_id) ON DELETE CASCADE,
    unavailable_date DATE NOT NULL,
    start_time TIME DEFAULT NULL,  -- NULL + NULL end_time = whole day
    end_time TIME DEFAULT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_priest_unavail_priest_date ON priest_unavailability(priest_id, unavailable_date);

ALTER TABLE appointments ADD COLUMN IF NOT EXISTS schedule_type VARCHAR(10) DEFAULT NULL
    CHECK (schedule_type IN ('Regular', 'Special'));

ALTER TABLE uploaded_documents ADD COLUMN IF NOT EXISTS requirement_label VARCHAR(255) DEFAULT NULL;

-- Seed: reproduce today's exact Baptism/Wedding rule; placeholder defaults for
-- Blessing/Confirmation (parish edits later via the new admin page). Seeded by
-- category via SELECT so it's correct regardless of live service_id values.
INSERT INTO service_schedules (service_id, weekday, occurrence, slot_time, is_active)
SELECT service_id, 6::smallint, 1::smallint, '09:00:00'::time, TRUE FROM services WHERE category = 'Baptism'
UNION ALL SELECT service_id, 6::smallint, 3::smallint, '09:00:00'::time, TRUE FROM services WHERE category = 'Baptism'
UNION ALL SELECT service_id, 6::smallint, 4::smallint, '08:00:00'::time, TRUE FROM services WHERE category = 'Wedding'
UNION ALL SELECT service_id, 0::smallint, NULL::smallint, '10:00:00'::time, TRUE FROM services WHERE category = 'Confirmation'
UNION ALL SELECT service_id, 6::smallint, NULL::smallint, '14:00:00'::time, TRUE FROM services WHERE category = 'Blessing';

-- Backfill existing rows so validateBooking()'s new-column fallback is never load-bearing for old data.
UPDATE appointments a SET schedule_type = 'Regular' FROM services s
  WHERE a.service_id = s.service_id AND s.category IN ('Baptism','Wedding') AND a.schedule_type IS NULL;
UPDATE appointments a SET schedule_type = 'Special' FROM services s
  WHERE a.service_id = s.service_id AND s.category IN ('Blessing','Confirmation') AND a.schedule_type IS NULL;
