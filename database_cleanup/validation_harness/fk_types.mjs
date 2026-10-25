import fs from 'node:fs';
const s = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const typeOf = (t, c) => s.cols.find(x => x.table === t && x.name === c)?.type;
const base = (t) => (t || '').replace(/\(.*\)/, '');
for (const k of s.cons.filter(k => k.type === 'f')) {
  const m = k.def.match(/FOREIGN KEY \((\w+)\) REFERENCES (\w+)\((\w+)\)/);
  if (!m) continue;
  const [, col, rt, rc] = m;
  const a = typeOf(k.table, col), b = typeOf(rt, rc);
  if (base(a) !== base(b)) console.log(`${k.table}.${col} (${a}) -> ${rt}.${rc} (${b})`);
}
// timestamp without time zone columns (inconsistent with the timestamptz convention)
for (const c of s.cols.filter(c => c.type === 'timestamp without time zone')) console.log(`TZ-less timestamp: ${c.table}.${c.name}`);
