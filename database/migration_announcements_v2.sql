ALTER TABLE announcements ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS duration_type VARCHAR(20) DEFAULT NULL
    CHECK (duration_type IN ('specific_date','month','year','custom_range'));
