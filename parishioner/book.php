<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
require_once __DIR__ . '/../includes/document-validation.php';
$identity = requireParishionerOrGuest();
$userId = $identity['user_id'];
$parishionerId = $identity['parishioner_id'];
$isGuest = $identity['is_guest'];

const SCHEDULE_TOGGLE_CATEGORIES = ['Baptism', 'Wedding', 'Blessing', 'Confirmation'];

/**
 * The booking form now lives in a modal on the Services page, submitted
 * via fetch() (hidden "ajax=1" field) so the parishioner never leaves
 * that page. This still falls back to a normal flash+redirect round trip
 * if JS is unavailable — same endpoint, same validation, either way.
 */
$isAjax = ($_POST['ajax'] ?? '') === '1';

function bookRespondError(bool $isAjax, string $message, string $redirectUrl): void
{
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    flash('error', $message);
    redirect($redirectUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // PHP silently empties $_POST and $_FILES entirely when the total upload
    // exceeds post_max_size — even though real data WAS sent. Detect this
    // specific case first, before verifyCsrf() runs, so the parishioner sees
    // an accurate "your files were too large" message instead of a
    // confusing, unrelated "session expired" error.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $contentLength > 0) {
        $limit = ini_get('post_max_size');
        bookRespondError($isAjax, "Your uploaded files were too large for the server to accept (total limit is $limit). Please upload smaller files or fewer at a time — you can also add documents later from your appointment page — then submit the rest of the form again.", url('parishioner/services.php'));
    }

    verifyCsrf();
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $priestId = ($_POST['priest_id'] ?? '') ?: null;
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $dateOfDeath = ($_POST['date_of_death'] ?? '') ?: null;
    $remarks = trim($_POST['remarks'] ?? '') ?: null;
    $scheduleType = in_array($_POST['schedule_type'] ?? '', ['Regular', 'Special'], true) ? $_POST['schedule_type'] : null;

    $guestName = null;
    $guestEmail = null;
    $guestPhone = null;
    $guestReference = null;
    if ($isGuest) {
        $guestName = trim($_POST['guest_name'] ?? '');
        $guestPhone = trim($_POST['guest_phone'] ?? '');
        $guestEmail = trim($_POST['guest_email'] ?? '') ?: null;
        if ($guestName === '' || $guestPhone === '') {
            bookRespondError($isAjax, 'Please provide your name and phone number.', url('parishioner/services.php'));
        }
        $guestReference = generateGuestReference();
    }

    $stmt = db()->prepare('SELECT category, requirements FROM services WHERE service_id = ?');
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch();
    $category = $service['category'] ?? null;

    if (!$category || !$date || !$time) {
        bookRespondError($isAjax, 'Please fill in the service, date, and time.', url('parishioner/services.php'));
    }

    $usesScheduleToggle = in_array($category, SCHEDULE_TOGGLE_CATEGORIES, true);
    $scheduleTypeToSave = $usesScheduleToggle ? $scheduleType : null;

    $isMassIntention = ($category === 'Mass Intention');
    if ($isMassIntention) {
        // Priests do not personally read Mass Intentions, and the time is
        // assigned automatically from the Mass schedule — never client-chosen.
        $priestId = null;
        $massTimes = massTimesFor($date);
        $time = $massTimes[0] ?? $time;
    }

    // ---- Enforce the parish's fixed scheduling rules (Regular/Special, Mass conflicts, staff day-off, funeral mourning period, ...) ----
    $check = validateBooking($category, $date, $time, $dateOfDeath, $scheduleTypeToSave, $serviceId);
    if (!$check['valid']) {
        bookRespondError($isAjax, $check['message'], url('parishioner/services.php'));
    }
    $finalTime = $check['forcedTime'] ?? $time;

    // ---- Re-check that this exact service+date+time hasn't just been taken by someone else ----
    if (!$isMassIntention && serviceSlotIsBooked($serviceId, $date, $finalTime)) {
        bookRespondError($isAjax, 'That exact date and time was just booked by someone else for this service. Please choose another slot.', url('parishioner/services.php'));
    }

    // ---- Re-check priest availability right before saving (race-condition guard) ----
    if ($priestId) {
        $availability = priestIsAvailable((int) $priestId, $date, $finalTime);
        if (!$availability['available']) {
            bookRespondError($isAjax, $availability['reason'], url('parishioner/services.php'));
        }
    }

    // ---- Validate every submitted file BEFORE touching the database or filesystem ----
    $requirementsList = parseRequirementsList($service['requirements']);
    $pendingUploads = []; // [ ['file' => $_FILES-entry, 'label' => ?string], ... ]

    $skippedFiles = [];

    if (!$isMassIntention) {
        foreach ($requirementsList as $i => $label) {
            $field = "req_doc_$i";
            $err = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $skippedFiles[] = $_FILES[$field]['name'];
                continue;
            }
            $pendingUploads[] = ['file' => $_FILES[$field], 'label' => $label];
        }
        if (!empty($_FILES['documents']['name'][0])) {
            foreach ($_FILES['documents']['name'] as $i => $name) {
                if ($name === '') continue;
                $err = $_FILES['documents']['error'][$i];
                if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    // Oversized files are skipped (not hard-rejected) — same
                    // long-standing behavior, surfaced via $skippedFiles below.
                    continue;
                }
                $pendingUploads[] = [
                    'file' => [
                        'name' => $name,
                        'type' => $_FILES['documents']['type'][$i],
                        'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                        'error' => $err,
                        'size' => $_FILES['documents']['size'][$i],
                    ],
                    'label' => null,
                ];
            }
        }

        foreach ($pendingUploads as $upload) {
            $result = validateUploadedFile($upload['file']);
            if (!$result['valid']) {
                bookRespondError($isAjax, DOCUMENT_VALIDATION_ERROR, url('parishioner/services.php'));
            }
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Manually blocked dates (holidays, etc.) still apply on top of the fixed rules
        $stmt = $pdo->prepare('SELECT * FROM calendar WHERE calendar_date = ? AND is_blocked = 1');
        $stmt->execute([$date]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            bookRespondError($isAjax, 'The selected date is not available for booking. Please choose another date.', url('parishioner/services.php'));
        }

        // Mass Intentions skip manual secretary review entirely — approved on
        // submission so the parishioner can go straight to payment.
        $initialStatusId = $isMassIntention ? 2 : 1;
        $approvedAtColumn = $isMassIntention ? ', approved_at' : '';
        $approvedAtValue = $isMassIntention ? ', NOW()' : '';

        $stmt = $pdo->prepare(
            "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, date_of_death, schedule_type, guest_name, guest_email, guest_phone, guest_reference{$approvedAtColumn})
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$approvedAtValue})"
        );
        $stmt->execute([$parishionerId, $serviceId, $priestId, $date, $finalTime, $initialStatusId, $remarks, $dateOfDeath, $scheduleTypeToSave, $guestName, $guestEmail, $guestPhone, $guestReference]);
        $appointmentId = $pdo->lastInsertId();

        if ($category === 'Mass Intention' && !empty($_POST['intention_type'])) {
            $stmt = $pdo->prepare(
                "INSERT INTO mass_intentions (appointment_id, intention_type, offerer_name, intention_for, message)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $appointmentId,
                $_POST['intention_type'],
                $_POST['offerer_name'] ?? '',
                $_POST['intention_for'] ?? '',
                ($_POST['message'] ?? '') ?: null,
            ]);
        }

        $uploadDir = __DIR__ . '/../public/uploads/';
        foreach ($pendingUploads as $upload) {
            $file = $upload['file'];
            $safeName = time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
            $dest = $uploadDir . $safeName;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $stmt = $pdo->prepare(
                    "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label) VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$appointmentId, $file['name'], 'public/uploads/' . $safeName, $file['type'], $upload['label']]);
            }
        }

        $documentsReminder = null;
        if (!$isMassIntention && !empty($requirementsList) && empty($pendingUploads)) {
            $documentsReminder = 'Reminder: this service requires documents (' . implode(', ', $requirementsList) . '). You can upload them now or later from your appointment page — your request just won\'t be approved until they\'re submitted and verified.';
        }

        // Guests have no account to receive an in-app notification —
        // their reference code (shown on confirmation) is how they check
        // status instead.
        if (!$isGuest) {
            if ($isMassIntention) {
                $stmt = $pdo->prepare(
                    "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Mass Intention Approved', ?)"
                );
                $stmt->execute([$userId, "Your Mass Intention request (#$appointmentId) has been automatically approved. You may now proceed to payment."]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Submitted', ?)"
                );
                $stmt->execute([$userId, "Your appointment request (#$appointmentId) has been submitted and is pending review. Our secretary will check your requirements next."]);
            }
        }

        $pdo->commit();
        logActivity($userId, "Booked appointment #$appointmentId" . ($isGuest ? ' (guest)' : ''), 'Appointments');

        if ($isMassIntention) {
            $successMessage = 'Your Mass Intention request has been automatically approved! You can proceed to payment right away — no documents needed.';
        } else {
            $successMessage = 'Appointment request submitted! Our secretary will review your requirements before approving.';
        }
        if ($isGuest) {
            $successMessage .= " Your reference code is $guestReference — save it to check your request's status anytime.";
        }

        $detailUrl = $isGuest
            ? url('status.php?ref=' . urlencode($guestReference))
            : url('parishioner/appointment-detail.php?id=' . $appointmentId);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'appointment_id' => $appointmentId,
                'message' => $successMessage,
                'skipped_files' => $skippedFiles,
                'documents_reminder' => $documentsReminder,
                'schedule_type' => $scheduleTypeToSave,
                'guest_reference' => $guestReference,
                'detail_url' => $detailUrl,
            ]);
            exit;
        }

        flash('success', $successMessage);
        if (!empty($skippedFiles)) {
            $limit = ini_get('upload_max_filesize');
            flash('error', 'Note: the following file(s) were too large (max ' . $limit . ' each) and were NOT uploaded: ' . implode(', ', $skippedFiles) . '. You can upload them separately from your appointment page.');
        }
        redirect($detailUrl);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        bookRespondError($isAjax, 'Failed to submit appointment. Please try again.', url('parishioner/services.php'));
    }
}

// GET requests no longer render a standalone booking page — booking now
// happens in a modal on the Services page. Kept as a redirect (rather than
// deleting the file) so old bookmarks/links still land somewhere useful.
redirect(url('parishioner/services.php'));
