# PARISHHUB OLD DATA CLEANUP AUDIT

Read-only audit snapshot: `2026-10-04 01:57:26 UTC` (`2026-10-04` database date).

No `DELETE`, `UPDATE`, `TRUNCATE`, `DROP`, sequence reset, or Storage deletion was executed. The live database was queried through the configured Supabase PostgreSQL connection. Counts can change while the application remains online.

## 1. Current Database Overview

The canonical runtime is PostgreSQL/Supabase. The database contains current workflow data plus a small amount of legacy-path data from the earlier local-upload implementation. The current Supabase Storage bucket is `parish-documents`.

Current high-level totals:

| Area | Total | Audit result |
|---|---:|---|
| Users | 13 | Staff, guest placeholder, parishioners, and three suspicious no-dependency registrations identified |
| Parishioners | 8 | Linked to user accounts |
| Priests | 3 | All treated as protected master data |
| Appointments | 155 | One definitive runtime-test appointment; other suspicious-looking rows require review |
| Baptism/Wedding drafts | 19 / 35 | 25 are past `expires_at`; expiry alone is not a deletion reason |
| Generated forms | 12 Baptism, 53 Wedding, 3 Funeral | No orphan form rows or missing referenced documents |
| Uploaded documents | 271 | 262 active, 9 superseded; 68 legacy `public/uploads/...` references unresolved in the current workspace |
| Payments | 88 | 36 verified, 16 pending, 36 cancelled; all retained |
| Transactions | 50 | PayMongo history retained |
| Donations | 31 | All retained |
| Supabase Storage objects | 202 | 201 referenced by DB rows; one unreferenced object requires review |

## 2. Current Tables

| Table | Rows | Purpose / current use | Classification |
|---|---:|---|---|
| `activity_logs` | 1,253 | Audit/history trail | ACTIVE — KEEP |
| `announcements` | 7 | Parish announcements | ACTIVE — KEEP |
| `appointment_status` | 7 | Status master data | ACTIVE MASTER — PROTECT |
| `appointments` | 155 | All service requests | ACTIVE TRANSACTIONAL — REVIEW INDIVIDUALLY |
| `baptism_booking_drafts` | 19 | Baptism staging | ACTIVE — REVIEW INDIVIDUALLY |
| `calendar` | 0 | Blocked dates | ACTIVE STRUCTURE — PROTECT |
| `chat_messages` | 90 | Chat history | ACTIVE HISTORY — KEEP |
| `donations` | 31 | Donation details | ACTIVE FINANCIAL — KEEP |
| `events` | 37 | Parish calendar events | ACTIVE — KEEP |
| `generated_baptism_forms` | 12 | Baptism generated forms | ACTIVE — REVIEW BY OWNER |
| `generated_funeral_forms` | 3 | Funeral generated forms | ACTIVE — REVIEW BY OWNER |
| `generated_wedding_forms` | 53 | Wedding generated forms | ACTIVE — REVIEW BY OWNER |
| `locations` | 55 | Chapel/barangay/location master data | ACTIVE MASTER — PROTECT |
| `mass_intentions` | 42 | Mass intention details | ACTIVE TRANSACTIONAL — KEEP |
| `notifications` | 191 | User notifications | ACTIVE HISTORY — KEEP |
| `official_receipts` | 36 | Financial receipts | ACTIVE FINANCIAL — KEEP |
| `parishioners` | 8 | Parishioner profiles | ACTIVE — PROTECT |
| `payment_methods` | 7 | Payment method master data | ACTIVE MASTER — PROTECT |
| `payments` | 88 | Payment records | HIGH RISK — KEEP |
| `permissions` | 0 | Legacy permission structure | UNKNOWN / LEGACY SCHEMA CANDIDATE — DO NOT DROP |
| `priest_unavailability` | 1 | Priest availability exceptions | ACTIVE — PROTECT |
| `priests` | 3 | Priest master data | ACTIVE MASTER — PROTECT |
| `projects` | 1 | Donation projects | ACTIVE — KEEP |
| `reports` | 0 | Legacy/generated-report structure | UNKNOWN / LEGACY SCHEMA CANDIDATE — DO NOT DROP |
| `requirements` | 0 | Older normalized requirement structure | LEGACY/UNUSED DATA MODEL — DO NOT DROP |
| `role_permissions` | 0 | Legacy permission mapping | UNKNOWN / LEGACY SCHEMA CANDIDATE — DO NOT DROP |
| `roles` | 5 | Role master data | ACTIVE MASTER — PROTECT |
| `service_fee_rules` | 12 | PSS/schedule pricing rules | ACTIVE MASTER — PROTECT |
| `service_schedules` | 5 | Regular service schedules | ACTIVE MASTER — PROTECT |
| `services` | 13 | Service master data | ACTIVE MASTER — PROTECT |
| `settings` | 9 | Parish and feature configuration | ACTIVE MASTER — PROTECT |
| `transactions` | 50 | PayMongo/gateway history | HIGH RISK — KEEP |
| `uploaded_documents` | 271 | Supporting/generated document metadata | ACTIVE — REVIEW BY OWNER |
| `users` | 13 | Accounts and staff identities | ACTIVE — PROTECT unless explicitly confirmed test |
| `wedding_booking_drafts` | 35 | Wedding staging | ACTIVE — REVIEW INDIVIDUALLY |

