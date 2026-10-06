-- PARISHHUB — must_change_password column migration
-- Previously added at runtime by includes/auth.php on every page load.
-- Run this once; the auto-DDL bootstrap has been removed from auth.php.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS must_change_password SMALLINT DEFAULT 0;
