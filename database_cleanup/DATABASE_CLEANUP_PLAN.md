# ParishHub — Database Cleanup Plan

**Date:** 2026-10-25 · **Scope:** PostgreSQL (Supabase) schema used by the PHP 8.2 application · **Status:** proposal for review — nothing has been applied to production.

This plan classifies every table, says what to do with it and why, and gives the order of operations, backup steps and rollback path. The evidence behind each line is in `DATABASE_AUDIT_REPORT.md`; the SQL is in `DATABASE_MIGRATION_SAFE.sql` / `DATABASE_ROLLBACK.sql`; and every step was rehearsed on disposable databases (`DATABASE_VALIDATION_REPORT.md`).

---

## 1. Summary

The schema is in better shape than the migration history suggests. Out of 42 tables that have ever existed:

- **31 tables are ACTIVE** and match what the code reads and writes. All 731 SQL statements found in the PHP were checked against the replayed schema, plus 79 hand-reconstructed dynamic-query branches. None of them references a missing table or column, apart from the two items below.
- **The consolidation is complete in the code.** `generated_forms` and `appointment_drafts` fully replace the five old per-service tables. No current code references the old tables, and the merge migrations copy their data before dropping them.
- **Nothing needs to be deleted to make the database match the system.** The changes that matter are small and additive.

The real risks are not unused tables. They are:
1. **Data-loss paths through `ON DELETE CASCADE`.** Deleting a parishioner user in Admin → Users silently deletes that person's appointments, payments, receipts and certificates. Deleting the shared guest placeholder account would erase **every guest record**. Both were demonstrated on the test databases.
2. **Seven queries compare boolean columns to `1`** while other code compares the same columns to `TRUE`. On standard PostgreSQL one of the two groups must fail (M-01).
3. **`cleanup_audit.sql` dropped two columns that `secretary/parishioner-detail.php` still selects.** If that script ran in production, the parishioner detail page errors (M-02).
4. **The public Supabase REST API can read every table.** No table has Row Level Security, and the "anon" API key is publishable by design.

## 2. Table classification

**Used by system** = referenced by SQL in current PHP (number of files in parentheses), verified by EXPLAIN against the replayed schema.

