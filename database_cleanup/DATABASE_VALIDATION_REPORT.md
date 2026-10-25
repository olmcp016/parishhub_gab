# ParishHub — Database Validation Report

**Date:** 2026-10-25 · **Result:** 46 of 46 checks passed (23 per production scenario). No test touched the production database.

## 1. Test environment

| Item | Detail |
|---|---|
| Engine | **PGlite 0.2.17** = PostgreSQL **16.4** compiled to WebAssembly, run in-process by Node 22. Each test builds a fresh in-memory database and discards it. |
| Why not a local server | A PostgreSQL 17 *client* install exists (`C:\Program Files\PostgreSQL\17`), but it has no server data files (`share/postgres.bki` missing), so `initdb` cannot create a cluster. Docker is not installed. |
| Production | Never connected. No credentials were used. Supabase projects run PostgreSQL 15 or 17 (the preflight does not need to know which). Every statement used here (enums, CHECK/FK constraints, plpgsql triggers, RLS, `ALTER TABLE … SET SCHEMA`) behaves the same on 15, 16 and 17. |
| Supabase specifics | `anon`, `authenticated` and `service_role` roles were created as stubs so migrations that `REVOKE` from them run unchanged. `pgcrypto` (not bundled in PGlite) was skipped; no migration uses its functions. |
| Data | Synthetic only, covering a full member booking chain and a full guest booking chain: user → parishioner → appointment → payment → receipt (via trigger) → transaction → donation → Mass intention → uploaded document → generated form → issued certificate → email log, plus a notification and an appointment draft. |
| Reproduce | `database_cleanup/validation_harness/` (see its README). |

## 2. Rebuilding the schema from the repository

The base schema and all Postgres migrations were replayed **in git commit order**, with two corrections required for them to run at all:

1. **Enum values must be committed before use.** `migration_donations.sql` and `migration_anointing.sql` add an enum value and use it in the same batch. PostgreSQL rejects that ("unsafe use of new value"), and so would the Supabase SQL Editor. Each `ALTER TYPE … ADD VALUE` was run as its own transaction first. `migration_paymongo.sql` was handled the same way.
2. **`migration_service_process_corrections.sql` (committed 10-03) needs the `'Wake'` category** that `migration_anointing.sql` (10-05) adds. It was applied right after `migration_anointing.sql`. In production, `'Wake'` must have been added by hand.

**Excluded from the replay:**
- MySQL-only files: `schema.sql`, `seed.sql`, `parishhub_full.sql`, `migration_scheduling.sql`, `migration_service_fee_rules_mysql.sql`.
- Data-wipe and maintenance scripts: `fresh_start_wipe.sql`, `cleanup_mode1_targeted.sql`, `cleanup_mode2_fresh_reset.sql`, `parishhub_old_data_cleanup.sql` (the last is a dry run that rolls back).

**Verified application order:**
```
supabase_schema → donations* → rejection_reason → walkin_donor → consolidate_mass_intentions → scheduling_v2 →
locations → projects → announcements_v2 → paymongo* → paymongo_v2 → guest_access → users_middlename →
appointments_contact_location → priest_role → remove_demo_priests → service_fee_rules → document_review →
wedding_generated_forms → wedding_booking_drafts → baptism_booking_drafts → location_categories →
funeral_generated_forms → sessions → anointing* → service_process_corrections → [cleanup_audit] →
merge_forms → merge_drafts → receipt_trigger → notification_trigger → activity_log_trigger →
email_verification → must_change_password → password_reset_otp → wake_mass_rename → announcement_images →
split_wake_death_anniversary → special_mass_request → auth_throttle → requirements_review →
notifications_v2 → precana → funeral_preferred_burial → payment_deadline → outside_priest →
certificate_request* → issued_certificates → certificate_claimed → certificate_allow_form_type
(* = run its ALTER TYPE … ADD VALUE first, separately)
```

