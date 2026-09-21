<?php
/**
 * Shared "View/Review details" pop-up modal shell for the Secretary
 * dashboard (Parishioners, Mass Intentions, Appointments lists) — see
 * public/js/detail-modal.js. One dialog per page; its body is filled via
 * fetch() with the linked page's own content (requested as a fragment).
 */
?>
<dialog class="modal modal-xl" id="detailModal">
  <div class="modal-head">
    <h3 id="detailModalTitle">Details</h3>
    <button type="button" class="modal-close detail-modal-close" aria-label="Close">✕</button>
  </div>
  <div class="modal-body" id="detailModalBody"></div>
</dialog>
<script src="<?= url('public/js/detail-modal.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/detail-modal.js') ?>"></script>
