<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
require_once __DIR__ . '/../includes/service-fees.php';
require_once __DIR__ . '/../includes/wedding-forms.php';
require_once __DIR__ . '/../includes/baptism-forms.php';
requireRole('Secretary', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$userId = currentUser()['user_id'];
$isSecretaryViewer = currentUser()['role_name'] === 'Secretary';
$isAjax = isDetailModalRequest();
$redirectUrl = url('secretary/appointment-detail.php?id=' . $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_document') {
        db()->prepare("UPDATE uploaded_documents SET review_status = 'approved', verified = TRUE, rejection_reason = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL")
            ->execute([$userId, $_POST['document_id'], $id]);
        db()->prepare("UPDATE generated_wedding_forms SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE document_id = ? AND appointment_id = ?")->execute([$_POST['document_id'], $id]);
        db()->prepare("UPDATE generated_baptism_forms SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE document_id = ? AND appointment_id = ?")->execute([$_POST['document_id'], $id]);
        logActivity($userId, "Verified a document for appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Document marked as verified.', $redirectUrl);
    }

    if ($action === 'reject_document') {
        $reason = trim($_POST['document_rejection_reason'] ?? '');
        if ($reason === '' || mb_strlen($reason) > 1000) {
            respondAjaxOrRedirect($isAjax, false, 'A document rejection reason is required.', $redirectUrl);
        }
        db()->prepare("UPDATE uploaded_documents SET review_status = 'rejected', verified = FALSE, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL")
            ->execute([$reason, $userId, $_POST['document_id'], $id]);
        db()->prepare("UPDATE generated_wedding_forms SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE document_id = ? AND appointment_id = ?")->execute([$_POST['document_id'], $id]);
        db()->prepare("UPDATE generated_baptism_forms SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE document_id = ? AND appointment_id = ?")->execute([$_POST['document_id'], $id]);
        logActivity($userId, "Rejected a document for appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Document rejected with instructions.', $redirectUrl);
    }

    if ($action === 'verify_pss') {
        $classification = $_POST['pss_classification'] ?? '';
        if (!in_array($classification, ['pss', 'non_pss'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'Please choose a valid PSS classification.', $redirectUrl);
        }
        $stmt = db()->prepare("SELECT a.schedule_type, a.sponsor_count, a.wedding_sponsor_count, s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ? FOR UPDATE");
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt->execute([$id]);
            $feeAppointment = $stmt->fetch();
            if (!$feeAppointment || !in_array($feeAppointment['category'], ['Baptism', 'Wedding', 'Funeral'], true)) {
                throw new RuntimeException('This appointment does not use the PSS fee rules.');
            }
            $sponsors = $feeAppointment['category'] === 'Wedding' ? (int) $feeAppointment['wedding_sponsor_count'] : (int) $feeAppointment['sponsor_count'];
            $calculation = calculateServiceFee($feeAppointment['category'], $feeAppointment['schedule_type'], $classification, $sponsors);
            if (!$calculation) throw new RuntimeException('No fee rule is configured for this appointment.');
            $calculation['verified_by'] = $userId;
            $pdo->prepare('UPDATE appointments SET pss_classification = ?, pss_verified_by = ?, pss_verified_at = NOW(), fee_snapshot = ? WHERE appointment_id = ?')
                ->execute([$classification, $userId, json_encode($calculation), $id]);
            $pdo->commit();
            logActivity($userId, "Verified PSS classification for appointment #$id as $classification", 'Appointments');
            respondAjaxOrRedirect($isAjax, true, 'PSS classification verified and fee calculated.', $redirectUrl);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            respondAjaxOrRedirect($isAjax, false, 'Unable to verify the PSS classification.', $redirectUrl);
        }
    }

    if ($action === 'approve') {
        // Require that EVERY named requirement has a verified upload before
        // approving — not just "at least one document total" as before.
        // Mass Intentions and Donations never require documents — they're
        // auto-approved on submission anyway, but this also covers any
        // legacy pending records.
        $stmt = db()->prepare(
            "SELECT a.requirements_snapshot, s.requirements, s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$id]);
        $svc = $stmt->fetch();
        $requirementsList = !empty($appointment['requirements_snapshot']) ? (json_decode($appointment['requirements_snapshot'], true) ?: []) : parseRequirementsList($svc['requirements']);

        if (!in_array($svc['category'], ['Mass Intention', 'Donation'], true) && !empty($requirementsList)) {
            $stmt = db()->prepare('SELECT requirement_label, verified FROM uploaded_documents WHERE appointment_id = ?');
            $stmt->execute([$id]);
            $docs = $stmt->fetchAll();
            $hasAnyLabel = array_reduce($docs, fn($carry, $d) => $carry || $d['requirement_label'], false);

            if ($hasAnyLabel) {
                // New-style upload with per-requirement labels — every named item must be verified.
                $latestByLabel = [];
                foreach ($docs as $doc) {
                    if (!$doc['requirement_label'] || !isset($latestByLabel[$doc['requirement_label']])) {
                        $latestByLabel[$doc['requirement_label']] = $doc;
                    }
                }
                $verifiedLabels = array_keys(array_filter($latestByLabel, fn($d) => $d['verified']));
                $missing = array_diff($requirementsList, $verifiedLabels);
                if (!empty($missing)) {
                    respondAjaxOrRedirect($isAjax, false, 'The following required document(s) still need to be uploaded and verified before approving: ' . implode(', ', $missing) . '.', $redirectUrl);
                }
            } else {
                // Legacy appointment (uploaded before per-requirement tracking existed) —
                // keep the original, looser "at least one verified document" check.
                $verifiedCount = count(array_filter($docs, fn($d) => $d['verified']));
                if ($verifiedCount === 0) {
                    respondAjaxOrRedirect($isAjax, false, 'This service requires documents (' . $svc['requirements'] . '). Please verify at least one uploaded document before approving, or contact the parishioner to submit them.', $redirectUrl);
                }
            }
        }

        db()->prepare("UPDATE appointments SET status_id = 2, approved_by = ?, approved_at = NOW() WHERE appointment_id = ?")
            ->execute([$userId, $id]);
        $stmt = db()->prepare("SELECT parishioner_id FROM appointments WHERE appointment_id = ?");
        $stmt->execute([$id]);
        $parId = $stmt->fetchColumn();
        $stmt = db()->prepare("SELECT user_id FROM parishioners WHERE parishioner_id = ?");
        $stmt->execute([$parId]);
        $puid = $stmt->fetchColumn();
        db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Approved', ?)")
            ->execute([$puid, "Your appointment #$id has been approved. Please proceed with payment."]);
        logActivity($userId, "Approved appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Appointment approved. The parishioner may now proceed to payment.', $redirectUrl);
    } elseif ($action === 'reject') {
        $selectedReason = trim($_POST['rejection_reason'] ?? '');
        $allowedReasons = ['Schedule Conflict', 'Incomplete Documents', 'Requirements Not Met', 'Other'];
        if (!in_array($selectedReason, $allowedReasons, true)) {
            respondAjaxOrRedirect($isAjax, false, 'Please select a valid reason for rejecting this appointment.', $redirectUrl);
        }
        $reason = $selectedReason;
        if ($selectedReason === 'Other') {
            $reason = trim($_POST['custom_rejection_reason'] ?? '');
            if ($reason === '') {
                respondAjaxOrRedirect($isAjax, false, 'Please specify the reason for rejecting this appointment.', $redirectUrl);
            }
            if (mb_strlen($reason) > 500) {
                respondAjaxOrRedirect($isAjax, false, 'The custom rejection reason must be 500 characters or fewer.', $redirectUrl);
            }
        }

        db()->prepare("UPDATE appointments SET status_id = 3, rejection_reason = ? WHERE appointment_id = ?")
            ->execute([$reason, $id]);
        $stmt = db()->prepare("SELECT parishioner_id FROM appointments WHERE appointment_id = ?");
        $stmt->execute([$id]);
        $parId = $stmt->fetchColumn();
        $stmt = db()->prepare("SELECT user_id FROM parishioners WHERE parishioner_id = ?");
        $stmt->execute([$parId]);
        $puid = $stmt->fetchColumn();
        db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Rejected', ?)")
            ->execute([$puid, "Your appointment #$id was not approved. Reason: $reason"]);
        logActivity($userId, "Rejected appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Appointment rejected.', $redirectUrl);
    } elseif ($action === 'assign_priest') {
        $priestId = $_POST['priest_id'];

        $stmt = db()->prepare(
            "SELECT appointment_date, appointment_time FROM appointments WHERE appointment_id = ?"
        );
        $stmt->execute([$id]);
        $slot = $stmt->fetch();

        $availability = priestIsAvailable((int) $priestId, $slot['appointment_date'], $slot['appointment_time'], $id);

        if (!$availability['available']) {
            respondAjaxOrRedirect($isAjax, false, $availability['reason'], $redirectUrl);
        }
        db()->prepare("UPDATE appointments SET priest_id = ? WHERE appointment_id = ?")
            ->execute([$priestId, $id]);
        logActivity($userId, "Assigned priest to appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Priest assigned.', $redirectUrl);
    } elseif ($action === 'reschedule') {
        $massCheck = db()->prepare('SELECT s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?');
        $massCheck->execute([$id]);
        if ($massCheck->fetchColumn() === 'Mass Intention') {
            respondAjaxOrRedirect($isAjax, false, 'Mass Intentions are tied to scheduled Masses and cannot be rescheduled.', $redirectUrl);
        }
        $newDate = $_POST['appointment_date'];
        $newTime = $_POST['appointment_time'];

        $stmt = db()->prepare(
            "SELECT s.category, s.service_id, a.schedule_type FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$id]);
        $apptInfo = $stmt->fetch();
        $category = $apptInfo['category'];

        $check = validateBooking($category, $newDate, $newTime, null, $apptInfo['schedule_type'], (int) $apptInfo['service_id']);
        if (!$check['valid']) {
            respondAjaxOrRedirect($isAjax, false, $check['message'], $redirectUrl);
        }
        if (serviceSlotIsBooked((int) $apptInfo['service_id'], $newDate, $check['forcedTime'] ?? $newTime, $id)) {
            respondAjaxOrRedirect($isAjax, false, 'That exact date and time is already booked for this service. Please choose another slot.', $redirectUrl);
        }
        $finalTime = $check['forcedTime'] ?? $newTime;
        db()->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ? WHERE appointment_id = ?")
            ->execute([$newDate, $finalTime, $id]);
        logActivity($userId, "Rescheduled appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Schedule updated.', $redirectUrl);
    } elseif ($action === 'confirm') {
        // Mass Intention/Donation payment confirmation is the Cashier's
        // responsibility (see treasurer/payment-detail.php) — Secretary no
        // longer manages payments for these two categories.
        $stmt = db()->prepare("SELECT s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?");
        $stmt->execute([$id]);
        if (in_array($stmt->fetchColumn(), ['Mass Intention', 'Donation'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'Payment confirmation for Mass Intentions and Donations is handled by the Cashier.', $redirectUrl);
        }

        db()->prepare("UPDATE appointments SET status_id = 5 WHERE appointment_id = ?")->execute([$id]);
        $stmt = db()->prepare("SELECT parishioner_id FROM appointments WHERE appointment_id = ?");
        $stmt->execute([$id]);
        $parId = $stmt->fetchColumn();
        $stmt = db()->prepare("SELECT user_id FROM parishioners WHERE parishioner_id = ?");
        $stmt->execute([$parId]);
        $puid = $stmt->fetchColumn();
        db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Confirmed', ?)")
            ->execute([$puid, "Your appointment #$id is confirmed. We look forward to seeing you."]);
        logActivity($userId, "Confirmed appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Appointment confirmed.', $redirectUrl);
    } elseif ($action === 'complete') {
        db()->prepare("UPDATE appointments SET status_id = 6 WHERE appointment_id = ?")->execute([$id]);
        logActivity($userId, "Marked appointment #$id as completed", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Appointment marked as completed.', $redirectUrl);
    }
    redirect($redirectUrl);
}

$stmt = db()->prepare(
    "SELECT a.*, s.service_name, s.fee, s.category, s.requirements, u.firstname, u.lastname, u.email, u.phone, st.status_name
     FROM appointments a
     JOIN services s ON a.service_id = s.service_id
     JOIN parishioners par ON a.parishioner_id = par.parishioner_id
     JOIN users u ON par.user_id = u.user_id
     JOIN appointment_status st ON a.status_id = st.status_id
     WHERE a.appointment_id = ?"
);
$stmt->execute([$id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    if ($isAjax) {
        http_response_code(404);
        echo '<p class="text-muted">Appointment not found.</p>';
        exit;
    }
    flash('error', 'Appointment not found.');
    redirect(url('secretary/appointments.php'));
}

$priests = db()->query("SELECT * FROM priests WHERE status = 'active'")->fetchAll();
$priestSchedules = [];
$priestUnavailability = [];
$stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
$stmt->execute([$id]);
$intention = $stmt->fetch() ?: null;
$stmt = db()->prepare('SELECT * FROM donations WHERE appointment_id = ?');
$stmt->execute([$id]);
$donation = $stmt->fetch() ?: null;
$stmt = db()->prepare("SELECT * FROM uploaded_documents WHERE appointment_id = ? AND superseded_by IS NULL AND (document_source IS NULL OR document_source <> 'generated') ORDER BY uploaded_at DESC, document_id DESC");
$stmt->execute([$id]);
$documents = $stmt->fetchAll();
$generatedForms = [];
if (($appointment['category'] ?? '') === 'Wedding') {
    $stmt = db()->prepare("SELECT g.*, d.document_id, d.file_name, d.review_status, d.rejection_reason FROM generated_wedding_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.appointment_id = ? ORDER BY g.form_type");
    $stmt->execute([$id]);
    $generatedForms = $stmt->fetchAll();
} elseif (($appointment['category'] ?? '') === 'Baptism') {
    $stmt = db()->prepare("SELECT g.*, d.document_id, d.file_name, d.review_status, d.rejection_reason FROM generated_baptism_forms g LEFT JOIN uploaded_documents d ON d.document_id = g.document_id WHERE g.appointment_id = ? ORDER BY g.form_type");
    $stmt->execute([$id]);
    $generatedForms = $stmt->fetchAll();
}

// Each priest's upcoming schedule and declared unavailability, so the
// secretary can check availability before assigning — directly at the
// point of decision.
$active = 'appointments';
$pageTitle = 'Appointment #' . $appointment['appointment_id'];
if (!$isAjax) {
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/dash-start.php';
}
?>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap: 22px;" class="detail-grid">
  <div class="card">
    <div class="card-header">
      <h3><?= e($appointment['service_name']) ?></h3>
      <div class="flex gap-2">
        <?php if ($appointment['schedule_type']): ?>
          <span class="badge badge-<?= strtolower($appointment['schedule_type']) ?>"><?= e($appointment['schedule_type']) ?></span>
        <?php endif; ?>
        <?php if ($appointment['category'] === 'Mass Intention' || ($isSecretaryViewer && $appointment['category'] === 'Donation')): ?>
          <?php
            // Mass Intentions use the shared Payment Required / Pending Cashier
            // Verification / Approved / Rejected wording (the Secretary only
            // sees approved-or-not); the Secretary never sees the Cashier's
            // payment-lifecycle detail for Donations either.
            $displayStatus = $appointment['category'] === 'Mass Intention'
                ? massIntentionStatusDisplay($appointment['status_name'], true, $isSecretaryViewer)
                : match ($appointment['status_name']) {
                    'Rejected' => ['Rejected', 'rejected'],
                    'Cancelled' => ['Cancelled', 'cancelled'],
                    'Completed' => ['Completed', 'completed'],
                    default => ['Scheduled', 'approved'],
                };
          ?>
          <span class="badge badge-<?= $displayStatus[1] ?>"><?= $displayStatus[0] ?></span>
        <?php else: ?>
          <span class="badge badge-<?= badgeClass($appointment['status_name']) ?>"><?= e($appointment['status_name']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($appointment['guest_name']): ?>
      <p><strong>Guest:</strong> <?= e($appointment['guest_name']) ?> (<?= e($appointment['guest_phone']) ?><?= $appointment['guest_email'] ? ', ' . e($appointment['guest_email']) : '' ?>) — Ref <?= e($appointment['guest_reference']) ?></p>
    <?php else: ?>
      <p><strong>Parishioner:</strong> <?= e($appointment['firstname']) ?> <?= e($appointment['lastname']) ?> (<?= e($appointment['email']) ?>, <?= e($appointment['phone']) ?>)</p>
    <?php endif; ?>
    <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?> at <?= date('g:i A', strtotime($appointment['appointment_time'])) ?></p>
    <p><strong>Fee:</strong>
      <?php if ($appointment['pss_classification'] === 'pending_verification'): ?>Fee pending PSS verification
      <?php elseif (!empty($appointment['fee_snapshot'])): ?><?= feeLabel((float) (json_decode($appointment['fee_snapshot'], true)['total'] ?? 0)) ?>
      <?php else: ?><?= feeLabel((float) $appointment['fee']) ?><?php endif; ?>
    </p>
    <?php if (in_array($appointment['category'], ['Baptism', 'Wedding', 'Funeral'], true)): ?>
      <p><strong>PSS Classification:</strong> <?= e($appointment['pss_classification'] === 'pending_verification' ? 'Pending Verification' : ucfirst(str_replace('_', ' ', $appointment['pss_classification']))) ?></p>
      <?php if ($appointment['category'] === 'Baptism'): ?><p><strong>Sponsors:</strong> <?= (int) $appointment['sponsor_count'] ?></p><?php endif; ?>
      <?php if ($appointment['category'] === 'Wedding'): ?><p><strong>Individual Sponsors:</strong> <?= (int) $appointment['wedding_sponsor_count'] ?> (<?= (int) ceil((int) $appointment['wedding_sponsor_count'] / 2) ?> pairs)</p><?php endif; ?>
      <?php if (!empty($appointment['fee_snapshot'])): $feeSnapshot = json_decode($appointment['fee_snapshot'], true) ?: []; ?>
        <div class="alert" style="background:var(--cream); border:1px solid var(--cream-dark);">
          <strong>Fee Calculation</strong><br>
          Base Fee: <?= feeLabel((float) ($feeSnapshot['base_fee'] ?? 0)) ?><br>
          Priest Stipend: <?= feeLabel((float) ($feeSnapshot['priest_stipend'] ?? 0)) ?><br>
          Additional Sponsor Fee: <?= feeLabel((float) ($feeSnapshot['additional_sponsor_fee'] ?? 0)) ?><br>
          <strong>Total: <?= feeLabel((float) ($feeSnapshot['total'] ?? 0)) ?></strong>
        </div>
      <?php endif; ?>
      <?php if ($appointment['pss_classification'] === 'pending_verification'): ?>
        <form method="POST" class="card" style="background:var(--cream); margin:16px 0;">
          <?= csrfField() ?><input type="hidden" name="action" value="verify_pss">
          <h4 style="margin-top:0;">Verify PSS Classification</h4>
          <p class="text-muted">Applicant claim: <?= e($appointment['pss_claim'] ? ($appointment['pss_claim'] === 'pss' ? 'PSS Giver' : 'Non-PSS Giver') : 'Not provided') ?></p>
          <label for="pss_classification">Secretary verification</label>
          <select name="pss_classification" id="pss_classification" required>
            <option value="">-- Select --</option><option value="pss">PSS Giver</option><option value="non_pss">Non-PSS Giver</option>
          </select>
          <button class="btn btn-primary btn-sm" type="submit" style="margin-top:10px;">Save Classification &amp; Calculate Fee</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($appointment['category'] === 'Funeral' && $appointment['date_of_death']): ?>
      <p><strong>Date of Death:</strong> <?= formatDate($appointment['date_of_death']) ?> <span class="text-muted">(9-day mourning period ends <?= formatDate(date('Y-m-d', strtotime($appointment['date_of_death'] . ' +9 days'))) ?>)</span></p>
    <?php endif; ?>
    <?php if (!in_array($appointment['category'], ['Mass Intention', 'Donation'], true) && $appointment['requirements']): ?>
      <p><strong>Required Documents:</strong> <?= e($appointment['requirements']) ?></p>
    <?php endif; ?>
    <?php if ($appointment['remarks']): ?><p><strong>Remarks:</strong> <?= e($appointment['remarks']) ?></p><?php endif; ?>
    <?php if (!empty($appointment['location_address'])): ?>
      <p><strong>Address to Bless:</strong> <?= nl2br(e($appointment['location_address'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($appointment['contact_phone'])): ?>
      <p><strong>Contact Phone:</strong> <?= e($appointment['contact_phone']) ?></p>
    <?php endif; ?>
    <?php if ($appointment['rejection_reason']): ?><p><strong>Rejection Reason:</strong> <?= e($appointment['rejection_reason']) ?></p><?php endif; ?>
    <?php if ($appointment['cancelled_reason']): ?><p><strong>Cancellation Reason:</strong> <?= e($appointment['cancelled_reason']) ?></p><?php endif; ?>

    <?php if ($intention): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Mass Intention Details</h4>
      <p><strong>Type:</strong> <?= e($intention['intention_type']) ?></p>
      <p><strong>Offerer:</strong> <?= e($intention['offerer_name']) ?></p>
      <p><strong>Intention For:</strong> <?= e($intention['intention_for']) ?></p>
    <?php endif; ?>

    <?php if ($donation): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Donation Details</h4>
      <p><strong>Donor:</strong> <?= e($donation['donor_name'] ?: 'Anonymous') ?></p>
      <?php if ($donation['donor_email']): ?><p><strong>Email:</strong> <?= e($donation['donor_email']) ?></p><?php endif; ?>
      <p><strong>Purpose:</strong> <?= e($donation['purpose']) ?></p>
      <?php if ($donation['message']): ?><p><strong>Message:</strong> <?= e($donation['message']) ?></p><?php endif; ?>
    <?php endif; ?>

    <?php if (!in_array($appointment['category'], ['Mass Intention', 'Donation'], true)): ?>
    <?php if ($appointment['category'] === 'Wedding'): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Wedding Generated Forms</h4>
      <?php foreach ($generatedForms as $gf): $formStatus = $gf['review_status'] ?? 'pending'; ?>
        <div class="card" style="background:var(--cream); margin:10px 0;">
          <strong><?= e(weddingFormDefinition($gf['form_type'])['title']) ?></strong>
          <span class="badge badge-<?= $formStatus === 'approved' ? 'verified' : ($formStatus === 'rejected' ? 'rejected' : 'pending') ?>"><?= $formStatus === 'approved' ? 'Approved' : ($formStatus === 'rejected' ? 'Needs Revision' : 'Pending Review') ?></span>
          <?php if ($gf['document_id']): ?><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>">View PDF</a><?php endif; ?>
          <?php if ($gf['rejection_reason']): ?><p class="text-muted">Reason: <?= e($gf['rejection_reason']) ?></p><?php endif; ?>
          <?php if ($gf['document_id'] && $formStatus === 'pending'): ?>
            <form method="POST" style="display:inline;"> <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= (int) $gf['document_id'] ?>"><button class="btn btn-outline btn-sm">Approve</button></form>
            <form method="POST" style="margin-top:8px;"><?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= (int) $gf['document_id'] ?>"><textarea name="document_rejection_reason" required maxlength="1000" placeholder="Reason for rejection"></textarea><button class="btn btn-danger btn-sm">Reject</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$generatedForms): ?><p class="text-muted">No generated Wedding forms have been submitted.</p><?php endif; ?>
    <?php elseif ($appointment['category'] === 'Baptism'): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Baptism Generated Forms</h4>
      <?php $baptismTitles = ['katin_awan_bunyag' => 'KATIN-AWAN SA BUNYAG', 'cluster_clearance_baptism_sponsor' => 'CLUSTER CLEARANCE FOR BAPTISM SPONSOR']; ?>
      <?php foreach ($generatedForms as $gf): $formStatus = $gf['review_status'] ?? 'pending'; ?>
        <div class="card" style="background:var(--cream); margin:10px 0;">
          <strong><?= e($baptismTitles[$gf['form_type']] ?? $gf['form_type']) ?></strong>
          <span class="badge badge-<?= $formStatus === 'approved' ? 'verified' : ($formStatus === 'rejected' ? 'rejected' : 'pending') ?>"><?= $formStatus === 'approved' ? 'Approved' : ($formStatus === 'rejected' ? 'Needs Revision' : 'Pending Review') ?></span>
          <?php if ($gf['document_id']): ?><a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>">View PDF</a><a class="btn btn-outline btn-sm" href="<?= url('document.php?id=' . (int) $gf['document_id'] . '&download=1') ?>">Download PDF</a><?php endif; ?>
          <?php if ($gf['rejection_reason']): ?><p class="text-muted">Reason: <?= e($gf['rejection_reason']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$generatedForms): ?><p class="text-muted">No generated Baptism forms have been submitted.</p><?php endif; ?>
    <?php endif; ?>
    <hr style="border-color: var(--cream-dark); margin: 18px 0;">
    <h4>Uploaded Documents</h4>
    <?php
      $requirementsList = !empty($appointment['requirements_snapshot']) ? (json_decode($appointment['requirements_snapshot'], true) ?: []) : parseRequirementsList($appointment['requirements']);
      if (!empty($requirementsList)):
        $verifiedLabels = array_column(array_filter($documents, fn($d) => ($d['review_status'] ?? ($d['verified'] ? 'approved' : 'pending')) === 'approved'), 'requirement_label');
        $uploadedLabels = array_column($documents, 'requirement_label');
    ?>
      <div class="flex gap-2" style="flex-wrap:wrap; margin-bottom:12px;">
        <?php foreach ($requirementsList as $label): ?>
          <?php if (in_array($label, $verifiedLabels, true)): ?>
            <span class="badge badge-verified"><?= e($label) ?>: Verified</span>
          <?php elseif (in_array($label, $uploadedLabels, true)): ?>
            <span class="badge badge-pending"><?= e($label) ?>: Pending</span>
          <?php else: ?>
            <span class="badge badge-rejected"><?= e($label) ?>: Missing</span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if (empty($documents)): ?><p class="text-muted">No documents uploaded yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>File</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($documents as $d): ?>
              <tr>
                <td>
                  <a href="<?= url('document.php?id=' . (int) $d['document_id']) ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:10px;">
                    <?php if (isImageFile($d['file_name'])): ?>
                      <img src="<?= url('document.php?id=' . (int) $d['document_id']) ?>" alt="<?= e($d['file_name']) ?>" style="width:44px; height:44px; object-fit:cover; border-radius:6px; border:1px solid var(--cream-dark);">
                    <?php else: ?>
                      <span style="display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; background:var(--cream); border-radius:6px; font-size:18px;">📄</span>
                    <?php endif; ?>
                    <span><?= e($d['file_name']) ?></span>
                  </a>
                </td>
                <?php $docStatus = $d['review_status'] ?? ($d['verified'] ? 'approved' : 'pending'); ?>
                <td><?= $docStatus === 'approved' ? '<span class="badge badge-verified">Approved</span>' : ($docStatus === 'rejected' ? '<span class="badge badge-rejected">Needs Replacement</span>' : '<span class="badge badge-pending">Pending Review</span>') ?></td>
                <td>
                  <?php if ($docStatus === 'pending' && $d['superseded_by'] === null): ?>
                    <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" style="display:inline;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="verify_document">
                      <input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                      <button type="submit" class="btn btn-outline btn-sm">Mark Verified</button>
                    </form>
                    <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" style="margin-top:6px;">
                      <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= (int) $d['document_id'] ?>">
                      <textarea name="document_rejection_reason" rows="2" required maxlength="1000" placeholder="Reason for rejection"></textarea>
                      <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><h3>Actions</h3></div>
      <?php if ($appointment['status_name'] === 'Pending' && in_array($appointment['category'], ['Mass Intention', 'Donation'], true)): ?>
        <p class="text-muted">This request is approved automatically — no action needed.</p>
      <?php elseif ($appointment['status_name'] === 'Pending'): ?>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approve">
          <button type="submit" class="btn btn-success btn-block">✔ Approve</button>
        </form>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" id="rejectForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reject">
          <div class="form-group">
            <label for="rejectionReasonSelect">Reason for Rejection</label>
            <select name="rejection_reason" id="rejectionReasonSelect" required>
              <option value="">Select a reason</option>
              <option value="Schedule Conflict">Schedule Conflict</option>
              <option value="Incomplete Documents">Incomplete Documents</option>
              <option value="Requirements Not Met">Requirements Not Met</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="form-group" id="customRejectionReasonGroup" style="display:none;">
            <label for="customRejectionReason">Please Specify Reason</label>
            <textarea name="custom_rejection_reason" id="customRejectionReason" rows="3" maxlength="500" placeholder="Enter the reason for rejection..."></textarea>
          </div>
          <button type="button" class="btn btn-danger btn-block" id="openRejectConfirmBtn">&#10006; Reject</button>
        </form>
      <?php elseif ($appointment['status_name'] === 'Payment Verified' && in_array($appointment['category'], ['Mass Intention', 'Donation'], true)): ?>
        <p class="text-muted">This request is being finalized by the Cashier — no action needed here.</p>
      <?php elseif ($appointment['status_name'] === 'Payment Verified'): ?>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="confirm">
          <button type="submit" class="btn btn-success btn-block">✔ Confirm Appointment</button>
        </form>
      <?php elseif ($appointment['status_name'] === 'Confirmed'): ?>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="complete">
          <button type="submit" class="btn btn-dark btn-block">Mark as Completed</button>
        </form>
      <?php else: ?>
        <p class="text-muted">No pending actions for this status.</p>
      <?php endif; ?>
    </div>

    <?php if (!in_array($appointment['category'], ['Mass Intention', 'Donation'], true)): ?>
    <?php
      $priestAlreadySet = !empty($appointment['priest_id']);
      $assignedPriest = null;
      if ($priestAlreadySet) {
          foreach ($priests as $p) {
              if ((int) $p['priest_id'] === (int) $appointment['priest_id']) { $assignedPriest = $p; break; }
          }
          if (!$assignedPriest) {
              // Assigned priest is no longer active — look them up anyway so the name still shows correctly.
              $stmt = db()->prepare('SELECT * FROM priests WHERE priest_id = ?');
              $stmt->execute([$appointment['priest_id']]);
              $assignedPriest = $stmt->fetch() ?: null;
          }
      }
    ?>
    <div class="card">
      <div class="card-header"><h3><?= $priestAlreadySet ? 'Priest Assignment' : 'Assign Priest' ?></h3></div>

      <?php if ($priestAlreadySet): ?>
        <!-- The parishioner already picked (and the system already checked
             availability for) a priest at booking time — no manual
             assignment step is needed. This form stays available only for
             an authorized emergency reassignment (e.g. the assigned priest
             becomes unavailable), never as a required step. -->
        <p style="margin-top:0;"><strong>Currently assigned:</strong> <?= $assignedPriest ? e($assignedPriest['title'] . ' ' . $assignedPriest['full_name']) : 'Not yet assigned' ?></p>
        <details>
          <summary class="text-muted" style="cursor:pointer; font-size:13px;">Reassign priest (emergency only)</summary>
          <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-3 mt-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="assign_priest">
            <div class="form-group">
              <select name="priest_id" required>
                <option value="">-- Select Priest --</option>
                <?php foreach ($priests as $p): ?>
                  <option value="<?= $p['priest_id'] ?>" <?= $appointment['priest_id'] == $p['priest_id'] ? 'selected' : '' ?>><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-outline btn-block">Reassign</button>
          </form>
        </details>
      <?php else: ?>
        <p class="helper-text" style="margin-top:-6px;">The parishioner didn't request a specific priest — please assign one.</p>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="assign_priest">
          <div class="form-group">
            <select name="priest_id" required>
              <option value="">-- Select Priest --</option>
              <?php foreach ($priests as $p): ?>
                <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-outline btn-block">Assign</button>
        </form>
      <?php endif; ?>

      <details>
        <summary class="text-muted" style="cursor:pointer; font-size:13px;">Priest Availability</summary>
        <div class="priest-availability-list">
          <?php foreach ($priests as $p): ?>
            <div class="priest-availability-row">
              <div class="priest-availability-info"><strong><?= e($p['full_name']) ?></strong><span class="priest-availability-role"><?= e($p['title'] ?: 'Priest') ?></span><span class="badge badge-approved priest-availability-status">Active</span></div>
              <button type="button" class="btn btn-outline btn-sm js-view-priest-schedule" data-priest-id="<?= (int) $p['priest_id'] ?>" data-priest-name="<?= e($p['title'] . ' ' . $p['full_name']) ?>" data-schedule-url="<?= e(url('secretary/priest-schedule.php')) ?>">View Schedule</button>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="display:none;">
        <?php foreach ($priests as $p): ?>
          <div class="mb-2 mt-2" style="font-size:12.5px; border-bottom: 1px solid var(--cream-dark); padding-bottom:6px;">
            <strong><?= e($p['title']) ?> <?= e($p['full_name']) ?></strong>
            <?php if (empty($priestSchedules[$p['priest_id']])): ?>
              <span class="text-muted"> — no upcoming appointments</span>
            <?php else: ?>
              <ul style="margin: 4px 0 0 18px; padding: 0;">
                <?php foreach ($priestSchedules[$p['priest_id']] as $sched): ?>
                  <li><?= formatDate($sched['appointment_date']) ?> at <?= date('g:i A', strtotime($sched['appointment_time'])) ?> — <?= e($sched['service_name']) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if (!empty($priestUnavailability[$p['priest_id']])): ?>
              <ul style="margin: 4px 0 0 18px; padding: 0; color: var(--danger);">
                <?php foreach ($priestUnavailability[$p['priest_id']] as $u): ?>
                  <li>Unavailable <?= formatDate($u['unavailable_date']) ?><?= $u['start_time'] ? ' (' . date('g:i A', strtotime($u['start_time'])) . '–' . date('g:i A', strtotime($u['end_time'])) . ')' : ' (whole day)' ?><?= $u['reason'] ? ' — ' . e($u['reason']) : '' ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>

    <dialog class="modal modal-lg priest-schedule-modal" id="priestScheduleModal">
      <div class="modal-head">
        <h3>Priest Schedule</h3>
        <button type="button" class="modal-close js-close-priest-schedule" aria-label="Close">✕</button>
      </div>
      <div class="modal-body" id="priestScheduleModalBody">
        <p class="text-muted">Select View Schedule to load appointments.</p>
      </div>
    </dialog>

    <?php if ($appointment['category'] !== 'Mass Intention'): ?>
    <div class="card">
      <div class="card-header"><h3>Reschedule</h3></div>
      <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reschedule">
        <div class="form-group">
          <label>New Date</label>
          <input type="date" name="appointment_date" required>
        </div>
        <div class="form-group">
          <label>New Time</label>
          <input type="time" name="appointment_time" required>
        </div>
        <p class="helper-text">Fixed-schedule services (Baptism, Wedding, Funeral) will still be checked against parish scheduling rules.</p>
        <button type="submit" class="btn btn-outline btn-block">Move Schedule</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<dialog id="rejectApptModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Reject Appointment?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to reject this appointment?</p>
  <p><strong>Reason:</strong> <span id="rejectConfirmReason"></span></p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('rejectApptModal').close()">Cancel</button>
    <button type="button" class="btn btn-danger" onclick="document.getElementById('rejectApptModal').close(); document.getElementById('rejectForm').submit();">Yes, Reject</button>
  </div>
</dialog>

<script>
(function () {
  var select = document.getElementById('rejectionReasonSelect');
  var customGroup = document.getElementById('customRejectionReasonGroup');
  var customField = document.getElementById('customRejectionReason');
  var openButton = document.getElementById('openRejectConfirmBtn');
  var confirmReason = document.getElementById('rejectConfirmReason');
  var modal = document.getElementById('rejectApptModal');
  if (!select || !openButton || !modal) return;
  function updateReasonField() {
    var isOther = select.value === 'Other';
    customGroup.style.display = isOther ? '' : 'none';
    customField.required = isOther;
    if (!isOther) customField.value = '';
  }
  select.addEventListener('change', updateReasonField);
  updateReasonField();
  openButton.addEventListener('click', function () {
    updateReasonField();
    if (!select.value) { select.setCustomValidity('Please select a reason for rejection.'); select.reportValidity(); select.setCustomValidity(''); return; }
    if (select.value === 'Other' && !customField.value.trim()) { customField.setCustomValidity('Please specify the reason for rejection.'); customField.reportValidity(); customField.setCustomValidity(''); return; }
    confirmReason.textContent = select.value === 'Other' ? customField.value.trim() : select.value;
    modal.showModal();
  });
})();
</script>

<?php if (!$isAjax): ?>
<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