**Two scenarios**, because the repository cannot tell whether `cleanup_audit.sql` (a schema-changing script with a data-script name) ran in production:

| Scenario | Tables | Columns | Constraints | Indexes | Triggers |
|---|---|---|---|---|---|
| A — `cleanup_audit.sql` applied | 34 | 330 | 136 | 70 | 9 |
| B — not applied | 37 | 349 | 144 | 74 | 9 |

In both scenarios, the merge migrations remove all five legacy tables (`generated_wedding_forms`, `generated_baptism_forms`, `generated_funeral_forms`, `wedding_booking_drafts`, `baptism_booking_drafts`). `DATABASE_PREFLIGHT_CHECK.sql` tells you which scenario production is in.

## 3. Results

### 3.1 Schema supports the existing code (Phase 7 checklist)

| Check | Method | Result |
|---|---|---|
| Authentication queries match the schema | EXPLAIN on all `auth/`, `staff/login.php`, `includes/auth.php` SQL, including the `auth_throttle` upsert and `password_reset_otps` queries | ✅ Pass |
| Booking records can be created and retrieved | EXPLAIN on all appointment / draft INSERT, UPDATE and SELECT branches (column and value counts checked by hand); synthetic member and guest bookings inserted | ✅ Pass |
| Generated forms use the correct table | Every forms query targets `generated_forms`; no reference to the old tables anywhere | ✅ Pass |
| Appointment drafts stored correctly | Inserts satisfy all 12 CHECK constraints on `appointment_drafts`; finalize path valid | ✅ Pass |
| Document review workflow consistent | `review_status`, `document_source`, `generated_form_type` literals all allowed by CHECKs | ✅ Pass (certificate type requires the newest migration, M-06) |
| Payments and receipts reference correct records | payment → receipt → transaction chain inserted; receipt trigger produced `OR-2026-000001`, `OR-2026-000002` | ✅ Pass |
| Notifications and email logs consistent | Status-change trigger fired on update; `email_logs` insert valid against CHECK | ✅ Pass |
| Certificate records have valid relationships | `issued_certificates` FKs to appointment and document; statuses `draft → generated → ready_for_pickup → claimed` match CHECK and code | ✅ Pass |
| Reports reference existing tables and columns | 79 hand-reconstructed branches of the report, dashboard and list queries | ✅ 79/79 pass |

**EXPLAIN over all 731 statements extracted from the PHP** (after the migration):

| Outcome | Count | Meaning |
|---|---|---|
| Valid | 678 | Schema-correct |
| Syntax errors | 39 | Not SQL (UI strings like "Select a valid date…", the word "delete") or fragments of dynamic SQL. Fragments were re-validated by hand. |
| "Missing FROM-clause" | 7 | Dynamic SQL whose FROM clause is in a PHP variable. All re-validated by hand: pass. |
| `operator does not exist: boolean = integer` | 7 | **Real:** M-01 |
| Undefined column | 0 after Part A | In scenario A *before* Part A: 1 (`secretary/parishioner-detail.php:31`, M-02). Fixed by Part A1. |

### 3.2 The migration is safe

| Check | Scenario A | Scenario B |
|---|---|---|
| Preflight runs read-only and returns one result set | ✅ 159 rows; flags `parishioners.baptism_date/confirmation_date` MISSING | ✅ 165 rows; flags `permissions`, `reports`, `role_permissions` STILL PRESENT |
| Part A applies | ✅ | ✅ |
| Part A re-applies with no change (idempotent) | ✅ | ✅ |
| Catalog changes made by Part A | +12 indexes, −2 duplicate indexes, +1 trigger/function, +2 nullable columns | Same, except +0 columns (already present) |
| **Every pre-existing row unchanged** (per-table row count + MD5 over the original columns) | ✅ 34/34 tables identical | ✅ 37/37 tables identical |
| Guest placeholder delete blocked after Part A | ✅ | ✅ |
| Walk-in donor placeholder delete blocked | ✅ | ✅ |
| Renaming the guest placeholder's email blocked | ✅ | ✅ |
| Ordinary users can still be renamed and deleted | ✅ | ✅ |

