// Generates DATABASE_SCHEMA_FINAL.sql and schema_final.json from the recommended final state:
// base + all migrations (cleanup_audit applied) + DATABASE_MIGRATION_SAFE.sql Part A.
import { PGlite } from '@electric-sql/pglite';
import fs from 'node:fs';
import path from 'node:path';

const REPO = 'C:/Users/JDAE/Desktop/parishhub_gab';
const SCRATCH = path.dirname(new URL(import.meta.url).pathname).replace(/^\/([A-Za-z]:)/, '$1');
const src = fs.readFileSync(`${SCRATCH}/validate.mjs`, 'utf8');
const ORDER = eval(src.match(/const ORDER = (\[[\s\S]*?\]);/)[1]);
const ENUM_ADD = /^\s*ALTER\s+TYPE\s+\w+\s+ADD\s+VALUE[^;]*;/gim;

const db = new PGlite();
await db.exec(`CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN; CREATE ROLE service_role NOLOGIN;`);
for (const f of ORDER) {
  let sql = fs.readFileSync(`${REPO}/database/${f}`, 'utf8').replace(/CREATE EXTENSION IF NOT EXISTS\s+"?pgcrypto"?\s*;/gi, '');
  for (const s of sql.match(ENUM_ADD) || []) await db.exec(s);
  await db.exec(sql.replace(ENUM_ADD, ''));
}
await db.exec(fs.readFileSync(`${REPO}/database_cleanup/DATABASE_MIGRATION_SAFE.sql`, 'utf8'));

const q = async (s, p) => (await db.query(s, p)).rows;
const MYSQL_SHIMS = new Set(['curdate', 'date_format', 'month', 'year', 'yearweek', 'now']);

const enums = await q(`SELECT t.typname AS name, array_agg(e.enumlabel ORDER BY e.enumsortorder) AS labels
  FROM pg_type t JOIN pg_enum e ON e.enumtypid=t.oid JOIN pg_namespace n ON n.oid=t.typnamespace
  WHERE n.nspname='public' GROUP BY 1 ORDER BY 1`);
const tables = (await q(`SELECT c.oid, c.relname AS name, obj_description(c.oid,'pg_class') AS comment FROM pg_class c
  JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' ORDER BY 2`));
const cols = await q(`SELECT c.relname AS table, a.attnum, a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type,
  a.attnotnull AS notnull, pg_get_expr(d.adbin, d.adrelid) AS def, col_description(c.oid, a.attnum) AS comment
  FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
  LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
  WHERE n.nspname='public' AND c.relkind='r' AND a.attnum>0 AND NOT a.attisdropped ORDER BY c.relname, a.attnum`);
const cons = await q(`SELECT rel.relname AS table, con.conname AS name, con.contype AS type, pg_get_constraintdef(con.oid) AS def,
  ref.relname AS ref_table FROM pg_constraint con JOIN pg_class rel ON rel.oid=con.conrelid
  JOIN pg_namespace n ON n.oid=rel.relnamespace LEFT JOIN pg_class ref ON ref.oid=con.confrelid
  WHERE n.nspname='public' ORDER BY 1, CASE con.contype WHEN 'p' THEN 0 WHEN 'u' THEN 1 WHEN 'c' THEN 2 ELSE 3 END, 2`);
const idx = await q(`SELECT i.tablename AS table, i.indexname AS name, i.indexdef AS def FROM pg_indexes i
  WHERE i.schemaname='public' AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = (quote_ident(i.schemaname)||'.'||quote_ident(i.indexname))::regclass)
  ORDER BY 1, 2`);
const funcs = await q(`SELECT p.proname AS name, pg_get_functiondef(p.oid) AS def FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
  WHERE n.nspname='public' AND p.prokind='f' ORDER BY 1, p.oid`);
const trigs = await q(`SELECT c.relname AS table, t.tgname AS name, pg_get_triggerdef(t.oid) AS def FROM pg_trigger t
  JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY 1, 2`);

const lit = (v) => v === null ? 'NULL' : typeof v === 'number' ? String(v) : typeof v === 'boolean' ? (v ? 'TRUE' : 'FALSE')
  : v instanceof Date ? `'${v.toISOString()}'` : typeof v === 'object' ? `'${JSON.stringify(v).replace(/'/g, "''")}'` : `'${String(v).replace(/'/g, "''")}'`;
async function seedRows(table, keyCols, orderBy) {
  const rows = await q(`SELECT * FROM ${table} ORDER BY ${orderBy}`);
  if (!rows.length) return '';
  const colsT = cols.filter(c => c.table === table && !(c.def || '').startsWith('nextval(') || (c.table === table && keyCols.includes(c.name)));
  const names = colsT.map(c => c.name).filter(n => n !== 'created_at' && n !== 'updated_at');
  const out = rows.map(r => {
    const vals = names.map(n => lit(r[n])).join(', ');
    const cond = keyCols.map(k => `${k} = ${lit(r[k])}`).join(' AND ');
    return `INSERT INTO public.${table} (${names.join(', ')})\nSELECT ${vals}\nWHERE NOT EXISTS (SELECT 1 FROM public.${table} WHERE ${cond});`;
  });
  return out.join('\n');
}

