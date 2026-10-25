# ParishHub — Code ↔ Database Mismatches

**Date:** 2026-10-25 · All findings are against the replayed schema (see `DATABASE_VALIDATION_REPORT.md`). **No PHP file has been modified.** Each item gives the exact location, the evidence, and a proposed fix for review.

## How the code was checked

1. **Static extraction.** PHP's own tokenizer found every SQL string in the codebase: 731 statements across all modules, with string concatenation chains merged.
2. **EXPLAIN validation.** Each statement was run as `EXPLAIN …` against the replayed schema, in both possible production states. `EXPLAIN` makes PostgreSQL resolve every table, column, function and operator **without executing anything**.
   - 678 statements passed outright.
   - The rest were UI strings that happen to start with "Select"/"Update", or SQL assembled in PHP variables. The second group (15 files, 79 branches) was reconstructed by hand and every branch passes.
3. **Value audit.** Every status/enum literal the PHP writes or compares was checked against the database's CHECK constraints, enums and lookup rows.
4. **Behavioral checks.** Things EXPLAIN cannot see were exercised on the test database: delete cascades, triggers, lock modes, NOT NULL paths.

**Result:**
- No query references a table or column that does not exist, except M-02 in one production state.
- No query writes a value the database rejects, provided the newest migration is applied (M-06).
- What remains is listed below.

| ID | Severity | Area | Summary |
|---|---|---|---|
| M-01 | **High** | Services, booking, calendars | Same boolean columns compared to `1` in 7 queries and to `TRUE` in 18; one group must fail on PostgreSQL |
| M-02 | **High** (if `cleanup_audit.sql` ran) | Parishioner records | Page selects two columns that `cleanup_audit.sql` dropped |
| M-03 | Medium | Secretary dashboard | "Requirements Review" cards always open an empty list |
| M-04 | Medium | Mass intentions list | Pagination total is wrong when the outside-priest column exists (the normal case) |
| M-05 | **High** | User management | Deleting a user cascades away payments, receipts and certificates; guest placeholder unprotected |
| M-06 | **High** (if migration missing) | Certificates | Certificate generation requires the newest migration |
| M-07 | Medium | Performance / locking | Runtime DDL takes an ACCESS EXCLUSIVE lock on `appointments` on many page loads |
| M-08 | Medium | Deployment | Seven features switch themselves off silently when their migration is missing |
| M-09 | Low | Data hygiene | Status trigger writes guest notifications to a placeholder nobody can read |
| M-10 | Low | Labels / seed data | "PayMaya" vs "Maya"; parish contact settings not seeded |
| M-11 | Low | Dead values / code | Unused enum values, dead `'pending_review'` literal, unused `$formTitles` |
| M-12 | Low | Types | One FK type mismatch; three timezone-less timestamps |

---

## M-01 — Boolean columns compared to integer `1` · High

`services.is_active` and `calendar.is_blocked` are declared `BOOLEAN` in every Postgres schema file.

**These seven queries compare them to the integer `1`:**

| File:line | Query |
|---|---|
| `parishioner/services.php:37` | `SELECT * FROM services WHERE is_active = 1 AND category != 'Donation' …` (main Services/booking page, unguarded) |
| `parishioner/services.php:65` | `SELECT calendar_date, notes FROM calendar WHERE is_blocked = 1` |
| `includes/scheduling.php:149` | `SELECT 1 FROM calendar WHERE calendar_date = ? AND is_blocked = 1` (Mass-intention slot availability) |
| `includes/scheduling.php:554` | same, inside `lockedBookingConflict()` (final blocked-date check at submission) |
| `parishioner/book.php:805` | `SELECT * FROM calendar WHERE calendar_date = ? AND is_blocked = 1` |
| `parishioner/calendar.php:10` | `SELECT * FROM calendar WHERE is_blocked = 1 …` |
| `priest/calendar.php:22` | `SELECT * FROM calendar WHERE is_blocked = 1 …` |

**Eighteen other queries compare the same columns to `TRUE`.** Examples: `index.php:15,18`, `includes/public-chatbot.php:75`, `includes/scheduling.php:234,304,333,394,734`, `parishioner/donate.php:43`, `includes/precana.php:108`.

**Evidence.** Tested on PostgreSQL 16 (see the validation report):
- `boolean = 1` → `ERROR: operator does not exist: boolean = integer`
- `smallint = TRUE` → `ERROR: operator does not exist: smallint = boolean`

**No column type satisfies both forms.** Both forms were introduced in the same commit (2026-09-08) and have coexisted on core pages since. So production either has a compatibility operator or cast that was created outside the repository, or one of these groups of pages is currently erroring. The preflight `types` group tells you which.

