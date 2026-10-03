<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/document-storage.php';
require_once __DIR__ . '/includes/document-validation.php';
require_once __DIR__ . '/includes/funeral-forms.php';
require_once __DIR__ . '/includes/paymongo.php';

$draftId = (int) ($_GET['draft_id'] ?? $_POST['draft_id'] ?? 0);
$user = currentUser();
$pdo = db();

if (!isset($_SESSION['funeral_booking_drafts'][$draftId])) {
    exit('Funeral booking draft not found, expired, or you have already submitted it. Please start again.');
}

$draft = $_SESSION['funeral_booking_drafts'][$draftId];

// Check authorization (Guest token or Parishioner ID match)
if ($draft['is_guest']) {
    $guestToken = $_SESSION['funeral_draft_tokens'][$draftId] ?? null;
    if (!$guestToken || hash('sha256', $guestToken) !== $draft['guest_token']) {
        exit('Access denied to this Funeral draft.');
    }
} else {
    if (!$user) {
        exit('Access denied to this Funeral draft.');
    }
    $q = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
    $q->execute([$user['user_id']]);
    if ((int)$q->fetchColumn() !== (int)$draft['parishioner_id']) {
        exit('Access denied to this Funeral draft.');
    }
}

if ($draft['expires_at'] <= time()) {
    unset($_SESSION['funeral_booking_drafts'][$draftId]);
    unset($_SESSION['funeral_draft_tokens'][$draftId]);
    exit('This Funeral booking draft has expired. Please start again.');
}

// Handling POST for submit or file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    
    if (($_POST['action'] ?? '') === 'upload_documents') {
        $storedKeys = [];
        try {
            foreach (['Death Certificate'] as $label) {
                $field = 'req_doc_0';
                if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $validation = validateUploadedFile($_FILES[$field]);
                if (!$validation['valid']) throw new RuntimeException($label . ': ' . documentValidationMessage($validation['reason']));
                $stored = documentStorageMoveUpload($_FILES[$field]['tmp_name'], pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                $storedKeys[] = $stored['key'];
                $_SESSION['funeral_booking_drafts'][$draftId]['uploaded_keys'][] = [
                    'file_name' => basename((string) $_FILES[$field]['name']),
                    'key' => $stored['key'],
                    'mime' => $validation['mime'],
                    'label' => $label
                ];
            }
            flash('success', 'Funeral supporting documents saved successfully.');
        } catch (Throwable $e) {
            foreach ($storedKeys as $key) { try { documentStorageDelete($key); } catch (Throwable $cleanupError) { error_log('Draft document cleanup failed.'); } }
            flash('error', $e->getMessage());
        }
        redirect(url('funeral-draft.php?draft_id=' . $draftId));
    }
    
    if (($_POST['action'] ?? '') === 'submit_appointment') {
        // Validation before final submit
        $hasDeathCert = false;
        foreach ($draft['uploaded_keys'] as $upl) {
            if ($upl['label'] === 'Death Certificate') $hasDeathCert = true;
        }
        $hasKatinAwan = !empty($draft['katin_awan_payload']);
        
        $missing = [];
        if (!$hasDeathCert) $missing[] = 'Death Certificate';
        if (!$hasKatinAwan) $missing[] = 'Katin-awan sa Paglubong';
        
        if ($missing) {
            flash('error', 'Please complete: ' . implode(', ', $missing) . '.');
            redirect(url('funeral-draft.php?draft_id=' . $draftId));
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare(
                "INSERT INTO appointments (parishioner_id, service_id, priest_id, appointment_date, appointment_time, status_id, remarks, date_of_death, pss_claim, pss_classification, guest_name, guest_email, guest_phone, guest_reference, contact_phone, location_address, requirements_snapshot)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $draft['parishioner_id'],
                $draft['service_id'],
                $draft['priest_id'],
                $draft['date'],
                $draft['finalTime'],
                $draft['remarks'],
                $draft['dateOfDeath'],
                $draft['pssClaim'],
                $draft['pssClassification'],
                $draft['guest_name'],
                $draft['guest_email'],
                $draft['guest_phone'],
                $draft['guest_reference'],
                $draft['contactPhone'],
                $draft['locationAddress'],
                $draft['requirementsSnapshot']
            ]);
            $appointmentId = $pdo->lastInsertId();
            
            // Insert uploaded docs
            foreach ($draft['uploaded_keys'] as $upl) {
                $stmt = $pdo->prepare(
                    "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source) VALUES (?, ?, ?, ?, ?, 'pending', FALSE, 'uploaded')"
                );
                $stmt->execute([$appointmentId, $upl['file_name'], $upl['key'], $upl['mime'], $upl['label']]);
            }
            
            // Generate and save Katin-awan form
            processFuneralGeneratedForm($appointmentId, 'katin_awan_paglubong', $draft['katin_awan_payload']);
            
            $pdo->commit();
            
            // Clean up session
            unset($_SESSION['funeral_booking_drafts'][$draftId]);
            unset($_SESSION['funeral_draft_tokens'][$draftId]);
            
            flash('success', 'Your Funeral appointment request has been submitted for review.');
            
            if ($draft['is_guest']) {
                $_SESSION['guest_success_reference'] = $draft['guest_reference'];
                redirect(url('status.php'));
            } else {
                redirect(url('parishioner/appointment-detail.php?id=' . $appointmentId));
            }
            
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            flash('error', 'Unable to submit Funeral appointment request. Please try again.');
            redirect(url('funeral-draft.php?draft_id=' . $draftId));
        }
    }
}

