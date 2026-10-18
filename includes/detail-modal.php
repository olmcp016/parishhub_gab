<?php
/**
 * Shared "View/Review details" pop-up modal shell — used by the Secretary
 * dashboard (Parishioners, Mass Intentions, Appointments lists) and the
 * Parishioner's own My Appointments list. See public/js/detail-modal.js.
 * One dialog per page; its body is filled via fetch() with the linked
 * page's own content (requested as a fragment).
 */
?>
<dialog class="modal modal-xl" id="detailModal">
  <div class="modal-head">
    <h3 id="detailModalTitle">Details</h3>
    <button type="button" class="modal-close detail-modal-close" aria-label="Close">✕</button>
  </div>
  <div class="modal-body" id="detailModalBody"></div>
</dialog>
<dialog class="modal review-close-confirm-modal" id="reviewCloseConfirmModal" aria-labelledby="reviewCloseConfirmTitle" aria-describedby="reviewCloseConfirmMessage">
  <div class="modal-head">
    <h3 id="reviewCloseConfirmTitle">Unfinished Appointment Review</h3>
    <button type="button" class="modal-close" id="reviewCloseConfirmX" aria-label="Close confirmation">✕</button>
  </div>
  <div class="modal-body">
    <p id="reviewCloseConfirmMessage">You haven't finished reviewing this appointment. Are you sure you want to close it?</p>
    <div class="review-close-confirm-actions">
      <button type="button" class="btn btn-outline" id="reviewContinueBtn">Continue Reviewing</button>
      <button type="button" class="btn btn-danger" id="reviewCloseAnywayBtn">Close Anyway</button>
    </div>
  </div>
</dialog>
<script src="<?= url('public/js/detail-modal.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/detail-modal.js') ?>"></script>
