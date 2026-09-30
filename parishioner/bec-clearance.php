<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$identity = requireParishionerOrGuest();
if ($identity['is_guest']) {
    http_response_code(403);
    exit('BEC / Cluster Clearance forms are available to registered parishioners only.');
}

$user = currentUser();
$userId = (int) $user['user_id'];
$errors = [];
$old = [
    'sponsor_name' => trim(($user['firstname'] ?? '') . ' ' . ($user['middlename'] ?? '') . ' ' . ($user['lastname'] ?? '')),
    'address' => (string) ($user['address'] ?? ''),
    'child_name' => '',
    'father_name' => '',
    'mother_maiden_name' => '',
    'service_requested' => 'Bunyag',
    'other_service' => '',
    'service_date' => '',
    'status' => 'Active',
    'cluster_number' => '',
    'cluster_name' => '',
];

// Only show the parishioner's own baptism bookings as an optional date source.
$stmt = db()->prepare(
    "SELECT a.appointment_id, a.appointment_date, s.service_name
     FROM appointments a
     JOIN parishioners p ON p.parishioner_id = a.parishioner_id
     JOIN services s ON s.service_id = a.service_id
     WHERE p.user_id = ? AND s.category = 'Baptism' AND a.status_id NOT IN (3, 7)
     ORDER BY a.appointment_date DESC"
);
$stmt->execute([$userId]);
$baptismAppointments = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach ($old as $field => $_) {
        if (isset($_POST[$field])) $old[$field] = trim((string) $_POST[$field]);
    }

    $required = [
        'sponsor_name' => 'Name of Sponsor',
        'address' => 'Pinuy-anan / Address',
        'child_name' => 'Name of the Child',
        'father_name' => 'Father',
        'mother_maiden_name' => "Mother's Maiden Name",
        'service_date' => 'Date of Service',
        'cluster_number' => 'Member of Cluster No.',
        'cluster_name' => 'Cluster Name',
    ];
    foreach ($required as $field => $label) {
        if ($old[$field] === '') $errors[] = $label . ' is required.';
    }
    $allowedServices = ['Bunyag', 'Confirmation', 'Kasal', 'Ninong/Ninang', 'Others'];
    if (!in_array($old['service_requested'], $allowedServices, true)) $errors[] = 'Please select a valid service requested.';
    if ($old['service_requested'] === 'Others' && $old['other_service'] === '') $errors[] = 'Please specify the service.';
    if (!in_array($old['status'], ['Active', 'Inactive'], true)) $errors[] = 'Please select a valid status.';
    if ($old['service_date'] !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $old['service_date']);
        if (!$date || $date->format('Y-m-d') !== $old['service_date']) $errors[] = 'Please select a valid service date.';
    }
    if (!preg_match('/^[0-9]{1,10}$/', $old['cluster_number'])) $errors[] = 'Cluster number must contain 1 to 10 digits.';
    $limits = ['sponsor_name' => 90, 'address' => 120, 'child_name' => 80, 'father_name' => 80, 'mother_maiden_name' => 80, 'other_service' => 50, 'cluster_name' => 60];
    foreach ($limits as $field => $limit) {
        if (mb_strlen($old[$field]) > $limit) $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be $limit characters or fewer.";
    }
}

