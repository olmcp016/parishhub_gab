# ParishHub service workflow audit

Audit date: 2026-10-04 (Asia/Manila)

This audit covers the active service catalog, booking entry points, draft persistence, supporting-document upload/review, generated parish forms, guest status access, payment hand-off, and Secretary approval behavior. No database migration or data mutation was performed for this audit.

## 1. Active service inventory

The live Supabase catalog contains 13 service rows. Nine are active; General Donation is active but uses the donation/payment workflow rather than the appointment-requirements workflow. The customer-bookable parish-service set is therefore eight active services:

| Service | Category | Active | Workflow family | Generated parish form |
|---|---|---:|---|---|
| Mass Intentions | Mass Intention | Yes | Generic booking + payment/cashier flow | No |
| Wedding Ceremony | Wedding | Yes | Persistent DB draft, supporting documents, generated forms, Secretary review | Yes: 3 |
| Baptism | Baptism | Yes | Persistent DB draft, supporting documents, generated forms, Secretary review | Yes: 2 |
| Funeral Mass | Funeral | Yes | Session-backed draft, supporting documents, generated form, Secretary review | Yes: 1 |
| House Blessing | Blessing | Yes | Generic booking with address/contact fields | No |
| Confirmation | Confirmation | Yes | Generic booking with supporting documents | No |
| First Communion | First Communion | Yes | Generic booking with supporting documents | No |
| Misa sa Haya / Misa sa Sementeryo atol sa Sumad sa Kamatayon | Wake | Yes | Generic booking | No |
| General Donation | Donation | Yes | Donation + payment flow; not a service appointment | No |

The four inactive Mass Intention subtype rows remain historical catalog data and are not exposed by the active booking catalog.

The live Wedding catalog lists eight supporting documents. Before this audit, the persistent Wedding draft helper hard-coded only the first five. New Wedding drafts now use the service catalog's stored requirement list; the previous five-document list remains a fallback for older/partial records without a requirement snapshot.

## 2. Workflow matrix

| Area | Guest | Authenticated parishioner | Persistence | Secretary review |
|---|---|---|---|---|
| Generic services | Booking reference and public status lookup | Booking and appointment detail | Appointment row; uploads where required | Supporting documents where applicable |
| Wedding | Guest token/session access to DB draft, then public reference | DB draft tied to parishioner | `wedding_booking_drafts`, `generated_wedding_forms`, `uploaded_documents` | Documents and each generated form independently |
| Baptism | Guest token/session access to DB draft, then public reference | DB draft tied to parishioner | `baptism_booking_drafts`, `generated_baptism_forms`, `uploaded_documents` | Documents and each generated form independently |
| Funeral | Session draft and public reference after submission | Session draft tied to parishioner, then appointment detail | PHP session preview before submission; `generated_funeral_forms` after submission | Death Certificate and generated form independently |
| Payment | Reference-based payment/status where eligible | Appointment detail/payment flow | `payments`, `transactions`, fee snapshots | Separate from form/document review |

The different draft implementations are intentional: Baptism/Wedding drafts need durable multi-document staging, while Funeral currently uses a session preview because its generated PDF is created before an appointment exists. The UX is standardized; the persistence models are not forcibly merged.

## 3. Standard generated-form state machine

All three generated-form services now present the same applicant-facing vocabulary:

`Not started` → `Draft saved` → `Generated — ready to submit` (staging) → `Pending Secretary review` (appointment) → `Approved`

The rejection branch is independent:

`Pending Secretary review` → `Needs revision` → `Edit and Regenerate` → `Pending Secretary review`

An appointment-level rejection remains separate and is never represented as a form rejection. The appointment status still controls whether editing/review actions are available.

The shared presentation rules are in `includes/generated-form-workflow.php`. They derive the displayed state from the existing generated-form row and its linked uploaded-document review state; they do not change table constraints or data values.

## 4. Supporting-document state

Supporting-document cards already use the appropriate independent states: missing, pending review, approved, and needs revision. The audit retained that model and aligned the generated-form cards with it. A rejected generated PDF now shows the Secretary's reason and directs the applicant to regenerate only that form.

## 5. Implemented stabilization changes

- Added the shared generated-form state/action/help presentation helper.
- Standardized Baptism, Wedding, and Funeral requirement-page cards.
- Added consistent Save Draft versus Generate PDF guidance to all generated-form editors.
- Added clear back navigation from generated-form editors to requirements or appointment status.
- Added Secretary rejection-reason visibility to Baptism and Wedding form editors.
- Standardized applicant, guest status, and Secretary generated-form labels/actions.
- Prevented approved forms from being presented as editable actions.
- Disabled Baptism and Funeral appointment submission until required documents/forms are complete, matching the existing Wedding gate.
- Updated Wedding completion checks so a saved form draft is not treated as a generated/submittable PDF.
- Updated approval eligibility so a Funeral form saved as `draft` cannot satisfy appointment approval.
- Added Funeral Save Draft support for both session drafts and appointment forms.
- Prevented a changed Funeral session draft from retaining an outdated generated PDF. If answers change, the old preview artifact is removed and the user must generate again.
- Kept `generated_document_id` as a post-redirect preview hint only. It is checked against the current linked document; document authorization remains server-side in `document.php`.
- Corrected generated-PDF filename metadata lookup so appointment-owned Wedding/Baptism forms use their appointment form data instead of incorrectly querying only draft-owned rows.

## 6. Reference, guest, and persistence audit

- Wedding and Baptism finalization transfer draft documents/forms to the created appointment inside a transaction.
- Funeral finalization transfers session uploads into `uploaded_documents` and creates its generated-form row inside the appointment transaction.
- Guest status uses the generated reference and establishes a short-lived session verification record before exposing appointment documents/forms.
- Generated-form rejection updates the generated-document review state and form state; whole-appointment rejection uses the appointment rejection reason and is handled separately.
- Save Draft does not generate a PDF or submit a reviewable document.
- Generate PDF creates/replaces the generated document and sets it to pending review for appointment workflows.

## 7. Scope intentionally not changed

Mass Intentions, House Blessing, Confirmation, First Communion, Wake, and Donation do not currently have generated parish forms. They remain on their existing generic, supporting-document, scheduling, and payment paths. No artificial generated-form step was added to them.

No storage bucket, payment, scheduling, authentication/session, or database schema migration was changed by this audit.

## 8. Verification performed

- Queried the live service catalog read-only through the configured Supabase PostgreSQL connection.
- Confirmed the generated-form tables and existing status constraints before making app-only changes.
- Confirmed all `generated_document_id` uses are in redirect/preview paths; no GET parameter performs a write or grants document authorization.
- Ran `git diff --check` successfully.
- PHP CLI is not installed in the workspace, so `php -l` and PHP runtime tests could not be executed locally.

## 9. Recommended manual test matrix

Run each row as both a guest and authenticated parishioner where supported:

1. Start each active service and confirm the correct fields, schedule behavior, fee/PSS behavior, and reference/status destination.
2. Wedding: save partial form, reload, generate each of three forms, upload each required document, submit, and verify Secretary approval/rejection independently.
3. Baptism: repeat for both generated forms, including the conditional parents' marriage document.
4. Funeral: save partial session draft, generate, change an answer and save again, confirm the old preview is gone, regenerate, submit, then test document/form rejection and replacement.
5. Open every generated PDF through View and Download links as owner, guest, and Secretary; confirm cross-appointment access is denied.
6. Reject one supporting document, reject one generated form, and reject one appointment as a whole; verify the three messages and available actions remain distinct.
7. Complete an approved appointment and verify PayMongo/payment and scheduling behavior is unchanged.
