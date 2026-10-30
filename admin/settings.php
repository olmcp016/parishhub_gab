<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Admin');

$adminId = currentUser()['user_id'];

// Strict allowlist — only these keys may ever be written to the settings table.
// Adding a key to the form without adding it here has no effect.
const SETTINGS_ALLOWED_KEYS = [
    'parish_name', 'parish_address', 'office_hours',
    'contact_number', 'contact_email', 'mass_schedule',
];

/** "Rev. Fr. Al John Miñoza / Parish Priest" — a priest record exactly as stored, for logs. */
function priestRecordLabel(?array $priest): string
{
    return $priest ? trim($priest['title'] . ' / ' . $priest['full_name'], ' /') : 'Not set';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'signatory_names') {
    // Certificate Signatory Names: the Admin types and confirms each priest's official
    // printed name. Only a name submitted here is ever "verified" — nothing is
    // suggested into these fields. All names are checked before any is saved.
    verifyCsrf();
    require_once __DIR__ . '/../fpdf.php';
    require_once __DIR__ . '/../includes/certificate-generator.php';
    $verified = verifiedPriestNames();
    $updated = $verified;
    $errors = [];
    $changes = [];
    foreach ((array) ($_POST['printed_name'] ?? []) as $id => $input) {
        if (!ctype_digit((string) $id) || !($priest = activePriestById((int) $id))) {
            $errors[] = 'A printed name was submitted for a priest who is not an active priest record.';
            continue;
        }
        $id = (int) $id;
        $input = trim((string) $input);
        if ($input === '') {
            if (isset($updated[$id])) { $changes[] = [$priest, $updated[$id], null]; unset($updated[$id]); }
            continue;
        }
        [$name, $problem] = normalizePriestPrintedName($input);
        if ($problem !== null) { $errors[] = 'The printed name for ' . priestRecordLabel($priest) . " $problem."; continue; }
        $tooLong = certificateSignatoryFitErrors($name);
        if ($tooLong) { $errors[] = "\"$name\" is too long to print in full on the " . implode(', ', $tooLong) . ' certificate.'; continue; }
        if (($updated[$id] ?? null) !== $name) { $changes[] = [$priest, $updated[$id] ?? null, $name]; $updated[$id] = $name; }
    }
    if ($errors) {
        flash('error', 'No signatory names were saved. ' . implode(' ', $errors));
        redirect(url('admin/settings.php'));
    }
    if ($changes) {
        ksort($updated);
        db()->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('priest_printed_names', ?)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value"
        )->execute([json_encode((object) $updated, JSON_UNESCAPED_UNICODE)]);
        foreach ($changes as [$priest, $old, $new]) {
            logActivity($adminId, 'Certificate signatory name for priest #' . $priest['priest_id'] . ' (' . priestRecordLabel($priest) . ') changed from "'
                . ($old ?? 'Not verified') . '" to "' . ($new ?? 'Not verified') . '"', 'Settings');
        }
    }
    flash('success', $changes ? 'Certificate signatory names saved.' : 'No signatory names were changed.');
    redirect(url('admin/settings.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $stmt = db()->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value"
    );

    // Current Parish Priest: an active priest WITH a verified printed name (or cleared).
    // Checked before anything is saved, so an invalid choice changes nothing.
    $newParishPriest = null;
    if (array_key_exists('parish_priest_id', $_POST)) {
        $posted = trim((string) $_POST['parish_priest_id']);
        if ($posted !== '' && (!ctype_digit($posted) || !activePriestById((int) $posted) || priestPrintedName((int) $posted) === null)) {
            flash('error', 'Please choose the Current Parish Priest from the list of active priests with a verified printed name.');
            redirect(url('admin/settings.php'));
        }
        $newParishPriest = $posted;
    }

    foreach (SETTINGS_ALLOWED_KEYS as $key) {
        if (!array_key_exists($key, $_POST)) continue;
        $stmt->execute([$key, trim($_POST[$key])]);
    }

    if ($newParishPriest !== null) {
        $before = currentParishPriest();
        $oldId = trim((string) db()->query("SELECT setting_value FROM settings WHERE setting_key = 'parish_priest_id'")->fetchColumn());
        if ($oldId !== $newParishPriest) {
            $stmt->execute(['parish_priest_id', $newParishPriest]);
            $after = $newParishPriest !== '' ? activePriestById((int) $newParishPriest) : null;
            logActivity($adminId, 'Changed Current Parish Priest from "'
                . ($before ? (priestPrintedName($before['priest_id']) ?? priestRecordLabel($before)) : 'Not set') . '" to "'
                . ($after ? priestPrintedName($after['priest_id']) : 'Not set') . '"', 'Settings');
        }
    }
    logActivity($adminId, 'Updated system settings', 'Settings');
    flash('success', 'Settings updated.');
    redirect(url('admin/settings.php'));
}

$rows = db()->query('SELECT * FROM settings')->fetchAll();
$map = [];
foreach ($rows as $r) { $map[$r['setting_key']] = $r['setting_value']; }
$signatoryPriests = signatoryPriests();   // active + verified: the only Current Parish Priest choices
$currentParishPriest = currentParishPriest();
$currentParishPriestName = currentParishPriestSignatory();
$allPriests = db()->query('SELECT priest_id, title, full_name, status FROM priests ORDER BY priest_id')->fetchAll();
$verifiedNames = verifiedPriestNames();

