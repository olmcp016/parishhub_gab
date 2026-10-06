# Funeral Generated-Form Workflow Standardization

The Funeral booking flow has been successfully standardized to match the conceptual UX of Wedding and Baptism, strictly following your requirements:

1. **Booking Details → Requirements Step**: 
   When a user submits the initial booking details and Death Certificate in the modal, they are now redirected to a dedicated **Funeral Requirements** page (`funeral-draft.php`), rather than completing the Katin-awan sa Paglubong form inline.
   
2. **Session-based Draft Implementation (Zero Migrations)**:
   As requested, I avoided creating a large new Funeral draft database architecture. Instead, the Funeral draft is temporarily stored securely in `$_SESSION['funeral_booking_drafts']` along with any uploaded document storage keys. This achieves exactly the required behavior (delayed appointment submission) without requiring any database schema changes or migrations. Guest users are secured using standard token hashing.

3. **Form Generation and Submission**:
   - On the `funeral-draft.php` page, users can upload/replace their Death Certificate and click **Fill Out Form** to complete the `Katin-awan sa Paglubong` form (handled via `funeral-form.php?draft_id=...`).
   - `funeral-form.php` was rewritten to gracefully handle both session-based drafts (pre-submission) and database-backed appointments (post-submission for edits).
   - Once the Death Certificate is uploaded and the Katin-awan form is marked **Completed**, the user clicks **Submit Appointment Request**.
   - The application then creates the appointment, links the uploaded documents, generates the final Katin-awan PDF, and permanently stores it in the database.

4. **Consistency**:
   - The UI matches the draft pages of Baptism and Wedding perfectly.
   - The Funeral booking modal no longer forces an inline form fill.
   - The internal implementation is completely different (Session vs Draft Table) as permitted, but the User Experience is identically sequenced.

All changes have been implemented in `parishioner/services.php`, `parishioner/book.php`, `funeral-draft.php`, and `funeral-form.php`.
