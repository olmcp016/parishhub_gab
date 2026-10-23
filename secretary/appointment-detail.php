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
require_once __DIR__ . '/../includes/requirements-review.php';
require_once __DIR__ . '/../includes/funeral-schedule.php';
requireRole('Secretary', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$userId = currentUser()['user_id'];
$isSecretaryViewer = currentUser()['role_name'] === 'Secretary';
$isAjax = isDetailModalRequest();
$redirectUrl = url('secretary/appointment-detail.php?id=' . $id);

// Reasons for rejecting one supporting document or generated form ("Others" asks for details).
const DOCUMENT_REJECTION_REASONS = ['Incomplete Documents', 'Missing Information', 'Others'];

/** The reason dropdown (+ "Please Specify Reason" for Others) in each Requirements Review rejection form. */
function renderDocumentRejectionReason(string $fieldId): void
{
    ?>
    <label for="<?= e($fieldId) ?>">Reason for rejection</label>
    <select id="<?= e($fieldId) ?>" name="document_rejection_choice" class="js-doc-rejection-choice" required>
      <option value="">Select Rejection Reason</option>
      <?php foreach (DOCUMENT_REJECTION_REASONS as $option): ?><option value="<?= e($option) ?>"><?= e($option) ?></option><?php endforeach; ?>
    </select>
    <div class="review-rejection-custom js-doc-rejection-custom" hidden>
      <label for="<?= e($fieldId) ?>_custom">Please Specify Reason</label>
      <textarea id="<?= e($fieldId) ?>_custom" name="document_rejection_custom" rows="4" maxlength="1000" placeholder="Enter the specific reason for rejection"></textarea>
    </div>
    <?php
}

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

    // Documents are reviewed while the appointment is pending. Two exceptions, until
    // the appointment is confirmed: the Pre-Cana Seminar Certificate of a parish
    // seminar (it arrives after the seminar — includes/precana.php), and a Funeral's
    // Katin-awan sa Paglubong form corrected after its schedule changed.
    $documentReviewOpen = static function (int $documentId) use ($id, $currentStatusId, $actionAppointment): bool {
        if ($currentStatusId === 1) return true;
        if (!in_array($currentStatusId, [2, 4], true)) return false;
        $doc = db()->prepare('SELECT requirement_label, generated_form_type FROM uploaded_documents WHERE document_id = ? AND appointment_id = ?');
        $doc->execute([$documentId, $id]);
        $docRow = $doc->fetch() ?: [];
        if (($actionAppointment['category'] ?? '') === 'Funeral' && ($docRow['generated_form_type'] ?? '') === 'katin_awan_paglubong') return true;
        return precanaForAppointment($id) && precanaIsCertificateLabel((string) ($docRow['requirement_label'] ?? ''));
    };

    if ($action === 'verify_document') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if (!$documentReviewOpen($documentId)) {
            respondAjaxOrRedirect($isAjax, false, 'Documents can only be reviewed while the appointment is pending.', $redirectUrl);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $statusLock = $pdo->prepare('SELECT status_id FROM appointments WHERE appointment_id = ? FOR UPDATE');
            $statusLock->execute([$id]);
            if ((int) $statusLock->fetchColumn() !== $currentStatusId) {
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
        requirementsReviewAfterSecretaryDecision($id);
        respondAjaxOrRedirect($isAjax, true, 'Document marked as verified.', $redirectUrl);
    }

    if ($action === 'send_revision_request') {
        if (!requirementsReviewAvailable()) {
            respondAjaxOrRedirect($isAjax, false, 'Revision requests need the requirements review database update first.', $redirectUrl);
        }
        $result = sendRevisionRequest($id, (int) $userId);
        respondAjaxOrRedirect($isAjax, $result['ok'], $result['message'], $redirectUrl);
    }

    if ($action === 'reject_document') {
        $choice = trim((string) ($_POST['document_rejection_choice'] ?? ''));
        if (!in_array($choice, DOCUMENT_REJECTION_REASONS, true)) {
            respondAjaxOrRedirect($isAjax, false, 'Please select a rejection reason.', $redirectUrl);
        }
        // Incomplete Documents / Missing Information are saved as-is; Others saves the Secretary's explanation.
        $reason = $choice;
        if ($choice === 'Others') {
            $reason = trim((string) ($_POST['document_rejection_custom'] ?? ''));
            if ($reason === '') {
                respondAjaxOrRedirect($isAjax, false, 'Please specify the reason for rejection.', $redirectUrl);
            }
            if (mb_strlen($reason) > 1000) {
                respondAjaxOrRedirect($isAjax, false, 'The rejection reason must be 1000 characters or fewer.', $redirectUrl);
            }
        }
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if (!$documentReviewOpen($documentId)) {
            respondAjaxOrRedirect($isAjax, false, 'Documents can only be reviewed while the appointment is pending.', $redirectUrl);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $statusLock = $pdo->prepare('SELECT status_id FROM appointments WHERE appointment_id = ? FOR UPDATE');
            $statusLock->execute([$id]);
            if ((int) $statusLock->fetchColumn() !== $currentStatusId) {
                $pdo->rollBack();
                respondAjaxOrRedirect($isAjax, false, 'Documents can only be reviewed while the appointment is pending.', $redirectUrl);
            }
            $documentInfo = $pdo->prepare('SELECT requirement_label, document_source FROM uploaded_documents WHERE document_id = ? AND appointment_id = ? AND review_status = \'pending\' AND superseded_by IS NULL FOR UPDATE');
            $documentInfo->execute([$documentId, $id]);
            $documentRow = $documentInfo->fetch() ?: [];
            $documentLabel = trim((string) (($documentRow['requirement_label'] ?? '') ?: 'Uploaded document'));
            $itemKind = ($documentRow['document_source'] ?? '') === 'generated' ? 'form' : 'document';
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
            $recipient = $pdo->prepare('SELECT u.user_id, a.guest_name FROM appointments a JOIN parishioners p ON p.parishioner_id = a.parishioner_id JOIN users u ON u.user_id = p.user_id WHERE a.appointment_id = ?');
            $recipient->execute([$id]);
            $recipientRow = $recipient->fetch() ?: [];
            $parishionerUserId = $recipientRow['user_id'] ?? null;
            // With the revision workflow in place the applicant hears about all
            // rejections at once, by email, when the Secretary sends the revision request.
            // That workflow is for pending appointments only, so a Pre-Cana certificate
            // rejected after approval is notified here directly (registered
            // parishioners; guests see it on their booking status page).
            $isGuestBooking = trim((string) ($recipientRow['guest_name'] ?? '')) !== '';
            if ($parishionerUserId && (!requirementsReviewAvailable() || ($currentStatusId !== 1 && !$isGuestBooking))) {
                // One Needs Revision notification per appointment while it is unread, not one per rejected item.
                notifyParishionerNeedsRevision((int) $parishionerUserId, $id, $choice === 'Others'
                    ? "The requirement '$documentLabel' for appointment #$id needs correction. Reason: $reason"
                    : "Your submitted $itemKind '$documentLabel' for appointment #$id was rejected due to " . strtolower($reason) . ($itemKind === 'form' ? '. Please correct the form and generate it again.' : '. Please upload a corrected copy.'));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Document rejection failed: ' . $e->getMessage());
            respondAjaxOrRedirect($isAjax, false, 'The document could not be rejected. Please try again.', $redirectUrl);
        }
        logActivity($userId, "Rejected a document for appointment #$id", 'Appointments');
        requirementsReviewAfterSecretaryDecision($id);
        // A Pre-Cana certificate rejected after approval: email the applicant now (one
        // email per rejected submission). While pending, the rejection goes out in the
        // regular consolidated revision-request email instead.
        if ($currentStatusId !== 1 && precanaIsCertificateLabel($documentRow['requirement_label'] ?? '') && precanaForAppointment($id)) {
            require_once __DIR__ . '/../includes/precana-notifications.php';
            $emailNotice = precanaEmailNotice(sendPrecanaCertificateRevisionEmail($id, $documentId, $reason, (int) $userId));
            respondAjaxOrRedirect($isAjax, true, 'Pre-Cana Seminar Certificate rejected (Needs Revision). ' . $emailNotice, $redirectUrl);
        }
        respondAjaxOrRedirect($isAjax, true, 'Requirement rejected successfully.', $redirectUrl);
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
            // Wedding Pre-Cana seminar fee (Special ₱2,500, Regular none) is added once, here, to the frozen fee.
            $precanaType = $feeAppointment['category'] === 'Wedding' ? (precanaForAppointment($id)['precana_type'] ?? null) : null;
            $calculation = calculateServiceFee($feeAppointment['category'], $feeAppointment['schedule_type'], $classification, $sponsors, $precanaType);
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
        $noPaymentNeeded = false;
        $confirmNow = false;
        $ceremonyBlockers = [];
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

            // A verified ₱0 total (PSS Giver Funeral Mass, or a Wedding whose fees
            // all come to ₱0) has no payment step: approve it straight to Confirmed.
            // A Wedding still waiting on its Pre-Cana conditions stays Approved — no payment needed — and is confirmed later.
            require_once __DIR__ . '/../includes/appointment-approval.php';
            $noPaymentNeeded = appointmentNoPaymentRequired($lockedAppointment);
            $ceremonyBlockers = $noPaymentNeeded ? appointmentConfirmationBlockers($lockedAppointment) : [];
            $confirmNow = $noPaymentNeeded && !$ceremonyBlockers;

            $hasDeadlineCol = paymentDeadlineColumnAvailable();
            $paymentDeadline = (!$confirmNow && !$noPaymentNeeded) ? appointmentPaymentDeadline($lockedAppointment) : null;
            $deadlineSql = ($hasDeadlineCol && $paymentDeadline) ? ', payment_deadline = ?' : '';

            $update = $pdo->prepare($confirmNow
                ? 'UPDATE appointments
                   SET status_id = 5, approved_by = ?, approved_at = NOW(), confirmed_by = ?, confirmed_at = NOW()
                   WHERE appointment_id = ? AND status_id = 1'
                : "UPDATE appointments
                   SET status_id = 2, approved_by = ?, approved_at = NOW(){$deadlineSql}
                   WHERE appointment_id = ? AND status_id = 1"
            );
            $params = $confirmNow
                ? [$userId, $userId, $id]
                : ($deadlineSql ? [$userId, $paymentDeadline->format('Y-m-d H:i:sP'), $id] : [$userId, $id]);
            $update->execute($params);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('This appointment is no longer pending approval.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'This appointment is no longer')) {
                respondAjaxOrRedirect($isAjax, false, $e->getMessage(), $redirectUrl);
            }
            error_log('Appointment approval failed: ' . $e->getMessage());
            respondAjaxOrRedirect($isAjax, false, 'The appointment could not be approved. Please try again.', $redirectUrl);
        }

        // The approval is committed; the email / notification can no longer undo it.
        require_once __DIR__ . '/../includes/appointment-approval.php';
        if ($confirmNow) {
            $isFuneralApproval = $lockedAppointment['category'] === 'Funeral';
            logActivity($userId, "Approved and confirmed appointment #$id (" . ($isFuneralApproval ? 'PSS Giver Funeral Mass' : 'verified total ₱0') . ', no payment required)', 'Appointments');
            $approvalNotice = appointmentApprovedNoticeSummary(notifyAppointmentApproved($id, (int) $userId));
            respondAjaxOrRedirect($isAjax, true, 'Appointment approved and confirmed. ' . NO_PAYMENT_REQUIRED_LABEL . ' (verified total). ' . $approvalNotice, $redirectUrl);
        }
        if ($noPaymentNeeded) {
            logActivity($userId, "Approved appointment #$id (no payment required; awaiting " . ($lockedAppointment['category'] === 'Funeral' ? 'the confirmed burial / Funeral Mass schedule' : 'Pre-Cana conditions') . ' before confirmation)', 'Appointments');
            $approvalNotice = appointmentApprovedNoticeSummary(notifyAppointmentApproved($id, (int) $userId));
            respondAjaxOrRedirect($isAjax, true, 'Appointment approved. ' . NO_PAYMENT_REQUIRED_LABEL . '. Confirm it once these are done: ' . implode(' ', $ceremonyBlockers) . ' ' . $approvalNotice, $redirectUrl);
        }
        logActivity($userId, "Approved appointment #$id", 'Appointments');
        $approvalNotice = appointmentApprovedNoticeSummary(notifyAppointmentApproved($id, (int) $userId));
        respondAjaxOrRedirect($isAjax, true, 'Appointment approved. The parishioner may now proceed to payment. ' . $approvalNotice, $redirectUrl);
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
        if ($puid) {
            db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Rejected', ?)")
                ->execute([$puid, "Your appointment #$id was not approved. Reason: $reason"]);
        }
        logActivity($userId, "Rejected appointment #$id", 'Appointments');
        require_once __DIR__ . '/../includes/appointment-approval.php';
        $rejNotice = appointmentApprovedNoticeSummary(notifyAppointmentRejected($id, $reason, (int) $userId));
        respondAjaxOrRedirect($isAjax, true, 'Appointment rejected. ' . $rejNotice, $redirectUrl);
    } elseif ($action === 'assign_priest') {
        if (!in_array($currentStatusId, [1, 2, 4, 5], true)
            || ($actionAppointment['category'] ?? '') === 'Donation') {
            respondAjaxOrRedirect($isAjax, false, 'A priest cannot be assigned at this appointment status.', $redirectUrl);
        }
        $priestId = (int) ($_POST['priest_id'] ?? 0);
        if ($priestId < 1) {
            respondAjaxOrRedirect($isAjax, false, 'Please choose a valid priest.', $redirectUrl);
        }

        $stmt = db()->prepare(
            "SELECT appointment_date, appointment_time, service_id, schedule_type FROM appointments WHERE appointment_id = ?"
        );
        $stmt->execute([$id]);
        $slot = $stmt->fetch();

        if (!empty($slot['appointment_date']) && !empty($slot['appointment_time'])) {
            // One priest may serve every booking of a shared Regular Wedding/Baptism schedule.
            $availability = priestIsAvailable((int) $priestId, $slot['appointment_date'], $slot['appointment_time'], $id, (int) $slot['service_id'], $slot['schedule_type']);
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
        $clearOutside = outsidePriestAvailable() ? ', outside_priest_name = NULL' : '';
        $update = db()->prepare("UPDATE appointments SET priest_id = ?{$clearOutside} WHERE appointment_id = ? AND status_id IN (1, 2, 4, 5)");
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

        // Funeral: the final burial / Funeral Mass schedule needs both a date and a time.
        if ($category === 'Funeral' && (!$newDate || !$newTime)) {
            respondAjaxOrRedirect($isAjax, false, 'Please choose both the burial / Funeral Mass date and time.', $redirectUrl);
        }
        $check = validateBooking($category, $newDate, $newTime, $apptInfo['date_of_death'], $apptInfo['schedule_type'], (int) $apptInfo['service_id']);
        if (!$check['valid']) {
            respondAjaxOrRedirect($isAjax, false, $check['message'], $redirectUrl);
        }
        // Funeral: the final schedule depends on priest availability, so an assigned
        // priest must be free at the new date and time.
        if ($category === 'Funeral') {
            $funeralPriest = db()->prepare('SELECT priest_id FROM appointments WHERE appointment_id = ?');
            $funeralPriest->execute([$id]);
            $funeralPriestId = (int) $funeralPriest->fetchColumn();
            if ($funeralPriestId > 0) {
                $priestCheck = priestIsAvailable($funeralPriestId, $newDate, $check['forcedTime'] ?? $newTime, $id);
                if (!$priestCheck['available']) respondAjaxOrRedirect($isAjax, false, $priestCheck['reason'], $redirectUrl);
            }
        }
        // The Wedding Ceremony may not move to or before its Pre-Cana seminar (a separate schedule).
        $reschedulePrecana = $category === 'Wedding' ? precanaForAppointment($id) : null;
        if ($reschedulePrecana && !empty($reschedulePrecana['precana_date']) && $newDate && $newDate <= $reschedulePrecana['precana_date']) {
            respondAjaxOrRedirect($isAjax, false, 'The Wedding Ceremony must stay after its Pre-Cana seminar (' . formatDate($reschedulePrecana['precana_date']) . '). Change the Pre-Cana schedule first, or choose a later wedding date.', $redirectUrl);
        }
        // Moving into a shared Regular Wedding/Baptism schedule is allowed; a Special-held time is not.
        if (serviceSlotIsBooked((int) $apptInfo['service_id'], $newDate, $check['forcedTime'] ?? $newTime, $id, $apptInfo['schedule_type'])) {
            respondAjaxOrRedirect($isAjax, false, 'That exact date and time is already booked for this service. Please choose another slot.', $redirectUrl);
        }
        $finalTime = $check['forcedTime'] ?? $newTime;
        $update = db()->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ? WHERE appointment_id = ? AND status_id IN (1, 2, 4, 5)");
        $update->execute([$newDate, $finalTime, $id]);
        if ($update->rowCount() !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'The schedule was not changed. Refresh and try again.', $redirectUrl);
        }
        logActivity($userId, "Rescheduled appointment #$id", 'Appointments');
        require_once __DIR__ . '/../includes/appointment-approval.php';
        $rescheduleEmailNotice = appointmentApprovedNoticeSummary(notifyAppointmentRescheduled($id, $newDate, $finalTime, (int) $userId));

        // Confirmation / First Communion / Funeral are booked without a final date;
        // tell the parishioner once the office sets the final schedule. Guests see
        // it on their booking status page instead (they have no account to notify).
        $scheduleMessage = 'Schedule updated.';
        if (in_array($category, ['Confirmation', 'First Communion', 'Funeral'], true)) {
            try {
                $recipient = db()->prepare(
                    "SELECT u.user_id, a.guest_name FROM appointments a
                     JOIN parishioners p ON p.parishioner_id = a.parishioner_id
                     JOIN users u ON u.user_id = p.user_id
                     WHERE a.appointment_id = ?"
                );
                $recipient->execute([$id]);
                $recipientRow = $recipient->fetch();
                if ($recipientRow && trim((string) ($recipientRow['guest_name'] ?? '')) === '') {
                    $when = formatDate($newDate) . ' at ' . date('g:i A', strtotime((string) $finalTime));
                    db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', ?, ?)")
                        ->execute([(int) $recipientRow['user_id'], "$category Schedule Confirmed", "Your $category schedule for appointment #$id has been set: $when."]);
                    $scheduleMessage = 'Schedule updated. The parishioner has been notified.';
                } else {
                    $scheduleMessage = 'Schedule updated. The guest will see it on their booking status page.';
                }
            } catch (Throwable $e) {
                error_log("Schedule notification failed for appointment #$id: " . $e->getMessage());
                $scheduleMessage = 'Schedule updated, but the parishioner could not be notified. Please contact them directly.';
            }
        }
        // Funeral: never auto-edit a saved Katin-awan form; point out a differing burial date/time.
        if ($category === 'Funeral') {
            require_once __DIR__ . '/../includes/funeral-schedule.php';
            $katinStmt = db()->prepare("SELECT form_data FROM generated_forms WHERE service_category = 'Funeral' AND appointment_id = ? AND form_type = 'katin_awan_paglubong'");
            $katinStmt->execute([$id]);
            $katinData = $katinStmt->fetchColumn();
            $mismatch = $katinData ? funeralKatinAwanScheduleMismatch(['appointment_date' => $newDate, 'appointment_time' => $finalTime], json_decode((string) $katinData, true) ?: []) : null;
            if ($mismatch) $scheduleMessage .= ' Note: the Katin-awan sa Paglubong form still shows a different burial date/time — use "Request Katin-awan Update" if it needs correcting.';
        }
        respondAjaxOrRedirect($isAjax, true, $scheduleMessage . ' ' . $rescheduleEmailNotice, $redirectUrl);
    } elseif ($action === 'confirm') {
        $confirmStmt = db()->prepare('SELECT a.*, s.category FROM appointments a JOIN services s ON s.service_id = a.service_id WHERE a.appointment_id = ?');
        $confirmStmt->execute([$id]);
        $confirmAppointment = $confirmStmt->fetch() ?: [];
        // Payment Verified — or Approved with a verified ₱0 total (no payment step).
        $confirmableStatus = $currentStatusId === 4 || ($currentStatusId === 2 && appointmentNoPaymentRequired($confirmAppointment));
        if (!$confirmableStatus) {
            respondAjaxOrRedirect($isAjax, false, 'Only a payment-verified appointment can be confirmed.', $redirectUrl);
        }
        // Ready for ceremony: a Wedding with a parish Pre-Cana seminar needs its conditions met first.
        $confirmBlockers = appointmentConfirmationBlockers($confirmAppointment);
        if ($confirmBlockers) {
            respondAjaxOrRedirect($isAjax, false, 'Cannot confirm yet: ' . implode(' ', $confirmBlockers), $redirectUrl);
        }
        // Mass Intention/Donation payment confirmation is the Cashier's
        // responsibility (see treasurer/payment-detail.php) — Secretary no
        // longer manages payments for these two categories.
        $stmt = db()->prepare("SELECT s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?");
        $stmt->execute([$id]);
        if (in_array($stmt->fetchColumn(), ['Mass Intention', 'Donation'], true)) {
            respondAjaxOrRedirect($isAjax, false, 'Payment confirmation for Mass Intentions and Donations is handled by the Cashier.', $redirectUrl);
        }

        $update = db()->prepare("UPDATE appointments SET status_id = 5 WHERE appointment_id = ? AND status_id = ?");
        $update->execute([$id, $currentStatusId]);
        if ($update->rowCount() !== 1) {
            respondAjaxOrRedirect($isAjax, false, 'This appointment is no longer awaiting confirmation.', $redirectUrl);
        }
        logActivity($userId, "Confirmed appointment #$id", 'Appointments');
        require_once __DIR__ . '/../includes/appointment-approval.php';
        $confirmNotice = appointmentApprovedNoticeSummary(notifyAppointmentConfirmed($id, (int) $userId));
        respondAjaxOrRedirect($isAjax, true, 'Appointment confirmed. ' . $confirmNotice, $redirectUrl);
    } elseif ($action === 'confirm_precana' || $action === 'propose_precana') {
        // Special Pre-Cana only: confirm the schedule on file, or propose another
        // one. A proposal is NOT a confirmation: it stays "Awaiting Parish Office
        // Confirmation" until confirmed. Separate from the Wedding Ceremony
        // schedule; Regular sessions are fixed and have no edit action.
        require_once __DIR__ . '/../includes/precana-notifications.php';
        $precanaRow = precanaForAppointment($id);
        if (!$precanaRow || $precanaRow['precana_type'] !== 'Special' || !in_array($currentStatusId, [1, 2, 4], true)) {
            respondAjaxOrRedirect($isAjax, false, 'Only a Special Pre-Cana schedule of an active Wedding booking can be confirmed or changed.', $redirectUrl);
        }
        if ($action === 'confirm_precana') {
            $newPrecanaDate = (string) $precanaRow['precana_date'];
            $newPrecanaTime = substr((string) $precanaRow['precana_time'], 0, 5);
            if ($precanaRow['precana_status'] === 'confirmed') {
                respondAjaxOrRedirect($isAjax, false, 'This Pre-Cana schedule is already confirmed.', $redirectUrl);
            }
        } else {
            $newPrecanaDate = (string) ($_POST['precana_date'] ?? '');
            $newPrecanaTime = (string) ($_POST['precana_time'] ?? '');
        }
        $weddingDateStmt = db()->prepare('SELECT appointment_date FROM appointments WHERE appointment_id = ?');
        $weddingDateStmt->execute([$id]);
        $precanaCheck = precanaValidate('Special', null, $newPrecanaDate, $newPrecanaTime, $weddingDateStmt->fetchColumn() ?: null);
        if (!$precanaCheck['valid']) respondAjaxOrRedirect($isAjax, false, $precanaCheck['message'], $redirectUrl);

        $isConfirm = $action === 'confirm_precana';
        $update = db()->prepare("UPDATE appointments SET precana_date = ?, precana_time = ?, precana_status = ? WHERE appointment_id = ? AND precana_type = 'Special' AND status_id IN (1, 2, 4)");
        // Propose: an Office-proposed alternative, never confirmed automatically.
        $update->execute([$precanaCheck['date'], $precanaCheck['time'], $isConfirm ? 'confirmed' : 'proposed', $id]);
        if ($update->rowCount() !== 1) respondAjaxOrRedirect($isAjax, false, 'The Pre-Cana schedule was not changed. Refresh and try again.', $redirectUrl);
        $when = date('l, F j, Y', strtotime($precanaCheck['date'])) . ' at ' . date('g:i A', strtotime($precanaCheck['time']));
        logActivity($userId, ($isConfirm ? 'Confirmed' : 'Proposed a new') . " Special Pre-Cana schedule for appointment #$id ($when)", 'Appointments');
        // Registered parishioners also get a website notification.
        try {
            $recipient = db()->prepare("SELECT u.user_id FROM appointments a JOIN parishioners p ON p.parishioner_id = a.parishioner_id JOIN users u ON u.user_id = p.user_id WHERE a.appointment_id = ? AND COALESCE(a.guest_name, '') = ''");
            $recipient->execute([$id]);
            if ($recipientId = $recipient->fetchColumn()) {
                db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', ?, ?)")
                    ->execute([(int) $recipientId, $isConfirm ? 'Pre-Cana Schedule Confirmed' : 'Pre-Cana Schedule Proposed', $isConfirm
                        ? "Your Special Pre-Cana seminar schedule for appointment #$id is confirmed: $when."
                        : "The Parish Office proposed a new Special Pre-Cana seminar schedule for appointment #$id: $when. It is not yet confirmed."]);
            }
        } catch (Throwable $e) {
            error_log("Pre-Cana schedule notification failed for appointment #$id: " . $e->getMessage());
        }
        // Email (guests and parishioners) only after the change is saved; a failure never undoes it.
        $emailNotice = precanaEmailNotice(sendPrecanaScheduleEmail($id, $isConfirm ? 'confirmed' : 'proposed', (int) $userId));
        respondAjaxOrRedirect($isAjax, true, ($isConfirm
            ? 'Special Pre-Cana schedule confirmed: ' . $when . '. '
            : 'New Special Pre-Cana schedule proposed: ' . $when . '. It stays Office-Proposed — Not Yet Confirmed until you confirm it. ') . $emailNotice, $redirectUrl);
    } elseif ($action === 'request_katin_awan_update') {
        // The confirmed burial / Funeral Mass schedule differs from the date/time in the
        // saved Katin-awan sa Paglubong form: mark the form "Needs Revision" through the
        // regular review workflow so the family updates it. Their answers are never
        // changed here.
        require_once __DIR__ . '/../includes/funeral-schedule.php';
        $funeralStmt = db()->prepare('SELECT a.*, s.category FROM appointments a JOIN services s ON s.service_id = a.service_id WHERE a.appointment_id = ?');
        $funeralStmt->execute([$id]);
        $funeralRow = $funeralStmt->fetch() ?: [];
        $katinStmt = db()->prepare("SELECT form_data, document_id FROM generated_forms WHERE service_category = 'Funeral' AND appointment_id = ? AND form_type = 'katin_awan_paglubong'");
        $katinStmt->execute([$id]);
        $katin = $katinStmt->fetch() ?: null;
        $mismatch = $katin ? funeralKatinAwanScheduleMismatch($funeralRow, json_decode((string) $katin['form_data'], true) ?: []) : null;
        if (($funeralRow['category'] ?? '') !== 'Funeral' || !in_array($currentStatusId, [1, 2, 4], true) || !$mismatch) {
            respondAjaxOrRedirect($isAjax, false, 'There is no burial schedule difference to correct in the Katin-awan sa Paglubong form.', $redirectUrl);
        }
        $confirmedWhen = date('F j, Y', strtotime((string) $funeralRow['appointment_date'])) . ' at ' . date('g:i A', strtotime((string) $funeralRow['appointment_time']));
        $reason = "The burial / Funeral Mass schedule was confirmed as $confirmedWhen. Please update KANUS-A ILUBONG and ORAS in this form, then generate it again.";
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (!empty($katin['document_id'])) {
                $pdo->prepare("UPDATE uploaded_documents SET review_status = 'rejected', verified = FALSE, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL")
                    ->execute([$reason, $userId, (int) $katin['document_id'], $id]);
            }
            $pdo->prepare("UPDATE generated_forms SET status = 'rejected', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE service_category = 'Funeral' AND appointment_id = ? AND form_type = 'katin_awan_paglubong'")
                ->execute([$reason, $id]);
            $recipient = $pdo->prepare("SELECT u.user_id FROM appointments a JOIN parishioners p ON p.parishioner_id = a.parishioner_id JOIN users u ON u.user_id = p.user_id WHERE a.appointment_id = ? AND COALESCE(a.guest_name, '') = ''");
            $recipient->execute([$id]);
            if ($recipientId = $recipient->fetchColumn()) {
                notifyParishionerNeedsRevision((int) $recipientId, $id, "Please update the Katin-awan sa Paglubong form for appointment #$id: $reason");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Katin-awan update request failed: ' . $e->getMessage());
            respondAjaxOrRedirect($isAjax, false, 'The update request could not be saved. Please try again.', $redirectUrl);
        }
        logActivity($userId, "Requested a Katin-awan sa Paglubong update for appointment #$id (schedule changed)", 'Appointments');
        requirementsReviewAfterSecretaryDecision($id);
        // Pending appointments: it goes out with the regular "Send Revision Request" email.
        $updateNotice = $currentStatusId === 1
            ? 'Send the revision request to email the applicant.'
            : funeralFormUpdateEmailNotice(sendFuneralFormUpdateEmail($id, $reason, (int) $userId));
        respondAjaxOrRedirect($isAjax, true, 'Katin-awan sa Paglubong marked "Needs Revision" so the family updates the burial date and time. ' . $updateNotice, $redirectUrl);
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

// Opened from the notification bell: the appointment loaded, so mark that notification read.
if (!empty($_GET['notification']) && currentUser()['role_name'] === 'Secretary') {
    require_once __DIR__ . '/../includes/secretary-notifications.php';
    markSecretaryNotificationRead((int) $_GET['notification'], (int) $userId);
}

$priests = db()->query("SELECT * FROM priests WHERE status = 'active'")->fetchAll();
$priestSchedules = [];
$priestUnavailability = [];
$stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
$stmt->execute([$id]);
$intention = $stmt->fetch() ?: null;
$stmt = db()->prepare('SELECT d.*, pr.project_name FROM donations d LEFT JOIN projects pr ON pr.project_id = d.project_id WHERE d.appointment_id = ?');
$stmt->execute([$id]);
$donation = $stmt->fetch() ?: null;
$stmt = db()->prepare("SELECT * FROM uploaded_documents WHERE appointment_id = ? AND superseded_by IS NULL AND (document_source IS NULL OR document_source <> 'generated') ORDER BY uploaded_at DESC, document_id DESC");
$stmt->execute([$id]);
$documents = $stmt->fetchAll();
$generatedForms = appointmentGeneratedFormReviewRows(db(), $id, (string) ($appointment['category'] ?? ''));
$approvalEligibility = appointmentApprovalEligibility($appointment);
// Supporting documents are reviewed while pending; a parish Pre-Cana certificate also after approval (until confirmation).
$certificateReviewOpen = precanaCertificateOpen($appointment);
$canReviewDoc = static fn(array $d): bool => (int) $appointment['status_id'] === 1
    || ($certificateReviewOpen && precanaIsCertificateLabel($d['requirement_label'] ?? ''));
// Ready for ceremony: what still blocks confirming (Pre-Cana schedule + certificate).
$ceremonyBlockers = appointmentConfirmationBlockers($appointment);
$noPaymentRequired = appointmentNoPaymentRequired($appointment);

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
        <?php
          require_once __DIR__ . '/../includes/appointment-approval.php';
          $isPastDateUnpaid = (int) $appointment['status_id'] === 2
              && !empty($appointment['appointment_date'])
              && $appointment['appointment_date'] < date('Y-m-d')
              && !$noPaymentRequired
              && (empty($payment) || !in_array($payment['payment_status'] ?? '', ['pending', 'verified'], true));
          $paymentDeadline = ((int) $appointment['status_id'] === 2 && !$noPaymentRequired)
              ? appointmentPaymentDeadline($appointment)
              : null;
        ?>
        <?php if ($isPastDateUnpaid): ?>
          <span class="badge badge-warning" style="background:#b45309;color:#fff;">Needs Reschedule</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($isPastDateUnpaid): ?>
      <div class="alert" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d; margin:14px 0;">
        <strong>⚠️ Scheduled Date Has Passed:</strong> This appointment was scheduled for <?= formatDate($appointment['appointment_date']) ?> and remains unpaid. Per parish policy, this booking has <strong>not</strong> been cancelled. Please coordinate with the applicant and use the <strong>Reschedule</strong> form below to assign a new date and time.
      </div>
    <?php endif; ?>
    <p class="appointment-reference"><strong>Booking Reference:</strong> <span><?= e($appointment['guest_reference'] ?: 'Appointment #' . (int) $appointment['appointment_id']) ?></span></p>
    <?php if ($appointment['guest_name']): ?>
      <p><strong>Guest:</strong> <?= e($appointment['guest_name']) ?> (<?= e($appointment['guest_phone']) ?><?= $appointment['guest_email'] ? ', ' . e($appointment['guest_email']) : '' ?>)</p>
    <?php else: ?>
      <p><strong>Parishioner:</strong> <?= e($appointment['firstname']) ?> <?= e($appointment['lastname']) ?> (<?= e($appointment['email']) ?>, <?= e($appointment['phone']) ?>)</p>
    <?php endif; ?>
    <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?><?= $appointment['appointment_time'] ? ' at ' . date('g:i A', strtotime($appointment['appointment_time'])) : ' (To be scheduled)' ?></p>
    <?php if (!empty($appointment['outside_priest_name'])): ?>
      <p><strong>Priest:</strong> <?= e($appointment['outside_priest_name']) ?> <span class="badge badge-info" style="font-size:11px; margin-left:4px;">Visiting / Guest Priest</span></p>
    <?php endif; ?>
    <?php if ($paymentDeadline && (int) $appointment['status_id'] === 2): ?>
      <p><strong>Payment Deadline:</strong> <span style="color:#b45309; font-weight:600;"><?= e($paymentDeadline->format('F j, Y \a\t g:i A')) ?></span></p>
    <?php endif; ?>
    <?php
      // Fee-rule services (Baptism, Wedding, Funeral Mass) show "No Fees" for a ₱0
      // line (e.g. PSS Giver), not "Voluntary Offering"; a ₱0 total is "₱0.00 — No Payment Required".
      $isFuneral = $appointment['category'] === 'Funeral';
      $isFeeRuleService = in_array($appointment['category'], NO_PAYMENT_CATEGORIES, true);
      $feeText = fn(float $amount): string => ($isFeeRuleService && $amount <= 0) ? 'No Fees' : feeLabel($amount);
      $totalText = fn(float $amount): string => feeTotalNeedsNoPayment($appointment['category'], $amount) ? NO_PAYMENT_REQUIRED_LABEL : feeLabel($amount);
    ?>
    <p><strong>Fee:</strong>
      <?php if ($appointment['pss_classification'] === 'pending_verification'): ?>Fee pending PSS verification
      <?php elseif (!empty($appointment['fee_snapshot'])): ?><?= $totalText((float) (json_decode($appointment['fee_snapshot'], true)['total'] ?? 0)) ?>
      <?php else: ?><?= feeLabel((float) $appointment['fee']) ?><?php endif; ?>
    </p>
    <?php if (in_array($appointment['category'], ['Baptism', 'Wedding', 'Funeral'], true)): ?>
      <p><strong>PSS Classification:</strong> <?= e($appointment['pss_classification'] === 'pending_verification' ? 'Pending Verification' : ucfirst(str_replace('_', ' ', $appointment['pss_classification']))) ?></p>
      <?php if ($appointment['category'] === 'Baptism'): ?><p><strong>Sponsors:</strong> <?= (int) $appointment['sponsor_count'] ?></p><?php endif; ?>
      <?php if ($appointment['category'] === 'Wedding'): ?><p><strong>Individual Sponsors:</strong> <?= (int) $appointment['wedding_sponsor_count'] ?> (<?= (int) ceil((int) $appointment['wedding_sponsor_count'] / 2) ?> pairs)</p><?php endif; ?>
      <?php if (!empty($appointment['fee_snapshot'])): $feeSnapshot = json_decode($appointment['fee_snapshot'], true) ?: []; ?>
        <div class="alert" style="background:var(--cream); border:1px solid var(--cream-dark);">
          <strong>Fee Calculation</strong><br>
          <?= $isFuneral ? 'Funeral Mass Service Fee' : 'Base Fee' ?>: <?= $feeText((float) ($feeSnapshot['base_fee'] ?? 0)) ?><br>
          Priest Stipend: <?= $feeText((float) ($feeSnapshot['priest_stipend'] ?? 0)) ?><br>
          <?php if (!$isFuneral): ?>Additional Sponsor Fee: <?= $feeText((float) ($feeSnapshot['additional_sponsor_fee'] ?? 0)) ?><br><?php endif; ?>
          <?php if (array_key_exists('precana_fee', $feeSnapshot)): ?>Pre-Cana Seminar (<?= e((string) ($feeSnapshot['precana_type'] ?? '')) ?>): <?= (float) $feeSnapshot['precana_fee'] > 0 ? money((float) $feeSnapshot['precana_fee']) : 'No Fees' ?><br><?php endif; ?>
          <strong>Total<?= $isFuneral ? ' Payment' : '' ?>: <?= $totalText((float) ($feeSnapshot['total'] ?? 0)) ?></strong>
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
    <?php if ($appointment['category'] === 'Wedding'): // Pre-Cana seminar — separate from the Wedding Ceremony date above ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <?php if (!precanaCertificateDeferred($appointment)): ?>
        <?php renderPrecanaSummary($appointment); // older Wedding: "Not recorded" ?>
      <?php else:
        $precanaInfo = precanaDisplay($appointment);
        [$scheduleText, $scheduleBadge] = precanaScheduleStatusLabel($appointment);
        $precanaCertificate = precanaCertificateStatus($id);
        $certificateDoc = $precanaCertificate['document'];
        [$certificateText, $certificateBadge] = precanaCertificateStatusLabel($precanaCertificate['status']);
        $precanaActive = in_array((int) $appointment['status_id'], [1, 2, 4], true); ?>
        <h4 id="precana-review" style="margin-bottom:6px;">Pre-Cana Seminar</h4>
        <p style="margin-top:0;"><strong>Seminar Type:</strong> <?= e($precanaInfo['type_label']) ?> &nbsp;·&nbsp; <strong>Seminar Fee:</strong> <?= e($precanaInfo['fee_label']) ?></p>
        <?php if (!precanaCatalogHasCertificate($appointment['requirements'] ?? '')): ?>
          <div class="approval-blockers" role="status"><strong>Service setup:</strong> the Wedding service's requirements do not list a Pre-Cana Seminar Certificate (Services → Wedding → Requirements). The certificate is still required below before this Wedding can be confirmed.</div>
        <?php endif; ?>

        <section class="precana-review-section" aria-labelledby="precanaScheduleHeading">
          <h5 id="precanaScheduleHeading">Seminar Schedule <span class="badge badge-<?= e($scheduleBadge) ?>"><?= e($scheduleText) ?></span></h5>
          <p><strong><?= e($precanaInfo['schedule_heading']) ?>:</strong> <?= e($precanaInfo['date']) ?> &nbsp;·&nbsp; <strong>Time:</strong> <?= e($precanaInfo['time']) ?></p>
          <?php if ($precanaInfo['type'] === 'Regular'): ?>
            <p class="helper-text">Fixed parish session (second Saturday, 8:00 AM) — it cannot be changed here.</p>
          <?php elseif ($precanaActive): ?>
            <?php if (($appointment['precana_status'] ?? '') !== 'confirmed'): ?>
              <form method="POST" action="<?= e($redirectUrl) ?>" class="mb-3">
                <?= csrfField() ?><input type="hidden" name="action" value="confirm_precana">
                <button type="submit" class="btn btn-success btn-sm">✔ Confirm Schedule</button>
                <span class="helper-text">Confirms the date and time shown above and emails the applicant.</span>
              </form>
            <?php endif; ?>
            <details>
              <summary class="text-muted" style="cursor:pointer; font-size:13px;">Propose Another Schedule</summary>
              <form method="POST" action="<?= e($redirectUrl) ?>" class="mt-2">
                <?= csrfField() ?><input type="hidden" name="action" value="propose_precana">
                <div class="form-row">
                  <div class="form-group"><label>Seminar Date</label><input type="date" name="precana_date" required min="<?= date('Y-m-d') ?>"<?= $appointment['appointment_date'] ? ' max="' . e(date('Y-m-d', strtotime($appointment['appointment_date'] . ' -1 day'))) . '"' : '' ?>></div>
                  <div class="form-group"><label>Seminar Time</label><input type="time" name="precana_time" required step="1800"></div>
                </div>
                <p class="helper-text">Before the Wedding Ceremony, in 30-minute steps, outside the staff day off. A proposed schedule stays <strong>Office-Proposed — Not Yet Confirmed</strong> until you confirm it; the applicant is emailed that it is proposed, not confirmed. The Wedding Ceremony schedule does not change.</p>
                <button type="submit" class="btn btn-outline btn-sm">Propose Schedule</button>
              </form>
            </details>
          <?php endif; ?>
        </section>

        <section class="precana-review-section" aria-labelledby="precanaCertificateHeading">
          <h5 id="precanaCertificateHeading">Certificate Review <span class="badge badge-<?= e($certificateBadge) ?>"><?= e($certificateText) ?></span></h5>
          <?php if ($certificateDoc): ?>
            <p><a href="<?= url('document.php?id=' . (int) $certificateDoc['document_id']) ?>" target="_blank" rel="noopener">📄 <?= e($certificateDoc['file_name']) ?></a></p>
            <?php if ($precanaCertificate['status'] === 'rejected' && $certificateDoc['rejection_reason']): ?><p class="text-muted" style="font-size:.9em;">Reason: <?= e($certificateDoc['rejection_reason']) ?></p><?php endif; ?>
          <?php else: ?>
            <p class="text-muted">The couple uploads it after attending the seminar.</p>
          <?php endif; ?>
          <p class="helper-text">Not required for Wedding approval; must be Approved before the Wedding is confirmed.</p>
          <?php if ($certificateDoc && $precanaCertificate['status'] === 'pending' && ((int) $appointment['status_id'] === 1 || $precanaActive)): ?>
            <div class="review-actions">
              <form method="POST" action="<?= e($redirectUrl) ?>" class="review-approve-form">
                <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= (int) $certificateDoc['document_id'] ?>">
                <button type="submit" class="btn btn-success btn-sm">Approve Certificate</button>
              </form>
              <button type="button" class="btn btn-danger btn-sm js-open-rejection" data-target="rejectPrecanaCertificate">Reject Certificate</button>
            </div>
            <form id="rejectPrecanaCertificate" class="review-rejection-form" method="POST" action="<?= e($redirectUrl) ?>" hidden>
              <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= (int) $certificateDoc['document_id'] ?>">
              <?php renderDocumentRejectionReason('rejectPrecanaCertificateReason'); ?>
              <div class="review-rejection-buttons">
                <button type="button" class="btn btn-outline btn-sm js-cancel-rejection">Cancel</button>
                <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
              </div>
            </form>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($appointment['category'] === 'Funeral'): // burial and Funeral Mass are one schedule ?>
      <?php if ($appointment['date_of_death']): ?><p><strong>Date of Death:</strong> <?= formatDate($appointment['date_of_death']) ?></p><?php endif; ?>
      <?php renderFuneralScheduleSummary($appointment); ?>
      <?php
        $katinStmt = db()->prepare("SELECT form_data FROM generated_forms WHERE service_category = 'Funeral' AND appointment_id = ? AND form_type = 'katin_awan_paglubong'");
        $katinStmt->execute([$id]);
        $katinData = $katinStmt->fetchColumn();
        $katinMismatch = $katinData ? funeralKatinAwanScheduleMismatch($appointment, json_decode((string) $katinData, true) ?: []) : null;
      ?>
      <?php if ($katinMismatch): ?>
        <div class="approval-blockers" role="status">
          <strong>Katin-awan sa Paglubong differs from the confirmed schedule:</strong> <?= e($katinMismatch) ?>
          <?php if (in_array((int) $appointment['status_id'], [1, 2, 4], true)): ?>
            <form method="POST" action="<?= e($redirectUrl) ?>" style="margin-top:8px;">
              <?= csrfField() ?><input type="hidden" name="action" value="request_katin_awan_update">
              <button type="submit" class="btn btn-outline btn-sm">Request Katin-awan Update</button>
              <span class="helper-text">Marks the form "Needs Revision" so the family corrects it (their answers are not changed).</span>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
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
      <p><strong><?= $appointment['category'] === 'Blessing' ? 'Address to Bless' : ($appointment['category'] === 'Anointing' ? 'Location/Address' : 'Mass Location') ?>:</strong> <?= nl2br(e($appointment['location_address'])) ?></p>
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
      <p><strong>Allocation:</strong> <?= e(donationAllocationLabel($donation)) ?></p>
      <?php if ($donation['message']): ?><p><strong>Message:</strong> <?= e($donation['message']) ?></p><?php endif; ?>
    <?php endif; ?>

    <?php if (!in_array($appointment['category'], ['Mass Intention', 'Donation'], true)): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4 id="requirements-review">Requirements Review</h4>
      <?php
        // Items the applicant re-submitted to replace a rejected one ("Updated" in the status column).
        $updatedStmt = db()->prepare("SELECT DISTINCT superseded_by FROM uploaded_documents WHERE appointment_id = ? AND review_status = 'rejected' AND superseded_by IS NOT NULL");
        $updatedStmt->execute([$id]);
        $updatedDocumentIds = array_flip(array_map('intval', $updatedStmt->fetchAll(PDO::FETCH_COLUMN)));
        $updatedBadge = static fn($documentId): string => isset($updatedDocumentIds[(int) $documentId]) ? ' <span class="badge badge-confirmed review-updated-badge" title="Re-submitted to replace a rejected item">Updated</span>' : '';
      ?>

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
      <?php if (precanaCertificateDeferred($appointment)): ?><p class="helper-text" style="margin-top:-6px;">The Pre-Cana Seminar Certificate is reviewed in the <a href="#precana-review">Pre-Cana Seminar → Certificate Review</a> section.</p><?php endif; ?>
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
                    <?php else: ?><span class="badge badge-pending">Pending Review</span><?= $d ? $updatedBadge($d['document_id']) : '' ?><?php endif; ?>
                  </td>
                  <td data-label="Actions">
                    <div class="review-actions">
                    <?php if ($d && $docStatus === 'pending' && $d['superseded_by'] === null && $canReviewDoc($d)): ?>
                      <form method="POST" action="<?= e($redirectUrl) ?>" class="review-approve-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                      </form>
                      <button type="button" class="btn btn-danger btn-sm js-open-rejection" data-target="rejectDocRow_<?= $d['document_id'] ?>">Reject</button>
                    <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($d && $docStatus === 'pending' && $d['superseded_by'] === null && $canReviewDoc($d)): ?>
                  <tr id="rejectDocRow_<?= $d['document_id'] ?>" class="review-rejection-row" hidden>
                    <td colspan="3">
                      <form id="rejectDoc_<?= $d['document_id'] ?>" class="review-rejection-form" method="POST" action="<?= e($redirectUrl) ?>">
                        <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <?php renderDocumentRejectionReason('rejectDocReason_' . $d['document_id']); ?>
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
                  // A parish Pre-Cana certificate is reviewed in its own "Certificate Review" section above.
                  if (precanaCertificateDeferred($appointment) && precanaIsCertificateLabel($d['requirement_label'] ?? '')) continue;
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
                    <?php else: ?><span class="badge badge-pending">Pending Review</span><?= $updatedBadge($d['document_id']) ?><?php endif; ?>
                  </td>
                  <td data-label="Actions">
                    <div class="review-actions">
                    <?php if ($docStatus === 'pending' && $d['superseded_by'] === null && $canReviewDoc($d)): ?>
                      <form method="POST" action="<?= e($redirectUrl) ?>" class="review-approve-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                      </form>
                      <button type="button" class="btn btn-danger btn-sm js-open-rejection" data-target="rejectDocRow_<?= $d['document_id'] ?>">Reject</button>
                    <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($docStatus === 'pending' && $d['superseded_by'] === null && $canReviewDoc($d)): ?>
                  <tr id="rejectDocRow_<?= $d['document_id'] ?>" class="review-rejection-row" hidden>
                    <td colspan="3">
                      <form id="rejectDoc_<?= $d['document_id'] ?>" class="review-rejection-form" method="POST" action="<?= e($redirectUrl) ?>">
                        <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                        <?php renderDocumentRejectionReason('rejectDocReason_' . $d['document_id']); ?>
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
                    <?php else: ?><span class="badge badge-pending">Pending Review</span><?= $gf['document_id'] ? $updatedBadge($gf['document_id']) : '' ?><?php endif; ?>
                  </td>
                  <td data-label="Actions">
                    <div class="review-actions">
                    <?php if ($gf['document_id'] && $formStatus === 'pending_review' && ((int) $appointment['status_id'] === 1 || ($appointment['category'] === 'Funeral' && in_array((int) $appointment['status_id'], [2, 4], true)))): ?>
                      <form method="POST" action="<?= e($redirectUrl) ?>" class="review-approve-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="verify_document"><input type="hidden" name="document_id" value="<?= $gf['document_id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                      </form>
                      <button type="button" class="btn btn-danger btn-sm js-open-rejection" data-target="rejectFormRow_<?= $gf['document_id'] ?>">Reject</button>
                    <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if ($gf['document_id'] && $formStatus === 'pending_review' && ((int) $appointment['status_id'] === 1 || ($appointment['category'] === 'Funeral' && in_array((int) $appointment['status_id'], [2, 4], true)))): ?>
                  <tr id="rejectFormRow_<?= $gf['document_id'] ?>" class="review-rejection-row" hidden>
                    <td colspan="3">
                      <form id="rejectForm_<?= $gf['document_id'] ?>" class="review-rejection-form" method="POST" action="<?= e($redirectUrl) ?>">
                        <?= csrfField() ?><input type="hidden" name="action" value="reject_document"><input type="hidden" name="document_id" value="<?= $gf['document_id'] ?>">
                        <?php renderDocumentRejectionReason('rejectFormReason_' . $gf['document_id']); ?>
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

      <?php if (requirementsReviewAvailable()):
        // Requirements revision / re-review (see includes/requirements-review.php).
        $reviewItems = requirementsReviewItems($id);
        $reviewState = requirementsReviewState($appointment['requirements_review_status'] ?? null, requirementsReviewCounts($reviewItems));
        $unsentRejections = requirementsReviewUnsentRejections($appointment, $reviewItems);
        $previousStmt = db()->prepare(
            "SELECT old.requirement_label, old.file_name, old.rejection_reason
             FROM uploaded_documents old JOIN uploaded_documents cur ON cur.document_id = old.superseded_by
             WHERE old.appointment_id = ? AND old.review_status = 'rejected' AND old.rejection_reason IS NOT NULL AND cur.superseded_by IS NULL
             ORDER BY old.document_id"
        );
        $previousStmt->execute([$id]);
        $previousRejections = $previousStmt->fetchAll();
      ?>
        <div class="card" style="background:var(--cream); margin-top:16px;">
          <p style="margin-top:0;"><strong>Requirements status:</strong> <span class="badge badge-<?= e($reviewState['badge']) ?>"><?= e($reviewState['label']) ?></span></p>
          <?php if (!empty($appointment['revision_requested_at'])): ?>
            <p class="text-muted" style="margin:0 0 8px;">Last revision request sent: <?= e(date('M j, Y g:i A', strtotime((string) $appointment['revision_requested_at']))) ?></p>
          <?php endif; ?>
          <?php if ($previousRejections): ?>
            <p style="margin:8px 0 4px;"><strong>Previous rejection reasons</strong> (replaced items):</p>
            <ul style="margin:0 0 8px; padding-left:20px;">
              <?php foreach ($previousRejections as $previous): ?>
                <li><?= e(preg_replace('/\.pdf$/i', '', (string) ($previous['requirement_label'] ?: $previous['file_name']))) ?> — <?= e($previous['rejection_reason']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ((int) $appointment['status_id'] === 1 && $unsentRejections): ?>
            <form method="POST" action="<?= e($redirectUrl) ?>">
              <?= csrfField() ?><input type="hidden" name="action" value="send_revision_request">
              <p class="helper-text" style="margin-top:0;"><?= count($unsentRejections) ?> rejected requirement<?= count($unsentRejections) === 1 ? '' : 's' ?> will be listed, with reasons, in one email to the applicant. Finish reviewing the other requirements first.</p>
              <button type="submit" class="btn btn-danger btn-block">Send Revision Request</button>
            </form>
          <?php elseif ($reviewState['code'] === 'awaiting_revision'): ?>
            <p class="helper-text" style="margin:0;">Waiting for the applicant to submit updated requirements. You can review other appointments meanwhile.</p>
          <?php elseif ($reviewState['code'] === 'ready_for_rereview'): ?>
            <p class="helper-text" style="margin:0;">The applicant submitted updated requirements. Approve or reject the items marked Pending Review above.</p>
          <?php endif; ?>
        </div>
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
      <?php elseif ($appointment['status_name'] === 'Payment Verified' || ($appointment['status_name'] === 'Approved' && $noPaymentRequired)): ?>
        <?php if ($appointment['status_name'] === 'Approved'): ?><p class="helper-text" style="margin-top:0;">No payment is required (verified total: <?= NO_PAYMENT_REQUIRED_LABEL ?>).</p><?php endif; ?>
        <?php if ($ceremonyBlockers): ?>
          <div class="approval-blockers" role="status">
            <strong>Not ready for ceremony yet:</strong>
            <ul><?php foreach ($ceremonyBlockers as $blocker): ?><li><?= e($blocker) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="confirm">
          <button type="submit" class="btn btn-success btn-block" <?= $ceremonyBlockers ? 'disabled title="Complete the Pre-Cana conditions listed above first."' : '' ?>>✔ Confirm Appointment</button>
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

    <?php if (($appointment['category'] ?? '') !== 'Donation' && in_array((int) $appointment['status_id'], [1, 2, 4, 5], true)): ?>
    <?php
      $hasOutsidePriest = !empty($appointment['outside_priest_name']) && isOutsidePriestEligibleCategory($appointment['category'] ?? '');
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
      <div class="card-header">
        <h3><?= ($priestAlreadySet || $hasOutsidePriest) ? 'Priest Assignment' : 'Assign Priest' ?></h3>
        <?php if ($hasOutsidePriest): ?>
          <span class="badge badge-success">Visiting Priest</span>
        <?php elseif ($priestAlreadySet): ?>
          <span class="badge badge-success">Assigned</span>
        <?php else: ?>
          <span class="badge badge-pending">Required before approval</span>
        <?php endif; ?>
      </div>

      <?php if ($hasOutsidePriest): ?>
        <p style="margin-top:0;"><strong>Designated Priest:</strong> <?= e($appointment['outside_priest_name']) ?> <span class="badge badge-info" style="font-size:11px; margin-left:4px;">Visiting / Guest Priest</span></p>
        <p class="text-muted" style="font-size:13px; margin-bottom:12px;">This service was requested with a guest or visiting priest chosen by the family. An in-house parish priest assignment is not required for approval.</p>
        <details>
          <summary class="text-muted" style="cursor:pointer; font-size:13px;">Reassign to in-house parish priest</summary>
          <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-3 mt-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="assign_priest">
            <div class="form-group">
              <select name="priest_id" required>
                <option value="">-- Select In-House Priest --</option>
                <?php foreach ($priests as $p): ?>
                  <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-outline btn-block">Assign In-House Priest</button>
          </form>
        </details>
      <?php elseif ($priestAlreadySet): ?>
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
      <?php
        // Funeral: one-click confirmation of the family's preferred schedule, through
        // this same Reschedule action (same rules, conflict and priest checks).
        $isFuneralSchedule = $appointment['category'] === 'Funeral';
        $funeralPreferredOpen = $isFuneralSchedule && !empty($appointment['preferred_burial_date']) && !empty($appointment['preferred_burial_time'])
            && (substr((string) $appointment['appointment_date'], 0, 10) !== substr((string) $appointment['preferred_burial_date'], 0, 10)
                || substr((string) $appointment['appointment_time'], 0, 5) !== substr((string) $appointment['preferred_burial_time'], 0, 5));
      ?>
      <?php if ($funeralPreferredOpen): ?>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-3">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reschedule">
          <input type="hidden" name="appointment_date" value="<?= e(substr((string) $appointment['preferred_burial_date'], 0, 10)) ?>">
          <input type="hidden" name="appointment_time" value="<?= e(substr((string) $appointment['preferred_burial_time'], 0, 5)) ?>">
          <button type="submit" class="btn btn-success btn-block">✔ Confirm Preferred Schedule</button>
          <p class="helper-text" style="margin:6px 0 0;">Sets the burial / Funeral Mass to the family's preferred <?= e(funeralScheduleDisplay($appointment)['preferred_date']) ?> at <?= e(funeralScheduleDisplay($appointment)['preferred_time']) ?>. Or choose another date and time below.</p>
        </form>
      <?php endif; ?>
      <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reschedule">
        <div class="form-group">
          <label><?= $isFuneralSchedule ? 'Burial Date' : 'New Date' ?></label>
          <input type="date" name="appointment_date" required<?= $isFuneralSchedule ? ' min="' . e(date('Y-m-d')) . '"' : '' ?>>
        </div>
        <div class="form-group">
          <label><?= $isFuneralSchedule ? 'Burial Time' : 'New Time' ?></label>
          <input type="time" name="appointment_time" required<?= $isFuneralSchedule ? ' step="1800"' : '' ?>>
        </div>
        <p class="helper-text"><?= $isFuneralSchedule
            ? 'After the date of death, 30-minute steps, outside the staff day off; the assigned priest must be available.'
            : 'Fixed-schedule services (Baptism, Wedding, Funeral) will still be checked against parish scheduling rules.' ?></p>
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