| # | Table | Current purpose | Used by system | Classification | Problem | Recommended action |
|---|---|---|---|---|---|---|
| 1 | appointments | Every service request (sacraments, Mass intentions, donations, certificate requests; members and guests) | Yes (58) | ACTIVE | 11 columns added by later migrations; feature-detected at runtime. `parishioner_id` cascades from users. | **Keep.** Add FK indexes (Part A). Consider RESTRICT on `parishioner_id` (Part B). |
| 2 | users | Login accounts + guest/walk-in placeholders | Yes (50) | ACTIVE | Admin delete cascades through all history; guest placeholder unprotected. 2 timezone-less timestamps. | **Keep.** Protect guest placeholder (Part A, A4). PHP guard + Part B. |
| 3 | services | Bookable services, fees, comma-separated requirement lists | Yes (51) | ACTIVE | `is_active` compared to both `1` and `TRUE` (M-01). | **Keep.** Fix the PHP comparisons. |
| 4 | parishioners | Profile per parishioner user | Yes (40) | NEEDS MODIFICATION (state A only) | `baptism_date`/`confirmation_date` dropped by cleanup_audit.sql but still selected (M-02). | **Keep.** Re-add the two nullable columns if missing (Part A, A1), or remove them from the SELECT. |
| 5 | priests | Priest roster (optional login link) | Yes (26) | ACTIVE | — | **Keep.** |
| 6 | payments | Payments per appointment (cash / PayMongo) | Yes (28) | ACTIVE | CASCADE from appointments. | **Keep.** Index `appointment_id` (A). RESTRICT (B). |
| 7 | uploaded_documents | Uploaded + generated files, per-document review | Yes (20) | ACTIVE | `requirement_id` points at the unused `requirements` table and is never set on first upload. | **Keep.** Index `appointment_id` (A). |
| 8 | generated_forms | Online forms for Wedding/Baptism/Funeral (consolidated) | Yes (16) | ACTIVE | `form_type` has no CHECK constraint (low). | **Keep.** This is the correct consolidated table. |
| 9 | appointment_drafts | Unfinished Wedding/Baptism bookings (consolidated) | Yes (7) | ACTIVE | Status values `expired`/`abandoned` never written (low). | **Keep.** This is the correct consolidated table. Index `parishioner_id` (A). |
| 10 | issued_certificates | Official certificates, register refs, pickup tracking | Yes (6) | ACTIVE | CASCADE from appointments would delete certificate records; `claimed_by_user_id` is `bigint` → `users.user_id integer`. | **Keep.** RESTRICT (B). Align type in a later change. |
| 11 | notifications | In-app notifications | Yes (15) | ACTIVE | `type` enum includes unused `email`/`sms`; trigger writes guest notifications to the placeholder user. | **Keep.** Index `appointment_id` (A). |
| 12 | email_logs | Outgoing email log with retry/de-dupe | Yes (1 — `includes/email-log.php`, used by 4 modules) | ACTIVE | Feature-detected; if absent, revision emails are not logged. | **Keep.** Confirm present (preflight). |
| 13 | transactions | Raw PayMongo gateway records | Yes (11) | ACTIVE | CASCADE from payments. | **Keep.** Index `payment_id` (A). RESTRICT (B). |
| 14 | official_receipts | Receipts; number generated by trigger | Yes (6) | ACTIVE | CASCADE from payments. | **Keep.** RESTRICT (B). |
| 15 | donations | Donation details | Yes (14) | ACTIVE | CASCADE from appointments. | **Keep.** Indexes (A). RESTRICT (B). |
| 16 | mass_intentions | Mass intention details | Yes (10) | ACTIVE | — | **Keep.** Index (A). |
| 17 | projects | Ongoing parish projects | Yes (15) | ACTIVE | — | **Keep.** |
| 18 | payment_methods | Method lookup (ids hard-coded; 7 = PayMongo) | Yes (9) | ACTIVE | Row 3 stored as "PayMaya", code labels it "Maya". | **Keep.** Optional label alignment. Never renumber. |
| 19 | appointment_status | Status lookup (ids 1–7 hard-coded) | Yes (18) | ACTIVE | — | **Keep.** Never renumber. |
| 20 | roles | Roles (Treasurer shown as "Cashier") | Yes (10) | ACTIVE | — | **Keep.** |
| 21 | settings | Key/value settings | Yes (14) | ACTIVE | Parish contact keys not seeded (receipts blank until saved once). | **Keep.** |
| 22 | calendar | Parish calendar / blocked dates | Yes (7) | ACTIVE | `is_blocked` compared to both `1` and `TRUE` (M-01). | **Keep.** Fix PHP. |
| 23 | events | Calendar events | Yes (6) | ACTIVE | — | **Keep.** |
| 24 | locations | Event locations | Yes (6) | ACTIVE | — | **Keep.** |
| 25 | announcements | Announcements (up to 3 images) | Yes (7) | ACTIVE | — | **Keep.** |
| 26 | service_schedules | Regular schedule slots | Yes (2) | ACTIVE | — | **Keep.** |
| 27 | service_fee_rules | Fee rules (server-side only) | Yes (3) | ACTIVE | — | **Keep.** Privileges already locked down. |
| 28 | priest_unavailability | Priest blackout periods | Yes (2) | ACTIVE | — | **Keep.** |
| 29 | activity_logs | Audit trail (PHP + trigger) | Yes (3 + central `logActivity()`) | ACTIVE | — | **Keep.** Index (A). |
| 30 | auth_throttle | Login / guest-lookup lockout counters | Yes (1 — `includes/auth.php`, used by both login portals) | ACTIVE | — | **Keep.** |
| 31 | password_reset_otps | OTP password reset | Yes (1 — `auth/forgot-password.php`) | ACTIVE | Duplicate index on `email`. | **Keep.** Drop duplicate index (A). |
| 32 | app_sessions | DB-backed PHP sessions | Only when `SESSION_DRIVER=database` (default `files`; recent commit reverted to files) | ACTIVE (conditional) | Unused under the current default. The handler auto-creates it. | **Keep.** Harmless when unused. Required if DB sessions are re-enabled. |
| 33 | requirements | Legacy normalized requirement list | No SQL reads/writes; still the target of `uploaded_documents.requirement_id` FK | UNUSED | Requirement lists actually live in `services.requirements` (text). | **Review.** Keep for now: removing it means dropping an FK and column. Mark as legacy in documentation. |
| 34 | chat_messages | Chatbot log | No (0) | UNUSED | The chatbot (`includes/public-chatbot.php`) stores nothing. | **Archive (Part C, opt-in).** Moved, not dropped. |
| 35 | permissions | Unused RBAC | No (0) | UNUSED / DEPRECATED | Already dropped by `cleanup_audit.sql` if that ran. | **Archive (Part C)** if still present. |
| 36 | role_permissions | Unused RBAC | No (0) | UNUSED / DEPRECATED | Same as above. | **Archive (Part C)** if still present. |
| 37 | reports | Never written | No (0) | UNUSED / DEPRECATED | Report pages compute live. | **Archive (Part C)** if still present. |
| 38 | generated_wedding_forms | Old per-service forms | No (0) | DEPRECATED | Merged into `generated_forms` and dropped by `migration_merge_forms.sql`. | **None if absent** (expected). If present: run the merge migration (it copies then drops). |
| 39 | generated_baptism_forms | Old per-service forms | No (0) | DEPRECATED | Same as above. | Same as above. |
| 40 | generated_funeral_forms | Old per-service forms (never documented in Chapter 2) | No (0) | DEPRECATED | Same as above. | Same as above. |
| 41 | wedding_booking_drafts | Old drafts | No (0) | DEPRECATED | Merged into `appointment_drafts` and dropped by `migration_merge_drafts.sql`. | **None if absent** (expected). |
| 42 | baptism_booking_drafts | Old drafts | No (0) | DEPRECATED | Same as above. | Same as above. |

