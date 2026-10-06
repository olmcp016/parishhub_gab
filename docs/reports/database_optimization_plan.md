# ParishHub — Database Architecture & Optimization Plan

> **Status:** Proposal only — no changes applied yet.
> **Generated:** 2026-10-05
> **Based on:** Full codebase + schema audit (supabase_schema.sql + all migrations)

---

## Executive Summary

The three highest-value changes are:
1. Consolidate `wedding_booking_drafts` and `baptism_booking_drafts` into a single `appointment_drafts` table — they are structurally identical except for one column.
2. Merge the three `generated_*_forms` tables into one `generated_forms` table with a `service_category` column — zero data risk, highest schema simplification per line of migration.
3. Move receipt number generation out of PHP (`functions.php:515`) into a Supabase `BEFORE INSERT` trigger — smallest change, removes a fragility immediately.

The `parishioners`↔`users` and `official_receipts`↔`payments` splits are well-designed and should stay.

---

## Part 1 — Table Consolidation

### 1.1 KEEP SEPARATE — `parishioners` ↔ `users`

- **Relationship:** Strict 1-to-1 (`user_id UNIQUE` FK). Always inserted together in `auth/register.php:99` and `admin/users.php:26`. `parishioners` holds sacramental/personal data; `users` holds auth data.
- **Why keep separate:** Non-parishioner roles (Admin, Secretary, Treasurer, Priest) have a `users` row but deliberately have **no** `parishioners` row. The split encodes "this account is a parishioner" as a presence/absence relationship. Merging would require nullable columns for all staff accounts.
- **Action:** None.

---

### 1.2 KEEP SEPARATE — `official_receipts` ↔ `payments`

- **Relationship:** True 1-to-1 (`payment_id UNIQUE` on `official_receipts`). A receipt only exists after a payment is verified.
- **Why keep separate:** The separation encodes a business rule — a payment exists before verification; a receipt only exists after. Merging would add nullable `receipt_number` / `issued_by` / `issue_date` to `payments`, which is messier than the current split. The only dead weight (`pdf_path`) was already removed in the cleanup script.
- **Action:** None.

---

### 1.3 MERGE — `wedding_booking_drafts` + `baptism_booking_drafts` → `appointment_drafts`

**Priority: High | Effort: Medium | Risk: Medium**

These two tables are structurally identical. The only difference is **one column**.

| Column | `wedding_booking_drafts` | `baptism_booking_drafts` |
|--------|:---:|:---:|
| `draft_id`, `parishioner_id`, `guest_*`, `service_id`, `priest_id`, `appointment_date/time`, `schedule_type`, `pss_claim`, `sponsor_count`, `remarks`, `contact_phone`, `location_address`, `status`, timestamps, `expires_at`, `finalized_appointment_id`, `guest_access_token_hash` | ✓ | ✓ |
| `wedding_sponsor_count` | ✓ only | — |

**How to merge:**
- Add a `service_type VARCHAR(20) NOT NULL` column (values: `'wedding'`, `'baptism'`; extensible for future services).
- Move `wedding_sponsor_count` into the unified table as a nullable column.
- Consolidate the two FK columns on `uploaded_documents` (`draft_id` and `baptism_draft_id`) into a single `draft_id BIGINT` + `draft_type VARCHAR(20)` pair.

**PHP files to update after migration:**
- `includes/wedding-draft.php`
- `includes/baptism-draft.php`
- `wedding-draft.php`
- `baptism-draft.php`
- `wedding-draft-form.php`
- `baptism-draft-form.php`

**Pro:** One table, one expiry cron job, one cleanup path. Future service types (Funeral draft) need zero schema changes.
**Con:** Medium PHP refactor across 6 files.

---

### 1.4 MERGE — `generated_wedding_forms` + `generated_funeral_forms` + `generated_baptism_forms` → `generated_forms`

**Priority: High | Effort: Medium | Risk: Low**

Three tables with **identical structure:**

| Column | All three tables |
|--------|:---:|
| `appointment_id INT` | ✓ |
| `draft_id BIGINT NULL` | ✓ |
| `form_type VARCHAR(60)` | ✓ |
| `form_data JSONB` | ✓ |
| `document_id INT NULL` | ✓ |
| `status VARCHAR(30)` | ✓ |
| `rejection_reason TEXT NULL` | ✓ |
| `created_at`, `updated_at` | ✓ |

