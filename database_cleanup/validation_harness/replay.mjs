// Replays the ParishHub Postgres base schema + migrations (git order) into a
// throwaway in-memory PGlite database, then dumps the effective catalog.
// Usage: node replay.mjs <dbDir> <outPrefix> [extra.sql ...]
import { PGlite } from '@electric-sql/pglite';
import fs from 'node:fs';
import path from 'node:path';

const REPO = 'C:/Users/JDAE/Desktop/parishhub_gab/database';
const ORDER = [
  'supabase_schema.sql',
  'migration_donations.sql',
  'migration_rejection_reason.sql',
  'migration_walkin_donor.sql',
  'migration_consolidate_mass_intentions.sql',
  'migration_scheduling_v2.sql',
  'migration_locations.sql',
  'migration_projects.sql',
  'migration_announcements_v2.sql',
  'migration_paymongo.sql',
  'migration_paymongo_v2.sql',
  'migration_guest_access.sql',
  'migration_users_middlename.sql',
  'migration_appointments_contact_location.sql',
  'migration_priest_role.sql',
  'migration_remove_demo_priests.sql',
  'migration_service_fee_rules.sql',
  'migration_document_review.sql',
  'migration_wedding_generated_forms.sql',
  'migration_wedding_booking_drafts.sql',
  'migration_baptism_booking_drafts.sql',
  'migration_location_categories.sql',
  'migration_funeral_generated_forms.sql',
  'migration_sessions.sql',
  'migration_anointing.sql',
  // Committed 10-03 but depends on the 'Wake' enum label first added by migration_anointing.sql (10-05).
  'migration_service_process_corrections.sql',
  'cleanup_audit.sql',
  'migration_merge_forms.sql',
  'migration_merge_drafts.sql',
  'migration_receipt_trigger.sql',
  'migration_notification_trigger.sql',
  'migration_activity_log_trigger.sql',
  'migration_email_verification.sql',
  'migration_must_change_password.sql',
  'migration_password_reset_otp.sql',
  'migration_wake_mass_rename.sql',
  'migration_announcement_images.sql',
  'migration_split_wake_death_anniversary.sql',
  'migration_special_mass_request.sql',
  'migration_auth_throttle.sql',
  'migration_requirements_review.sql',
  'migration_notifications_v2.sql',
  'migration_precana.sql',
  'migration_funeral_preferred_burial.sql',
  'migration_payment_deadline.sql',
  'migration_outside_priest.sql',
  'migration_certificate_request.sql',
  'migration_issued_certificates.sql',
  'migration_certificate_claimed.sql',
  'migration_certificate_allow_form_type.sql',
];

const [, , dbDir, outPrefix, ...extra] = process.argv;
const skip = new Set((process.env.SKIP || '').split(',').filter(Boolean));
const db = dbDir === 'memory' ? new PGlite() : new PGlite(dbDir);

function prep(sql) {
  // PGlite has no pgcrypto bundle; core gen_random_uuid() exists in PG13+.
  return sql.replace(/CREATE EXTENSION IF NOT EXISTS\s+"?pgcrypto"?\s*;/gi, '-- (pgcrypto skipped in PGlite)');
}

const log = [];
await db.exec(`DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='anon') THEN CREATE ROLE anon NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='authenticated') THEN CREATE ROLE authenticated NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='service_role') THEN CREATE ROLE service_role NOLOGIN; END IF;
END $$;`);

const files = dbDir === 'memory' || extra.length === 0 ? ORDER.filter(f => !skip.has(f)).map(f => path.join(REPO, f)) : [];
const ENUM_ADD = /^\s*ALTER\s+TYPE\s+\w+\s+ADD\s+VALUE[^;]*;/gim;
for (const f of [...files, ...extra]) {
  let sql = prep(fs.readFileSync(f, 'utf8'));
  // New enum labels must be committed before use (PG restriction), so run them first, one per transaction.
  const enumAdds = sql.match(ENUM_ADD) || [];
  for (const stmt of enumAdds) {
    try { await db.exec(stmt); log.push(`ENUM  ${path.basename(f)} :: ${stmt.trim()}`); }
    catch (e) { log.push(`ERROR ${path.basename(f)} (enum) :: ${e.message.split('\n')[0]}`); }
  }
  sql = sql.replace(ENUM_ADD, '-- (enum value committed separately)');
  try {
    await db.exec(sql);
    log.push(`OK    ${path.basename(f)}`);
  } catch (e) {
    log.push(`ERROR ${path.basename(f)} :: ${e.message.split('\n')[0]}`);
    try { await db.exec('ROLLBACK'); } catch {}
  }
}

const q = async (s) => (await db.query(s)).rows;
const tables = await q(`SELECT c.relname AS table FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public' AND c.relkind='r' ORDER BY 1`);
const columns = await q(`SELECT table_name, column_name, ordinal_position, data_type, udt_name,
  character_maximum_length, is_nullable, column_default FROM information_schema.columns
  WHERE table_schema='public' ORDER BY table_name, ordinal_position`);
const constraints = await q(`SELECT rel.relname AS table, con.conname AS name, con.contype AS type,
  pg_get_constraintdef(con.oid) AS def FROM pg_constraint con JOIN pg_class rel ON rel.oid=con.conrelid
  JOIN pg_namespace n ON n.oid=rel.relnamespace WHERE n.nspname='public' ORDER BY 1, 3, 2`);
const indexes = await q(`SELECT tablename AS table, indexname AS name, indexdef AS def FROM pg_indexes
  WHERE schemaname='public' ORDER BY 1, 2`);
const enums = await q(`SELECT t.typname AS name, string_agg(e.enumlabel, ',' ORDER BY e.enumsortorder) AS labels
  FROM pg_type t JOIN pg_enum e ON e.enumtypid=t.oid JOIN pg_namespace n ON n.oid=t.typnamespace
  WHERE n.nspname='public' GROUP BY 1 ORDER BY 1`);
const triggers = await q(`SELECT c.relname AS table, t.tgname AS name, pg_get_triggerdef(t.oid) AS def
  FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
  WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY 1, 2`);
const functions = await q(`SELECT p.proname AS name, pg_get_functiondef(p.oid) AS def FROM pg_proc p
  JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.prokind='f' ORDER BY 1`);
const seeds = {};
for (const t of ['roles', 'appointment_status', 'payment_methods', 'services', 'settings']) {
  try { seeds[t] = await q(`SELECT * FROM ${t} ORDER BY 1`); } catch (e) { seeds[t] = `ERR ${e.message}`; }
}

fs.writeFileSync(`${outPrefix}.json`, JSON.stringify({ log, tables, columns, constraints, indexes, enums, triggers, functions, seeds }, null, 2));
fs.writeFileSync(`${outPrefix}.log`, log.join('\n') + '\n');
console.log(log.join('\n'));
console.log(`tables=${tables.length} columns=${columns.length} constraints=${constraints.length} indexes=${indexes.length} triggers=${triggers.length} functions=${functions.length}`);
await db.close();
