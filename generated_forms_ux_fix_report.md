# ParishHub Generated Forms UX & Filename Fix Report

## 1. Formal Generated PDF Filenames
The legacy system relied on a hardcoded array in `document.php` to assign generic filenames like `Marriage-Requirement-and-Application-Form.pdf` for all users. This made managing downloaded PDFs confusing for end-users and administrators.

We updated `document.php` to dynamically fetch the relevant form's JSON data directly from the corresponding table (`generated_wedding_forms`, `generated_baptism_forms`, or `generated_funeral_forms`) based on the form context (`draft_id` or `appointment_id`).

It now generates a formal, sanitized filename dynamically. Examples of the new format:
- **Marriage Requirements**: `Marriage_Requirement_and_Application_Form_John_Doe_and_Jane_Doe.pdf`
- **Katin-awan sa Kasal**: `Katin-awan_sa_Kasal_John_Doe_and_Jane_Doe.pdf`
- **Wedding Sponsor**: `Cluster_Clearance_for_Wedding_Sponsor_Sponsor_Name.pdf`
- **Baptism**: `Katin-awan_sa_Bunyag_Child_Name.pdf`
- **Funeral**: `Katin-awan_sa_Paglubong_Deceased_Name.pdf`

If form data is missing or incomplete, it falls back to a safe `Generated_Form_Appointment_ID.pdf` or `Draft_ID.pdf` naming convention.

## 2. Reorganized Generated Form UI
Previously, generated forms inside draft pages (`wedding-draft.php`, `baptism-draft.php`) and status views (`status.php`, `parishioner/appointment-detail.php`) rendered inline within a single `<p>` tag, causing buttons to awkwardly wrap and blend with titles.

We introduced a dedicated `.generated-form-item` CSS component block in `public/css/style.css` to group form elements vertically:
1. **Title & Status (`.generated-form-header`)**: Clearly stacked at the top of the block.
2. **Action Buttons (`.generated-form-actions`)**: Neatly grouped inside a flexible layout container beneath the title, eliminating the inline wrapping mess.

This layout has been successfully applied to:
- `wedding-draft.php`
- `baptism-draft.php`
- `status.php` (Guest tracking)
- `parishioner/appointment-detail.php` (Parishioner dashboard, including the inline Funeral Form)

### Status
All constraints have been strictly followed:
- No new form fields were added to the actual PDF forms.
- Business logic around form progression remains untouched.
- Pricing and payments logic were strictly avoided.
