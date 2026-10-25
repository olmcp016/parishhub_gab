-- =============================================================================
-- ParishHub — PRE-FLIGHT CHECK (READ-ONLY)
-- Generated: 2026-10-25 by the database cleanup audit.
--
-- PURPOSE
--   The schema files in /database cannot tell us which migrations were actually
--   applied to the live Supabase database. This script answers that question
--   without changing anything. Run it BEFORE DATABASE_MIGRATION_SAFE.sql and
--   keep the output with the change record.
--
-- SAFETY
--   * SELECT-only. Creates, alters, and deletes nothing.
--   * Returns ONE result set (check_group, check_name, result, detail) so it can
--     be read in full in the Supabase SQL Editor, which shows only the last
--     result set of a script.
--   * Row counts use query_to_xml() so missing tables are skipped, not errors.
-- =============================================================================

WITH
expected_tables(t, grp) AS (
  VALUES
    -- ACTIVE tables the current PHP code reads/writes
    ('users','active'),('roles','active'),('parishioners','active'),('priests','active'),
    ('priest_unavailability','active'),('services','active'),('service_schedules','active'),
    ('service_fee_rules','active'),('appointment_status','active'),('appointments','active'),
    ('appointment_drafts','active'),('generated_forms','active'),('uploaded_documents','active'),
    ('issued_certificates','active'),('mass_intentions','active'),('donations','active'),
    ('projects','active'),('payments','active'),('payment_methods','active'),
    ('transactions','active'),('official_receipts','active'),('notifications','active'),
    ('email_logs','active'),('activity_logs','active'),('calendar','active'),('events','active'),
    ('locations','active'),('announcements','active'),('settings','active'),
    ('auth_throttle','active'),('password_reset_otps','active'),
    -- conditionally used (SESSION_DRIVER=database only)
    ('app_sessions','conditional'),
    -- present but no SQL reference in current code
    ('requirements','unused'),('chat_messages','unused'),
    -- removed by cleanup_audit.sql (should be ABSENT if that script ran)
    ('permissions','dropped_by_cleanup_audit'),('role_permissions','dropped_by_cleanup_audit'),
    ('reports','dropped_by_cleanup_audit'),
    -- replaced by generated_forms / appointment_drafts (should be ABSENT)
    ('generated_wedding_forms','deprecated'),('generated_baptism_forms','deprecated'),
    ('generated_funeral_forms','deprecated'),('wedding_booking_drafts','deprecated'),
    ('baptism_booking_drafts','deprecated')
),
expected_columns(t, c, why) AS (
  VALUES
    ('users','email_verified_at','migration_email_verification.sql (login gate)'),
    ('users','verification_token','migration_email_verification.sql'),
    ('users','token_expires_at','migration_email_verification.sql'),
    ('users','must_change_password','migration_must_change_password.sql'),
    ('users','middlename','migration_users_middlename.sql'),
    ('appointments','payment_deadline','migration_payment_deadline.sql (feature-detected in appointment-approval.php)'),
    ('appointments','requirements_review_status','migration_requirements_review.sql (feature-detected)'),
    ('appointments','revision_requested_at','migration_requirements_review.sql'),
    ('appointments','preferred_burial_date','migration_funeral_preferred_burial.sql (feature-detected)'),
    ('appointments','preferred_burial_time','migration_funeral_preferred_burial.sql (feature-detected)'),
    ('appointments','precana_type','migration_precana.sql (feature-detected)'),
    ('appointments','outside_priest_name','migration_outside_priest.sql (auto-added at runtime if permitted)'),
    ('appointments','requester_name','migration_anointing.sql'),
    ('appointments','patient_name','migration_anointing.sql'),
    ('appointments','guest_reference','migration_guest_access.sql'),
    ('appointment_drafts','service_type','migration_merge_drafts.sql'),
    ('appointment_drafts','precana_type','migration_precana.sql'),
    ('generated_forms','service_category','migration_merge_forms.sql'),
    ('notifications','appointment_id','migration_notifications_v2.sql (feature-detected)'),
    ('issued_certificates','claimed_at','migration_certificate_claimed.sql'),
    ('announcements','image2','migration_announcement_images.sql'),
    -- still SELECTed by secretary/parishioner-detail.php:31 but DROPPED by cleanup_audit.sql
    ('parishioners','baptism_date','secretary/parishioner-detail.php:31 SELECTs it'),
    ('parishioners','confirmation_date','secretary/parishioner-detail.php:31 SELECTs it')
),
checks AS (
  -- 1. Table presence
  SELECT 'tables' AS check_group,
         e.t AS check_name,
         CASE
           WHEN e.grp IN ('active') AND to_regclass('public.' || e.t) IS NULL THEN 'MISSING (code will fail)'
           WHEN e.grp IN ('deprecated','dropped_by_cleanup_audit') AND to_regclass('public.' || e.t) IS NOT NULL THEN 'STILL PRESENT'
           WHEN to_regclass('public.' || e.t) IS NULL THEN 'absent'
           ELSE 'present'
         END AS result,
         e.grp AS detail
  FROM expected_tables e

  UNION ALL
  -- 2. Tables in the database that this audit does not know about
  SELECT 'tables', c.relname, 'UNKNOWN TABLE', 'not in the audited table list — review'
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = 'public' AND c.relkind = 'r'
    AND c.relname NOT IN (SELECT t FROM expected_tables)

  UNION ALL
  -- 3. Columns the code depends on (tells which migrations ran)
  SELECT 'columns', ec.t || '.' || ec.c,
         CASE WHEN EXISTS (SELECT 1 FROM information_schema.columns ic
                           WHERE ic.table_schema = 'public' AND ic.table_name = ec.t AND ic.column_name = ec.c)
              THEN 'present' ELSE 'MISSING' END,
         ec.why
  FROM expected_columns ec

  UNION ALL
  -- 4. Boolean-vs-integer comparison hazard (see DATABASE_SYSTEM_MISMATCHES.md M-01)
  SELECT 'types', ic.table_name || '.' || ic.column_name, ic.data_type,
         'PHP compares this column to both TRUE and 1; only one form can work on stock PostgreSQL'
  FROM information_schema.columns ic
  WHERE ic.table_schema = 'public'
    AND (ic.table_name, ic.column_name) IN (('services','is_active'),('calendar','is_blocked'),
                                            ('projects','is_active'),('locations','is_active'),
                                            ('service_schedules','is_active'))

  UNION ALL
  SELECT 'types', 'operator boolean = integer',
         CASE WHEN EXISTS (SELECT 1 FROM pg_operator o
                           WHERE o.oprname = '=' AND o.oprleft = 'boolean'::regtype AND o.oprright = 'integer'::regtype)
              THEN 'EXISTS (custom, not in repo)' ELSE 'absent' END,
         'If absent and is_active/is_blocked are boolean, the 7 "= 1" queries fail'
  UNION ALL
  SELECT 'types', 'cast integer -> boolean context',
         COALESCE((SELECT CASE castcontext WHEN 'i' THEN 'IMPLICIT (customised)' WHEN 'a' THEN 'assignment' ELSE 'explicit (default)' END
                   FROM pg_cast WHERE castsource = 'integer'::regtype AND casttarget = 'boolean'::regtype), 'none'),
         'Default PostgreSQL is explicit'

  UNION ALL
  -- 5. Enum labels the code relies on
  SELECT 'enums', 'service_category',
         string_agg(e.enumlabel, ', ' ORDER BY e.enumsortorder),
         'expected to include Wake, Anointing, Certificate, Donation'
  FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid WHERE t.typname = 'service_category'
  UNION ALL
  SELECT 'enums', 'payment_status_type',
         string_agg(e.enumlabel, ', ' ORDER BY e.enumsortorder),
         'expected to include cancelled'
  FROM pg_type t JOIN pg_enum e ON e.enumtypid = t.oid WHERE t.typname = 'payment_status_type'

  UNION ALL
  -- 6. Triggers that carry business logic
  SELECT 'triggers', x.tg,
         CASE WHEN EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = x.tg AND NOT tgisinternal) THEN 'present' ELSE 'MISSING' END,
         x.why
  FROM (VALUES ('trg_set_receipt_number','official receipt numbers (OR-YYYY-NNNNNN)'),
               ('trg_appointment_notify','in-app notification on status change'),
               ('trg_appointment_status_log','activity log on status change')) AS x(tg, why)

  UNION ALL
  -- 6b. Constraint that certificate generation depends on (see DATABASE_SYSTEM_MISMATCHES.md M-06)
  SELECT 'constraints', 'uploaded_documents.generated_form_type allows official_certificate',
         CASE WHEN EXISTS (SELECT 1 FROM pg_constraint
                           WHERE conrelid = to_regclass('public.uploaded_documents')
                             AND contype = 'c'
                             AND pg_get_constraintdef(oid) LIKE '%official_certificate%')
              THEN 'present' ELSE 'MISSING (certificate generation will fail)' END,
         'migration_certificate_allow_form_type.sql'

  UNION ALL
  -- 7. Redundant indexes Part A would remove
  SELECT 'indexes', i.indexname, 'present', 'duplicate of a UNIQUE constraint index'
  FROM pg_indexes i WHERE i.schemaname = 'public' AND i.indexname IN ('idx_appt_guest_reference','idx_prot_email')

  UNION ALL
  -- 8. Foreign-key delete rules on records that must be preserved
  SELECT 'fk_delete_rules', con.conname,
         CASE con.confdeltype WHEN 'c' THEN 'CASCADE' WHEN 'r' THEN 'RESTRICT' WHEN 'a' THEN 'NO ACTION' WHEN 'n' THEN 'SET NULL' ELSE con.confdeltype::text END,
         rel.relname || ' -> ' || ref.relname
  FROM pg_constraint con
  JOIN pg_class rel ON rel.oid = con.conrelid
  JOIN pg_class ref ON ref.oid = con.confrelid
  WHERE con.contype = 'f'
    AND con.conname IN ('appointments_parishioner_id_fkey','payments_appointment_id_fkey',
                        'official_receipts_payment_id_fkey','transactions_payment_id_fkey',
                        'donations_appointment_id_fkey','issued_certificates_appointment_id_fkey',
                        'parishioners_user_id_fkey')

  UNION ALL
  -- 8b. Row Level Security and ownership (for opt-in Part D)
  SELECT 'rls', c.relname,
         CASE WHEN c.relrowsecurity THEN 'RLS on' ELSE 'RLS OFF (exposed to Supabase REST if anon has grants)' END,
         'owner=' || pg_get_userbyid(c.relowner)
           || '; anon_select=' || has_table_privilege('anon', c.oid, 'SELECT')::text
           || '; current_user=' || current_user
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = 'public' AND c.relkind = 'r'
    AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon')

  UNION ALL
  -- 9. Data-integrity spot checks
  SELECT 'integrity', 'uploaded_documents owned by both or neither',
         (SELECT count(*)::text FROM uploaded_documents WHERE (appointment_id IS NULL) = (draft_id IS NULL)),
         'expected 0 (each document belongs to exactly one appointment OR draft)'
  WHERE to_regclass('public.uploaded_documents') IS NOT NULL
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'uploaded_documents' AND column_name = 'draft_id')
  UNION ALL
  SELECT 'integrity', 'payments with more than one active (pending/verified) row per appointment',
         (SELECT count(*)::text FROM (SELECT appointment_id FROM payments
                                      WHERE payment_status IN ('pending','verified')
                                      GROUP BY appointment_id HAVING count(*) > 1) d),
         'business rule: one active payment per request'
  WHERE to_regclass('public.payments') IS NOT NULL
  UNION ALL
  SELECT 'integrity', 'verified payments without an official receipt',
         (SELECT count(*)::text FROM payments p
          WHERE p.payment_status = 'verified'
            AND NOT EXISTS (SELECT 1 FROM official_receipts r WHERE r.payment_id = p.payment_id)),
         'review: receipts are generated on verification'
  WHERE to_regclass('public.official_receipts') IS NOT NULL
  UNION ALL
  SELECT 'integrity', 'duplicate generated_forms (same owner + form_type)',
         (SELECT count(*)::text FROM (SELECT COALESCE(appointment_id::text, 'd' || draft_id::text) AS owner, form_type
                                      FROM generated_forms GROUP BY 1, 2 HAVING count(*) > 1) d),
         'expected 0'
  WHERE to_regclass('public.generated_forms') IS NOT NULL

  UNION ALL
  -- 10. Row counts (baseline for before/after comparison; never modified by Part A)
  SELECT 'row_counts', t.t,
         (xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM public.%I', t.t), false, true, '')))[1]::text,
         'rows'
  FROM expected_tables t
  WHERE to_regclass('public.' || t.t) IS NOT NULL
)
SELECT check_group, check_name, result, detail
FROM checks
ORDER BY CASE check_group WHEN 'tables' THEN 1 WHEN 'columns' THEN 2 WHEN 'constraints' THEN 2 WHEN 'types' THEN 3 WHEN 'enums' THEN 4
                          WHEN 'triggers' THEN 5 WHEN 'indexes' THEN 6 WHEN 'fk_delete_rules' THEN 7
                          WHEN 'rls' THEN 8 WHEN 'integrity' THEN 9 ELSE 10 END,
         check_name;