The only difference is the allowed values in the `form_type` CHECK constraint.

**How to merge:**
- Add a `service_category VARCHAR(20) NOT NULL` column (values: `'wedding'`, `'funeral'`, `'baptism'`).
- Relax or drop the per-table `form_type` CHECK; enforce at the application layer or via a single combined constraint.
- Future service types (Confirmation forms, First Communion forms) need zero schema changes.

**PHP files to update after migration:**
- `includes/generated-form-workflow.php`
- All form-generation PHP files referencing the old table names.

**Pro:** Zero data risk (generated forms are artifacts, not financial records). Highest schema simplification per line of migration.
**Con:** PHP refactor in the form-generation workflow.

---

### 1.5 KEEP SEPARATE — `transactions` ↔ `payments`

- **Why keep separate:** `transactions` is a raw PayMongo gateway-response log; `payments` is the application-level payment record. They have different cardinalities — a payment could have multiple retry transactions. Merging would lose the ability to store multiple gateway attempts per payment.
- **Action:** None.

---

### 1.6 DEFER — `appointments` JSONB refactor for service-specific columns

**Priority: Defer to v2 | Effort: Very High | Risk: High**

~15 nullable, service-specific columns have been added by migrations:

| Column | Added by | Used for |
|--------|----------|----------|
| `date_of_death` | migration_scheduling | Funeral only |
| `guest_name`, `guest_email`, `guest_phone`, `guest_reference` | migration_guest_access | Guest bookings |
| `contact_phone`, `location_address` | migration_appointments_contact_location | Home visits (Anointing) |
| `requester_name`, `patient_name` | migration_anointing | Anointing only |
| `pss_classification`, `pss_claim`, `pss_verified_by`, `pss_verified_at` | migration_service_fee_rules | Wedding/Baptism PSS |
| `sponsor_count`, `wedding_sponsor_count`, `fee_snapshot` | migration_service_fee_rules | Wedding/Baptism |
| `schedule_type` | migration_scheduling_v2 | All services |
| `rejection_reason` | migration_rejection_reason | All services |
| `requirements_snapshot` | migration_wedding_generated_forms | Wedding/Baptism |

**Why defer:** Many columns are used in WHERE/JOIN conditions (e.g., `pss_classification = 'pending_verification'` in `secretary/appointment-detail.php:152`, `fee_snapshot` already queried as JSONB). Moving them into a single `extra_data JSONB` blob breaks all indexed queries and requires full rewrites of the most critical PHP files.

**Right approach going forward:** Stop adding bare columns for new services; extend `extra_data` instead. But migrating existing columns is a high-risk, high-effort refactor — do not attempt on a live system.

---

## Part 2 — Supabase Triggers & Functions

### 2.1 Auto-log `activity_logs` on appointment status change

**Priority: Medium | Effort: Low | Risk: Low**

**PHP files affected:**
- `secretary/appointment-detail.php` — 9 calls to `logActivity()`
- `includes/functions.php:318` — auto-cancel path

**Current problem:** Every status-changing action calls `logActivity()` manually as 3 separate PHP→DB round-trips. If any future code path changes `appointments.status_id` without calling `logActivity()`, the audit trail silently breaks.

**Proposed trigger:**

```sql
CREATE OR REPLACE FUNCTION fn_log_appointment_status_change()
RETURNS TRIGGER AS $$
BEGIN
  IF OLD.status_id IS DISTINCT FROM NEW.status_id THEN
    INSERT INTO activity_logs (user_id, action, module)
    VALUES (
      NEW.approved_by,
      'Status changed from ' || OLD.status_id || ' → ' || NEW.status_id
        || ' on appointment #' || NEW.appointment_id,
      'Appointments'
    );
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_appointment_status_log
AFTER UPDATE ON appointments
FOR EACH ROW EXECUTE FUNCTION fn_log_appointment_status_change();
```

**Benefit:** Audit trail is guaranteed even if future developers forget to call `logActivity()`.

