// Static catalog analysis of a replay dump (JSON) against the PHP source. Opens no database.
import fs from 'node:fs';
import path from 'node:path';

const [, , dumpFile, outFile] = process.argv;
const dump = JSON.parse(fs.readFileSync(dumpFile, 'utf8'));
const ROOT = 'C:/Users/JDAE/Desktop/parishhub_gab';

function walk(d, acc = []) {
  for (const e of fs.readdirSync(d, { withFileTypes: true })) {
    const p = path.join(d, e.name);
    if (e.isDirectory()) { if (!['vendor', 'node_modules', '.git', 'test-output', 'database', 'docs'].includes(e.name)) walk(p, acc); }
    else if (/\.(php|js)$/.test(e.name)) acc.push(p);
  }
  return acc;
}
const files = walk(ROOT);
const src = files.map(f => ({ f: path.relative(ROOT, f).replace(/\\/g, '/'), t: fs.readFileSync(f, 'utf8') }));
const countRefs = (word) => {
  const re = new RegExp(`\\b${word}\\b`, 'g');
  let n = 0; const where = new Set();
  for (const s of src) { const m = s.t.match(re); if (m) { n += m.length; where.add(s.f); } }
  return { n, files: [...where] };
};

const out = [];
// 1. tables with no code reference
out.push('## Tables by code reference');
for (const { table } of dump.tables) {
  const r = countRefs(table);
  out.push(`${table}\trefs=${r.n}\tfiles=${r.files.length}`);
}

// 2. columns never referenced by name anywhere in PHP/JS
out.push('\n## Columns with zero references in PHP/JS (by exact name)');
const generic = new Set(['created_at', 'updated_at']);
for (const c of dump.columns) {
  if (generic.has(c.column_name)) continue;
  const r = countRefs(c.column_name);
  if (r.n === 0) out.push(`${c.table_name}.${c.column_name}\t${c.data_type}`);
}

// 3. *_id / *_by columns that have no FK constraint
out.push('\n## *_id / *_by columns without a FOREIGN KEY');
const fkCols = new Set();
for (const k of dump.constraints.filter(k => k.type === 'f')) {
  const m = k.def.match(/^FOREIGN KEY \(([^)]+)\)/);
  if (m) for (const col of m[1].split(',').map(s => s.trim())) fkCols.add(`${k.table}.${col}`);
}
const pkCols = new Set();
for (const k of dump.constraints.filter(k => k.type === 'p')) {
  const m = k.def.match(/^PRIMARY KEY \(([^)]+)\)/);
  if (m) for (const col of m[1].split(',').map(s => s.trim())) pkCols.add(`${k.table}.${col}`);
}
for (const c of dump.columns) {
  const key = `${c.table_name}.${c.column_name}`;
  if (/(_id|_by|_by_user_id)$/.test(c.column_name) && !fkCols.has(key) && !pkCols.has(key)) out.push(`${key}\t${c.data_type}`);
}

// 4. FK list
out.push('\n## Foreign keys');
for (const k of dump.constraints.filter(k => k.type === 'f')) out.push(`${k.table}\t${k.name}\t${k.def}`);

// 5. duplicate / redundant indexes (same table + same column list), and FK columns lacking an index
out.push('\n## Indexes: duplicates / redundant');
const idxCols = (def) => { const m = def.match(/\(([^)]*)\)/); return m ? m[1].replace(/\s+/g, '') : ''; };
const byTable = {};
for (const i of dump.indexes) (byTable[i.table] ||= []).push({ ...i, cols: idxCols(i.def), unique: /UNIQUE/.test(i.def), partial: / WHERE /.test(i.def) });
for (const [t, list] of Object.entries(byTable)) {
  for (let a = 0; a < list.length; a++) for (let b = a + 1; b < list.length; b++) {
    const x = list[a], y = list[b];
    if (x.partial || y.partial) continue;
    if (x.cols === y.cols) out.push(`DUPLICATE ${t}: ${x.name} == ${y.name} (${x.cols})`);
    else if (y.cols.startsWith(x.cols + ',') && !x.unique) out.push(`REDUNDANT-PREFIX ${t}: ${x.name}(${x.cols}) covered by ${y.name}(${y.cols})`);
    else if (x.cols.startsWith(y.cols + ',') && !y.unique) out.push(`REDUNDANT-PREFIX ${t}: ${y.name}(${y.cols}) covered by ${x.name}(${x.cols})`);
  }
}
out.push('\n## FK columns without a leading index');
for (const k of dump.constraints.filter(k => k.type === 'f')) {
  const m = k.def.match(/^FOREIGN KEY \(([^)]+)\)/); if (!m) continue;
  const col = m[1].split(',')[0].trim();
  const has = (byTable[k.table] || []).some(i => i.cols.split(',')[0] === col);
  if (!has) out.push(`${k.table}.${col}`);
}

// 6. enums, triggers, functions summary
out.push('\n## Enums'); for (const e of dump.enums) out.push(`${e.name}: ${e.labels}`);
out.push('\n## Triggers'); for (const t of dump.triggers) out.push(`${t.table}\t${t.def}`);
out.push('\n## Functions'); for (const f of dump.functions) out.push(f.name);

fs.writeFileSync(outFile, out.join('\n') + '\n');
console.log(`wrote ${outFile} (${out.length} lines)`);
