import { PGlite } from '@electric-sql/pglite';
const db = new PGlite();
await db.exec(`CREATE TABLE appointments (appointment_id serial primary key, outside_priest_name text);`);
await db.exec('BEGIN');
await db.exec('ALTER TABLE appointments ALTER COLUMN outside_priest_name TYPE TEXT');
const r = await db.query(`SELECT mode FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'appointments'`);
console.log('locks held on appointments by a no-op ALTER COLUMN TYPE:', r.rows.map(x => x.mode).join(', '));
await db.exec('ROLLBACK');
await db.close();