**Tradeoff:** The trigger cannot easily capture the PHP actor (user who made the change) in a pooled Supabase connection. Practical middle ground: keep `logActivity()` in PHP for human-readable messages; the trigger acts as a safety net for missed calls. The auto-cancel path in `functions.php:318` can drop its `logActivity()` entirely.

---

### 2.2 Receipt number auto-generation via DB trigger

**Priority: High | Effort: Low | Risk: Low**

**PHP files affected:**
- `includes/functions.php:515`

**Current PHP code:**

```php
// functions.php line 515
$receiptNumber = 'OR-' . date('Y') . '-'
    . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
$pdo->prepare(
    "INSERT INTO official_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)"
)->execute([$paymentId, $receiptNumber, $verifiedByUserId]);
```

The receipt number encodes the payment_id — predictable and redundant with the PK. If this logic is ever duplicated in a new code path, receipt numbers could collide or diverge in format.

**Proposed BEFORE INSERT trigger:**

```sql
CREATE OR REPLACE FUNCTION fn_set_receipt_number()
RETURNS TRIGGER AS $$
BEGIN
  IF NEW.receipt_number IS NULL OR NEW.receipt_number = '' THEN
    NEW.receipt_number :=
      'OR-' || to_char(CURRENT_DATE, 'YYYY') || '-'
      || LPAD(NEW.payment_id::text, 6, '0');
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_set_receipt_number
BEFORE INSERT ON official_receipts
FOR EACH ROW EXECUTE FUNCTION fn_set_receipt_number();
```

**PHP code after:** Delete the `$receiptNumber = 'OR-' ...` line. PHP just does:

```php
$pdo->prepare(
    "INSERT INTO official_receipts (payment_id, issued_by) VALUES (?, ?)"
)->execute([$paymentId, $verifiedByUserId]);
```

Then read back the generated number. Receipt format is enforced at the DB layer — impossible to insert a malformed or missing number.

---

### 2.3 Payment verification → `rpc_verify_payment` RPC Function

**Priority: Medium | Effort: High | Risk: Medium**

**PHP files affected:**
- `includes/functions.php:464–538` (`verifyPaymentAndIssueReceipt`)
- `treasurer/payment-detail.php:15`
- `treasurer/payments.php:52`

**Current pattern:** A PHP-managed transaction (~70 lines) that does 5 things atomically: lock rows, update `payments`, update `appointments.status_id`, insert `official_receipts`, insert `notifications`.

**After RPC:** PHP collapses to ~5 lines:

```php
$stmt = db()->prepare("SELECT rpc_verify_payment(?, ?, ?) AS result");
$stmt->execute([$paymentId, $verifiedByUserId, $referenceNumber]);
$result = json_decode($stmt->fetchColumn(), true);
```

The critical payment logic becomes testable directly in the Supabase SQL Editor.

**Tradeoff:** Business logic is split between PHP and the DB — debugging requires checking both sides. Only tackle once you have a Supabase staging environment with a test suite covering the payment verification flow.

---

### 2.4 Auto-notify on appointment status change

**Priority: Medium | Effort: Low–Medium | Risk: Low**

**PHP files affected:**
- `secretary/appointment-detail.php` — 4 manual `INSERT INTO notifications` blocks (lines 113, 241, 276, 374)

**Current problem:** Notification INSERT is manually paired with each status UPDATE across 4 code locations. If a status is ever changed outside this file (e.g., a future bulk-action), no notification is sent.

**Proposed trigger:**

```sql
CREATE OR REPLACE FUNCTION fn_notify_appointment_status()
RETURNS TRIGGER AS $$
DECLARE
  v_user_id INT;
  v_title   TEXT;
BEGIN
  IF OLD.status_id IS DISTINCT FROM NEW.status_id THEN
    SELECT u.user_id INTO v_user_id
    FROM parishioners p
    JOIN users u ON u.user_id = p.user_id
    WHERE p.parishioner_id = NEW.parishioner_id;

    v_title := CASE NEW.status_id
      WHEN 2 THEN 'Appointment Approved'
      WHEN 3 THEN 'Appointment Rejected'
      WHEN 5 THEN 'Appointment Confirmed'
      WHEN 6 THEN 'Appointment Completed'
      ELSE NULL
    END;

    IF v_title IS NOT NULL AND v_user_id IS NOT NULL THEN
      INSERT INTO notifications (user_id, type, category, title, message)
      VALUES (
        v_user_id, 'website', 'appointment', v_title,
        'Your appointment #' || NEW.appointment_id
          || ' status has been updated to: ' || v_title
      );
    END IF;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_appointment_notify
AFTER UPDATE ON appointments
FOR EACH ROW EXECUTE FUNCTION fn_notify_appointment_status();
```

