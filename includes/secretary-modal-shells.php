<?php
/**
 * Page-level Secretary dialog shells.
 *
 * These must remain siblings of the shared detail dialog, not markup fetched
 * into its body. Native dialogs are independently managed by detail-modal.js.
 */
?>
<dialog class="modal modal-lg priest-schedule-modal" id="priestScheduleModal" aria-labelledby="priestScheduleTitle">
  <div class="modal-head">
    <h3 id="priestScheduleTitle">Priest Schedule</h3>
    <button type="button" class="modal-close js-close-priest-schedule" aria-label="Close">✕</button>
  </div>
  <div class="modal-body" id="priestScheduleModalBody">
    <p class="text-muted">Select View Schedule to load appointments.</p>
  </div>
</dialog>

<dialog class="modal appointment-rejection-confirm-modal" id="rejectApptModal" aria-labelledby="rejectApptTitle">
  <div class="modal-head">
    <h3 id="rejectApptTitle">Reject Appointment?</h3>
    <button type="button" class="modal-close" id="closeRejectApptModal" aria-label="Close">✕</button>
  </div>
  <div class="modal-body">
    <p><strong>This rejects the entire appointment request.</strong> The applicant will need to submit a new booking if the request is rejected.</p>
    <p class="appointment-rejection-warning">If only a document or generated form needs correction, cancel this confirmation and reject that requirement instead. The appointment and booking reference will remain active.</p>
    <p><strong>Reason:</strong> <span id="rejectConfirmReason"></span></p>
    <div class="review-rejection-buttons">
      <button type="button" class="btn btn-outline" id="cancelRejectApptModal">Cancel</button>
      <button type="button" class="btn btn-danger" id="confirmRejectApptBtn">Reject Appointment</button>
    </div>
  </div>
</dialog>