The repository also contains legacy MySQL artifacts (`schema.sql`, `seed.sql`, `parishhub_full.sql`, the installer, and MySQL-specific migrations). They are not the live Supabase schema and were not used as live-data evidence.

## 3. Protected Master Data

The following were not proposed for deletion: 5 roles, 7 appointment statuses, 7 payment methods, 13 services, 12 fee rules, 5 service schedules, 3 priests, 55 locations, 9 settings, and staff/admin/treasurer/secretary accounts. The shared inactive guest placeholder account is also protected because 60 appointments currently depend on it.

The inactive mass-intention service rows remain because historical appointments reference them. They are intentionally deactivated, not obsolete rows to delete.

## 4. Suspected Test Data

Definite synthetic record:

- Appointment `184`, reference `PH-7A02B8`, requester `Runtime Test Guest`, synthetic test email, null appointment date/time, Pending, no payment, 2 documents, and 1 generated Funeral form. Its identifying values match the repository's runtime-test fixture.

Strong account candidates, but not included in the deletion SQL without owner confirmation:

- Users `16`, `17`, and `18` have gibberish-looking registration names, no appointments, no drafts, no chats, no activity logs, and no payment relationships. Each still represents an account identity and is classified **MANUAL REVIEW**, not automatic deletion.

Pattern-only appointment signals were found in IDs `75, 117, 119, 120, 123, 125, 132, 141, 147, 149, 150, 160, 170, 174, 176, 183, 188`, plus null-date appointment `185`. Several have documents, generated forms, approvals, or payments. Suspicious text alone is insufficient evidence; these remain **MANUAL REVIEW**. Appointment `189` is recent and has active Baptism documents/forms, so it is kept out of cleanup candidates.

## 5. Appointment Cleanup Candidates

Current status totals by service category:

| Category | Total | Notes |
|---|---:|---|
| Mass Intention | 50 | Includes historical/paid/cancelled records; retain |
| Wedding | 23 | Multiple approved/completed/paid records; retain unless individually proven synthetic |
| Baptism | 21 | Active and historical records with documents/forms/payments |
| Funeral | 15 | Includes current records and the definitive runtime-test row |
| Blessing | 12 | Retain pending/paid/history |
| Confirmation | 1 | Current pending request |
| First Communion | 2 | Current pending requests |
| Donation | 31 | Financial records; retain |

Proposed deletion candidate:

| Table | ID | Reference/name | Created | Reason | Dependencies |
|---|---:|---|---|---|---|
| `appointments` | 184 | `PH-7A02B8` / Runtime Test Guest | 2026-10-03 | Explicit runtime-test identity, null date/time, no payment | 2 documents, 1 Funeral form, 2 local `storage://` files; no payment |

No real appointment was deleted or changed. All other suspicious-looking appointment rows are manual review.

## 6. Draft Cleanup Candidates

There are 19 Baptism drafts and 35 Wedding drafts. Six Baptism drafts and 15 Wedding drafts are past their 24-hour `expires_at` value, but expiration is not treated as proof of abandonment. Six drafts are finalized and linked to appointments; they remain historical workflow records.

Guest drafts `Baptism 6, 7, 9` and `Wedding 27, 28` contain test-like names. Some have documents/forms or a finalized appointment. They are **MANUAL REVIEW**, not automatic deletion. The many repeated date/owner clusters are consistent with repeated booking attempts and do not establish which draft a user still needs.

Proposed draft deletion: **0**. Keep or review drafts until the owner/retention decision is explicit.

## 7. Generated Form Cleanup Candidates

