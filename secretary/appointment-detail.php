<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
require_once __DIR__ . '/../includes/service-fees.php';
require_once __DIR__ . '/../includes/wedding-forms.php';
require_once __DIR__ . '/../includes/wedding-draft.php';
require_once __DIR__ . '/../includes/baptism-forms.php';
require_once __DIR__ . '/../includes/appointment-workflow.php';
require_once __DIR__ . '/../includes/generated-form-workflow.php';
requireRole('Secretary', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$userId = currentUser()['user_id'];
$isSecretaryViewer = currentUser()['role_name'] === 'Secretary';
$isAjax = isDetailModalRequest();
$redirectUrl = url('secretary/appointment-detail.php?id=' . $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    $targetStmt = db()->prepare(
        'SELECT a.status_id, a.pss_classification, s.category
         FROM appointments a JOIN services s ON s.service_id = a.service_id
         WHERE a.appointment_id = ?'
    );
    $targetStmt->execute([$id]);
    $actionAppointment = $targetStmt->fetch();
    if (!$actionAppointment) {
        respondAjaxOrRedirect($isAjax, false, 'Appointment not found.', url('secretary/appointments.php'));
    }
    $currentStatusId = (int) $actionAppointment['status_id'];

    if ($action === 'verify_document') {
        if ($currentStatusId !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'Documents can only be reviewed while the appointment is pending.', $redirectUrl);
        }
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $statusLock = $pdo->prepare('SELECT status_id FROM appointments WHERE appointment_id = ? FOR UPDATE');
            $statusLock->execute([$id]);
            if ((int) $statusLock->fetchColumn() !== 1) {
                $pdo->rollBack();
                respondAjaxOrRedirect($isAjax, false, 'Documents can only be reviewed while the appointment is pending.', $redirectUrl);
            }
            $update = $pdo->prepare(
                "UPDATE uploaded_documents
                 SET review_status = 'approved', verified = TRUE, rejection_reason = NULL, reviewed_by = ?, reviewed_at = NOW()
                 WHERE document_id = ? AND appointment_id = ? AND review_status = 'pending' AND superseded_by IS NULL"
            );
            $update->execute([$userId, $documentId, $id]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                respondAjaxOrRedirect($isAjax, false, 'This document is no longer pending review.', $redirectUrl);
            }
            $pdo->prepare("UPDATE generated_forms SET status = CASE WHEN service_category = 'Funeral' THEN 'generated' ELSE 'approved' END, rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE document_id = ? AND appointment_id = ?")->execute([$documentId, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Document approval failed: ' . $e->getMessage());
            respondAjaxOrRedirect($isAjax, false, 'The document could not be approved. Please try again.', $redirectUrl);
        }
        logActivity($userId, "Verified a document for appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Document marked as verified.', $redirectUrl);
    }

    if ($action === 'reject_document') {
        $reason = trim($_POST['document_rejection_reason'] ?? '');
        if ($reason === '') {
            respondAjaxOrRedirect($isAjax, false, 'Please provide a reason for rejection.', $redirectUrl);
        }
        if (mb_strlen($reason) > 1000) {
            respondAjaxOrRedirect($isAjax, false, 'The rejection reason must be 1000 characters or fewer.', $redirectUrl);
        }
        if ($currentStatusId !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'Documents can only be reviewed while the appointment is pending.', $redirectUrl);
        }
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $statusLock = $pdo->prepare('SELECT status_id FROM appointments WHERE appointment_id = ? FOR UPDATE');
            $statusLock->execute([$id]);
            if ((int) $statusLock->fetchColumn() !== 1) {
                $pdo->rollBack();
                respondAjaxOrRedirect($isAjax, false, 'Documents can only be reviewed while the appointment is pending.', $redirectUrl);
            }
            $documentInfo = $pdo->prepare('SELECT requirement_label FROM uploaded_documents WHERE document_id = ? AND appointment_id = ? AND review_status = \'pending\' AND superseded_by IS NULL FOR UPDATE');
            $documentInfo->execute([$documentId, $id]);
            $documentLabel = trim((string) ($documentInfo->fetchColumn() ?: 'Uploaded document'));
            $update = $pdo->prepare(
                "UPDATE uploaded_documents
                 SET review_status = 'rejected', verified = FALSE, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW()
                 WHERE document_id = ? AND appointment_id = ? AND review_status = 'pending' AND superseded_by IS NULL"
            );
            $update->execute([$reason, $userId, $documentId, $id]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                respondAjaxOrRedirect($isAjax, false, 'This document is no longer pending review.', $redirectUrl);
            }
            $pdo->prepare("UPDATE generated_forms SET status = 'rejected', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE document_id = ? AND appointment_id = ?")->execute([$reason, $documentId, $id]);
            $recipient = $pdo->prepare('SELECT u.user_id FROM appointments a JOIN parishioners p ON p.parishioner_id = a.parishioner_id JOIN users u ON u.user_id = p.user_id WHERE a.appointment_id = ?');
            $recipient->execute([$id]);
            $parishionerUserId = $recipient->fetchColumn();
            if ($parishionerUserId) {
                $pdo->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Requirement Needs Revision', ?)")
                    ->execute([(int) $parishionerUserId, "The requirement '$documentLabel' for appointment #$id needs correction. Reason: $reason"]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Document rejection failed: ' . $e->getMessage());
            respondAjaxOrRedirect($isAjax, false, 'The document could not be rejected. Please try again.', $redirectUrl);
        }
        logActivity($userId, "Rejected a document for appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Document rejected with instructions.', $redirectUrl);
    }

    if ($action === 'verify_pss') {
        $stage = 'validate_request';
        $classification = $_POST['pss_classification'] ?? '';
        if (!in_array($classification, ['pss', 'non_pss'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'Please choose a valid PSS classification.', $redirectUrl);
        }
        $stage = 'load_appointment';
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT a.status_id, a.pss_classification, a.schedule_type, a.sponsor_count, a.wedding_sponsor_count, s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $feeAppointment = $stmt->fetch();
            if (!$feeAppointment || !in_array($feeAppointment['category'], ['Baptism', 'Wedding', 'Funeral'], true)) {
                throw new RuntimeException('This appointment does not use the PSS fee rules.');
            }
            if (!in_array((int) $feeAppointment['status_id'], [1, 2], true)
                || $feeAppointment['pss_classification'] !== 'pending_verification') {
                throw new RuntimeException('This appointment is no longer awaiting PSS verification.');
            }
            $stage = 'calculate_fee';
            $sponsors = $feeAppointment['category'] === 'Wedding' ? (int) $feeAppointment['wedding_sponsor_count'] : (int) $feeAppointment['sponsor_count'];
            $calculation = calculateServiceFee($feeAppointment['category'], $feeAppointment['schedule_type'], $classification, $sponsors);
            if (!$calculation) throw new RuntimeException('No fee rule is configured for this appointment.');
            $calculation['verified_by'] = $userId;
            $stage = 'update_appointment';
            $update = $pdo->prepare("UPDATE appointments SET pss_classification = ?, pss_verified_by = ?, pss_verified_at = NOW(), fee_snapshot = ? WHERE appointment_id = ? AND status_id IN (1, 2) AND pss_classification = 'pending_verification'");
            $update->execute([$classification, $userId, json_encode($calculation), $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('This appointment is no longer awaiting PSS verification.');
            }
            $stage = 'commit';
            $pdo->commit();
            logActivity($userId, "Verified PSS classification for appointment #$id as $classification", 'Appointments');
            respondAjaxOrRedirect($isAjax, true, 'PSS classification verified and fee calculated.', $redirectUrl);
        } catch (Throwable $e) {
            $rolledBack = false;
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                $rolledBack = true;
            }
            $sqlState = $e instanceof PDOException ? $e->getCode() : '';
            error_log(sprintf(
                'PSS classification verification failed: appointment_id=%d stage=%s exception=%s code=%s sqlstate=%s transaction_active=%s rolled_back=%s message=%s file=%s line=%d',
                $id,
                $stage,
                get_class($e),
                (string) $e->getCode(),
                (string) $sqlState,
                $pdo->inTransaction() ? 'yes' : 'no',
                $rolledBack ? 'yes' : 'no',
                preg_replace('/\s+/', ' ', $e->getMessage()),
                $e->getFile(),
                $e->getLine()
            ));
            respondAjaxOrRedirect($isAjax, false, 'Unable to verify the PSS classification.', $redirectUrl);
        }
    }

    if ($action === 'approve') {
        if ($currentStatusId !== 1 || in_array($actionAppointment['category'], ['Mass Intention', 'Donation'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'This appointment is no longer pending approval.', $redirectUrl);
        }

        // Lock the appointment while evaluating the same prerequisites shown
        // in the modal. This makes two near-simultaneous approval requests
        // converge on one canonical status transition.
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT a.*, s.category, s.requirements
                 FROM appointments a JOIN services s ON a.service_id = s.service_id
                 WHERE a.appointment_id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $lockedAppointment = $stmt->fetch();
            if (!$lockedAppointment || (int) $lockedAppointment['status_id'] !== 1) {
                throw new RuntimeException('This appointment is no longer pending approval.');
            }

            $eligibility = appointmentApprovalEligibility($lockedAppointment);
            if (!$eligibility['can_approve']) {
                $pdo->rollBack();
                respondAjaxOrRedirect(
                    $isAjax,
                    false,
                    'Cannot approve yet: ' . implode(' ', $eligibility['blocking_reasons']),
                    $redirectUrl
                );
            }

            $update = $pdo->prepare(
                'UPDATE appointments
                 SET status_id = 2, approved_by = ?, approved_at = NOW()
                 WHERE appointment_id = ? AND status_id = 1'
            );
            $update->execute([$userId, $id]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('This appointment is no longer pending approval.');
            }
            $parId = $lockedAppointment['parishioner_id'];
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'This appointment is no longer')) {
                respondAjaxOrRedirect($isAjax, false, $e->getMessage(), $redirectUrl);
            }
            error_log('Appointment approval failed: ' . $e->getMessage());
            respondAjaxOrRedirect($isAjax, false, 'The appointment could not be approved. Please try again.', $redirectUrl);
        }

        $stmt = db()->prepare('SELECT user_id FROM parishioners WHERE parishioner_id = ?');
        $stmt->execute([$parId]);
        $puid = $stmt->fetchColumn();
        db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Approved', ?)")
            ->execute([$puid, "Your appointment #$id has been approved. Please proceed with payment."]);
        logActivity($userId, "Approved appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Appointment approved. The parishioner may now proceed to payment.', $redirectUrl);
    } elseif ($action === 'reject') {
        if ($currentStatusId !== 1 || in_array($actionAppointment['category'], ['Mass Intention', 'Donation'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'This appointment is no longer pending review.', $redirectUrl);
        }
        $selectedReason = trim($_POST['rejection_reason'] ?? '');
        $allowedReasons = ['Unresolvable Schedule Conflict', 'Invalid or Ineligible Request', 'Duplicate Request', 'Other'];
        if (!in_array($selectedReason, $allowedReasons, true)) {
            respondAjaxOrRedirect($isAjax, false, 'Please select a valid reason for rejecting this appointment.', $redirectUrl);
        }
        $reason = $selectedReason;
        if ($selectedReason === 'Other') {
            $reason = trim($_POST['custom_rejection_reason'] ?? '');
            if ($reason === '') {
                respondAjaxOrRedirect($isAjax, false, 'Please provide a reason for rejection.', $redirectUrl);
            }
            if (mb_strlen($reason) > 500) {
                respondAjaxOrRedirect($isAjax, false, 'The custom rejection reason must be 500 characters or fewer.', $redirectUrl);
            }
        }

        $update = db()->prepare("UPDATE appointments SET status_id = 3, rejection_reason = ? WHERE appointment_id = ? AND status_id = 1");
        $update->execute([$reason, $id]);
        if ($update->rowCount() !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'This appointment is no longer pending review.', $redirectUrl);
        }
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
        if (!in_array($currentStatusId, [1, 2, 4, 5], true)
            || in_array($actionAppointment['category'], ['Mass Intention', 'Donation'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'A priest cannot be assigned at this appointment status.', $redirectUrl);
        }
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        if ($priestId < 1) {
            respondAjaxOrRedirect($isAjax, false, 'Please choose a valid priest.', $redirectUrl);
        }

        $stmt = db()->prepare(
            "SELECT appointment_date, appointment_time FROM appointments WHERE appointment_id = ?"
        );
        $stmt->execute([$id]);
        $slot = $stmt->fetch();

        if (!empty($slot['appointment_date']) && !empty($slot['appointment_time'])) {
            $availability = priestIsAvailable((int) $priestId, $slot['appointment_date'], $slot['appointment_time'], $id);
            if (!$availability['available']) {
                respondAjaxOrRedirect($isAjax, false, $availability['reason'], $redirectUrl);
            }
        } else {
            $activeCheck = db()->prepare("SELECT 1 FROM priests WHERE priest_id = ? AND status = 'active'");
            $activeCheck->execute([$priestId]);
            if (!$activeCheck->fetchColumn()) {
                respondAjaxOrRedirect($isAjax, false, 'Please choose an active priest.', $redirectUrl);
            }
        }
        $update = db()->prepare("UPDATE appointments SET priest_id = ? WHERE appointment_id = ? AND status_id IN (1, 2, 4, 5)");
        $update->execute([$priestId, $id]);
        if ($update->rowCount() !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'The priest assignment was not changed. Refresh and try again.', $redirectUrl);
        }
        logActivity($userId, "Assigned priest to appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Priest assigned.', $redirectUrl);
    } elseif ($action === 'reschedule') {
        if (!in_array($currentStatusId, [1, 2, 4, 5], true)
            || in_array($actionAppointment['category'], ['Mass Intention', 'Donation'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'This appointment can no longer be rescheduled.', $redirectUrl);
        }
        $massCheck = db()->prepare('SELECT s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?');
        $massCheck->execute([$id]);
        if ($massCheck->fetchColumn() === 'Mass Intention') {
            respondAjaxOrRedirect($isAjax, false, 'Mass Intentions are tied to scheduled Masses and cannot be rescheduled.', $redirectUrl);
        }
        $newDate = empty($_POST['appointment_date']) ? null : $_POST['appointment_date'];
        $newTime = empty($_POST['appointment_time']) ? null : $_POST['appointment_time'];

        $stmt = db()->prepare(
            "SELECT s.category, s.service_id, a.schedule_type, a.date_of_death FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$id]);
        $apptInfo = $stmt->fetch();
        $category = $apptInfo['category'];

        $check = validateBooking($category, $newDate, $newTime, $apptInfo['date_of_death'], $apptInfo['schedule_type'], (int) $apptInfo['service_id']);
        if (!$check['valid']) {
            respondAjaxOrRedirect($isAjax, false, $check['message'], $redirectUrl);
        }
        if (serviceSlotIsBooked((int) $apptInfo['service_id'], $newDate, $check['forcedTime'] ?? $newTime, $id)) {
            respondAjaxOrRedirect($isAjax, false, 'That exact date and time is already booked for this service. Please choose another slot.', $redirectUrl);
        }
        $finalTime = $check['forcedTime'] ?? $newTime;
        $update = db()->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ? WHERE appointment_id = ? AND status_id IN (1, 2, 4, 5)");
        $update->execute([$newDate, $finalTime, $id]);
        if ($update->rowCount() !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'The schedule was not changed. Refresh and try again.', $redirectUrl);
        }
        logActivity($userId, "Rescheduled appointment #$id", 'Appointments');
        respondAjaxOrRedirect($isAjax, true, 'Schedule updated.', $redirectUrl);
    } elseif ($action === 'confirm') {
        if ($currentStatusId !== 4) {
            respondAjaxOrRedirect($isAjax, false, 'Only a payment-verified appointment can be confirmed.', $redirectUrl);
        }
        // Mass Intention/Donation payment confirmation is the Cashier's
        // responsibility (see treasurer/payment-detail.php) — Secretary no
        // longer manages payments for these two categories.
        $stmt = db()->prepare("SELECT s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?");
        $stmt->execute([$id]);
        if (in_array($stmt->fetchColumn(), ['Mass Intention', 'Donation'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'Payment confirmation for Mass Intentions and Donations is handled by the Cashier.', $redirectUrl);
        }

        $update = db()->prepare("UPDATE appointments SET status_id = 5 WHERE appointment_id = ? AND status_id = 4");
        $update->execute([$id]);
        if ($update->rowCount() !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'This appointment is no longer awaiting confirmation.', $redirectUrl);
        }
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
        if ($currentStatusId !== 5) {
            respondAjaxOrRedirect($isAjax, false, 'Only a confirmed appointment can be completed.', $redirectUrl);
        }
        $update = db()->prepare("UPDATE appointments SET status_id = 6 WHERE appointment_id = ? AND status_id = 5");
        $update->execute([$id]);
        if ($update->rowCount() !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'This appointment is no longer awaiting completion.', $redirectUrl);
        }
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
$generatedForms = appointmentGeneratedFormReviewRows(db(), $id, (string) ($appointment['category'] ?? ''));
$approvalEligibility = appointmentApprovalEligibility($appointment);

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

<div style="display:grid; grid-template-columns:minmax(0, 1.4fr) minmax(280px, 1fr); gap:22px;" class="detail-grid">
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
    <p class="appointment-reference"><strong>Booking Reference:</strong> <span><?= e($appointment['guest_reference'] ?: 'Appointment #' . (int) $appointment['appointment_id']) ?></span></p>
    <?php if ($appointment['guest_name']): ?>
      <p><strong>Guest:</strong> <?= e($appointment['guest_name']) ?> (<?= e($appointment['guest_phone']) ?><?= $appointment['guest_email'] ? ', ' . e($appointment['guest_email']) : '' ?>)</p>
    <?php else: ?>
      <p><strong>Parishioner:</strong> <?= e($appointment['firstname']) ?> <?= e($appointment['lastname']) ?> (<?= e($appointment['email']) ?>, <?= e($appointment['phone']) ?>)</p>
    <?php endif; ?>
    <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?><?= $appointment['appointment_time'] ? ' at ' . date('g:i A', strtotime($appointment['appointment_time'])) : ' (To be scheduled)' ?></p>
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
      <?php if ($appointment['pss_classification'] === 'pending_verification' && in_array((int) $appointment['status_id'], [1, 2], true)): ?>
        <form method="POST" action="<?= e($redirectUrl) ?>" class="card" style="background:var(--cream); margin:16px 0;">
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
    <?php if (!empty($appointment['requester_name'])): ?>
      <p><strong>Requested by:</strong> <?= e($appointment['requester_name']) ?></p>
    <?php endif; ?>
    <?php if (!empty($appointment['patient_name'])): ?>
      <p><strong>Sick Person's Name:</strong> <?= e($appointment['patient_name']) ?></p>
    <?php endif; ?>
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
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Requirements Review</h4>

      <?php
        $requirementsList = $appointment['category'] === 'Wedding'
            ? weddingDraftRequiredDocuments(['category' => 'Wedding'])
            : (!empty($appointment['requirements_snapshot']) ? (json_decode($appointment['requirements_snapshot'], true) ?: []) : parseRequirementsList($appointment['requirements']));
        $formTitles = [
            'marriage_application' => 'Marriage Requirement and Application Form',
            'katin_awan_kasal' => 'Katin-awan sa Kasal',
            'cluster_clearance_wedding_sponsor' => 'Cluster Clearance for Wedding Sponsor',
            'katin_awan_bunyag' => 'Katin-awan sa Bunyag',
            'cluster_clearance_baptism_sponsor' => 'Cluster Clearance for Baptism Sponsor',
            'katin_awan_paglubong' => 'Katin-awan sa Paglubong'
        ];
      ?>

      <h5 style="margin-top:16px; margin-bottom:10px; color:var(--brown-mid);">Supporting Documents</h5>
      <?php if (empty($requirementsList) && empty($documents)): ?>
        <p class="text-muted">No supporting documents are required or uploaded.</p>
      <?php else: ?>
        <div class="review-table-wrap">
          <table class="review-table">
            <thead><tr><th>Requirement</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php 
                $matchedDocIds = [];
                foreach ($requirementsList as $label): 
                  $docMatch = array_values(array_filter($documents, fn($d) => $d['requirement_label'] === $label));
                  $d = $docMatch[0] ?? null;
                  if ($d) $matchedDocIds[] = $d['document_id'];
                  $docStatus = $d ? ($d['review_status'] ?? (appointmentWorkflowBoolean($d['verified'] ?? false) ? 'approved' : 'pending')) : 'missing';
              ?>
                <tr>
                  <td data-label="Requirement">
                    <strong><?= e($label) ?></strong><br>
                    <?php if ($d): ?>
                      <a href="<?= url('document.php?id=' . (int) $d['document_id']) ?>" target="_blank" rel="noopener" style="font-size:0.9em; display:inline-flex; align-items:center; gap:4px; margin-top:4px;">📄 <?= e($d['file_name']) ?></a>
                    <?php else: ?>
                      <span class="text-muted" style="font-size:0.9em;">Not uploaded yet</span>
                    <?php endif; ?>
                    <?php if ($d && $d['rejection_reason']): ?><div class="text-muted" style="font-size:0.85em; margin-top:4px;">Reason: <?= e($d['rejection_reason']) ?></div><?php endif; ?>
                  </td>
                  <td data-label="Status">
                    <?php if ($docStatus === 'approved'): ?><span class="badge badge-verified">Approved</span>
                    <?php elseif ($docStatus === 'rejected'): ?><span class="badge badge-rejected">Needs Revision</span>
                    <?php elseif ($docStatus === 'missing'): ?><span class="badge badge-rejected">Missing</span>
                    <?php else: ?><span class="badge badge-pending">Pending Review</span><?php endif; ?>
                  </td>
                  <td data-label="Actions">
                    <div class="review-actions">
                    <?php if ($d && $docStatus === 'pending' && $d['superseded_by'] === null && (int) $appointment['status_id'] === 1): ?>
                      <form method="POST" action="<?= e($redirectUrl) ?>" class="review-approve-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                      </form>
                      <button type="button" class="btn btn-danger btn-sm js-open-rejection" data-target="rejectDocRow_<?= $d['document_id'] ?>">Reject</button>
                    <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($d && $docStatus === 'pending' && $d['superseded_by'] === null && (int) $appointment['status_id'] === 1): ?>
                  <tr id="rejectDocRow_<?= $d['document_id'] ?>" class="review-rejection-row" hidden>
                    <td colspan="3">
                      <form id="rejectDoc_<?= $d['document_id'] ?>" class="review-rejection-form" method="POST" action="<?= e($redirectUrl) ?>">
                        <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <label for="rejectDocReason_<?= $d['document_id'] ?>">Reason for rejection</label>
                        <textarea id="rejectDocReason_<?= $d['document_id'] ?>" name="document_rejection_reason" rows="4" maxlength="1000" placeholder="Explain what needs to be replaced or corrected." required></textarea>
                        <div class="review-rejection-buttons">
                          <button type="button" class="btn btn-outline btn-sm js-cancel-rejection">Cancel</button>
                          <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
              
              <?php foreach ($documents as $d): ?>
                <?php 
                  if (in_array($d['document_id'], $matchedDocIds, true)) continue;
                  $docStatus = $d['review_status'] ?? (appointmentWorkflowBoolean($d['verified'] ?? false) ? 'approved' : 'pending');
                ?>
                <tr>
                  <td data-label="Requirement">
                    <strong><?= e($d['requirement_label'] ?: 'Uploaded Document') ?></strong><br>
                    <a href="<?= url('document.php?id=' . (int) $d['document_id']) ?>" target="_blank" rel="noopener" style="font-size:0.9em; display:inline-flex; align-items:center; gap:4px; margin-top:4px;">📄 <?= e($d['file_name']) ?></a>
                    <?php if ($d['rejection_reason']): ?><div class="text-muted" style="font-size:0.85em; margin-top:4px;">Reason: <?= e($d['rejection_reason']) ?></div><?php endif; ?>
                  </td>
                  <td data-label="Status">
                    <?php if ($docStatus === 'approved'): ?><span class="badge badge-verified">Approved</span>
                    <?php elseif ($docStatus === 'rejected'): ?><span class="badge badge-rejected">Needs Revision</span>
                    <?php else: ?><span class="badge badge-pending">Pending Review</span><?php endif; ?>
                  </td>
                  <td data-label="Actions">
                    <div class="review-actions">
                    <?php if ($docStatus === 'pending' && $d['superseded_by'] === null && (int) $appointment['status_id'] === 1): ?>
                      <form method="POST" action="<?= e($redirectUrl) ?>" class="review-approve-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                      </form>
                      <button type="button" class="btn btn-danger btn-sm js-open-rejection" data-target="rejectDocRow_<?= $d['document_id'] ?>">Reject</button>
                    <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($docStatus === 'pending' && $d['superseded_by'] === null && (int) $appointment['status_id'] === 1): ?>
                  <tr id="rejectDocRow_<?= $d['document_id'] ?>" class="review-rejection-row" hidden>
                    <td colspan="3">
                      <form id="rejectDoc_<?= $d['document_id'] ?>" class="review-rejection-form" method="POST" action="<?= e($redirectUrl) ?>">
                        <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <label for="rejectDocReason_<?= $d['document_id'] ?>">Reason for rejection</label>
                        <textarea id="rejectDocReason_<?= $d['document_id'] ?>" name="document_rejection_reason" rows="4" maxlength="1000" placeholder="Explain what needs to be replaced or corrected." required></textarea>
                        <div class="review-rejection-buttons">
                          <button type="button" class="btn btn-outline btn-sm js-cancel-rejection">Cancel</button>
                          <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if (!empty($generatedForms) || $appointment['category'] === 'Funeral'): ?>
      <h5 style="margin-top:24px; margin-bottom:10px; color:var(--brown-mid);">Generated Forms</h5>
      <?php if (empty($generatedForms)): ?>
        <p class="text-muted">No generated forms have been submitted yet.</p>
      <?php else: ?>
        <div class="review-table-wrap">
          <table class="review-table">
            <thead><tr><th>Requirement</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($generatedForms as $gf): ?>
                <?php $formState = generatedFormWorkflowState($gf, $gf, false); $formStatus = $formState['code']; ?>
                <tr>
                  <td data-label="Requirement">
                    <strong><?= e(appointmentGeneratedFormTitle((string) $gf['form_type'])) ?></strong><br>
                    <?php if ($gf['document_id']): ?>
                      <a href="<?= url('document.php?id=' . (int) $gf['document_id']) ?>" target="_blank" rel="noopener" style="font-size:0.9em; display:inline-flex; align-items:center; gap:4px; margin-top:4px;">📄 View Generated PDF</a>
                    <?php else: ?>
                      <span class="text-muted" style="font-size:0.9em;">Not completed yet</span>
                    <?php endif; ?>
                    <div class="text-muted" style="font-size:0.85em; margin-top:4px;"><?= e($formState['description']) ?></div>
                    <?php if ($gf['rejection_reason']): ?><div class="text-muted" style="font-size:0.85em; margin-top:4px;">Reason: <?= e($gf['rejection_reason']) ?></div><?php endif; ?>
                  </td>
                  <td data-label="Status">
                    <?php if ($formStatus === 'approved'): ?><span class="badge badge-verified">Approved</span>
                    <?php elseif ($formStatus === 'needs_revision'): ?><span class="badge badge-rejected">Needs Revision</span>
                    <?php elseif ($formStatus === 'not_started'): ?><span class="badge badge-rejected">Missing</span>
                    <?php elseif ($formStatus === 'draft'): ?><span class="badge badge-pending">Draft saved</span>
                    <?php else: ?><span class="badge badge-pending">Pending Review</span><?php endif; ?>
                  </td>
                  <td data-label="Actions">
                    <div class="review-actions">
                    <?php if ($gf['document_id'] && $formStatus === 'pending_review' && (int) $appointment['status_id'] === 1): ?>
                      <form method="POST" action="<?= e($redirectUrl) ?>" class="review-approve-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= $gf['document_id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                      </form>
                      <button type="button" class="btn btn-danger btn-sm js-open-rejection" data-target="rejectFormRow_<?= $gf['document_id'] ?>">Reject</button>
                    <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($gf['document_id'] && $formStatus === 'pending_review' && (int) $appointment['status_id'] === 1): ?>
                  <tr id="rejectFormRow_<?= $gf['document_id'] ?>" class="review-rejection-row" hidden>
                    <td colspan="3">
                      <form id="rejectForm_<?= $gf['document_id'] ?>" class="review-rejection-form" method="POST" action="<?= e($redirectUrl) ?>">
                        <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= $gf['document_id'] ?>">
                        <label for="rejectFormReason_<?= $gf['document_id'] ?>">Reason for rejection</label>
                        <textarea id="rejectFormReason_<?= $gf['document_id'] ?>" name="document_rejection_reason" rows="4" maxlength="1000" placeholder="Explain what needs to be corrected in this form." required></textarea>
                        <div class="review-rejection-buttons">
                          <button type="button" class="btn btn-outline btn-sm js-cancel-rejection">Cancel</button>
                          <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><h3>Appointment Decision</h3></div>
      <?php if ($appointment['status_name'] === 'Pending' && in_array($appointment['category'], ['Mass Intention', 'Donation'], true)): ?>
        <p class="text-muted">This request is approved automatically — no action needed.</p>
      <?php elseif ($appointment['status_name'] === 'Pending'): ?>
        <?php if (!$approvalEligibility['can_approve']): ?>
          <div class="approval-blockers" role="status">
            <strong>Cannot approve yet:</strong>
            <ul>
              <?php foreach ($approvalEligibility['blocking_reasons'] as $blockingReason): ?><li><?= e($blockingReason) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approve">
          <button type="submit" class="btn btn-success btn-block" <?= $approvalEligibility['can_approve'] ? '' : 'disabled title="Complete the listed prerequisites before approving this appointment."' ?>>✔ Approve Appointment</button>
        </form>
        <button type="button" class="btn btn-danger btn-block" id="showAppointmentRejectionBtn">&#10006; Reject Appointment</button>
        <div id="appointmentRejectionPanel" class="appointment-rejection-panel" hidden>
        <div class="appointment-rejection-warning"><strong>Entire appointment rejection</strong><br>Use this only when the booking request itself cannot proceed. If one document needs correction, reject that requirement above so the appointment and reference stay active.</div>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" id="rejectForm" class="appointment-rejection-form">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reject">
          <div class="form-group">
            <label for="rejectionReasonSelect">Reason for Rejection <span class="required-mark">*</span></label>
            <select name="rejection_reason" id="rejectionReasonSelect" required>
              <option value="">Select a reason</option>
              <option value="Unresolvable Schedule Conflict">Unresolvable Schedule Conflict</option>
              <option value="Invalid or Ineligible Request">Invalid or Ineligible Request</option>
              <option value="Duplicate Request">Duplicate Request</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="form-group" id="customRejectionReasonGroup" style="display:none;">
            <label for="customRejectionReason">Reason details <span class="required-mark">*</span></label>
            <textarea name="custom_rejection_reason" id="customRejectionReason" rows="5" maxlength="500" placeholder="Please explain the reason for rejection..."></textarea>
          </div>
          <div class="review-rejection-buttons">
            <button type="button" class="btn btn-outline" id="cancelAppointmentRejection">Cancel</button>
            <button type="button" class="btn btn-danger" id="openRejectConfirmBtn">Continue</button>
          </div>
        </form>
        </div>
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

    <?php if (!in_array($appointment['category'], ['Mass Intention', 'Donation'], true) && in_array((int) $appointment['status_id'], [1, 2, 4, 5], true)): ?>
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
      <div class="card-header"><h3><?= $priestAlreadySet ? 'Priest Assignment' : 'Assign Priest' ?></h3><span class="badge badge-pending">Required before approval</span></div>

      <?php if ($priestAlreadySet): ?>
        <!-- The parishioner already picked (and the system already checked
             availability for) a priest at booking time — no manual
             assignment step is needed. This form stays available only for
             an authorized emergency reassignment (e.g. the assigned priest
             becomes unavailable), never as a required step. -->
        <p style="margin-top:0;"><strong>Currently assigned:</strong> <?= $assignedPriest ? e($assignedPriest['title'] . ' ' . $assignedPriest['full_name']) : 'Not yet assigned' ?></p>
        <?php if (!$assignedPriest || ($assignedPriest['status'] ?? '') !== 'active'): ?><p class="approval-inline-warning">This priest is no longer active. Reassign an active priest before approving.</p><?php endif; ?>
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
        <p class="helper-text" style="margin-top:-6px;">No active priest is assigned yet. Assign one before approving this appointment.</p>
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
                  <li><?= formatDate($sched['appointment_date']) ?><?= $sched['appointment_time'] ? ' at ' . date('g:i A', strtotime($sched['appointment_time'])) : ' (To be scheduled)' ?> — <?= e($sched['service_name']) ?></li>
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

    <?php if (!in_array($appointment['category'], ['Mass Intention', 'Donation'], true) && in_array((int) $appointment['status_id'], [1, 2, 4, 5], true)): ?>
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

<?php if (!$isAjax): ?>
<?php include __DIR__ . '/../includes/secretary-modal-shells.php'; ?>
<script src="<?= url('public/js/detail-modal.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/detail-modal.js') ?>"></script>
<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
