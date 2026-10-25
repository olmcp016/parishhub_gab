// Phase 7 validation: for each possible production state, replay the schema into a
// throwaway in-memory PGlite DB, seed synthetic records, then exercise
// preflight -> migration Part A -> re-run -> Parts B/C -> rollbacks, checking that
// data is untouched and the catalog returns to its original state.
import { PGlite } from '@electric-sql/pglite';
import fs from 'node:fs';
import path from 'node:path';

const REPO = 'C:/Users/JDAE/Desktop/parishhub_gab';
const DB = `${REPO}/database`;
const OUT = `${REPO}/database_cleanup`;
const SCRATCH = path.dirname(new URL(import.meta.url).pathname).replace(/^\/([A-Za-z]:)/, '$1');
const ORDER = ['supabase_schema.sql', 'migration_donations.sql', 'migration_rejection_reason.sql', 'migration_walkin_donor.sql',
  'migration_consolidate_mass_intentions.sql', 'migration_scheduling_v2.sql', 'migration_locations.sql', 'migration_projects.sql',
  'migration_announcements_v2.sql', 'migration_paymongo.sql', 'migration_paymongo_v2.sql', 'migration_guest_access.sql',
  'migration_users_middlename.sql', 'migration_appointments_contact_location.sql', 'migration_priest_role.sql',
  'migration_remove_demo_priests.sql', 'migration_service_fee_rules.sql', 'migration_document_review.sql',
  'migration_wedding_generated_forms.sql', 'migration_wedding_booking_drafts.sql', 'migration_baptism_booking_drafts.sql',
  'migration_location_categories.sql', 'migration_funeral_generated_forms.sql', 'migration_sessions.sql', 'migration_anointing.sql',
  'migration_service_process_corrections.sql', 'cleanup_audit.sql', 'migration_merge_forms.sql', 'migration_merge_drafts.sql',
  'migration_receipt_trigger.sql', 'migration_notification_trigger.sql', 'migration_activity_log_trigger.sql',
  'migration_email_verification.sql', 'migration_must_change_password.sql', 'migration_password_reset_otp.sql',
  'migration_wake_mass_rename.sql', 'migration_announcement_images.sql', 'migration_split_wake_death_anniversary.sql',
  'migration_special_mass_request.sql', 'migration_auth_throttle.sql', 'migration_requirements_review.sql',
  'migration_notifications_v2.sql', 'migration_precana.sql', 'migration_funeral_preferred_burial.sql',
  'migration_payment_deadline.sql', 'migration_outside_priest.sql', 'migration_certificate_request.sql',
  'migration_issued_certificates.sql', 'migration_certificate_claimed.sql', 'migration_certificate_allow_form_type.sql'];
const ENUM_ADD = /^\s*ALTER\s+TYPE\s+\w+\s+ADD\s+VALUE[^;]*;/gim;

async function replay(db, skip) {
  await db.exec(`CREATE ROLE anon NOLOGIN; CREATE ROLE authenticated NOLOGIN; CREATE ROLE service_role NOLOGIN;`);
  for (const f of ORDER) {
    if (skip.has(f)) continue;
    let sql = fs.readFileSync(`${DB}/${f}`, 'utf8').replace(/CREATE EXTENSION IF NOT EXISTS\s+"?pgcrypto"?\s*;/gi, '');
    for (const s of sql.match(ENUM_ADD) || []) await db.exec(s);
    await db.exec(sql.replace(ENUM_ADD, ''));
  }
}