**MISSING tables:** none. Every table the code queries exists after the migrations are applied. Some features feature-detect their table or columns and **silently switch off** if a migration is missing: payment deadline, requirements review, preferred burial, Pre-Cana, outside priest, email log, and notification-to-appointment links. Run `DATABASE_PREFLIGHT_CHECK.sql` to see whether any of these are missing in production.

**DUPLICATE tables:** none remain. The per-service form/draft tables were duplicates and have been consolidated. Two **duplicate indexes** remain: `idx_appt_guest_reference` and `idx_prot_email`.

## 3. Every proposed change, explained

### Part A — default, additive, safe on any production state

| ID | Change | Why | Risk | Reversible |
|---|---|---|---|---|
| A0 | Guard: abort unless `generated_forms`, `appointment_drafts`, `parishioners`, `appointments` exist | Prevents running on a database that is behind (the merge migrations must come first) or isn't ParishHub. | None | n/a |
| A1 | Add `parishioners.baptism_date`, `confirmation_date` (DATE, nullable) **only if missing** | `secretary/parishioner-detail.php:31` selects them; `cleanup_audit.sql` dropped them, so the page errors. Adding empty nullable columns fixes it without touching data. | None — no rows change | Yes. Rollback drops them only if this script added them **and** they're still empty. |
| A2 | Drop `idx_appt_guest_reference`, `idx_prot_email` | Each is identical to the index that already backs a UNIQUE constraint on the same column. Wasted writes and storage, no read benefit. | None — uniqueness and lookup speed unchanged | Yes, recreated by rollback |
| A3 | Add 12 indexes on FK columns used in joins (`appointments.parishioner_id`, `service_id`, `(priest_id, appointment_date)`, `payments.appointment_id`, …) | About 40 FK columns have no index. These 12 are the ones on the hot paths: dashboards, appointment lists, payment and receipt lookups, conflict checks. | Brief write lock while each index builds (seconds at parish data size). Use `CREATE INDEX CONCURRENTLY` one by one if you need zero blocking. | Yes |
| A4 | Trigger blocking DELETE / email-change of the two system placeholder users (`guest@parishhub.internal`, `walkin-donor@parishhub.internal`) | These rows anchor every guest booking and every walk-in donation through `ON DELETE CASCADE`. One delete in Admin → Users would erase all of them, including payments, receipts and certificates (demonstrated). | Only those forbidden actions now error instead of destroying data | Yes |