All 68 generated-form rows have valid owners and referenced document rows. There are no orphan generated-form rows and no form pointing at a missing document. Nine older generated document versions are no longer the direct document referenced by a form, but all nine are marked superseded and remain traceable; they are not automatically deleted.

The Funeral form linked to appointment `184` (`generated_funeral_forms.generated_form_id = 1`) is included only through the explicit appointment deletion candidate. No standalone form deletion is proposed.

## 8. Uploaded Document Cleanup Candidates

There are 271 document rows: 262 active and 9 superseded. All rows have exactly one valid owner (appointment, Wedding draft, or Baptism draft), and all owner references resolve.

The only document rows in the proposed deletion graph are:

- `uploaded_documents.document_id = 270`, uploaded Death Certificate metadata for appointment `184`;
- `uploaded_documents.document_id = 271`, generated Funeral Katin-awan metadata for appointment `184`.

They will be removed by the appointment's FK cascade only if the explicit SQL is later approved. No direct document delete is included.

The nine superseded document rows (`90, 105, 106, 111, 244, 267, 274, 296, 297`) are historical revisions and remain **MANUAL REVIEW**.

## 9. Storage Reconciliation

The `parish-documents` bucket contains 202 objects. Of 201 database rows using `supabase://...`, all 201 have matching objects. There are no DB rows pointing to a missing Supabase object.

One object has no DB row and is **MANUAL REVIEW / STORAGE CLEANUP CANDIDATE**:

`appointments/generated/document-7e5de5f8e8a73fb4705ac790dcb31aa05c3a3301.pdf`

It must not be deleted until its creation history and any failed/replaced form operation are confirmed.

Two `storage://...` objects for appointment `184` exist as local workspace files. They are not Supabase Storage objects and are intentionally not deleted in this audit.

There are 68 older DB rows using `public/uploads/...` paths. None of their 34 distinct paths is present in the current workspace. These may be lost legacy references or files held outside this checkout; they are unresolved historical records, not safe deletion candidates.

## 10. User Cleanup Candidates

Users `16`, `17`, and `18` are strong test-account candidates with no dependent business records. They are **MANUAL REVIEW REQUIRED** because deleting an account is an identity decision, even when current dependency counts are zero. No account delete is in the SQL file.

All staff, priest, parishioner, and shared guest-placeholder accounts with business history are KEEP.

## 11. Payment/Donation Safety Review

| Table/status | Count | Amount / result |
|---|---:|---|
| `payments` pending | 16 | 15,051.00 |
| `payments` verified | 36 | 48,197.00; all have official receipts |
| `payments` cancelled | 36 | 263,566.95; retained as gateway/audit history |
| `transactions` | 50 | 11 succeeded, 3 pending, 35 cancelled, 1 awaiting payment method |
| `donations` | 31 | All retained; 13 are approved/payment-verified and 18 cancelled |
| `official_receipts` | 36 | All linked to payments |

No payment is attached to appointment `184`. No payment, transaction, donation, or official receipt is proposed for deletion. Payment/transaction integrity checks found no orphan payment, transaction, receipt, or verified payment without a receipt.

## 12. Foreign-Key Dependency Analysis

Important actual relationships:

```text
users
 ├─ parishioners (CASCADE)
 │   └─ appointments (CASCADE)
 │       ├─ uploaded_documents (CASCADE)
 │       ├─ generated_*_forms (CASCADE)
 │       ├─ payments (CASCADE) ─ transactions / official_receipts (CASCADE)
 │       ├─ mass_intentions (CASCADE)
 │       └─ donations (CASCADE)
 └─ staff/audit/notification references (mostly SET NULL or CASCADE)

baptism_booking_drafts / wedding_booking_drafts
 ├─ draft documents and draft forms (CASCADE)
 └─ finalized_appointment_id (RESTRICT)
```

Important non-cascade protections include appointment→service (`NO ACTION`), appointment→status (`NO ACTION`), user→role (`NO ACTION`), payment→method (`NO ACTION`), receipt→issuer (`NO ACTION`), and report→generator (`NO ACTION`). Draft finalization references use `RESTRICT`.

Correct approved deletion order is: snapshot DB rows and Storage objects; verify explicit IDs/preconditions; delete external Storage objects only after the snapshot; delete the owning appointment or draft and allow only documented FK cascades; verify no remaining references. Do not rely on deleting a user to clean business history.

## 13. Safe to Delete

Subject to explicit approval, only this database graph is presently classified safe:

