-- Up to 3 poster images per announcement.
-- `image` stays as image #1 (the cover); `image2` and `image3` hold the rest.
-- Existing rows keep their single image and need no backfill.
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS image2 VARCHAR(255) DEFAULT NULL;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS image3 VARCHAR(255) DEFAULT NULL;