async function catalog(db) {
  const q = async (s) => (await db.query(s)).rows;
  return {
    columns: (await q(`SELECT table_name||'.'||column_name||' '||data_type||' '||is_nullable AS k FROM information_schema.columns WHERE table_schema='public' ORDER BY 1`)).map(r => r.k),
    indexes: (await q(`SELECT indexdef AS k FROM pg_indexes WHERE schemaname='public' ORDER BY 1`)).map(r => r.k),
    constraints: (await q(`SELECT rel.relname||' '||pg_get_constraintdef(c.oid) AS k FROM pg_constraint c JOIN pg_class rel ON rel.oid=c.conrelid JOIN pg_namespace n ON n.oid=rel.relnamespace WHERE n.nspname='public' ORDER BY 1`)).map(r => r.k),
    triggers: (await q(`SELECT pg_get_triggerdef(t.oid) AS k FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY 1`)).map(r => r.k),
    functions: (await q(`SELECT p.proname||'('||pg_get_function_identity_arguments(p.oid)||')' AS k FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' ORDER BY 1`)).map(r => r.k),
    tables: (await q(`SELECT n.nspname||'.'||c.relname AS k FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('public','archive') AND c.relkind='r' ORDER BY 1`)).map(r => r.k),
  };
}
const diff = (a, b) => ({ removed: a.filter(x => !b.includes(x)), added: b.filter(x => !a.includes(x)) });
const sameCatalog = (a, b) => Object.keys(a).every(k => diff(a[k], b[k]).removed.length === 0 && diff(a[k], b[k]).added.length === 0);

async function checksums(db, colsByTable) {
  const out = {};
  for (const [t, cols] of Object.entries(colsByTable)) {
    const list = cols.map(c => `"${c}"`).join(',');
    const r = await db.query(`SELECT count(*)::int AS n, md5(coalesce(string_agg(x::text, '|' ORDER BY x::text), '')) AS h FROM (SELECT ROW(${list}) AS x FROM public."${t}") s`);
    out[t] = `${r.rows[0].n}:${r.rows[0].h}`;
  }
  return out;
}

async function seed(db) {
  const one = async (s, p = []) => (await db.query(s, p)).rows[0];
  const svcOf = async (cat) => (await one(`SELECT service_id FROM services WHERE category = $1 ORDER BY service_id LIMIT 1`, [cat]))?.service_id
    ?? (await one(`INSERT INTO services (service_name, category, fee) VALUES ($1, $2, 500) RETURNING service_id`, [`Test ${cat}`, cat])).service_id;
  const svc = await svcOf('Baptism');
  const wed = await svcOf('Wedding');
  const u = (await one(`INSERT INTO users (role_id, firstname, lastname, email, password) VALUES (1,'Juan','Dela Cruz','juan@test.local','x') RETURNING user_id`)).user_id;
  const par = (await one(`INSERT INTO parishioners (user_id) VALUES ($1) RETURNING parishioner_id`, [u])).parishioner_id;
  const guestPar = (await one(`SELECT p.parishioner_id FROM parishioners p JOIN users u ON u.user_id=p.user_id WHERE u.email='guest@parishhub.internal'`)).parishioner_id;
  await db.query(`INSERT INTO priests (full_name) VALUES ('Fr. Test Priest')`);
  const records = {};
  for (const [label, parishioner, extra] of [['member', par, null], ['guest', guestPar, 'PH-TEST01']]) {
    const a = (await one(`INSERT INTO appointments (parishioner_id, service_id, status_id, guest_name, guest_phone, guest_reference)
                          VALUES ($1,$2,6,$3,$4,$5) RETURNING appointment_id`, [parishioner, svc, extra ? 'Maria Guest' : null, extra ? '09170000000' : null, extra])).appointment_id;
    const pay = (await one(`INSERT INTO payments (appointment_id, amount, method_id, payment_status) VALUES ($1, 500, 1, 'verified') RETURNING payment_id`, [a])).payment_id;
    const rc = await one(`INSERT INTO official_receipts (payment_id, receipt_number) VALUES ($1, '') RETURNING receipt_number`, [pay]);
    await db.query(`INSERT INTO transactions (payment_id, gateway, status) VALUES ($1, 'paymongo', 'paid')`, [pay]);
    await db.query(`INSERT INTO donations (appointment_id, purpose) VALUES ($1, 'Charity')`, [a]);
    await db.query(`INSERT INTO mass_intentions (appointment_id, intention_type, offerer_name, intention_for) VALUES ($1,'Living','Offerer','Intended')`, [a]);
    const doc = (await one(`INSERT INTO uploaded_documents (appointment_id, file_name, file_path, document_source, review_status) VALUES ($1,'birth.pdf','k/birth.pdf','uploaded','approved') RETURNING document_id`, [a])).document_id;
    await db.query(`INSERT INTO generated_forms (service_category, form_type, appointment_id, status) VALUES ('Baptism','katin_awan_bunyag',$1,'approved')`, [a]);
    await db.query(`INSERT INTO issued_certificates (appointment_id, certificate_type, status, document_id) VALUES ($1,'Baptism','claimed',$2)`, [a, doc]);
    await db.query(`INSERT INTO email_logs (dedupe_key, notification_type, recipient, subject, body_html, appointment_id, status) VALUES ($1,'revision_request','x@test.local','S','<p>B</p>',$2,'sent')`, [`dk-${label}`, a]);
    records[label] = { appointment_id: a, receipt_number: rc.receipt_number };
  }
  await db.query(`INSERT INTO notifications (user_id, title, message) VALUES ($1,'T','M')`, [u]);
  await db.query(`INSERT INTO appointment_drafts (service_type, service_id, parishioner_id, status) VALUES ('Wedding',$1,$2,'draft')`, [wed, par]);
  await db.query(`UPDATE appointments SET status_id = 5 WHERE appointment_id = $1`, [records.member.appointment_id]); // fires notify + activity-log triggers
  return { memberUserId: u, ...records };
}

