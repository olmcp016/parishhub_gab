<?php
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    file_put_contents(__DIR__ . '/../debug_log.txt', "Error $errno: $errstr in $errfile on line $errline\n", FILE_APPEND);
    return true; // suppress default output so JSON stays clean
});
set_exception_handler(function($e) {
    file_put_contents(__DIR__ . '/../debug_log.txt', "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
});
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document-storage.php';
require_once __DIR__ . '/../includes/scheduling.php';
require_once __DIR__ . '/../includes/document-validation.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/service-fees.php';
require_once __DIR__ . '/../includes/wedding-draft.php';
require_once __DIR__ . '/../includes/funeral-forms.php';
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
$massIntentionStage = 'initial_request';

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
    $pssClaim = in_array($_POST['pss_claim'] ?? '', ['pss', 'non_pss'], true) ? $_POST['pss_claim'] : null;
    $rawSponsorCount = trim((string) ($_POST['sponsor_count'] ?? ''));
    $rawWeddingSponsorCount = trim((string) ($_POST['wedding_sponsor_count'] ?? ''));
    $sponsorCount = $rawSponsorCount === '' ? null : filter_var($rawSponsorCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
    $weddingSponsorCount = $rawWeddingSponsorCount === '' ? null : filter_var($rawWeddingSponsorCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 200]]);

    $guestName = null;
    $guestEmail = null;
    $guestPhone = null;
    $guestReference = null;
    if ($isGuest) {
        // Accept either split fields (new form) or the legacy combined guest_name hidden field.
        $guestLastname  = validateName($_POST['guest_lastname']  ?? '');
        $guestFirstname = validateName($_POST['guest_firstname'] ?? '');
        $guestMiddle    = validateName($_POST['guest_middlename'] ?? '') ?: null;
        $guestPhone     = validatePhilippineMobile($_POST['guest_phone'] ?? '');
        $guestEmail     = validateEmail($_POST['guest_email'] ?? '') ?: null;

        if ($guestLastname && $guestFirstname) {
            // Compose the full name from split fields
            $guestName = $guestLastname . ', ' . $guestFirstname . ($guestMiddle ? ' ' . $guestMiddle : '');
        } else {
            // Fall back to legacy combined field
            $guestName = validateName($_POST['guest_name'] ?? '');
        }

        if (!$guestName) {
            bookRespondError($isAjax, 'Please provide a valid full name (no numbers).', url('parishioner/services.php'));
        }
        if (!$guestPhone) {
            bookRespondError($isAjax, 'Enter a valid 11-digit mobile number starting with 09.', url('parishioner/services.php'));
        }
        $guestReference = generateGuestReference();
    }

    $stmt = db()->prepare('SELECT category, requirements FROM services WHERE service_id = ?');
    $massIntentionStage = 'load_service';
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch();
    $category = $service['category'] ?? null;

    $isMassIntention = ($category === 'Mass Intention');
    
    // Confirmation, First Communion, Funeral, and Wake have no parishioner-selected date/time
    $isNoScheduleCategory = in_array($category, ['Confirmation', 'First Communion', 'Funeral', 'Wake'], true);
    
    if (!$category || (!$isNoScheduleCategory && (!$date || !$time))) {
        bookRespondError($isAjax, $isMassIntention
            ? 'Please select a Mass date and one of the available Mass times.'
            : 'Please fill in the service, date, and time.', url('parishioner/services.php'));
    }
    
    // Allow empty date/time for these
    if ($isNoScheduleCategory) {
        $date = null;
        $time = null;
    }

    $usesScheduleToggle = in_array($category, SCHEDULE_TOGGLE_CATEGORIES, true);
    $scheduleTypeToSave = $usesScheduleToggle ? $scheduleType : null;
    if ($category === 'Wedding' && $scheduleTypeToSave === 'Special' && !preg_match('/^\d{2}:(00|30)$/', $time)) {
        bookRespondError($isAjax, 'Special Wedding times must use 30-minute intervals.', url('parishioner/services.php'));
    }
    $usesFeeRules = in_array($category, ['Baptism', 'Wedding', 'Funeral'], true);
    if ($usesFeeRules && $pssClaim === null) {
        bookRespondError($isAjax, 'Please indicate whether you are claiming PSS status. The parish will verify this before payment.', url('parishioner/services.php'));
    }
    if ($category === 'Baptism' && ($sponsorCount === null || $sponsorCount === false)) {
        bookRespondError($isAjax, 'Please enter a valid non-negative sponsor count.', url('parishioner/services.php'));
    }
    if ($category === 'Wedding' && ($weddingSponsorCount === null || $weddingSponsorCount === false)) {
        bookRespondError($isAjax, 'Please enter a valid non-negative individual sponsor count.', url('parishioner/services.php'));
    }
    $pssClassification = $usesFeeRules ? 'pending_verification' : null;

    $intentionType = $offererName = $intentionFor = $intentionMessage = null;
    $offeringAmount = 0.0;
    $payOnline = false;
    $manualMethodId = null;
    $manualReference = null;
    if ($isMassIntention) {
        $massIntentionStage = 'validate_mass_intention';
        // Priests do not personally read Mass Intentions. The time must be one
        // of the three official Mass times — checked by validateBooking()
        // below — and a Mass Intention can NEVER be saved without a real
        // payment: the amount, payment choice, and (for manual payments) the
        // payment choice is required up front, and the appointment,
        // intention, and payment record are created together in one transaction.
        $priestId = null;
        $paymentRequiredMsg = 'Payment is required before submitting a Mass Intention. Please enter a valid amount.';

        $intentionType = $_POST['intention_type'] ?? '';
        $offererName = trim($_POST['offerer_name'] ?? '');
        $intentionFor = trim($_POST['intention_for'] ?? '');
        $intentionMessage = trim($_POST['message'] ?? '') ?: null;
        if (!in_array($intentionType, ['Living', 'Dead', 'Thanksgiving', 'Healing', 'Birthday'], true) || $offererName === '' || $intentionFor === '') {
            bookRespondError($isAjax, 'Please enter the intention type, the offerer name, and who the Mass is offered for.', url('parishioner/services.php'));
        }

        $rawAmount = $_POST['amount'] ?? '';
        $offeringAmount = is_numeric($rawAmount) ? round((float) $rawAmount, 2) : 0.0;
        if (!($offeringAmount > 0) || $offeringAmount > 1000000) {
            bookRespondError($isAjax, $paymentRequiredMsg, url('parishioner/services.php'));
        }

        $payMode = $_POST['pay_mode'] ?? '';
        if ($payMode === 'online') {
            $massIntentionStage = 'validate_payment_method';
            $payOnline = true;
        } elseif ($payMode === 'cash') {
            $massIntentionStage = 'validate_payment_method';
            // Cash is recorded as pending and confirmed by the Cashier at the parish office.
            $manualMethodId = 1;
        } else {
            bookRespondError($isAjax, $paymentRequiredMsg, url('parishioner/services.php'));
        }
    }

    // House Blessing (category "Blessing") needs a contact number and the
    // address to bless — plain text, never a document upload. A registered
    // parishioner's own phone is reused unless they typed a different one;
    // a guest's phone comes from the guest fields collected above.
    $contactPhone = null;
    $locationAddress = null;
    if ($category === 'Blessing') {
        $locationAddress = trim($_POST['location_address'] ?? '');
        $contactPhone = $isGuest ? $guestPhone : trim($_POST['contact_phone'] ?? '');
        if ($locationAddress === '' || $contactPhone === '') {
            bookRespondError($isAjax, 'Please provide the address to bless and a contact phone number.', url('parishioner/services.php'));
        }
        if (!$isGuest && !preg_match('/^09[0-9]{9}$/', $contactPhone)) {
            bookRespondError($isAjax, 'Phone number must be exactly 11 digits starting with 09.', url('parishioner/services.php'));
        }
    }

    // ---- Enforce the parish's fixed scheduling rules (Regular/Special, Mass conflicts, staff day-off, funeral mourning period, ...) ----
    $finalTime = $time;
    if (!$isNoScheduleCategory) {
        $check = validateBooking($category, $date, $time, $dateOfDeath, $scheduleTypeToSave, $serviceId);
        if ($isMassIntention) $massIntentionStage = 'validate_schedule';
        if (!$check['valid']) {
            bookRespondError($isAjax, $check['message'], url('parishioner/services.php'));
        }
        $finalTime = $check['forcedTime'] ?? $time;
    }

    // ---- Re-check that this exact service+date+time hasn't just been taken by someone else ----
    if (!$isMassIntention && !$isNoScheduleCategory && serviceSlotIsBooked($serviceId, $date, $finalTime)) {
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
    $requirementsSnapshot = null;
    if ($category === 'Wedding') {
        $requirementsList = ['Baptismal Certificate', 'Confirmation Certificate', "Sponsors' Baptismal Certificate"];
        $requirementsSnapshot = json_encode($requirementsList);
    }
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
                $label = $upload['label'] ? ('"' . $upload['label'] . '": ') : '';
                bookRespondError($isAjax, $label . documentValidationMessage($result['reason']), url('parishioner/services.php'));
            }
        }

        // Guests must provide every service-required document before a booking
        // can be submitted. Registered parishioners may correct documents
        // during the secretary review workflow from the appointment page.
        if ($isGuest && $requirementsList && !in_array($category, ['Baptism', 'Wedding'], true)) {
            $uploadedLabels = array_unique(array_filter(array_map(
                fn($upload) => $upload['label'], $pendingUploads
            )));
            $missing = array_values(array_diff($requirementsList, $uploadedLabels, ['Katin-awan sa Paglubong']));
            if ($missing) {
                bookRespondError($isAjax, 'Please upload all required documents: ' . implode(', ', $missing) . '.', url('parishioner/services.php'));
            }
        }
    }
    
    $katinAwanPayload = [];

    // Baptism always uses the draft workflow. The service category is
    // authoritative so stale forms or missing/tampered draft_mode cannot
    // fall through to the legacy appointment INSERT below.
    if ($category === 'Baptism') {
        $pdo = db(); $createdStorageKeys = [];
        try {
            $pdo->beginTransaction();
            $rawGuestToken = $isGuest ? bin2hex(random_bytes(32)) : null;
            $stmt = $pdo->prepare("INSERT INTO baptism_booking_drafts (parishioner_id, guest_access_token_hash, guest_name, guest_email, guest_phone, service_id, priest_id, appointment_date, appointment_time, schedule_type, pss_claim, sponsor_count, remarks, contact_phone, location_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING draft_id");
            $stmt->execute([$isGuest ? null : $parishionerId, $rawGuestToken ? hash('sha256', $rawGuestToken) : null, $guestName, $guestEmail, $guestPhone, $serviceId, $priestId, $date, $finalTime, $scheduleTypeToSave, $pssClaim, $sponsorCount, $remarks, $contactPhone, $locationAddress]);
            $draftId = (int) $stmt->fetchColumn();
            if ($rawGuestToken) $_SESSION['baptism_draft_tokens'][$draftId] = $rawGuestToken;
            foreach ($pendingUploads as $upload) {
                $stored = documentStorageMoveUpload($upload['file']['tmp_name'], pathinfo($upload['file']['name'], PATHINFO_EXTENSION));
                $createdStorageKeys[] = $stored['key'];
                $stmt = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, baptism_draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (NULL, NULL, ?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
                $stmt->execute([$draftId, $upload['file']['name'], $stored['key'], $stored['mime'], $upload['label']]);
            }
            $pdo->commit();
            $redirect = url('baptism-draft.php?draft_id=' . $draftId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'redirect' => $redirect]); exit; }
            redirect($redirect);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($createdStorageKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); } }
            error_log($e->getMessage());
            bookRespondError($isAjax, 'Unable to save the Baptism booking draft. Please try again.', url('parishioner/services.php'));
    }

    // Funeral uses a session-based draft workflow to avoid a DB migration while
    // maintaining UX consistency (Booking Details -> Requirements -> Submit).
    if ($category === 'Funeral') {
        $rawGuestToken = $isGuest ? bin2hex(random_bytes(32)) : null;
        $draftId = time() . random_int(1000, 9999);
        
        $funeralDraft = [
            'id' => $draftId,
            'expires_at' => time() + (24 * 3600),
            'guest_token' => $rawGuestToken ? hash('sha256', $rawGuestToken) : null,
            'is_guest' => $isGuest,
            'parishioner_id' => $isGuest ? null : $parishionerId,
            'guest_name' => $guestName,
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
            'guest_reference' => $guestReference,
            'service_id' => $serviceId,
            'priest_id' => $priestId,
            'date' => $date,
            'finalTime' => $finalTime,
            'pssClaim' => $pssClaim,
            'pssClassification' => $pssClassification,
            'remarks' => $remarks,
            'contactPhone' => $contactPhone,
            'locationAddress' => $locationAddress,
            'dateOfDeath' => $dateOfDeath,
            'requirementsSnapshot' => $requirementsSnapshot,
            'uploaded_keys' => [],
            'katin_awan_payload' => null
        ];

        $createdStorageKeys = [];
        try {
            foreach ($pendingUploads as $upload) {
                $stored = documentStorageMoveUpload($upload['file']['tmp_name'], pathinfo($upload['file']['name'], PATHINFO_EXTENSION));
                $createdStorageKeys[] = $stored['key'];
                $funeralDraft['uploaded_keys'][] = [
                    'file_name' => $upload['file']['name'],
                    'key' => $stored['key'],
                    'mime' => $stored['mime'],
                    'label' => $upload['label']
                ];
            }
            if (!isset($_SESSION['funeral_booking_drafts'])) $_SESSION['funeral_booking_drafts'] = [];
            $_SESSION['funeral_booking_drafts'][$draftId] = $funeralDraft;
            if ($rawGuestToken) $_SESSION['funeral_draft_tokens'][$draftId] = $rawGuestToken;

            $redirect = url('funeral-draft.php?draft_id=' . $draftId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'redirect' => $redirect]); exit; }
            redirect($redirect);
        } catch (Throwable $e) {
            foreach ($createdStorageKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); } }
            error_log($e->getMessage());
            bookRespondError($isAjax, 'Unable to save the Funeral booking draft. Please try again.', url('parishioner/services.php'));
        }
    }

    if ($category === 'Wedding') {
        $pdo = db();
        $createdStorageKeys = [];
        try {
            $pdo->beginTransaction();
            $rawGuestToken = $isGuest ? bin2hex(random_bytes(32)) : null;
            $stmt = $pdo->prepare("INSERT INTO wedding_booking_drafts (parishioner_id, guest_access_token_hash, guest_name, guest_email, guest_phone, service_id, priest_id, appointment_date, appointment_time, schedule_type, pss_claim, sponsor_count, wedding_sponsor_count, remarks, contact_phone, location_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING draft_id");
            $stmt->execute([$isGuest ? null : $parishionerId, $rawGuestToken ? hash('sha256', $rawGuestToken) : null, $guestName, $guestEmail, $guestPhone, $serviceId, $priestId, $date, $finalTime, $scheduleTypeToSave, $pssClaim, null, $weddingSponsorCount, $remarks, $contactPhone, $locationAddress]);
            $draftId = (int) $stmt->fetchColumn();
            if ($draftId < 1) throw new RuntimeException('Wedding draft could not be created.');
            if ($rawGuestToken) $_SESSION['wedding_draft_tokens'][$draftId] = $rawGuestToken;
            foreach ($pendingUploads as $upload) {
                $stored = documentStorageMoveUpload($upload['file']['tmp_name'], pathinfo($upload['file']['name'], PATHINFO_EXTENSION));
                $createdStorageKeys[] = $stored['key'];
                $stmt = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, draft_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (NULL, ?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')");
                $stmt->execute([$draftId, $upload['file']['name'], $stored['key'], $stored['mime'], $upload['label']]);
            }
            $pdo->commit();
            $redirect = url('wedding-draft.php?draft_id=' . $draftId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'redirect' => $redirect]); exit; }
            redirect($redirect);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($createdStorageKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); } }
            $sqlState = $e instanceof PDOException ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode();
            error_log(sprintf(
                '[Wedding draft creation] operation=insert_or_stage exception=%s code=%s sqlstate=%s file=%s line=%d message=%s',
                get_class($e),
                (string) $e->getCode(),
                (string) $sqlState,
                $e->getFile(),
                $e->getLine(),
                preg_replace('/\s+/', ' ', $e->getMessage())
            ));
            bookRespondError($isAjax, 'Unable to save the Wedding booking draft. Please try again.', url('parishioner/services.php'));
        }
    }

    $pdo = db();
    if ($isMassIntention) $massIntentionStage = 'begin_transaction';
    $pdo->beginTransaction();
    $createdStorageKeys = [];
    try {
        // Manually blocked dates (holidays, etc.) still apply on top of the fixed rules
        if ($date) {
            $stmt = $pdo->prepare('SELECT * FROM calendar WHERE calendar_date = ? AND is_blocked = 1');
            $stmt->execute([$date]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                bookRespondError($isAjax, 'The selected date is not available for booking. Please choose another date.', url('parishioner/services.php'));
            }
        }

        // Mass Intentions skip manual secretary review entirely: they're
        // saved together with their payment record and wait on the Cashier
        // (status 2 = submitted with payment, pending Cashier verification).
        $initialStatusId = $isMassIntention ? 2 : 1;
        $approvedAtColumn = $isMassIntention ? ', approved_at' : '';
        $approvedAtValue = $isMassIntention ? ', NOW()' : '';

        $stmt = $pdo->prepare(
            "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, date_of_death, schedule_type, pss_claim, pss_classification, sponsor_count, wedding_sponsor_count, guest_name, guest_email, guest_phone, guest_reference, contact_phone, location_address, requirements_snapshot{$approvedAtColumn})
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$approvedAtValue})"
        );
        if ($isMassIntention) $massIntentionStage = 'insert_appointment';
        $stmt->execute([$parishionerId, $serviceId, $priestId, $date, $finalTime, $initialStatusId, $remarks, $dateOfDeath, $scheduleTypeToSave, $pssClaim, $pssClassification, $sponsorCount, $weddingSponsorCount, $guestName, $guestEmail, $guestPhone, $guestReference, $contactPhone, $locationAddress, $requirementsSnapshot]);
        $appointmentId = $pdo->lastInsertId();

        $checkoutUrl = null;
        if ($isMassIntention) {
            $massIntentionStage = 'insert_mass_intention';
            $stmt = $pdo->prepare(
                "INSERT INTO mass_intentions (appointment_id, intention_type, offerer_name, intention_for, message)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$appointmentId, $intentionType, $offererName, $intentionFor, $intentionMessage]);

            // The payment record is part of the same transaction — if it (or
            // the online checkout below) can't be created, nothing is saved.
            $methodId = $payOnline ? 7 : $manualMethodId; // 7 = PayMongo (Online)
            $stmt = $pdo->prepare(
                "INSERT INTO payments (appointment_id, reference_number, amount, method_id, payment_status, payment_date)
                 VALUES (?, ?, ?, ?, 'pending', NOW())"
            );
            $massIntentionStage = 'create_payment_record';
            $stmt->execute([$appointmentId, $payOnline ? null : $manualReference, $offeringAmount, $methodId]);
            $paymentId = $pdo->lastInsertId();

            if ($payOnline) {
                $massIntentionStage = 'create_paymongo_checkout';
                $returnBase = absoluteUrl('parishioner/mass-intention-return.php') . '?appointment_id=' . $appointmentId;
                $checkout = paymongoCreateCheckoutSession(
                    $offeringAmount,
                    'Mass Intention Offering',
                    $returnBase . '&paid=1',
                    $returnBase . '&cancelled=1',
                    $isGuest ? $guestEmail : (currentUser()['email'] ?? null)
                );
                if (!$checkout['ok'] || !$checkout['checkout_url']) {
                    $pdo->rollBack();
                    error_log('PayMongo checkout session creation failed (Mass Intention): ' . json_encode($checkout['raw'] ?? []));
                    bookRespondError($isAjax, 'Could not start the online payment (' . ($checkout['error'] ?: 'please try again') . '). Nothing was submitted — please try again, or pay by GCash/Maya/Bank Transfer and enter the reference number.', url('parishioner/services.php'));
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO transactions (payment_id, gateway, gateway_transaction_id, status, raw_response) VALUES (?, 'paymongo', ?, 'pending', ?)"
                );
                $massIntentionStage = 'insert_paymongo_transaction';
                $stmt->execute([$paymentId, $checkout['session_id'], json_encode($checkout['raw'])]);
                $checkoutUrl = $checkout['checkout_url'];
                $_SESSION['mi_checkout'][$appointmentId] = true; // lets only THIS browser cancel it on return
            }
        }

        foreach ($pendingUploads as $upload) {
            $file = $upload['file'];
            try { $stored = documentStorageMoveUpload($file['tmp_name'], pathinfo($file['name'], PATHINFO_EXTENSION)); } catch (Throwable $e) { $stored = null; }
            if ($stored) {
                $createdStorageKeys[] = $stored['key'];
                $stmt = $pdo->prepare(
                    "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified) VALUES (?, ?, ?, ?, ?, 'pending', FALSE)"
                );
                $stmt->execute([$appointmentId, $file['name'], $stored['key'], $stored['mime'], $upload['label']]);
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
                // Online payments are announced once PayMongo confirms them
                // (see parishioner/mass-intention-return.php); a manual
                // payment is on record right now, awaiting the Cashier.
                if (!$payOnline) {
                    $stmt = $pdo->prepare(
                        "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Mass Intention Submitted', ?)"
                    );
                    $stmt->execute([$userId, "Your Mass Intention (#$appointmentId) and payment were submitted. Our Cashier will verify your payment; once approved, your intention is confirmed."]);
                }
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', 'Appointment Submitted', ?)"
                );
                $stmt->execute([$userId, "Your appointment request (#$appointmentId) has been submitted and is pending review. Our secretary will check your requirements next."]);
            }
        }

        if ($isMassIntention) $massIntentionStage = 'commit';
        $pdo->commit();
        logActivity($userId, ($isMassIntention ? "Submitted Mass Intention #$appointmentId with an offering of ₱" . number_format($offeringAmount, 2) : "Booked appointment #$appointmentId") . ($isGuest ? ' (guest)' : ''), 'Appointments');

        if ($isMassIntention && $checkoutUrl) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'redirect' => $checkoutUrl, 'appointment_id' => $appointmentId]);
                exit;
            }
            redirect($checkoutUrl);
        }

        if ($isMassIntention) {
            $successMessage = 'Your Mass Intention and payment were submitted. Our Cashier will verify your payment — once approved, your intention is confirmed.';
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
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($createdStorageKeys as $storageKey) { try { documentStorageDelete($storageKey); } catch (Throwable $cleanupError) { error_log('Document cleanup failed.'); } }
        if ($isMassIntention ?? false) {
            $sqlState = $e instanceof PDOException ? $e->getCode() : '';
            error_log(sprintf(
                'Mass Intention submission failed: stage=%s exception=%s code=%s sqlstate=%s service_id=%d payment_method=%s transaction_active=%s rolled_back=%s message=%s file=%s line=%d',
                $massIntentionStage,
                get_class($e),
                (string) $e->getCode(),
                (string) $sqlState,
                (int) ($serviceId ?? 0),
                (string) ($payMode ?? ''),
                $pdo->inTransaction() ? 'yes' : 'no',
                'yes',
                preg_replace('/\s+/', ' ', $e->getMessage()),
                $e->getFile(),
                $e->getLine()
            ));
        } else {
            error_log($e->getMessage());
        }
        bookRespondError($isAjax, 'Failed to submit appointment. Please try again.', url('parishioner/services.php'));
    }
}

// GET requests no longer render a standalone booking page — booking now
// happens in a modal on the Services page. Kept as a redirect (rather than
// deleting the file) so old bookmarks/links still land somewhere useful.
redirect(url('parishioner/services.php'));