$showPreview = $_SERVER['REQUEST_METHOD'] === 'POST' && !$errors;
$pageTitle = 'BEC / Cluster Clearance Form';
$active = 'bec-clearance';
$templatePath = url('public/uploads/Cluster%20Clearance%20for%20Baptismal%20Sponsor.jpeg');
$map = [
    'sponsor' => ['left' => 31.5, 'top' => 24.8, 'width' => 43.5],
    'address' => ['left' => 26.0, 'top' => 27.5, 'width' => 47.0],
    'child' => ['left' => 32.0, 'top' => 33.0, 'width' => 48.0],
    'father' => ['left' => 25.5, 'top' => 35.7, 'width' => 43.0],
    'mother' => ['left' => 36.5, 'top' => 38.5, 'width' => 43.0],
    'bunyag' => ['left' => 12.0, 'top' => 47.0], 'confirmation' => ['left' => 27.0, 'top' => 47.0],
    'kasal' => ['left' => 45.5, 'top' => 47.0], 'ninang' => ['left' => 54.0, 'top' => 47.0],
    'others' => ['left' => 30.5, 'top' => 49.8, 'width' => 43.0],
    'date' => ['left' => 50.0, 'top' => 52.7, 'width' => 13.0],
    'active' => ['left' => 72.8, 'top' => 52.7], 'inactive' => ['left' => 84.2, 'top' => 52.7],
    'cluster_number' => ['left' => 32.0, 'top' => 55.5, 'width' => 7.0],
    'cluster_name' => ['left' => 48.0, 'top' => 55.5, 'width' => 35.0],
];

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>
<div class="card bec-entry no-print">
  <div class="card-header"><h2>BEC / Cluster Clearance Form</h2></div>
  <p class="helper-text">Complete the information below, then preview the official Cluster Clearance form. Signature areas remain blank for parish verification.</p>
  <?php if ($errors): ?><div class="alert" style="background:var(--danger-bg);color:var(--danger);"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="POST" novalidate>
    <?= csrfField() ?>
    <h3>Sponsor Information</h3>
    <div class="form-row"><div class="form-group"><label for="sponsor_name">Name of Sponsor</label><input id="sponsor_name" name="sponsor_name" value="<?= e($old['sponsor_name']) ?>" required maxlength="90"></div><div class="form-group"><label for="address">Pinuy-anan / Address</label><input id="address" name="address" value="<?= e($old['address']) ?>" required maxlength="120"></div></div>
    <h3>Child Information</h3>
    <div class="form-row"><div class="form-group"><label for="child_name">Name of the Child</label><input id="child_name" name="child_name" value="<?= e($old['child_name']) ?>" required maxlength="80"></div><div class="form-group"><label for="father_name">Father</label><input id="father_name" name="father_name" value="<?= e($old['father_name']) ?>" required maxlength="80"></div></div>
    <div class="form-group"><label for="mother_maiden_name">Mother's Maiden Name</label><input id="mother_maiden_name" name="mother_maiden_name" value="<?= e($old['mother_maiden_name']) ?>" required maxlength="80"></div>
    <h3>Service Information</h3>
    <div class="form-group"><label>Service Requested</label><div class="form-row"><label><input type="radio" name="service_requested" value="Bunyag" <?= $old['service_requested'] === 'Bunyag' ? 'checked' : '' ?>> Bunyag</label><label><input type="radio" name="service_requested" value="Confirmation" <?= $old['service_requested'] === 'Confirmation' ? 'checked' : '' ?>> Confirmation</label><label><input type="radio" name="service_requested" value="Kasal" <?= $old['service_requested'] === 'Kasal' ? 'checked' : '' ?>> Kasal</label><label><input type="radio" name="service_requested" value="Ninong/Ninang" <?= $old['service_requested'] === 'Ninong/Ninang' ? 'checked' : '' ?>> Ninong/Ninang</label><label><input type="radio" name="service_requested" value="Others" <?= $old['service_requested'] === 'Others' ? 'checked' : '' ?>> Others</label></div></div>
    <div class="form-group" id="otherServiceGroup"><label for="other_service">Please specify</label><input id="other_service" name="other_service" value="<?= e($old['other_service']) ?>" maxlength="50"></div>
    <div class="form-row"><div class="form-group"><label for="service_date">Date of Service / Adlaw sa Serbisyo</label><input type="date" id="service_date" name="service_date" value="<?= e($old['service_date']) ?>" required></div><div class="form-group"><label>Status</label><div class="form-row"><label><input type="radio" name="status" value="Active" <?= $old['status'] === 'Active' ? 'checked' : '' ?>> Active</label><label><input type="radio" name="status" value="Inactive" <?= $old['status'] === 'Inactive' ? 'checked' : '' ?>> Inactive</label></div></div></div>
    <div class="form-row"><div class="form-group"><label for="cluster_number">Member of Cluster No.</label><input id="cluster_number" name="cluster_number" value="<?= e($old['cluster_number']) ?>" required inputmode="numeric" maxlength="10"></div><div class="form-group"><label for="cluster_name">Cluster Name</label><input id="cluster_name" name="cluster_name" value="<?= e($old['cluster_name']) ?>" required maxlength="60"></div></div>
    <button type="submit" class="btn btn-primary">Generate BEC Form</button>
  </form>
</div>