async function protectedCounts(db) {
  const q = async (s) => (await db.query(s)).rows[0].n;
  return {
    appointments: await q(`SELECT count(*)::int n FROM appointments`),
    payments: await q(`SELECT count(*)::int n FROM payments`),
    official_receipts: await q(`SELECT count(*)::int n FROM official_receipts`),
    issued_certificates: await q(`SELECT count(*)::int n FROM issued_certificates`),
    uploaded_documents: await q(`SELECT count(*)::int n FROM uploaded_documents`),
  };
}

async function tryDelete(db, sql) {
  await db.exec('BEGIN');
  try {
    const before = await protectedCounts(db);
    await db.exec(sql);
    const after = await protectedCounts(db);
    await db.exec('ROLLBACK');
    return { outcome: 'DELETED (rolled back)', before, after };
  } catch (e) {
    await db.exec('ROLLBACK');
    return { outcome: 'BLOCKED', error: e.message.split('\n')[0] };
  }
}

function optInBlock(file, partHeader) {
  const text = fs.readFileSync(file, 'utf8');
  const start = text.indexOf(partHeader);
  const sect = text.slice(start);
  const lines = sect.split(/\r?\n/);
  const b = lines.findIndex(l => l.trim() === '-- BEGIN;');
  const e = lines.findIndex((l, i) => i > b && l.trim() === '-- COMMIT;');
  return lines.slice(b, e + 1).map(l => l.replace(/^-- ?/, '')).join('\n');
}

