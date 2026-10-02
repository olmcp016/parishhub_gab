-- Persist the bride's parish membership for new Wedding bookings.
-- Existing rows remain NULL (legacy/unknown) and are not reclassified.
BEGIN;
ALTER TABLE wedding_booking_drafts ADD COLUMN IF NOT EXISTS bride_parish_status VARCHAR(20) NULL;
ALTER TABLE appointments ADD COLUMN IF NOT EXISTS bride_parish_status VARCHAR(20) NULL;
DO $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE connamespace='public'::regnamespace AND conrelid='public.wedding_booking_drafts'::regclass AND conname='wedding_booking_drafts_bride_parish_status_check') THEN
  ALTER TABLE wedding_booking_drafts ADD CONSTRAINT wedding_booking_drafts_bride_parish_status_check CHECK (bride_parish_status IS NULL OR bride_parish_status IN ('this_parish','another_parish'));
 END IF;
 IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE connamespace='public'::regnamespace AND conrelid='public.appointments'::regclass AND conname='appointments_bride_parish_status_check') THEN
  ALTER TABLE appointments ADD CONSTRAINT appointments_bride_parish_status_check CHECK (bride_parish_status IS NULL OR bride_parish_status IN ('this_parish','another_parish'));
 END IF;
END $$;
COMMIT;
