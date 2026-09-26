ALTER TABLE appointments ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(20) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS location_address TEXT DEFAULT NULL;

-- House Blessing's "requirements" was misusing the document-upload mechanism
-- for what are really just contact/location text fields (see item 4) —
-- clearing it here removes the bogus "please upload your address" prompt.
UPDATE services SET requirements = NULL WHERE service_name = 'House Blessing';