async function explainAll(db) {
  const items = JSON.parse(fs.readFileSync(`${SCRATCH}/sql.json`, 'utf8'));
  const counts = {}; const real = [];
  for (const it of items) {
    const sql = it.sql.replace(/\/\*EXPR\*\//g, ' ').replace(/\?/g, 'NULL').replace(/(?<![:\w]):[A-Za-z_]\w*/g, 'NULL').trim().replace(/;\s*$/, '');
    try { await db.query('EXPLAIN ' + sql); counts.OK = (counts.OK || 0) + 1; }
    catch (e) {
      counts[e.code] = (counts[e.code] || 0) + 1;
      if (['42703', '42883', '42804'].includes(e.code)) real.push(`${it.file}:${it.line} [${e.code}] ${e.message.split('\n')[0]}`);
    }
  }
  return { counts, schemaErrors: real };
}

const report = {};
for (const [name, skip] of [['A_cleanup_audit_applied', new Set()], ['B_cleanup_audit_not_applied', new Set(['cleanup_audit.sql'])]]) {
  const r = (report[name] = { steps: [] });
  const step = (label, ok, detail) => r.steps.push({ label, ok, ...(detail !== undefined ? { detail } : {}) });
  const db = new PGlite();
  await replay(db, skip);
  const seeded = await seed(db);
  step('seed synthetic records (member + guest booking chains)', true, { receipt_numbers: [seeded.member.receipt_number, seeded.guest.receipt_number] });
  step('receipt trigger formats OR-YYYY-NNNNNN', /^OR-\d{4}-\d{6}$/.test(seeded.member.receipt_number), seeded.member.receipt_number);

  const pre = await db.query(fs.readFileSync(`${OUT}/DATABASE_PREFLIGHT_CHECK.sql`, 'utf8'));
  const flagged = pre.rows.filter(x => /MISSING|STILL PRESENT|UNKNOWN/.test(x.result)).map(x => `${x.check_group}:${x.check_name}=${x.result}`);
  step('preflight runs read-only and returns one result set', pre.rows.length > 0, { rows: pre.rows.length, flagged });

  const baseCat = await catalog(db);
  const cols = {};
  for (const c of (await db.query(`SELECT table_name, column_name FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name, ordinal_position`)).rows)
    (cols[c.table_name] ||= []).push(c.column_name);
  const baseSums = await checksums(db, cols);

  const lost = (r) => r.outcome.startsWith('DELETED') && r.after.payments < r.before.payments && r.after.official_receipts < r.before.official_receipts && r.after.issued_certificates < r.before.issued_certificates;
  const m0 = await tryDelete(db, `DELETE FROM users WHERE user_id = ${seeded.memberUserId}`);
  step('BEFORE migration: deleting a member user cascades into financial/certificate records (risk confirmed)', lost(m0), m0);
  const g0 = await tryDelete(db, `DELETE FROM users WHERE email = 'guest@parishhub.internal'`);
  step('BEFORE migration: deleting the guest placeholder cascades into ALL guest records (risk confirmed)', lost(g0), g0);

  const mig = fs.readFileSync(`${OUT}/DATABASE_MIGRATION_SAFE.sql`, 'utf8');
  try { await db.exec(mig); step('apply DATABASE_MIGRATION_SAFE.sql (Part A)', true); }
  catch (e) { step('apply DATABASE_MIGRATION_SAFE.sql (Part A)', false, e.message); }
  const afterCat = await catalog(db);
  try { await db.exec(mig); step('re-apply Part A (idempotent)', sameCatalog(afterCat, await catalog(db))); }
  catch (e) { step('re-apply Part A (idempotent)', false, e.message); }
  step('catalog changes made by Part A', true, Object.fromEntries(Object.keys(baseCat).map(k => [k, diff(baseCat[k], afterCat[k])])));

  const afterSums = await checksums(db, cols);
  const changed = Object.keys(baseSums).filter(t => baseSums[t] !== afterSums[t]);
  step('all pre-existing rows identical after Part A (per-table count + md5)', changed.length === 0, { tables_checked: Object.keys(baseSums).length, changed });

  const gBlocked = await tryDelete(db, `DELETE FROM users WHERE email = 'guest@parishhub.internal'`);
  step('AFTER Part A: guest placeholder delete is blocked', gBlocked.outcome === 'BLOCKED', gBlocked);
  const wBlocked = await tryDelete(db, `DELETE FROM users WHERE email = 'walkin-donor@parishhub.internal'`);
  step('AFTER Part A: walk-in donor placeholder delete is blocked', wBlocked.outcome === 'BLOCKED', wBlocked);
  const rename = await tryDelete(db, `UPDATE users SET email = 'renamed@parishhub.internal' WHERE email = 'guest@parishhub.internal'`);
  step('AFTER Part A: renaming the guest placeholder email is blocked', rename.outcome === 'BLOCKED', rename);
  const normal = await tryDelete(db, `UPDATE users SET email = 'juan2@test.local' WHERE user_id = ${seeded.memberUserId}; INSERT INTO users (role_id, firstname, lastname, email, password) VALUES (2,'Temp','Staff','temp@test.local','x'); DELETE FROM users WHERE email = 'temp@test.local'`);
  step('AFTER Part A: ordinary users can still be renamed and deleted', normal.outcome !== 'BLOCKED', normal);
  const ex = await explainAll(db);
  step('EXPLAIN all extracted PHP SQL against post-migration schema', ex.schemaErrors.every(x => /42883/.test(x)), ex);

  const partB = optInBlock(`${OUT}/DATABASE_MIGRATION_SAFE.sql`, 'PART B — OPT-IN');
  try { await db.exec(partB); step('apply Part B (opt-in FK hardening)', true); } catch (e) { step('apply Part B (opt-in FK hardening)', false, e.message); }
  const mB = await tryDelete(db, `DELETE FROM users WHERE user_id = ${seeded.memberUserId}`);
  step('AFTER Part B: member delete with history is blocked', mB.outcome === 'BLOCKED', mB);
  try { await db.exec(optInBlock(`${OUT}/DATABASE_ROLLBACK.sql`, 'PART B rollback')); step('rollback Part B', sameCatalog(afterCat, await catalog(db))); }
  catch (e) { step('rollback Part B', false, e.message); }

  const partC = optInBlock(`${OUT}/DATABASE_MIGRATION_SAFE.sql`, 'PART C — OPT-IN');
  try { await db.exec(partC); const t = (await catalog(db)).tables.filter(x => x.startsWith('archive.')); step('apply Part C (archive unused tables)', true, { archived: t }); }
  catch (e) { step('apply Part C (archive unused tables)', false, e.message); }
  try {
    await db.exec(optInBlock(`${OUT}/DATABASE_ROLLBACK.sql`, 'PART C rollback'));
    const c = await catalog(db);
    step('rollback Part C (tables back in public, data intact)', diff(afterCat.tables, c.tables).removed.length === 0, diff(afterCat.tables, c.tables));
  } catch (e) { step('rollback Part C', false, e.message); }

  // Part D: simulate Supabase's default API grants, then prove RLS denies anon while the owner (app) still reads.
  await db.exec(`GRANT USAGE ON SCHEMA public TO anon; GRANT SELECT ON ALL TABLES IN SCHEMA public TO anon;`);
  const anonCount = async () => { await db.exec('SET ROLE anon'); try { return (await db.query('SELECT count(*)::int n FROM users')).rows[0].n; } finally { await db.exec('RESET ROLE'); } };
  const ownerCount = async () => (await db.query('SELECT count(*)::int n FROM users')).rows[0].n;
  const anonBefore = await anonCount();
  try {
    await db.exec(optInBlock(`${OUT}/DATABASE_MIGRATION_SAFE.sql`, 'PART D — OPT-IN'));
    const off = (await db.query(`SELECT count(*)::int n FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' AND NOT c.relrowsecurity`)).rows[0].n;
    const anonAfter = await anonCount(); const owner = await ownerCount();
    step('apply Part D: RLS on every public table; anon sees 0 rows; owner (app) unaffected', off === 0 && anonAfter === 0 && owner === anonBefore, { tables_without_rls: off, anon_rows_before: anonBefore, anon_rows_after: anonAfter, owner_rows_after: owner });
    await db.exec(optInBlock(`${OUT}/DATABASE_ROLLBACK.sql`, 'PART D rollback'));
    const on = (await db.query(`SELECT count(*)::int n FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' AND c.relrowsecurity`)).rows[0].n;
    step('rollback Part D (RLS off again, comments restored)', on === 0 && (await anonCount()) === anonBefore, { tables_with_rls: on });
  } catch (e) { step('Part D', false, e.message); }
  await db.exec(`REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM anon; REVOKE USAGE ON SCHEMA public FROM anon;`);

  try {
    await db.exec(fs.readFileSync(`${OUT}/DATABASE_ROLLBACK.sql`, 'utf8'));
    const rbCat = await catalog(db);
    const d = Object.fromEntries(Object.keys(baseCat).map(k => [k, diff(baseCat[k], rbCat[k])]));
    // an empty archive schema may remain after Part C; it holds no tables
    step('rollback Part A restores the original catalog', sameCatalog(baseCat, rbCat), d);
  } catch (e) { step('rollback Part A', false, e.message); }
  const rbSums = await checksums(db, cols);
  step('all pre-existing rows identical after full rollback', Object.keys(baseSums).every(t => baseSums[t] === rbSums[t]));
  await db.close();
}

fs.writeFileSync(`${SCRATCH}/validation_result.json`, JSON.stringify(report, null, 2));
for (const [s, r] of Object.entries(report)) {
  console.log(`\n=== Scenario ${s}`);
  for (const st of r.steps) console.log(`${st.ok ? 'PASS' : 'FAIL'}  ${st.label}`);
}
