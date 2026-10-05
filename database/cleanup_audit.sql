-- =============================================================================
-- ParishHub — Database Cleanup Script
-- Generated: 2026-10-05
-- Target: PostgreSQL (Supabase)
-- Based on: Comprehensive codebase audit (0 PHP references = safe to remove)
--
-- INSTRUCTIONS:
--   1. Take a full database backup BEFORE running this script.
--   2. Run in a transaction so you can ROLLBACK if anything breaks.
--   3. Run in Supabase SQL Editor or via psql.
-- =============================================================================

BEGIN;

-- =============================================================================
-- SECTION 1: DROP UNUSED TABLES
-- These tables have ZERO references anywhere in the PHP codebase.
-- =============================================================================

-- 1a. permissions
--     The app uses hardcoded requireRole() calls; this RBAC table was never wired up.
DROP TABLE IF EXISTS permissions CASCADE;

-- 1b. role_permissions
--     Companion to permissions. FK to permissions is cascade-deleted above,
--     but listing it explicitly for clarity.
DROP TABLE IF EXISTS role_permissions CASCADE;

-- 1c. reports
--     Report pages (admin/reports.php, secretary/reports.php, treasurer/reports.php)
--     query payments/appointments directly. No code ever INSERTs, SELECTs, or UPDATEs
--     this table. CASCADE handles the FK on generated_by → users.
DROP TABLE IF EXISTS reports CASCADE;


-- =============================================================================
-- SECTION 2: DROP UNUSED COLUMNS
-- These columns exist in the schema but are never read or written by any PHP file.
-- =============================================================================

-- 2a. users — email verification was never implemented
ALTER TABLE users DROP COLUMN IF EXISTS email_verified_at;
ALTER TABLE users DROP COLUMN IF EXISTS remember_token;

-- 2b. official_receipts — PDFs are generated on-demand, never stored to a path
ALTER TABLE official_receipts DROP COLUMN IF EXISTS pdf_path;

-- 2c. events — calendar_id FK exists in schema but events are never linked to calendar rows;
--     all INSERT INTO events calls omit this column entirely.
--     Drop the FK constraint first, then the column.
ALTER TABLE events DROP CONSTRAINT IF EXISTS events_calendar_id_fkey;
ALTER TABLE events DROP COLUMN IF EXISTS calendar_id;

-- 2d. parishioners — emergency contact fields collected in schema, never displayed or saved
ALTER TABLE parishioners DROP COLUMN IF EXISTS emergency_contact_name;
ALTER TABLE parishioners DROP COLUMN IF EXISTS emergency_contact_number;

-- 2e. parishioners — baptism_date and confirmation_date are fetched in one SELECT
--     in parishioner-detail.php but never rendered in the view or written from any form.
--     Safe to drop; uncomment if you want to keep them for future use.
ALTER TABLE parishioners DROP COLUMN IF EXISTS baptism_date;
ALTER TABLE parishioners DROP COLUMN IF EXISTS confirmation_date;


-- =============================================================================
-- SECTION 3: SECURITY — Remove web-accessible DB utility (not an orphan, but a risk)
-- NOTE: This section has no SQL — public/fix-db.php must be deleted from the filesystem.
--       See cleanup notes below.
-- =============================================================================


-- =============================================================================
-- Verify nothing broke — spot-check row counts on affected tables
-- =============================================================================
SELECT 'users columns remaining' AS check_label,
       COUNT(*) AS col_count
FROM information_schema.columns
WHERE table_name = 'users' AND table_schema = 'public';

SELECT 'parishioners columns remaining' AS check_label,
       COUNT(*) AS col_count
FROM information_schema.columns
WHERE table_name = 'parishioners' AND table_schema = 'public';

SELECT 'events columns remaining' AS check_label,
       COUNT(*) AS col_count
FROM information_schema.columns
WHERE table_name = 'events' AND table_schema = 'public';


-- If everything looks correct, commit. Otherwise ROLLBACK.
COMMIT;

-- =============================================================================
-- POST-SCRIPT CLEANUP NOTES
-- =============================================================================
-- Filesystem files deleted as part of this cleanup:
--   admin/backup.php          — orphaned page, 0 links anywhere
--   guest-document-replace.php — orphaned POST handler, no form submits to it
--
-- Filesystem files to MANUALLY REVIEW (dev/utility):
--   public/fix-db.php         — web-accessible DB repair tool (SECURITY RISK — delete or restrict)
--   test_db.php               — DB connection test (safe to delete)
--   test_funeral.php          — funeral fee calculation test (safe to delete)
--   test_pdfs.php             — PDF generation test (safe to delete)
--   install.php               — one-time installer (safe to delete after setup)
--   .codex-funeral-runtime-test.php — AI-generated test file (safe to delete)
--   database/hash-password.php — CLI password hasher (keep only if needed offline)
-- =============================================================================
