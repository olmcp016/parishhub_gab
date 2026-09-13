<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];
$stmt = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
$stmt->execute([$userId]);
$parishionerId = $stmt->fetchColumn();

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
    $serviceId = $_POST['service_id'] ?? '';
    $priestId = ($_POST['priest_id'] ?? '') ?: null;
    $date = $_POST['appointment_date'] ?? '';
    $time = $_POST['appointment_time'] ?? '';
    $dateOfDeath = ($_POST['date_of_death'] ?? '') ?: null;
    $remarks = trim($_POST['remarks'] ?? '') ?: null;

    $stmt = db()->prepare('SELECT category FROM services WHERE service_id = ?');
    $stmt->execute([$serviceId]);
    $category = $stmt->fetchColumn();

    if (!$category || !$date || !$time) {
        bookRespondError($isAjax, 'Please fill in the service, date, and time.', url('parishioner/services.php'));
    }

    $isMassIntention = ($category === 'Mass Intention');
    if ($isMassIntention) {
        // Priests do not personally read Mass Intentions, and the time is
        // assigned automatically from the Mass schedule — never client-chosen.
        $priestId = null;
        $massTimes = massTimesFor($date);
        $time = $massTimes[0] ?? $time;
    }

    // ---- Enforce the parish's fixed scheduling rules ----
    $check = validateBooking($category, $date, $time, $dateOfDeath);
    if (!$check['valid']) {
        bookRespondError($isAjax, $check['message'], url('parishioner/services.php'));
    }
    $finalTime = $check['forcedTime'] ?? $time;

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

        // Priest availability check — a priest cannot be double-booked at the same date/time
        if ($priestId) {
            $stmt = $pdo->prepare(
                "SELECT a.appointment_id, s.service_name FROM appointments a
                 JOIN services s ON a.service_id = s.service_id
                 WHERE a.priest_id = ? AND a.appointment_date = ? AND a.appointment_time = ?
                   AND a.status_id NOT IN (3, 7)"
            );
            $stmt->execute([$priestId, $date, $finalTime]);
            $conflict = $stmt->fetch();
            if ($conflict) {
                $pdo->rollBack();
                bookRespondError($isAjax, "That priest is already booked for another appointment (\"{$conflict['service_name']}\", #{$conflict['appointment_id']}) at that exact date and time. Please choose a different time, or leave the priest field as \"No preference\" and the secretary will assign one.", url('parishioner/services.php'));
            }
        }

        // Mass Intentions skip manual secretary review entirely — approved on
        // submission so the parishioner can go straight to payment.
        $initialStatusId = $isMassIntention ? 2 : 1;
        $approvedAtColumn = $isMassIntention ? ', approved_at' : '';
        $approvedAtValue = $isMassIntention ? ', NOW()' : '';

        $stmt = $pdo->prepare(
            "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, date_of_death{$approvedAtColumn})
             VALUES (?, ?, ?, ?, ?, ?, ?, ?{$approvedAtValue})"
        );
        $stmt->execute([$parishionerId, $serviceId, $priestId, $date, $finalTime, $initialStatusId, $remarks, $dateOfDeath]);
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

        $skippedFiles = [];
        if (!$isMassIntention && !empty($_FILES['documents']['name'][0])) {
            $uploadDir = __DIR__ . '/../public/uploads/';
            foreach ($_FILES['documents']['name'] as $i => $name) {
                $err = $_FILES['documents']['error'][$i];
                if ($err !== UPLOAD_ERR_OK) {
                    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                        $skippedFiles[] = $name;
                    }
                    continue;
                }
                $safeName = time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                $dest = $uploadDir . $safeName;
                if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $dest)) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$appointmentId, $name, 'public/uploads/' . $safeName, $_FILES['documents']['type'][$i]]);
                }
            }
        }

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

        $pdo->commit();
        logActivity($userId, "Booked appointment #$appointmentId", 'Appointments');

        if ($isMassIntention) {
            $successMessage = 'Your Mass Intention request has been automatically approved! You can proceed to payment right away — no documents needed.';
        } else {
            $successMessage = 'Appointment request submitted! Our secretary will review your requirements before approving.';
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'appointment_id' => $appointmentId,
                'message' => $successMessage,
                'skipped_files' => $skippedFiles,
                'detail_url' => url('parishioner/appointment-detail.php?id=' . $appointmentId),
            ]);
            exit;
        }

        flash('success', $successMessage);
        if (!empty($skippedFiles)) {
            $limit = ini_get('upload_max_filesize');
            flash('error', 'Note: the following file(s) were too large (max ' . $limit . ' each) and were NOT uploaded: ' . implode(', ', $skippedFiles) . '. You can upload them separately from your appointment page.');
        }
        redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
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