### Part B — opt-in: stop cascading deletes of financial and certificate records

Change these FKs from `ON DELETE CASCADE` to `ON DELETE RESTRICT`: `appointments.parishioner_id`, `payments.appointment_id`, `official_receipts.payment_id`, `transactions.payment_id`, `donations.appointment_id`, `issued_certificates.appointment_id`.

- **Why:** `admin/users.php` deletes users with no history check. Receipts and certificates are records the parish must keep.
- **Prerequisite:** deploy a PHP guard in `admin/users.php` first. If the user has appointments, refuse and offer **Deactivate**. Without the guard, the admin gets a database error instead of a friendly message. See M-05.
- **Effect verified:** after Part B, deleting a member with history is refused, and rollback restores CASCADE exactly.

### Part C — opt-in: archive unused tables

Move `chat_messages` (and `permissions`, `role_permissions`, `reports` if they still exist) into an `archive` schema with `ALTER TABLE … SET SCHEMA`. Data, constraints and indexes travel with them. Moving them back is a one-liner in the rollback. Nothing is deleted.

- **Why:** they have no code dependency and clutter the schema, the ERD and the thesis.
- **Not included:** `requirements`, because an FK still points at it.

### Part D — opt-in, recommended: enable Row Level Security (deny-all)

- **Why:** Supabase publishes every `public` table through its REST API. With no RLS, anyone holding the project URL and the publishable anon key can read `users` (including password hashes), appointments and payments.
- **App impact:** none expected. ParishHub never uses the REST API or supabase-js. PHP connects with PDO as the role that owns the tables, and RLS does not apply to a table's owner. Verified on the test database with a non-superuser owner: the owner still reads and writes everything, while anon sees 0 rows.
- **Confirm first:** the preflight `rls` rows must show your DB_USER role as owner of every table.

### Not changed by the SQL (code fixes, see `DATABASE_SYSTEM_MISMATCHES.md`)

- M-01 boolean comparisons
- M-03 secretary dashboard review links
- M-04 Mass-intentions pagination count
- M-05 user-delete guard
- Minor label and seed items

These are PHP changes. They were identified but not applied, per the instruction not to change system functionality without review.

## 4. Migration-file hygiene (repository, not database)

The `database/` folder can no longer rebuild the database by running files top to bottom:

