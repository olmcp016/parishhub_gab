<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/funeral-forms.php';
require_once __DIR__ . '/includes/document-storage.php';

$appointmentId = (int) ($_REQUEST['appointment_id'] ?? 0);
$type = 'katin_awan_paglubong';

$user = currentUser();
$guest = $_SESSION['guest_status_verification'] ?? null;
$isGuest = !$user && is_array($guest) && (int) ($guest['appointment_id'] ?? 0) === $appointmentId && (int) ($guest['expires_at'] ?? 0) >= time();
if ($user && !in_array($user['role_name'] ?? '', ['Parishioner'], true)) { http_response_code(403); exit('Only the appointment owner may edit this form.'); }

$stmt = db()->prepare("SELECT a.*, s.category FROM appointments a JOIN services s ON s.service_id = a.service_id WHERE a.appointment_id = ? AND s.category = 'Funeral'");
$stmt->execute([$appointmentId]);
$appointment = $stmt->fetch();
if (!$appointment) { http_response_code(404); exit('Funeral Appointment not found.'); }
if ($user) {
    $q = db()->prepare('SELECT 1 FROM parishioners WHERE parishioner_id = ? AND user_id = ?');
    $q->execute([$appointment['parishioner_id'], $user['user_id']]);
    if (!$q->fetchColumn()) { http_response_code(403); exit('Not authorized.'); }
} elseif (!$isGuest) { http_response_code(403); exit('Verify the guest appointment before accessing this form.'); }

$formQuery = db()->prepare('SELECT * FROM generated_funeral_forms WHERE appointment_id = ? AND form_type = ?');
$formQuery->execute([$appointmentId, $type]);
$form = $formQuery->fetch() ?: null;
$data = $form ? (json_decode($form['form_data'], true) ?: []) : [];

if ($form) {
    $data = funeralKatinAwanNormalizeData($data);
} else {
    $profile = [];
    if ($user) {
        $profileQuery = db()->prepare('SELECT firstname, lastname, middlename, phone, address FROM users WHERE user_id = ?');
        $profileQuery->execute([$user['user_id']]);
        $profile = $profileQuery->fetch() ?: [];
    }
    
    $informantName = trim((string) ($appointment['guest_name'] ?? ''));
    $informantAddress = trim((string) ($appointment['location_address'] ?? ''));
    $informantPhone = trim((string) (($appointment['contact_phone'] ?? '') ?: ($appointment['guest_phone'] ?? '')));
    
    if ($user) {
        $informantName = trim(implode(' ', array_filter([$profile['firstname'] ?? '', $profile['middlename'] ?? '', $profile['lastname'] ?? ''])));
        $informantAddress = trim((string) ($profile['address'] ?? ''));
        $informantPhone = trim((string) ($profile['phone'] ?? ''));
    }
    
    $data = [
        'deceased_name' => '',
        'deceased_age' => '',
        'cause_of_death' => '',
        'place_of_wake' => '',
        'burial_date' => (string) ($appointment['appointment_date'] ?? ''),
        'burial_time' => substr((string) ($appointment['appointment_time'] ?? ''), 0, 5),
        'cemetery' => '',
        'spouse_name' => '',
        'father_name' => '',
        'mother_name' => '',
        'informant_name' => $informantName,
        'informant_relationship' => '',
        'informant_address' => $informantAddress,
        'informant_phone' => $informantPhone,
    ];
}