$active = 'settings';
$pageTitle = 'System Settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Parish Information</h3></div>
  <form method="POST" action="<?= url('admin/settings.php') ?>">
    <?= csrfField() ?>
    <div class="form-group"><label>Parish Name</label><input type="text" name="parish_name" value="<?= e($map['parish_name'] ?? '') ?>"></div>
    <div class="form-group"><label>Parish Address</label><input type="text" name="parish_address" value="<?= e($map['parish_address'] ?? '') ?>"></div>
    <div class="form-group"><label>Office Hours</label><input type="text" name="office_hours" value="<?= e($map['office_hours'] ?? '') ?>"></div>
    <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" value="<?= e($map['contact_number'] ?? '') ?>"></div>
    <div class="form-group"><label>Contact Email</label><input type="text" name="contact_email" value="<?= e($map['contact_email'] ?? '') ?>"></div>
    <div class="form-group"><label>Mass Schedule</label><textarea name="mass_schedule" rows="2"><?= e($map['mass_schedule'] ?? '') ?></textarea></div>
    <div class="form-group">
      <label for="parishPriestId">Current Parish Priest</label>
      <select id="parishPriestId" name="parish_priest_id">
        <option value="">— Not set —</option>
        <?php foreach ($signatoryPriests as $priest): ?>
          <option value="<?= (int) $priest['priest_id'] ?>" <?= $currentParishPriest && $priest['priest_id'] === $currentParishPriest['priest_id'] ? 'selected' : '' ?>><?= e($priest['printed_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="helper-text">Printed as the Parish Priest on new certificates and on the Wedding, Baptism and Funeral application forms. Certificates already prepared keep the name saved with them. Only active priests with a verified printed name (Certificate Signatory Names, below) are listed.</p>
      <?php if (!$currentParishPriest && trim((string) ($map['parish_priest_id'] ?? '')) !== ''): ?>
        <p class="helper-text" style="color:var(--danger);">The saved Parish Priest is no longer an active priest. Choose the current Parish Priest; until then the Parish Priest line is left blank and new certificates cannot be generated.</p>
      <?php elseif ($currentParishPriest && $currentParishPriestName === ''): ?>
        <p class="helper-text" style="color:var(--danger);">The Current Parish Priest (<?= e(priestRecordLabel($currentParishPriest)) ?>) has no verified printed name. Verify it under Certificate Signatory Names; until then the Parish Priest line is left blank and new certificates cannot be generated.</p>
      <?php elseif (!$currentParishPriest): ?>
        <p class="helper-text" style="color:var(--danger);">No Current Parish Priest is set: the Parish Priest line is left blank and new certificates cannot be generated.</p>
      <?php endif; ?>
    </div>
    <button type="submit" class="btn btn-primary">Save Settings</button>
  </form>
</div>

<div class="card" style="margin-top:20px;">
  <div class="card-header"><h3>Certificate Signatory Names</h3></div>
  <p class="helper-text" style="margin-top:0;">
    The official name printed for each priest on certificates and application forms (upper case; Ñ and other accents are kept).
    Type and confirm it exactly as it should appear, including the middle initial, e.g. <strong>REV. FR. JUAN A. DELA CRUZ</strong>.
    The Title and Full Name stored in Priest Management are shown for reference only and are never printed together.
    A priest without a verified name cannot be chosen as Current Parish Priest or as a certificate signatory. Clear a name to remove its verification.
  </p>
  <form method="POST" action="<?= url('admin/settings.php') ?>">
    <?= csrfField() ?>
    <input type="hidden" name="form" value="signatory_names">
    <div class="table-wrap">
      <table>
        <thead><tr><th>ID</th><th>Title (as stored)</th><th>Full Name (as stored)</th><th>Status</th><th>Verified printed name</th></tr></thead>
        <tbody>
          <?php foreach ($allPriests as $priest): $pid = (int) $priest['priest_id']; $isVerified = isset($verifiedNames[$pid]); ?>
            <tr>
              <td><?= $pid ?></td>
              <td><?= e($priest['title']) ?></td>
              <td><?= e($priest['full_name']) ?></td>
              <td><?= e(ucfirst(str_replace('_', ' ', (string) $priest['status']))) ?></td>
              <td>
                <?php if ($priest['status'] === 'active'): ?>
                  <input type="text" name="printed_name[<?= $pid ?>]" value="<?= e($verifiedNames[$pid] ?? '') ?>" maxlength="80" placeholder="Not verified — type the official printed name" aria-label="Verified printed name for priest #<?= $pid ?>">
                <?php else: ?>
                  <?= $isVerified ? e($verifiedNames[$pid]) : '<span class="text-muted">—</span>' ?>
                <?php endif; ?>
                <span class="badge <?= $isVerified ? 'badge-verified' : 'badge-pending' ?>" style="margin-top:4px;"><?= $isVerified ? 'Verified' : 'Not verified' ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="submit" class="btn btn-primary" style="margin-top:12px;">Save Signatory Names</button>
  </form>
</div>



<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