**Fix.** Run `DATABASE_PREFLIGHT_CHECK.sql` first.
- **If the columns are `boolean`** (as every schema file says): change the seven `= 1` to `= TRUE`.
- **If they are `integer`/`smallint`:** change the eighteen `= TRUE` to `= 1` instead.
- **Interim, type-agnostic option:** a quoted literal, `= '1'`, works on boolean, smallint and integer alike (verified).

This is a PHP-only change. No schema change is proposed, because the schema files and most of the code already agree on `BOOLEAN`.

## M-02 — Dropped columns still selected · High if `cleanup_audit.sql` was applied

`secretary/parishioner-detail.php:31`:
```php
"SELECT u.*, p.parishioner_id, p.baptism_date, p.confirmation_date, p.marital_status
```
`database/cleanup_audit.sql:61-62` drops `parishioners.baptism_date` and `confirmation_date`.

- **State A** (cleanup applied): EXPLAIN fails with `column p.baptism_date does not exist`, so the Secretary's parishioner detail page errors.
- **State B:** the query works.

**Fix — either one:**
- **Database:** `DATABASE_MIGRATION_SAFE.sql` A1 re-adds both as empty nullable `DATE` columns, only if missing. Validated: the error disappears and no data changes.
- **Code:** remove `p.baptism_date, p.confirmation_date` from the SELECT. The cleanup script's own notes say these values are never rendered. The other `baptism_date` references in the code are JSON form-field keys, not columns.

## M-03 — Secretary dashboard review cards open an empty list · Medium

`secretary/dashboard.php:56-58` links the "Pending Review", "Awaiting Revision" and "Ready for Re-Review" cards to `appointments.php?status=review:pending_review` (and so on).

`buildAppointmentsQuery()` in `secretary/appointments.php` has no branch for the `review:` prefix. The value falls through to `AND st.status_name = ?` (lines 39-41), which never matches. The cards show a non-zero count but open an empty list. The `REQUIREMENTS_REVIEW_FILTERS` constant (line 8) is declared but unused.

**Fix** (PHP only). Add a branch before the generic status branch:
```php
if (str_starts_with($statusFilter, 'review:')) {
    $review = substr($statusFilter, 7);
    if ($review === 'pending_review') {
        $where .= " AND a.status_id = 1 AND a.requirements_review_status IS NULL AND s.category NOT IN ('Mass Intention','Donation')";
    } elseif (in_array($review, ['awaiting_revision', 'ready_for_rereview'], true)) {
        $where .= ' AND a.status_id = 1 AND a.requirements_review_status = ?';
        $params[] = $review;
    }
}
```
This matches how `secretary/dashboard.php:11-13` derives the counts.

**Related dead code.** `secretary/appointments.php:30` lists `'pending_review'` in an IN-list against `requirements_review_status`. The CHECK constraint only allows `awaiting_revision` / `ready_for_rereview` (NULL means pending), so that literal never matches. Remove it.

## M-04 — Mass intentions pagination count · Medium

`secretary/mass-intentions.php:81` builds the count query with `str_replace()` on a hard-coded select list. When `outside_priest_name` exists, the select list contains `a.guest_name, a.outside_priest_name,` and the needle no longer matches. That column is auto-created at runtime, so this is the normal production case.

The "count" query then becomes the full row query, and `fetchColumn()` returns the first row's `appointment_id` as the total. Page numbers are wrong. This was confirmed against the real file text.

**Fix.** Derive the count from the FROM clause, as `secretary/appointments.php` already does:
```php
$countSql = preg_replace('/^SELECT\s+.*?\s+FROM\s+/is', 'SELECT COUNT(*) FROM ', $sql, 1);
```

## M-05 — User delete cascades into financial and certificate records · High

`admin/users.php:153-174` (action `delete`) checks only "not yourself", "not the last Admin" and the admin's password. Then it runs:
```php
db()->prepare('DELETE FROM users WHERE user_id = ?')->execute([$_POST['user_id']]);
```

The FK chain is all `ON DELETE CASCADE`:

`users → parishioners → appointments → payments → official_receipts, transactions` and `appointments → donations, mass_intentions, uploaded_documents, generated_forms, issued_certificates, notifications`

**Demonstrated on the test database** (inside a rolled-back transaction):
- Deleting a parishioner user removed that person's appointment, payment, receipt, certificate and document rows.
- Deleting the shared guest placeholder (`guest@parishhub.internal`) removed **every guest booking chain**. All guest appointments are stored under that one placeholder (`includes/auth.php` `guestParishionerId()`), and nothing in `admin/` excludes it.

