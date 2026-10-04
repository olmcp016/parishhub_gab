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
    <button type="button" class="modal-close" onclick="document.getElementById('rejectApptModal').close()" aria-label="Close">✕</button>
  </div>
  <div class="modal-body">
    <p>This will reject the entire appointment request. The applicant will need to submit a new booking if they want to proceed.</p>
    <p><strong>Reason:</strong> <span id="rejectConfirmReason"></span></p>
    <div class="review-rejection-buttons">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('rejectApptModal').close()">Cancel</button>
      <button type="button" class="btn btn-danger" style="white-space:nowrap;" onclick="document.getElementById('rejectApptModal').close(); document.getElementById('rejectForm').requestSubmit();">Reject Appointment</button>
    </div>
  </div>
</dialog>
