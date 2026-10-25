# ParishHub — Database Audit Report

**Project:** Parish-Hub: A Web-Based Services Management System · **Stack:** PHP 8.2, PDO pgsql, PostgreSQL (Supabase), Render
**Date:** 2026-10-25 · **Mode:** read-only. The production database was not connected to, no project source file was modified, and no migration was executed outside disposable test databases.

## 1. Bottom line

1. **The database already matches the implemented system closely.** Every table the PHP queries exists after the repository's migrations are applied. All 731 SQL statements in the code (plus 79 reconstructed dynamic-query branches) resolve against the schema. The only exceptions are the specific mismatches listed below.
2. **The consolidations are finished.** `generated_forms` replaces `generated_wedding_forms` / `generated_baptism_forms` / `generated_funeral_forms`, and `appointment_drafts` replaces `wedding_booking_drafts` / `baptism_booking_drafts`. No current code references the old tables. The merge migrations copy the data before dropping them. No backward-compatibility layer is needed.
3. **No table needs to be deleted** for the database to match the system. Four tables have no code dependency (`chat_messages`, and `permissions` / `role_permissions` / `reports` if they still exist). They can be **archived** (moved, reversibly) rather than dropped.
4. **The serious findings are about data safety and correctness, not clutter:**

| Severity | Finding | Where |
|---|---|---|
| **High** | Deleting a user in Admin → Users cascades away that person's payments, receipts and certificates. Deleting the guest or walk-in placeholder account would erase **every** guest booking or walk-in donation. Demonstrated on test data. | `admin/users.php:171`; FK `ON DELETE CASCADE` chain |
| **High** | The same boolean columns are compared to `1` in 7 queries and `TRUE` in 18; one group must fail on PostgreSQL, including the main Services page or the homepage | M-01 |
| **High** (state-dependent) | `secretary/parishioner-detail.php` selects two columns that `cleanup_audit.sql` drops | M-02 |
| **High** (state-dependent) | Certificate generation requires the newest migration (`migration_certificate_allow_form_type.sql`) | M-06 |
| **High** (security) | No table has Row Level Security; Supabase's public REST API can read everything with the publishable anon key, including `users` password hashes | Part D |
| Medium | Runtime `ALTER TABLE` in `outsidePriestAvailable()` takes an ACCESS EXCLUSIVE lock on `appointments` during ordinary page loads | M-07 |
| Medium | Secretary dashboard review cards open empty lists; Mass-intention pagination count wrong | M-03, M-04 |
| Medium | Seven features silently switch off if their migration is missing | M-08 |
| Medium | The migration files cannot be replayed as-is (enum usage in the same batch, out-of-order dependency, MySQL files mixed in, a schema change disguised as a "cleanup" script) | Cleanup plan §4 |
| Low | Duplicate indexes, ~40 unindexed FKs, unused enum values, label drift, timezone-less timestamps, 1 FK type mismatch | Below |

## 2. Method

| Phase | What was done |
|---|---|
| 1 — Scan | Inventoried every SQL statement in the PHP (excluding `vendor/`) with PHP's tokenizer: **731 statements, 32 distinct tables referenced**. Read every file in `database/` (base schemas, 50 migrations, maintenance scripts) and dated each with `git log`. Checked config, runtime DDL, `information_schema` feature probes, webhooks (`paymongo-webhook.php`), session handling and the storage driver. There is no scheduled job runner (no cron or `render.yaml`). The past-date appointment check runs opportunistically on page load. |
| 2 — Audit | Rebuilt the effective schema empirically: the base schema plus all migrations were replayed into a throwaway PostgreSQL 16 (PGlite), in both possible production states, and the catalog was dumped. Every PHP query was then validated with `EXPLAIN`, which resolves names without executing. Status literals were compared with CHECK constraints and enums. Unused columns, missing FKs and indexes, and duplicate indexes were computed from the catalog. The results were compared with Chapter 2 of `CHAPTER_2_REVISED.docx`. |
| 3 — Plan | Every table classified in `DATABASE_CLEANUP_PLAN.md`. |
| 4 — Scripts | `DATABASE_PREFLIGHT_CHECK.sql` (read-only), `DATABASE_MIGRATION_SAFE.sql` (Part A additive; B/C/D opt-in), `DATABASE_ROLLBACK.sql`. |
| 5 — Sync | Code ↔ schema mismatches in `DATABASE_SYSTEM_MISMATCHES.md`, with fixes proposed but not applied. |
| 6 — Docs | `DATABASE_SCHEMA_FINAL.sql` and `DATABASE_ERD.md`, generated from the catalog: Mermaid ERD plus a Chapter 2 data dictionary. |
| 7 — Validate | 46 automated checks in `DATABASE_VALIDATION_REPORT.md`. |