const L = [];
L.push(`-- =============================================================================
-- ParishHub — FINAL VERIFIED DATABASE SCHEMA (PostgreSQL / Supabase)
-- Generated: 2026-10-25 by the database cleanup audit.
--
-- WHAT THIS IS
--   The effective schema produced by replaying database/supabase_schema.sql and
--   every migration in database/ (git order, corrected where noted) into a
--   throwaway PostgreSQL 16 instance, with cleanup_audit.sql applied, plus
--   DATABASE_MIGRATION_SAFE.sql Part A. It is generated from the database
--   catalog itself (pg_catalog), not hand-written, so table/column names, types,
--   defaults, constraints, indexes, functions and triggers are exact.
--
-- *** DO NOT RUN THIS AGAINST THE PRODUCTION DATABASE. ***
--   It is the reference definition for documentation (Chapter 2 / ERD), code
--   review, and building a fresh development/test database. To bring the live
--   database in line, use DATABASE_PREFLIGHT_CHECK.sql + DATABASE_MIGRATION_SAFE.sql.
--
-- NOT INCLUDED: opt-in Parts B (FK hardening), C (archive unused tables) and
--   D (Row Level Security) of the safe migration — see DATABASE_CLEANUP_PLAN.md.
--   Supabase-managed schemas (auth, storage, extensions) are out of scope.
--
-- Tables: ${tables.length}   Enums: ${enums.length}   Indexes (non-constraint): ${idx.length}   Triggers: ${trigs.length}
-- =============================================================================

SET client_min_messages = warning;
`);

L.push(`-- -----------------------------------------------------------------------------\n-- 1. ENUM TYPES\n-- -----------------------------------------------------------------------------`);
for (const e of enums) {
  L.push(`DO $$ BEGIN\n    CREATE TYPE public.${e.name} AS ENUM (${e.labels.map(lit).join(', ')});\nEXCEPTION WHEN duplicate_object THEN NULL; END $$;`);
}

L.push(`\n-- -----------------------------------------------------------------------------\n-- 2. TABLES (primary keys, unique and check constraints inline; foreign keys in §3)\n-- -----------------------------------------------------------------------------`);
for (const t of tables) {
  const tc = cols.filter(c => c.table === t.name);
  const lines = tc.map(c => {
    let type = c.type, def = c.def;
    if (def && /^nextval\('.*_seq'::regclass\)$/.test(def)) { type = c.type === 'bigint' ? 'BIGSERIAL' : c.type === 'smallint' ? 'SMALLSERIAL' : 'SERIAL'; def = null; }
    return `    ${c.name.padEnd(28)} ${type}${c.notnull && !/SERIAL/.test(type) ? ' NOT NULL' : ''}${def ? ` DEFAULT ${def}` : ''}`;
  });
  for (const k of cons.filter(k => k.table === t.name && k.type !== 'f')) lines.push(`    CONSTRAINT ${k.name} ${k.def}`);
  if (t.comment) L.push(`-- ${t.comment}`);
  L.push(`CREATE TABLE IF NOT EXISTS public.${t.name} (\n${lines.join(',\n')}\n);`);
  for (const c of tc.filter(c => c.comment)) L.push(`COMMENT ON COLUMN public.${t.name}.${c.name} IS ${lit(c.comment)};`);
  L.push('');
}

L.push(`-- -----------------------------------------------------------------------------\n-- 3. FOREIGN KEYS\n-- -----------------------------------------------------------------------------`);
for (const k of cons.filter(k => k.type === 'f')) {
  L.push(`ALTER TABLE public.${k.table} DROP CONSTRAINT IF EXISTS ${k.name};\nALTER TABLE public.${k.table} ADD CONSTRAINT ${k.name} ${k.def.replace(/REFERENCES (\w+)/, 'REFERENCES public.$1')};`);
}

L.push(`\n-- -----------------------------------------------------------------------------\n-- 4. INDEXES (indexes backing PK/UNIQUE constraints are created by §2)\n-- -----------------------------------------------------------------------------`);
for (const i of idx) L.push(i.def.replace(/^CREATE (UNIQUE )?INDEX /, 'CREATE $1INDEX IF NOT EXISTS ') + ';');

L.push(`\n-- -----------------------------------------------------------------------------\n-- 5. FUNCTIONS (business-logic trigger functions)\n-- -----------------------------------------------------------------------------`);
for (const f of funcs.filter(f => !MYSQL_SHIMS.has(f.name))) L.push(f.def.trim() + ';\n');