**Fix.**
1. **Database, default:** `DATABASE_MIGRATION_SAFE.sql` A4 adds a trigger that blocks deleting or renaming only the two system placeholders. The walk-in donor account (`walkin-donor@parishhub.internal`, `migration_walkin_donor.sql`) anchors all walk-in donations the same way. Validated.
2. **PHP:** before the DELETE, refuse when the user has history, and offer deactivation instead:
   ```php
   $chk = db()->prepare(
       "SELECT u.email LIKE '%@parishhub.internal' AS is_placeholder,
               EXISTS (SELECT 1 FROM parishioners p JOIN appointments a ON a.parishioner_id = p.parishioner_id
                       WHERE p.user_id = u.user_id) AS has_history
        FROM users u WHERE u.user_id = ?");
   $chk->execute([$targetId]);
   $c = $chk->fetch();
   if ($c && ($c['is_placeholder'] || $c['has_history'])) {
       flash('error', 'This account has appointment or payment history and cannot be deleted. Set it to Inactive instead.');
       redirect(url('admin/users.php'));
   }
   ```
   Also hide the Delete button for the placeholder accounts (`guest@parishhub.internal`, the walk-in donor account).
3. **Database, opt-in Part B:** change the six financial/certificate FKs to `ON DELETE RESTRICT`. Apply only after step 2 is live.

## M-06 — Certificate generation needs the newest migration · High if missing

`includes/certificate-workflow.php:154` writes `uploaded_documents.generated_form_type = 'official_certificate'`. Only `migration_certificate_allow_form_type.sql` (the last file, 2026-10-24 22:21) adds that value to the CHECK constraint. Without it, every certificate generation fails with a CHECK violation; that migration's own header quotes the resulting user error.

**Fix.** The preflight row `constraints: … allows official_certificate` must say **present**. If it says MISSING, apply that migration.

## M-07 — Runtime DDL locks `appointments` on ordinary page loads · Medium

`includes/functions.php:944-948` (`outsidePriestAvailable()`). When the column already exists, it runs on every call:
```php
db()->exec("ALTER TABLE appointments ALTER COLUMN outside_priest_name TYPE TEXT");
db()->exec("ALTER TABLE appointment_drafts ALTER COLUMN outside_priest_name TYPE TEXT");
```

**Even when the type is already TEXT, PostgreSQL takes an `AccessExclusiveLock`** (verified on the test database). That is the strongest lock: it waits for every in-flight query on the table and blocks all others until it finishes.

The function is called from 12 files, including the booking flow, the secretary's appointment list and detail pages, reports and the Mass-intentions list. Under concurrent use this serializes access to the busiest table and can cause stalls or timeouts.

`ensureCertificateService()` (`functions.php:995`) similarly runs `ALTER TYPE service_category ADD VALUE IF NOT EXISTS 'Certificate'` on the pages that call it.

**Fix.** The migrations already create both columns as TEXT and add the enum value.
- Delete the `else { … ALTER COLUMN … TYPE TEXT … }` block.
- Keep only the `information_schema` check, or hard-code `true` once the preflight confirms the column exists.
- Drop the `ALTER TYPE` in `ensureCertificateService()` for the same reason.

## M-08 — Features silently disable themselves when a migration is missing · Medium (operational)

Several modules probe `information_schema` and quietly turn the feature off when a column or table is absent:

| Feature | Probe location | Requires |
|---|---|---|
| Payment deadline | `includes/appointment-approval.php:29` | `migration_payment_deadline.sql` |
| Requirements revision / re-review | `includes/requirements-review.php:35` | `migration_requirements_review.sql` |
| Preferred burial date/time | `includes/funeral-schedule.php:22` | `migration_funeral_preferred_burial.sql` |
| Pre-Cana scheduling | `includes/precana.php:40` | `migration_precana.sql` |
| Outside priest | `includes/functions.php:935` | `migration_outside_priest.sql` (or runtime auto-add) |
| Email delivery log | `includes/email-log.php:32` | `migration_notifications_v2.sql` |
| Notification → appointment link | `includes/secretary-notifications.php:33` | `migration_notifications_v2.sql` |

This keeps the site up on a lagging database, but a missing migration then looks like a business-rule bug rather than an error. By contrast, `parishioner/book.php:825` inserts `requester_name` / `patient_name` **without** a probe, so it hard-requires `migration_anointing.sql`.

**Fix.** Use the preflight `columns` group to confirm all of these are present. Once confirmed, the probes can stay (harmless) or be removed in a later refactor.

