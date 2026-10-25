# Validation harness

These scripts reproduce every check in `../DATABASE_VALIDATION_REPORT.md`. They run against a **throwaway in-memory PostgreSQL** (PGlite, PostgreSQL 16 compiled to WebAssembly), never against Supabase, and need no database server, Docker or credentials.

```bash
cd database_cleanup/validation_harness
npm install                                    # installs @electric-sql/pglite@0.2.17 only
php extract_sql.php ../.. sql.json             # re-extract SQL strings from the PHP source (C:\xampp\php\php.exe on this machine)
node validate.mjs                              # full Phase 7 run, both production scenarios -> validation_result.json
node gen_ddl.mjs                               # regenerate ../DATABASE_SCHEMA_FINAL.sql and round-trip test it
```

| Script | What it does |
|---|---|
| `replay.mjs` | Replays `database/supabase_schema.sql` + all migrations in verified order. Usage: `node replay.mjs memory <outPrefix>` dumps the resulting catalog to JSON. Set `SKIP=cleanup_audit.sql` for scenario B. |
| `validate.mjs` | Seeds synthetic data, then runs preflight → Part A → idempotent re-run → Parts B/C/D → all rollbacks, with data checksums and catalog comparisons. Asserts each outcome. |
| `extract_sql.php` / `explain_sql.mjs` | Extract every SQL string from the PHP with the PHP tokenizer and `EXPLAIN` each one (plans only; nothing executes). |
| `gen_ddl.mjs` | Generates `DATABASE_SCHEMA_FINAL.sql` from the catalog and proves it rebuilds an identical schema. |
| `analyze.mjs`, `fk_types.mjs` | Unused columns, FKs without indexes, duplicate indexes, FK type mismatches. |
| `booltest.mjs`, `rls_owner_test.mjs`, `locktest.mjs` | Focused proofs for M-01 (boolean vs integer), Part D (RLS vs non-superuser owner), and M-07 (lock taken by a no-op `ALTER COLUMN TYPE`). |

Paths to the repository are absolute (`C:/Users/JDAE/Desktop/parishhub_gab`). Edit the `REPO` constant at the top of a script if the project moves.
