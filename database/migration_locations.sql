CREATE TABLE IF NOT EXISTS locations (
    location_id SERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    notes VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE events ADD COLUMN IF NOT EXISTS location_id INT REFERENCES locations(location_id) ON DELETE SET NULL;
ALTER TABLE events ADD COLUMN IF NOT EXISTS priest_id INT REFERENCES priests(priest_id) ON DELETE SET NULL;

INSERT INTO locations (name, notes)
SELECT 'Main Church / Parish Church', 'Primary worship space'
WHERE NOT EXISTS (SELECT 1 FROM locations WHERE name = 'Main Church / Parish Church');

INSERT INTO locations (name, notes)
SELECT 'Barangay Chapel', NULL
WHERE NOT EXISTS (SELECT 1 FROM locations WHERE name = 'Barangay Chapel');

INSERT INTO locations (name, notes)
SELECT 'Parish Hall', NULL
WHERE NOT EXISTS (SELECT 1 FROM locations WHERE name = 'Parish Hall');

INSERT INTO locations (name, notes)
SELECT 'House', 'For house blessings and similar home visits'
WHERE NOT EXISTS (SELECT 1 FROM locations WHERE name = 'House');