**Production was not inspected directly.** Two facts can only be read from the live database:
1. whether `cleanup_audit.sql` was applied;
2. how production avoids the M-01 conflict.

Both are answered by running `DATABASE_PREFLIGHT_CHECK.sql`. It is SELECT-only, returns one result set, and should be the first thing run.

## 3. Database inventory

**Source of truth.** No single file in `database/` describes the live schema:
- `schema.sql`, `seed.sql`, `parishhub_full.sql`, `migration_scheduling.sql` and `migration_service_fee_rules_mysql.sql` are **MySQL** artifacts from before the Supabase move.
- `supabase_schema.sql` is the Postgres base, but 20+ later migrations add tables and columns on top of it.

The effective schema is base + migrations. Recommended final state: 34 tables, 332 columns, 52 foreign keys, 80 indexes (47 backing PK/UNIQUE constraints + 33 others), 10 triggers.

| Group | Tables |
|---|---|
| Accounts & security | `roles`, `users`, `parishioners`, `priests`, `auth_throttle`, `password_reset_otps`, `app_sessions` (conditional), `activity_logs` |
| Services & scheduling | `services`, `service_schedules`, `service_fee_rules`, `priest_unavailability`, `calendar`, `events`, `locations`, `requirements` (unused) |
| Bookings, forms, documents | `appointment_status`, `appointments`, `appointment_drafts`, `generated_forms`, `uploaded_documents`, `issued_certificates`, `mass_intentions` |
| Payments & donations | `payment_methods`, `payments`, `transactions`, `official_receipts`, `donations`, `projects` |
| Communication & content | `notifications`, `email_logs`, `announcements`, `settings`, `chat_messages` (unused) |

**Business logic in the database** (these triggers must be preserved):
- `trg_set_receipt_number` — official receipt numbers, format `OR-YYYY-NNNNNN`
- `trg_appointment_notify` — in-app notification on status change
- `trg_appointment_status_log` — activity log on status change
- `set_timestamp_*` — keeps `updated_at` current

**Runtime schema changes made by PHP:**
- `outsidePriestAvailable()` adds or alters `outside_priest_name`.
- `ensureCertificateService()` adds the `'Certificate'` enum value and seeds the Certificate Request service.
- The DB session handler creates `app_sessions`.

## 4. Specific questions from the brief

### 4.1 Generated forms
| Table | Status | Evidence |
|---|---|---|
| `generated_forms` | **ACTIVE — the correct table** | 57 SQL references in 16 files (Wedding, Baptism and Funeral form writers; document viewer; secretary review). CHECKs: `service_category` ∈ Wedding/Baptism/Funeral; `status` ∈ draft/generated/pending_review/approved/rejected; owned by exactly one of `appointment_id` or `draft_id`. |
| `generated_wedding_forms`, `generated_baptism_forms`, `generated_funeral_forms` | **DEPRECATED — replaced** | 0 code references. `migration_merge_forms.sql` copies all rows into `generated_forms` (preserving document links), then drops them. If the preflight shows them STILL PRESENT, run that migration; do not drop them by hand. |

### 4.2 Appointment drafts
| Table | Status | Evidence |
|---|---|---|
| `appointment_drafts` | **ACTIVE — the correct table** | `parishioner/book.php` (insert/update), `includes/wedding-draft.php` and `baptism-draft.php` (lock + finalize), `document.php`. `service_type` ∈ Wedding/Baptism. Funeral drafts are session-based and go straight to `appointments`. |
| `wedding_booking_drafts`, `baptism_booking_drafts` | **DEPRECATED — replaced** | 0 code references. `migration_merge_drafts.sql` migrates rows (re-mapping baptism draft ids and their document/form references), then drops them. |