| Issue | Files | Recommendation |
|---|---|---|
| MySQL-era files mixed with Postgres files | `schema.sql`, `seed.sql`, `parishhub_full.sql`, `migration_scheduling.sql`, `migration_service_fee_rules_mysql.sql` | Move to `database/legacy_mysql/` with a README. `parishhub_full.sql` also contains demo default credentials. Do not ship it to any public host. |
| Enum value added and used in one batch (PostgreSQL rejects it: "unsafe use of new value") | `migration_donations.sql`, `migration_anointing.sql` (also `migration_paymongo.sql`'s enum add) | Split each enum `ALTER TYPE … ADD VALUE` into its own preceding file, or document "run the first statement alone, then the rest". |
| Out-of-order dependency | `migration_service_process_corrections.sql` (committed 10-03) uses category `'Wake'`, first added by `migration_anointing.sql` (10-05) | Production must have had `'Wake'` added by hand. Re-number so the correct order is explicit. |
| A schema migration named like a data script | `cleanup_audit.sql` drops 3 tables and 7 columns | Rename to `migration_cleanup_audit.sql` and record whether production applied it (preflight tells you). |
| Destructive scripts beside migrations | `fresh_start_wipe.sql`, `cleanup_mode2_fresh_reset.sql` (TRUNCATE … CASCADE), `cleanup_mode1_targeted.sql`, `parishhub_old_data_cleanup.sql` | Move to `database/maintenance/DANGEROUS/` with a warning header, so nobody runs them while applying migrations. |
| No record of what ran | — | Add a `schema_migrations(filename, applied_at)` table in a future change, so this question never needs forensic reconstruction again. |

The verified, working application order (used for every test in this audit) is listed in `DATABASE_VALIDATION_REPORT.md` §2.

## 5. Order of operations

1. **Back up** (§6).
2. Run **`DATABASE_PREFLIGHT_CHECK.sql`**. Save the output.
   - If any ACTIVE table or a "feature-detected" column is **MISSING**, apply the corresponding original migration file first, in the verified order.
   - If `generated_*_forms` / `*_booking_drafts` are **STILL PRESENT**, run `migration_merge_forms.sql` / `migration_merge_drafts.sql`. They copy the data, then drop the old tables. Re-run the preflight `integrity` group afterwards.
   - Read the `types` group: it tells you which half of the M-01 queries is currently failing.
3. Run **`DATABASE_MIGRATION_SAFE.sql`**. Only Part A executes; B, C and D are commented out.
4. Re-run the preflight. Every `row_counts` value must be identical to step 2.
5. Deploy the PHP fixes (M-01 to M-05). Smoke-test: Services page, booking a blocked date, parishioner detail page, secretary dashboard review cards, Mass-intention pagination.
6. Optionally apply **Part D** (RLS), then **Part B** (after the M-05 guard is live), then **Part C**. Run the preflight again after each.

## 6. Backup instructions

Take **both** of these before step 3.

1. **Logical dump** (works on every Supabase plan). Use the **session-mode** connection on port 5432 (or the direct `db.<project-ref>.supabase.co:5432` host). The app's transaction-mode pooler on port 6543 does not support `pg_dump`. Take the values from your own `DB_*` environment; never paste the password into a committed file:
   ```bash
   pg_dump "postgresql://postgres.<project-ref>:<DB_PASS>@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres" \
     --format=custom --no-owner --no-privileges --schema=public \
     --file=parishhub_pre_cleanup_2026-10-25.dump
   ```
   Check the dump can be read: `pg_restore --list parishhub_pre_cleanup_2026-10-25.dump | head`.
2. **Supabase dashboard backup:** Database → Backups. Note the latest daily backup time. If your plan has Point-in-Time Recovery, note the timestamp just before you start.
3. **Storage objects** (uploaded documents and generated PDFs, bucket `parish-documents`) are **not** in the database dump. This migration never touches them. If you also plan any data cleanup, export the bucket separately first.
4. Store the dump outside the repository. It contains personal data and password hashes.

## 7. Rollback

- **Part A:** run the Part A section of `DATABASE_ROLLBACK.sql`. It removes the indexes and trigger, recreates the two duplicate indexes, and removes the re-added columns only if they were added by this script and are still empty.
- **Parts B, C and D:** uncomment the matching rollback block.
- **Last resort:** restore the §6 dump. Restore into a new Supabase project or branch first, verify it, then point `DB_HOST` at it, rather than overwriting production in place.

Every rollback path was executed on the test databases and returned the catalog to its exact prior state with all data checksums unchanged (`DATABASE_VALIDATION_REPORT.md`).