$def = funeralFormDefinition($type);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $postData = funeralKatinAwanNormalizeData($_POST);
    
    try {
        processFuneralGeneratedForm($appointmentId, $type, $postData, $form ? $form['document_id'] : null);
        flash('success', $def['title'] . ' has been generated and submitted for review.');
        $redirectUrl = $isGuest ? url('status.php?ref=' . urlencode($appointment['guest_reference'])) : url('parishioner/appointment-detail.php?id=' . $appointmentId);
        redirect($redirectUrl);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<?php ob_start(); ?>
<style>
.form-section { background: #fff; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 24px; border: 1px solid var(--cream-dark); }
.form-section h3 { margin-top: 0; color: var(--brown); margin-bottom: 20px; font-size: 1.1rem; border-bottom: 2px solid var(--cream); padding-bottom: 10px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 600px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
.back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--brown-mid); text-decoration: none; margin-bottom: 20px; font-weight: 500; }
.back-link:hover { color: var(--brown); }
</style>
<div style="max-width:800px; margin:0 auto; padding: 20px;">
    <?php if ($isGuest): ?>
        <a href="<?= url('status.php?ref=' . urlencode($appointment['guest_reference'])) ?>" class="back-link">← Back to Appointment</a>
    <?php else: ?>
        <a href="<?= url('parishioner/appointment-detail.php?id=' . $appointmentId) ?>" class="back-link">← Back to Appointment</a>
    <?php endif; ?>
    
    <div style="text-align:center; margin-bottom: 30px;">
        <h1 style="margin:0 0 10px; color:var(--brown);"><?= e($def['title']) ?></h1>
        <p class="text-muted" style="margin:0;">Fill out the details below to generate the official form.</p>
    </div>

    <?php if (!empty($error)): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger);"><?= e($error) ?></div><?php endif; ?>
    <?php if ($form && $form['status'] === 'rejected' && $form['rejection_reason']): ?>
        <div class="alert" style="background:var(--danger-bg); color:var(--danger); border:1px solid #f5c2c2;">
            <strong>Secretary requested revisions:</strong> <?= e($form['rejection_reason']) ?>
        </div>
    <?php endif; ?>
    <?php if ($form && $form['status'] === 'generated'): ?>
        <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
            This form has already been generated and submitted. You can edit and regenerate it below if you need to make corrections.
        </div>
    <?php endif; ?>

    <form method="POST">
        <?= csrfField() ?>
        
        <div class="form-section">
            <h3>Details of the Deceased</h3>
            <div class="form-group">
                <label>Ngalan sa Namatay (Name of Deceased) <span style="color:var(--danger)">*</span></label>
                <input type="text" name="deceased_name" value="<?= e($data['deceased_name']) ?>" required>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Edad (Age) <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="deceased_age" value="<?= e($data['deceased_age']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Sakit / Hinungdan sa Kamatayon (Cause of Death) <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="cause_of_death" value="<?= e($data['cause_of_death']) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Lugar sa Minatay (Place of Wake) <span style="color:var(--danger)">*</span></label>
                <input type="text" name="place_of_wake" value="<?= e($data['place_of_wake']) ?>" required>
            </div>
            <div class="grid-3">
                <div class="form-group">
                    <label>Petsa sa Paglubong (Burial Date) <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="burial_date" value="<?= e($data['burial_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Oras (Time) <span style="color:var(--danger)">*</span></label>
                    <input type="time" name="burial_time" value="<?= e($data['burial_time']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Sementeryo (Cemetery) <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="cemetery" value="<?= e($data['cemetery']) ?>" required>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h3>Family Information</h3>
            <div class="form-group">
                <label>Ngalan sa Bana o Asawa (Name of Spouse, if applicable)</label>
                <input type="text" name="spouse_name" value="<?= e($data['spouse_name']) ?>">
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Ngalan sa Amahan (Father's Name)</label>
                    <input type="text" name="father_name" value="<?= e($data['father_name']) ?>">
                </div>
                <div class="form-group">
                    <label>Ngalan sa Inahan (Mother's Name)</label>
                    <input type="text" name="mother_name" value="<?= e($data['mother_name']) ?>">
                </div>
            </div>
        </div>

        <div class="form-section">
            <h3>Informant Details</h3>
            <div class="grid-2">
                <div class="form-group">
                    <label>Pangalan sa Nagpa-lubong (Informant Name) <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="informant_name" value="<?= e($data['informant_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Relasyon (Relationship) <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="informant_relationship" value="<?= e($data['informant_relationship']) ?>" required>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Address <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="informant_address" value="<?= e($data['informant_address']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Telepono (Phone) <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="informant_phone" value="<?= e($data['informant_phone']) ?>" required>
                </div>
            </div>
        </div>

        <div style="margin-top:30px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
            <button type="submit" class="btn btn-primary" style="padding:12px 30px; font-size:1.1rem;">Generate Application Form</button>
            <?php if ($form && $form['document_id']): ?>
                <a href="<?= documentViewUrl((int) $form['document_id']) ?>" target="_blank" rel="noopener" class="btn btn-outline" style="padding:12px 20px;">Preview Current Form</a>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