### 4.3 The five newer tables
| Table | Actively used? | Evidence |
|---|---|---|
| `auth_throttle` | **Yes** | `includes/auth.php` (record failure, seconds left, clear) is called by **both** login portals and the guest status lookup. Policy: 5 failures per email, 20 per IP, 15-minute lock. |
| `password_reset_otps` | **Yes** | `auth/forgot-password.php`: request, verify and reset steps. One pending code per email, bcrypt-hashed, 10-minute expiry, 5 attempts. |
| `email_logs` | **Yes** (feature-detected) | `includes/email-log.php` → `sendLoggedEmail()`, used by requirements-review, appointment-approval, Pre-Cana and funeral-schedule notifications. De-duplicates by `dedupe_key`; keeps the body for retry. |
| `app_sessions` | **Conditionally** | Only when `SESSION_DRIVER=database`. The default is `files`, and commit `9f839b3` reverted Render to files. Harmless when idle, required if DB sessions are re-enabled. Keep. |
| `issued_certificates` | **Yes** | `includes/certificate-workflow.php` (automatic issuance on completion; paid re-issuance; generate → ready_for_pickup → claimed), `document.php`, appointment lists. |

## 5. Detailed findings

### 5.1 Tables
- **Unused but present:**
  - `chat_messages`: the chatbot stores nothing.
  - `requirements`: requirement lists live in `services.requirements` as comma-separated text. `uploaded_documents.requirement_id` still points at this table, but is only ever copied from a prior document on re-upload, never set initially.
  - `permissions`, `role_permissions`, `reports`: present only if `cleanup_audit.sql` never ran.
- **Missing:** none required by the code. Seven features probe for their columns and silently disable themselves when absent (M-08).

### 5.2 Columns
- **Never referenced by any PHP or JS:**
  - `payments.proof_of_payment`
  - `parishioners.emergency_contact_name/number`, `users.remember_token`, `official_receipts.pdf_path` (all already dropped in state A)
  - all columns of the four unused tables
- **Still referenced but dropped in state A:** `parishioners.baptism_date`, `confirmation_date` (M-02).
- **Runtime-created:** `outside_priest_name` (M-07).

### 5.3 Types and constraints
- **Boolean comparison conflict** on `services.is_active` / `calendar.is_blocked` (M-01).
- **FK type mismatch:** `issued_certificates.claimed_by_user_id` is `bigint`, but `users.user_id` is `integer`.
- **Timezone-less timestamps:** `appointments.pss_verified_at`, `users.email_verified_at` and `users.token_expires_at` are `timestamp` without time zone, while every other timestamp is `timestamptz` (M-12).
- **Missing CHECK:** `generated_forms.form_type` has none, while `uploaded_documents.generated_form_type` does.
- **Out-of-sync enum:** `notifications.type` allows `email`/`sms`, but only `website` is ever written. There is no SMS integration.

### 5.4 Relationships and keys
- **FK coverage is complete.** Every `*_id` / `*_by` column that should reference another table does. The only exceptions are external ids: `transactions.gateway_transaction_id` and `chat_messages.session_id`.
- **Delete rules are the problem.**
  - `users → parishioners → appointments` cascades into `payments`, `official_receipts`, `transactions`, `donations`, `mass_intentions`, `uploaded_documents`, `generated_forms` and `issued_certificates`.
  - All guest bookings hang off one placeholder user, and all walk-in donations off another.
  - Fixes: Part A4 (protect the placeholders), the M-05 PHP guard, and Part B (RESTRICT).

### 5.5 Indexes
- **Duplicates:**
  - `idx_appt_guest_reference` duplicates `appointments_guest_reference_key`.
  - `idx_prot_email` duplicates `prot_one_per_email`.
- **Unindexed FK columns:** about 40. The 12 on hot paths are added by Part A3.

### 5.6 Queries
- **731 statements extracted; 0 reference a non-existent table or column** in the recommended final state.
- Real defects:
  - M-01 (7 queries)
  - M-02 (1 query, state A)
  - M-03, M-04 (logic bugs EXPLAIN cannot see)
  - M-07 (locking)
- **Status and value literals:** all allowed by the database. `official_certificate` needs the newest migration (M-06).

### 5.7 Migrations
- **Not replayable in order:**
  - Enum values are added and used in the same batch (`migration_donations.sql`, `migration_anointing.sql`).
  - `migration_service_process_corrections.sql` depends on a value from a later file.
- **Mislabeled:** `cleanup_audit.sql` silently changes the schema: it drops 3 tables and 7 columns.
- **Dangerous neighbors:** destructive maintenance scripts (`fresh_start_wipe.sql`, `cleanup_mode2_fresh_reset.sql`) sit beside migrations.
- **Demo credentials:** `parishhub_full.sql` (MySQL) contains default credentials.
- **No record of applied migrations.**
- Recommendations: cleanup plan §4.