### 3.3 The risks the migration addresses are real

Each was run inside a transaction and **rolled back**.

| Before the migration | Scenario A | Scenario B |
|---|---|---|
| Delete a member user → their payment, receipt, certificate and document rows disappear | ✅ confirmed (payments 2→1, receipts 2→1, certificates 2→1) | ✅ confirmed |
| Delete the guest placeholder → **all** guest booking chains disappear | ✅ confirmed | ✅ confirmed |

### 3.4 Opt-in parts and every rollback

| Check | A | B |
|---|---|---|
| Part B applies; deleting a member with history is then refused (`violates foreign key constraint appointments_parishioner_id_fkey`) | ✅ | ✅ |
| Part B rollback restores the original CASCADE rules exactly | ✅ | ✅ |
| Part C archives unused tables (moved, not dropped) | ✅ `chat_messages` | ✅ `chat_messages`, `permissions`, `reports`, `role_permissions` |
| Part C rollback moves them back intact | ✅ | ✅ |
| Part D enables RLS on every public table; `anon` (with Supabase-style SELECT grants) sees **3 → 0** rows; the app's role still sees 3 | ✅ | ✅ |
| Part D rollback disables only the RLS it enabled | ✅ | ✅ |
| **Full Part A rollback restores the original catalog exactly** (columns, indexes, constraints, triggers, functions, tables) | ✅ | ✅ |
| Every pre-existing row unchanged after the full rollback | ✅ | ✅ |

### 3.5 Final schema file

`DATABASE_SCHEMA_FINAL.sql` was generated from the catalog of scenario A + Part A. It was then executed **on its own** against a fresh empty database, **twice** (idempotency). The result matched the replayed schema exactly: 34 tables, 332 columns, 136 constraints, 80 indexes, 10 triggers.

### 3.6 Focused proofs

| Claim | Test | Result |
|---|---|---|
| M-01: no column type satisfies both `= 1` and `= TRUE` | `booltest.mjs` against boolean, smallint and integer columns | `boolean = 1` → error; `smallint = TRUE` → error; `integer = TRUE` → error; quoted `'1'` works on all three |
| Part D does not affect the app | `rls_owner_test.mjs`: a **non-superuser, NOBYPASSRLS** role owns the table (as Supabase's `postgres` does) | Owner SELECT 3 rows and INSERT OK; anon SELECT 0 rows |
| M-07: runtime DDL takes the strongest lock | `locktest.mjs`: no-op `ALTER COLUMN … TYPE TEXT` on a TEXT column | Holds `AccessExclusiveLock` |

## 4. Limitations

- **Production state is inferred, not observed.** Both possible states were tested. `DATABASE_PREFLIGHT_CHECK.sql` must be run against Supabase to confirm which one is real, and whether any feature-detected migration or the M-01 compatibility workaround exists there.
- **Synthetic data, not a copy of production.** The data-preservation checks prove the migration does not modify, delete or reorder existing rows of any table (it contains no DML at all). They cannot surface production-only quirks, such as rows that violate a constraint created `NOT VALID`. The preflight's `integrity` group checks the known ones.
- **PGlite runs as a superuser.** The RLS claim was therefore re-tested separately with a non-superuser owner (§3.6).
- **Lock duration at real data volumes** was not measured. At parish scale, index builds take seconds. Use `CREATE INDEX CONCURRENTLY` if zero write-blocking is needed.
- **No PHP was executed against the database.** The PHP was validated statically: every SQL string was run through EXPLAIN, with hand reconstruction for dynamic SQL. Logic bugs EXPLAIN cannot see (M-03, M-04) were found by reading the code. A browser smoke test is listed in the cleanup plan, step 5.
