// Validates extracted PHP SQL against a replayed PGlite schema using EXPLAIN
// (EXPLAIN plans but never executes, so INSERT/UPDATE/DELETE change nothing).
// Usage: node explain_sql.mjs <dbDir> <sql.json> <out.json>
import { PGlite } from '@electric-sql/pglite';
import fs from 'node:fs';

const [, , dbDir, inFile, outFile] = process.argv;
const db = new PGlite(dbDir);
const items = JSON.parse(fs.readFileSync(inFile, 'utf8'));

function normalize(sql) {
  let s = sql.replace(/\/\*EXPR\*\//g, ' ');
  s = s.replace(/\?/g, 'NULL');
  s = s.replace(/(?<![:\w]):[A-Za-z_]\w*/g, 'NULL'); // named PDO params, not ::casts
  return s.trim().replace(/;\s*$/, '');
}

const results = [];
for (const it of items) {
  const sql = normalize(it.sql);
  let status = 'OK', code = null, message = null;
  try {
    await db.query('EXPLAIN ' + sql);
  } catch (e) {
    status = 'ERROR'; code = e.code || null; message = (e.message || '').split('\n')[0];
  }
  results.push({ ...it, normalized: sql, status, code, message });
}
// Safety: confirm nothing was written (EXPLAIN without ANALYZE never executes).
fs.writeFileSync(outFile, JSON.stringify(results, null, 2));
const by = {};
for (const r of results) { const k = r.status === 'OK' ? 'OK' : (r.code || 'other'); by[k] = (by[k] || 0) + 1; }
console.log(JSON.stringify(by));
await db.close();