### 5.8 Security (database layer)
- **No Row Level Security on any public table.**
  - The app does not use the Supabase REST API or supabase-js (verified). It connects via PDO as the table owner, so enabling deny-all RLS (Part D) blocks the public API without affecting the app (verified with a non-superuser owner).
- **Fee rules are already locked down:** `service_fee_rules` privileges are revoked from `anon` / `authenticated`.
- **Default connection values in source:** `config/config.php` contains the Supabase pooler host and user as defaults. The password correctly comes only from the environment. Consider moving host and user to environment-only too.

## 6. Comparison with Chapter 2 (`CHAPTER_2_REVISED.docx`, Database Design)

The revised manuscript already reflects the 11 new `appointments` columns, the new `users` / `announcements` / `notifications` columns, the 11-value service category, and the removal of `uploaded_documents.baptism_draft_id`. Against the verified schema, it still differs as follows:

| Manuscript | Verified schema | Action for Chapter 2 |
|---|---|---|
| Tables 2–3 `Permissions`, `Role_permissions`; Table 33 `Reports` | Unused by code; dropped if `cleanup_audit.sql` ran | Remove, or mark "legacy, unused" |
| Tables 18–19 `Generated_wedding_forms`, `Generated_baptism_forms` | One table, `generated_forms` (also covers Funeral) | Replace with `generated_forms` |
| Tables 20–21 `Wedding_booking_drafts`, `Baptism_booking_drafts` | One table, `appointment_drafts` | Replace with `appointment_drafts` |
| No entries | `auth_throttle`, `password_reset_otps`, `email_logs`, `app_sessions`, `issued_certificates` | Add |
| Table 4 `Users`: `remember_token` | Dropped in state A; never used | Remove |
| Table 5 `Parishioners`: emergency contact fields | Dropped in state A; never used | Remove (keep `baptism_date` / `confirmation_date` only if Part A1 is used) |
| Table 24 `Events`: `calendar_id` | Dropped in state A; never used | Remove |
| Table 29 `Official_receipts`: `pdf_path` | Dropped in state A; PDFs generated on demand | Remove; note the receipt number comes from a database trigger |
| Table 9 `Requirements` | Not read by code (lists are in `services.requirements`) | Mark legacy |
| Table 31 `Chat_messages` | Not used | Mark unused, or remove if archived |
| Table 30 `Notifications.type` (email/sms/website) | Only `website` used; email via `email_logs`; no SMS | Correct the description |
| Table 26 `Payment_methods` | Seeded names: Cash, GCash, PayMaya, Bank Transfer, Credit/Debit Card, PayPal, PayMongo (Online) | Use the seeded names |
| Table 1 `Roles` | Stored role is "Treasurer" (displayed "Cashier") | Already noted in the revision |
| Figure 39 ERD | Outdated | Replace with `DATABASE_ERD.md` §1 (Mermaid → PNG/SVG) |

`DATABASE_ERD.md` §3 contains a complete, verified data dictionary for all 34 tables, in manuscript order and wording where columns already existed. It can be pasted into Chapter 2 directly.

## 7. Deliverables

| File | Purpose |
|---|---|
| `DATABASE_AUDIT_REPORT.md` | This report |
| `DATABASE_CLEANUP_PLAN.md` | Table classification, every proposed change with rationale, order of operations, backup and rollback |
| `DATABASE_PREFLIGHT_CHECK.sql` | Read-only diagnosis of the live database; run first |
| `DATABASE_MIGRATION_SAFE.sql` | Part A (default, additive, idempotent); Parts B/C/D opt-in |
| `DATABASE_ROLLBACK.sql` | Reverses each part exactly |
| `DATABASE_SCHEMA_FINAL.sql` | Verified reference DDL, generated from the catalog; do not run on production |
| `DATABASE_ERD.md` | Mermaid ERD (overview + per-domain) and Chapter 2 data dictionary |
| `DATABASE_SYSTEM_MISMATCHES.md` | Code ↔ schema mismatches M-01 to M-12, with proposed fixes |
| `DATABASE_VALIDATION_REPORT.md` | 46 automated checks across both production scenarios |
| `validation_harness/` | Scripts to reproduce every check |