// Check completion status for rendering
$hasDeathCert = false;
foreach ($draft['uploaded_keys'] as $upl) {
    if ($upl['label'] === 'Death Certificate') $hasDeathCert = true;
}
$hasKatinAwan = !empty($draft['katin_awan_payload']);
$canSubmit = $hasDeathCert && $hasKatinAwan;

$usesPublicShell = $draft['is_guest'];
$pageTitle = 'Funeral Requirements';
$publicNavActive = 'services';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-start.php' : 'dash-start.php');
?>

<div class="content-wrapper <?php echo $draft['is_guest'] ? 'guest-draft-container' : ''; ?>">
  <div class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
      <div class="page-eyebrow">Pending Request</div>
      <h2 class="page-title">Funeral Requirements</h2>
    </div>
    <?php if ($draft['is_guest']): ?>
      <div><span class="badge badge-pending">Guest Session</span></div>
    <?php endif; ?>
  </div>
  
  <?php displayFlash(); ?>
  
  <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark); margin-bottom:24px;">
    <strong>Almost there!</strong> Please provide the required documents and fill out the generated forms below. Once everything is marked Completed, you can submit your request.
  </div>
  
  <div class="grid-2">
    <div>
      <div class="card" style="margin-bottom:24px;">
        <div class="card-header">
          <h3 class="card-title">Supporting Documents</h3>
        </div>
        <div class="card-body">
          <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_documents">
            <div class="draft-req-item <?php echo $hasDeathCert ? 'completed' : 'missing'; ?>">
              <div class="draft-req-info">
                <h4>Death Certificate</h4>
                <?php if ($hasDeathCert): ?>
                  <span class="badge badge-approved">Uploaded</span>
                <?php else: ?>
                  <span class="badge badge-rejected">Missing</span>
                  <div style="margin-top:8px;">
                    <input type="file" name="req_doc_0" accept=".pdf,.jpg,.jpeg,.png">
                  </div>
                <?php endif; ?>
              </div>
            </div>
            
            <?php if (!$hasDeathCert): ?>
              <button type="submit" class="btn btn-outline" style="margin-top:16px;">Save Documents</button>
            <?php endif; ?>
          </form>
        </div>
      </div>
    </div>
    
    <div>
      <div class="card" style="margin-bottom:24px;">
        <div class="card-header">
          <h3 class="card-title">Generated Forms</h3>
        </div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
          
          <div class="generated-form-item">
            <div class="gf-header">
              <span class="gf-title">Katin-awan sa Paglubong</span>
              <?php if ($hasKatinAwan): ?>
                <span class="badge badge-approved">Completed</span>
              <?php else: ?>
                <span class="badge badge-rejected">Not Completed</span>
              <?php endif; ?>
            </div>
            <div class="gf-actions">
              <?php if ($hasKatinAwan): ?>
                <a href="<?= url('funeral-form.php?draft_id=' . urlencode($draftId)) ?>" class="btn btn-outline btn-sm">Edit Form</a>
              <?php else: ?>
                <a href="<?= url('funeral-form.php?draft_id=' . urlencode($draftId)) ?>" class="btn btn-primary btn-sm">Fill Out Form</a>
              <?php endif; ?>
            </div>
          </div>
          
        </div>
      </div>
      
      <div class="card" style="background:var(--cream); border:1px solid var(--cream-dark);">
        <div class="card-body" style="text-align:center;">
          <h3 style="margin-top:0; color:var(--brown);">Ready to Submit?</h3>
          <p style="margin-bottom:20px; font-size:14.5px; color:var(--brown-mid);">Make sure all requirements are Completed before submitting your appointment request.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_appointment">
            <button type="submit" class="btn btn-dark" style="width:100%;" <?php echo !$canSubmit ? 'disabled' : ''; ?>>
              Submit Appointment Request
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/' . ($usesPublicShell ? 'public-shell-end.php' : 'dash-end.php'); include __DIR__ . '/includes/footer.php'; ?>
