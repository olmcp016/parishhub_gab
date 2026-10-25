// Verifies RLS semantics for a NON-superuser table owner (as Supabase's "postgres" role is).
import { PGlite } from '@electric-sql/pglite';
const db = new PGlite();
await db.exec(`
  CREATE ROLE app_owner NOLOGIN NOSUPERUSER NOBYPASSRLS;
  CREATE ROLE anon NOLOGIN;
  CREATE TABLE users (user_id serial primary key, email text);
  ALTER TABLE users OWNER TO app_owner;
  INSERT INTO users (email) VALUES ('a@x'), ('b@x'), ('c@x');
  GRANT USAGE ON SCHEMA public TO anon; GRANT SELECT, INSERT ON users TO anon;
  ALTER TABLE users ENABLE ROW LEVEL SECURITY;`);
const asRole = async (role, sql) => { await db.exec(`SET ROLE ${role}`); try { return (await db.query(sql)).rows; } catch (e) { return `ERROR ${e.message}`; } finally { await db.exec('RESET ROLE'); } };
console.log('superuser check (app_owner):', (await db.query(`SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname='app_owner'`)).rows[0]);
console.log('owner SELECT count :', JSON.stringify(await asRole('app_owner', 'SELECT count(*)::int n FROM users')));
console.log('owner INSERT       :', JSON.stringify(await asRole('app_owner', `INSERT INTO users (email) VALUES ('owner@x') RETURNING user_id`)));
console.log('anon SELECT count  :', JSON.stringify(await asRole('anon', 'SELECT count(*)::int n FROM users')));
console.log('anon INSERT        :', JSON.stringify(await asRole('anon', `INSERT INTO users (email) VALUES ('evil@x')`)));
await db.close();