**PHP code that can be deleted after:** All 4 manual `INSERT INTO notifications` blocks in `secretary/appointment-detail.php`.

**Tradeoff:** The trigger sends generic messages. The current PHP code crafts personalized messages (e.g., including the rejection reason). **Solution:** keep personalized messages in PHP for rejections only (status_id = 3); let the trigger handle Approved, Confirmed, and Completed.

---

### 2.5 Project fundraising total — materialized column

**Priority: Defer | Effort: Low | Risk: Low**

**PHP files affected:**
- `secretary/projects.php:33`
- `parishioner/projects.php:8`

**Current pattern:** A correlated subquery computes `SUM(payments.amount)` per project row on every page load — fine now, slow at scale.

**Proposed approach:** Add a `raised_amount NUMERIC DEFAULT 0` column to `projects`, updated by a trigger on `payments` whenever `payment_status` changes to `'verified'`. O(1) reads vs. O(n×m) correlated subqueries.

**When to do it:** Defer until the project feature sees real usage volume with many verified donations. Not urgent at current scale.

---

## Part 3 — Pros & Cons Summary

| Change | Pro | Con | Complexity | Priority |
|--------|-----|-----|:----------:|:--------:|
| Merge draft tables (1.3) | ½ the tables; extensible to new services | Medium PHP refactor; FK migration on `uploaded_documents` | Medium | **High** |
| Merge generated forms tables (1.4) | 3→1 table; zero data risk | PHP refactor in form-gen workflow | Medium | **High** |
| Receipt number trigger (2.2) | DB-enforced format; PHP simplification | Minor | Low | **High** |
| Activity log trigger (2.1) | Guaranteed audit trail | Can't capture PHP actor context in trigger | Low | Medium |
| Notification trigger (2.4) | Always fires, even from bulk ops | Loses personalized rejection messages | Low–Medium | Medium |
| `rpc_verify_payment` RPC (2.3) | Eliminates PHP transaction management | Logic split between PHP and DB | High | Medium |
| Project fundraising cache (2.5) | Faster reads at scale | Premature at current scale | Low | Defer |
| `appointments` JSONB refactor (1.6) | Clean schema long-term | Massive PHP refactor; breaks indexed queries | Very High | **Defer to v2** |

---

## Part 4 — Recommended Execution Order

Execute changes in this order — safest/highest-value first:

1. **Merge `generated_*_forms` tables**
   Zero data risk — generated forms are artifacts, not financial records. Highest schema simplification per line of migration. Do this first while the surface area is still small.

2. **Receipt number trigger**
   Smallest possible change. Add the trigger, then delete the `$receiptNumber` line and simplify `verifyPaymentAndIssueReceipt` in `functions.php`.

3. **Merge booking draft tables**
   Do this before adding any third service type that would need a third draft table. Medium effort but prevents future accumulation.

4. **Activity log trigger**
   Purely additive — doesn't require changing any existing PHP. Adds a safety net without disrupting current flows.

5. **Appointment notification trigger**
   Replace the 4 manual `INSERT INTO notifications` blocks in `secretary/appointment-detail.php` after validating the trigger fires correctly in staging.

6. **`rpc_verify_payment` RPC**
   High value but also highest risk. Only tackle once you have a Supabase staging environment with a test suite covering the payment verification flow.

7. **Project fundraising cache**
   Defer until the project feature sees real usage volume with many verified donations.

8. **`appointments` JSONB refactor**
   Plan for a v2 rewrite. From now on, stop adding bare columns for new services and extend `extra_data` instead — but do not migrate the existing columns on a live system.