L.push(`-- -----------------------------------------------------------------------------\n-- 6. TRIGGERS\n-- -----------------------------------------------------------------------------`);
for (const t of trigs) L.push(`DROP TRIGGER IF EXISTS ${t.name} ON public.${t.table};\n${t.def};`);

L.push(`\n-- -----------------------------------------------------------------------------
-- 7. PRIVILEGES (from migration_service_fee_rules.sql — fee rules are server-side only)
-- -----------------------------------------------------------------------------
DO $$ BEGIN
    REVOKE ALL PRIVILEGES ON TABLE public.service_fee_rules FROM PUBLIC;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN REVOKE ALL PRIVILEGES ON TABLE public.service_fee_rules FROM anon; END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN REVOKE ALL PRIVILEGES ON TABLE public.service_fee_rules FROM authenticated; END IF;
END $$;`);

L.push(`\n-- -----------------------------------------------------------------------------
-- 8. REFERENCE DATA the PHP code depends on (inserted only if missing)
--    roles / appointment_status / payment_methods ids are hard-coded in PHP
--    (e.g. status_id 1..7, method_id 7 = PayMongo) — do not renumber.
-- -----------------------------------------------------------------------------`);
L.push(await seedRows('roles', ['role_id'], 'role_id'));
L.push(await seedRows('appointment_status', ['status_id'], 'status_id'));
L.push(await seedRows('payment_methods', ['method_id'], 'method_id'));
L.push(await seedRows('settings', ['setting_key'], 'setting_key'));
L.push(await seedRows('service_fee_rules', ['service_category', 'schedule_type', 'pss_classification'], 'service_category, schedule_type, pss_classification'));
L.push(`-- Services, the guest placeholder account, and priests are operational data
-- managed in the app (see migration_guest_access.sql for the guest placeholder).
-- Re-sync identity sequences after explicit-id inserts above:`);
for (const t of ['roles', 'appointment_status', 'payment_methods']) {
  const pk = cons.find(k => k.table === t && k.type === 'p').def.match(/\((\w+)\)/)[1];
  L.push(`SELECT setval(pg_get_serial_sequence('public.${t}', '${pk}'), (SELECT COALESCE(MAX(${pk}), 1) FROM public.${t}));`);
}

L.push(`\n-- -----------------------------------------------------------------------------
-- 9. LEGACY MYSQL-COMPATIBILITY FUNCTIONS (present in supabase_schema.sql; NOT
--    called by any current PHP query). Kept here, commented, for completeness.
--    Recommended action: Review / remove in a later cleanup.
-- -----------------------------------------------------------------------------`);
for (const f of funcs.filter(f => MYSQL_SHIMS.has(f.name))) L.push(f.def.trim().split('\n').map(l => '-- ' + l).join('\n') + ';\n');

fs.writeFileSync(`${REPO}/database_cleanup/DATABASE_SCHEMA_FINAL.sql`, L.join('\n') + '\n');
fs.writeFileSync(`${SCRATCH}/schema_final.json`, JSON.stringify({ enums, tables: tables.map(t => ({ name: t.name, comment: t.comment })), cols, cons, idx, trigs }, null, 2));

// Round-trip test: the generated DDL must build a clean database on its own.
const fresh = new PGlite();
await fresh.exec(`CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN;`);
const ddl = fs.readFileSync(`${REPO}/database_cleanup/DATABASE_SCHEMA_FINAL.sql`, 'utf8');
await fresh.exec(ddl);
await fresh.exec(ddl); // idempotent
const count = async (d, s) => (await d.query(s)).rows[0].n;
const cmp = {
  tables: [await count(db, `SELECT count(*)::int n FROM pg_tables WHERE schemaname='public'`), await count(fresh, `SELECT count(*)::int n FROM pg_tables WHERE schemaname='public'`)],
  columns: [await count(db, `SELECT count(*)::int n FROM information_schema.columns WHERE table_schema='public'`), await count(fresh, `SELECT count(*)::int n FROM information_schema.columns WHERE table_schema='public'`)],
  constraints: [await count(db, `SELECT count(*)::int n FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname='public'`), await count(fresh, `SELECT count(*)::int n FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname='public'`)],
  indexes: [await count(db, `SELECT count(*)::int n FROM pg_indexes WHERE schemaname='public'`), await count(fresh, `SELECT count(*)::int n FROM pg_indexes WHERE schemaname='public'`)],
  triggers: [await count(db, `SELECT count(*)::int n FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal`), await count(fresh, `SELECT count(*)::int n FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal`)],
};
console.log('replayed vs fresh-from-DDL [replayed, fresh]:', JSON.stringify(cmp));
await db.close(); await fresh.close();