<?php if ($showPreview): ?>
<section class="bec-preview-shell">
  <div class="flex-between no-print" style="margin-bottom:12px;"><h2>Preview</h2><div class="flex gap-2"><button type="button" class="btn btn-outline" onclick="document.querySelector('.bec-entry').scrollIntoView({behavior:'smooth'})">Back / Edit</button><button type="button" class="btn btn-primary" onclick="window.print()">Print / Download PDF</button></div></div>
  <div class="bec-paper" role="document" aria-label="Completed Cluster Clearance for Baptism Sponsor form" style="--template:url('<?= e($templatePath) ?>');">
    <?php $text = ['sponsor' => $old['sponsor_name'], 'address' => $old['address'], 'child' => $old['child_name'], 'father' => $old['father_name'], 'mother' => $old['mother_maiden_name'], 'date' => date('m/d/Y', strtotime($old['service_date'])), 'cluster_number' => $old['cluster_number'], 'cluster_name' => $old['cluster_name']]; foreach ($text as $key => $value): ?><span class="bec-overlay bec-text bec-<?= e($key) ?>" style="left:<?= $map[$key]['left'] ?>%;top:<?= $map[$key]['top'] ?>%;width:<?= $map[$key]['width'] ?>%;" title="<?= e($value) ?>"><?= e($value) ?></span><?php endforeach; ?>
    <?php $selected = strtolower($old['service_requested']); foreach (['bunyag','confirmation','kasal','ninang'] as $key): ?><span class="bec-overlay bec-check bec-<?= $key ?>" style="left:<?= $map[$key]['left'] ?>%;top:<?= $map[$key]['top'] ?>%;"><?= $selected === strtolower(str_replace('ninang', 'ninong/ninang', $key)) ? '✓' : '' ?></span><?php endforeach; ?>
    <?php if ($old['service_requested'] === 'Others'): ?><span class="bec-overlay bec-check" style="left:12%;top:49.8%;">✓</span><span class="bec-overlay bec-text bec-others" style="left:<?= $map['others']['left'] ?>%;top:<?= $map['others']['top'] ?>%;width:<?= $map['others']['width'] ?>%;"><?= e($old['other_service']) ?></span><?php endif; ?>
    <span class="bec-overlay bec-check" style="left:<?= $map[strtolower($old['status'])]['left'] ?>%;top:<?= $map[strtolower($old['status'])]['top'] ?>%;">✓</span>
  </div>
</section>
<?php endif; ?>
<script>
(function () {
  var group = document.getElementById('otherServiceGroup');
  function toggleOther() { var selected = document.querySelector('input[name="service_requested"]:checked'); var show = selected && selected.value === 'Others'; group.style.display = show ? '' : 'none'; document.getElementById('other_service').required = !!show; }
  document.querySelectorAll('input[name="service_requested"]').forEach(function (el) { el.addEventListener('change', toggleOther); }); toggleOther();
  document.querySelector('form').addEventListener('submit', function (event) { var invalid = Array.from(this.querySelectorAll('[required]')).find(function (el) { return !el.value.trim(); }); if (invalid) { invalid.setCustomValidity('Please complete this field.'); invalid.reportValidity(); invalid.setCustomValidity(''); event.preventDefault(); } });
})();
</script>
<style>
.bec-entry h3 { margin: 22px 0 10px; color: var(--brown-dark); }
.bec-paper { position:relative; width:min(100%, 850px); aspect-ratio:1600 / 2048; margin:0 auto; background-image:var(--template); background-size:100% 100%; background-repeat:no-repeat; overflow:hidden; }
.bec-overlay { position:absolute; transform:translateY(-50%); z-index:2; color:#111; font-family:Arial,sans-serif; }
.bec-text { font-size:clamp(9px, 1.25vw, 17px); line-height:1.05; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.bec-check { font-size:clamp(17px, 2.2vw, 30px); font-weight:700; line-height:1; }
.bec-preview-shell { margin:24px 0; }
@media (max-width:700px) { .bec-entry .form-row { display:block; } .bec-entry .form-row > * { margin-bottom:12px; } .bec-preview-shell .flex-between { display:block; } .bec-preview-shell .flex { margin-top:10px; flex-wrap:wrap; } }
@media print { @page { size:A4 portrait; margin:0; } body { background:#fff!important; } .no-print, header, nav, aside, footer { display:none!important; } .bec-preview-shell { margin:0!important; } .bec-paper { width:210mm; height:268.8mm; max-width:none; } }
</style>
<?php include __DIR__ . '/../includes/dash-end.php'; include __DIR__ . '/../includes/footer.php'; ?>