- Appointment `184` and its cascade-owned rows: documents `270`/`271` and Funeral generated form `1`.
- The two local files referenced by those documents are storage cleanup candidates only; they are not included in the SQL file and must be backed up first.

This classification is evidence-based and explicit-ID-only; it is not an instruction to execute now.

## 14. Manual Review Required

- Users `16`, `17`, `18`.
- Pattern-matched appointment IDs listed in section 5, especially rows with payment, approved, completed, or document history.
- All 25 expired drafts, including drafts with documents/forms and finalized drafts.
- Superseded document IDs `90, 105, 106, 111, 244, 267, 274, 296, 297`.
- The unreferenced Storage object listed in section 9.
- All 68 legacy `public/uploads/...` document rows until an old-storage backup or parish-record decision is obtained.
- Any account or record whose real-world owner cannot be confirmed from the application history.

## 15. Must Keep

Keep all protected master data, staff/priest/guest-placeholder accounts, paid or payment-related records, approved/completed/cancelled/rejected history unless individually proven synthetic, active drafts, current appointments, generated forms/documents with unresolved ownership, notifications/logs, announcements/events, and all donations.

## 16. Legacy Schema Candidates

REPORT ONLY — DO NOT DROP

- `permissions`, `role_permissions`, and `requirements` are empty in the live database and are not the current runtime's active authorization/requirement source. Current code uses role names and the `services.requirements` text/snapshot flow. Keep the tables and columns until a separate schema migration is approved.
- `reports` is empty and appears to be a retained older generated-report table; current report pages calculate reports without stored rows.
- Legacy MySQL files and the installer are repository artifacts, not live data. Do not run or drop based on them.
- `app_sessions` is not present in the live public table list; the current configuration uses file sessions, so no session-table cleanup is proposed.

## 17. Proposed Deletion Counts

| Table/resource | Total | Keep now | Safe candidate | Manual review |
|---|---:|---:|---:|---:|
| `appointments` | 155 | 136 | 1 (`184`) | 18 |
| Baptism drafts | 19 | 2 finalized/history | 0 | 17 |
| Wedding drafts | 35 | 4 finalized/history | 0 | 31 |
| Generated forms | 68 | 67 | 1 via appointment `184` cascade | attached-owner review |
| Uploaded documents | 271 | 192 | 2 via appointment `184` cascade | 77 |
| Supabase Storage objects | 202 | 201 referenced | 0 now | 1 orphan |
| Users | 13 | 10 | 0 now | 3 |
| Payments | 88 | 88 | 0 | 0 |
| Transactions | 50 | 50 | 0 | 0 |
| Donations | 31 | 31 | 0 | 0 |
| Official receipts | 36 | 36 | 0 | 0 |

The appointment split is conservative: the 18 manual rows include the 17 pattern-matched rows plus null-date appointment `185`; the definitive runtime row `184` is separate.

## 18. Proposed Cleanup Order

1. Obtain approval for the explicit candidate IDs.
2. Take a database snapshot/export and a Storage manifest/backup.
3. Re-run the precondition checks in the SQL file immediately before execution.
4. Execute the DB transaction only after approval; the appointment FK cascade removes its documents/forms.
5. Reconcile the resulting DB rows and Storage objects.
6. Handle Storage cleanup separately through the approved Storage API process.
7. Re-check booking, requirements, documents, generated forms, Secretary review, PSS, priest assignment, payments, and status lookup for Baptism, Wedding, Funeral, Mass Intentions, Wake, and Donations.

## 19. Backup/Rollback Plan

Before execution, back up:

- the full affected rows for appointment `184`, documents `270`/`271`, and generated Funeral form `1`;
- all IDs, guest reference, owner/service/status/date fields, document metadata, form JSON, and Storage paths;
- the two local document files and SHA-256 hashes;
- all related payments/transactions/receipts (expected empty for appointment `184`), plus a full database snapshot for safety;
- the complete Storage object manifest, including bucket, key, size, MIME type, and timestamps.

Rollback is by restoring the database snapshot or reinserting the captured rows in dependency order, then restoring the captured Storage objects. Do not reset sequences; ID gaps are expected and acceptable.

## 20. Files Generated

- `PARISHHUB_DATA_CLEANUP_PLAN.md` — this dry-run audit and classification.
- `database/parishhub_old_data_cleanup.sql` — explicit-ID SQL, prepared but not executed.

## 21. FINAL STATUS

CLEANUP AUDIT COMPLETE — WAITING FOR APPROVAL