## M-09 — Status trigger notifies the guest placeholder · Low

`fn_notify_appointment_status()` (from `migration_notification_trigger.sql`) inserts a website notification for the user behind `appointments.parishioner_id` on status changes 2, 5, 6 and 7. For guest bookings that user is the login-disabled placeholder, so unreadable notification rows accumulate. (Duplication with PHP is handled correctly: PHP updates the trigger's row rather than adding a second one.)

**Fix (optional).** In a future `CREATE OR REPLACE` of the function, add `IF NEW.guest_reference IS NOT NULL THEN RETURN NEW; END IF;` near the top. Guests are emailed through `email_logs` instead. Not included in the safe migration, to avoid changing trigger behavior without review.

## M-10 — Labels and seed data · Low

- `payment_methods` row 3 is stored as **"PayMaya"**, but PHP labels it **"Maya"**. Forms say Maya, while receipts and reports printing `method_name` say PayMaya. Align with one data update, or relabel in PHP. Never renumber the ids: they are hard-coded (7 = PayMongo).
- Setting keys `parish_name`, `parish_address`, `contact_number`, `contact_email`, `office_hours`, `mass_schedule` are read by receipts and contact blocks but not seeded. They print blank until an admin saves Settings once. No error occurs.

## M-11 — Dead values and code · Low

| Item | Detail |
|---|---|
| `payments.payment_status = 'refunded'` | No refund workflow exists. |
| `notifications.type` values `email`, `sms` | Never written; every insert uses `website`. Email goes through `email_logs`; there is no SMS integration. The thesis must not claim SMS notifications. |
| `notifications.category` values `announcement`, `system` | Never written. `parishioner/donate.php:162` files the donation thank-you under `appointment`. |
| `appointment_drafts.status` values `expired`, `abandoned` | Never written; expiry is enforced by `expires_at`. |
| `announcements.status` values `draft`, `archived` | Never written. Separately, the edit form (`secretary/announcements.php:337`) re-labels month/year/specific-date announcements as `custom_range`. |
| `$formTitles` | Array in `secretary/appointment-detail.php:1518` with stale keys; never read. |
| `generated_forms.form_type` | Has no CHECK constraint, while `uploaded_documents.generated_form_type` does. |

## M-12 — Type consistency · Low

- `issued_certificates.claimed_by_user_id` is **bigint**, but `users.user_id` is **integer**. PostgreSQL allows this; it is just inconsistent.
- `appointments.pss_verified_at`, `users.email_verified_at` and `users.token_expires_at` are `timestamp` **without** time zone, while every other timestamp is `timestamptz`.
  - `token_expires_at` is written and compared in PHP local time, so it is internally consistent.
  - `pss_verified_at` / `email_verified_at` are set with `NOW()` and so store UTC wall-clock time. Anything that displays them shows them 8 hours behind Manila time.
- **Fix later**, in a reviewed change: `ALTER COLUMN … TYPE timestamptz USING … AT TIME ZONE 'UTC'`, which needs per-column care. This is not in the safe migration, because it reinterprets stored values.

---

## Queries confirmed correct for the consolidated tables

Phase 5 asked for these specifically. All pass in both production states:

- **`generated_forms`:** all reads/writes in `wedding-forms.php`, `baptism-forms.php`, `funeral-forms.php`, `baptism-draft-form.php`, `wedding-form.php`, `funeral-form.php`, `document.php`, and `secretary/appointment-detail.php` (review and reject).
- **`appointment_drafts`:** inserts and updates in `parishioner/book.php:604/614/723/734`; locks in `includes/wedding-draft.php:29` and `includes/baptism-draft.php:8`; finalization in `wedding-draft.php:105` and `baptism-draft.php:122`. Column and value counts match in every branch.
- **No code references** `generated_wedding_forms`, `generated_baptism_forms`, `generated_funeral_forms`, `wedding_booking_drafts` or `baptism_booking_drafts`. No backward-compatibility shim is needed.
- **`issued_certificates`:** `includes/certificate-workflow.php` (issue, generate, ready for pickup, claim), `document.php`, `secretary/appointments.php`, `parishioner/appointments.php`.
- **Payments and receipts:** `payments → official_receipts` (number from trigger `trg_set_receipt_number`, format verified `OR-YYYY-NNNNNN`), `transactions`, PayMongo webhook / return handlers.
- **`email_logs`, `auth_throttle`, `password_reset_otps`:** all queries valid, including the throttle `INSERT … ON CONFLICT … DO UPDATE`.
