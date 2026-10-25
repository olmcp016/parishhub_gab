import { PGlite } from '@electric-sql/pglite';
const db = new PGlite();
await db.exec(`CREATE TABLE tb (v boolean); CREATE TABLE ts (v smallint); CREATE TABLE ti (v integer);
  INSERT INTO tb VALUES (true); INSERT INTO ts VALUES (1); INSERT INTO ti VALUES (1);`);
for (const t of ['tb', 'ts', 'ti']) for (const lit of ['1', 'TRUE', `'1'`, `'t'`]) {
  try { const r = await db.query(`SELECT count(*)::int AS n FROM ${t} WHERE v = ${lit}`); console.log(`${t} (${t==='tb'?'boolean':t==='ts'?'smallint':'integer'}) = ${lit}: OK n=${r.rows[0].n}`); }
  catch (e) { console.log(`${t} (${t==='tb'?'boolean':t==='ts'?'smallint':'integer'}) = ${lit}: ERROR ${e.message.split('\n')[0]}`); }
}
console.log((await db.query('select version()')).rows[0].version);
await db.close();
