<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
requireRole('Secretary', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'verify_document') {
        db()->prepare('UPDATE uploaded_documents SET verified = TRUE WHERE document_id = ? AND appointment_id = ?')
            ->execute([$_POST['document_id'], $id]);
        logActivity($userId, "Verified a document for appointment #$id", 'Appointments');
        flash('success', 'Document marked as verified.');
        redirect(url('secretary/appointment-detail.php?id=' . $id));
    }

    if ($action === 'approve') {
        // Require that any required documents have been verified before approving.
        $stmt = db()->prepare(
            "SELECT s.requirements FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$id]);
        $requirements = $stmt->fetchColumn();

        if (!empty($requirements)) {
            $stmt = db()->prepare('SELECT COUNT(*) FROM uploaded_documents WHERE appointment_id = ? AND verified = TRUE');
            $stmt->execute([$id]);
            $verifiedCount = (int) $stmt->fetchColumn();

            if ($verifiedCount === 0) {
                flash('error', 'This service requires documents (' . $requirements . '). Please verify at least one uploaded document before approving, or contact the parishioner to submit them.');
                redirect(url('secretary/appointment-detail.php?id=' . $id));
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
        flash('success', 'Appointment approved. The parishioner may now proceed to payment.');
    } elseif ($action === 'reject') {
        db()->prepare("UPDATE appointments SET status_id = 3, remarks = ? WHERE appointment_id = ?")
            ->execute([$_POST['reason'] ?? 'Rejected by secretary', $id]);
        logActivity($userId, "Rejected appointment #$id", 'Appointments');
        flash('success', 'Appointment rejected.');
    } elseif ($action === 'assign_priest') {
        $priestId = $_POST['priest_id'];

        // Priest availability check — cannot double-book a priest at the same date/time.
        $stmt = db()->prepare(
            "SELECT appointment_date, appointment_time FROM appointments WHERE appointment_id = ?"
        );
        $stmt->execute([$id]);
        $slot = $stmt->fetch();

        $stmt = db()->prepare(
            "SELECT a.appointment_id, s.service_name FROM appointments a
             JOIN services s ON a.service_id = s.service_id
             WHERE a.priest_id = ? AND a.appointment_date = ? AND a.appointment_time = ?
               AND a.status_id NOT IN (3, 7) AND a.appointment_id != ?"
        );
        $stmt->execute([$priestId, $slot['appointment_date'], $slot['appointment_time'], $id]);
        $conflict = $stmt->fetch();

        if ($conflict) {
            flash('error', "This priest is already assigned to another appointment (\"{$conflict['service_name']}\", #{$conflict['appointment_id']}) at the exact same date and time. Please choose a different priest or reschedule first.");
        } else {
            db()->prepare("UPDATE appointments SET priest_id = ? WHERE appointment_id = ?")
                ->execute([$priestId, $id]);
            logActivity($userId, "Assigned priest to appointment #$id", 'Appointments');
            flash('success', 'Priest assigned.');
        }
    } elseif ($action === 'reschedule') {
        $newDate = $_POST['appointment_date'];
        $newTime = $_POST['appointment_time'];

        $stmt = db()->prepare(
            "SELECT s.category FROM appointments a JOIN services s ON a.service_id = s.service_id WHERE a.appointment_id = ?"
        );
        $stmt->execute([$id]);
        $category = $stmt->fetchColumn();

        $check = validateBooking($category, $newDate, $newTime);
        if (!$check['valid']) {
            flash('error', $check['message']);
        } else {
            $finalTime = $check['forcedTime'] ?? $newTime;
            db()->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ? WHERE appointment_id = ?")
                ->execute([$newDate, $finalTime, $id]);
            logActivity($userId, "Rescheduled appointment #$id", 'Appointments');
            flash('success', 'Schedule updated.');
        }
    } elseif ($action === 'confirm') {
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
        flash('success', 'Appointment confirmed.');
    } elseif ($action === 'complete') {
        db()->prepare("UPDATE appointments SET status_id = 6 WHERE appointment_id = ?")->execute([$id]);
        logActivity($userId, "Marked appointment #$id as completed", 'Appointments');
        flash('success', 'Appointment marked as completed.');
    }
    redirect(url('secretary/appointment-detail.php?id=' . $id));
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
    flash('error', 'Appointment not found.');
    redirect(url('secretary/appointments.php'));
}

$priests = db()->query("SELECT * FROM priests WHERE status = 'active'")->fetchAll();
$stmt = db()->prepare('SELECT * FROM mass_intentions WHERE appointment_id = ?');
$stmt->execute([$id]);
$intention = $stmt->fetch() ?: null;
$stmt = db()->prepare('SELECT * FROM uploaded_documents WHERE appointment_id = ?');
$stmt->execute([$id]);
$documents = $stmt->fetchAll();

// Each priest's upcoming schedule, so the secretary can check availability
// before assigning — directly at the point of decision.
$priestSchedules = [];
foreach ($priests as $p) {
    $stmt = db()->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.appointment_time, s.service_name
         FROM appointments a JOIN services s ON a.service_id = s.service_id
         WHERE a.priest_id = ? AND a.status_id NOT IN (3, 7) AND a.appointment_date >= CURDATE()
         ORDER BY a.appointment_date, a.appointment_time LIMIT 5"
    );
    $stmt->execute([$p['priest_id']]);
    $priestSchedules[$p['priest_id']] = $stmt->fetchAll();
}

$active = 'appointments';
$pageTitle = 'Appointment #' . $appointment['appointment_id'];
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div style="display:grid; grid-template-columns: 1.4fr 1fr; gap: 22px;">
  <div class="card">
    <div class="card-header">
      <h3><?= e($appointment['service_name']) ?></h3>
      <span class="badge badge-<?= badgeClass($appointment['status_name']) ?>"><?= e($appointment['status_name']) ?></span>
    </div>
    <p><strong>Parishioner:</strong> <?= e($appointment['firstname']) ?> <?= e($appointment['lastname']) ?> (<?= e($appointment['email']) ?>, <?= e($appointment['phone']) ?>)</p>
    <p><strong>Date:</strong> <?= formatDate($appointment['appointment_date']) ?> at <?= date('g:i A', strtotime($appointment['appointment_time'])) ?></p>
    <p><strong>Fee:</strong> <?= money($appointment['fee']) ?></p>
    <?php if ($appointment['category'] === 'Funeral' && $appointment['date_of_death']): ?>
      <p><strong>Date of Death:</strong> <?= formatDate($appointment['date_of_death']) ?> <span class="text-muted">(9-day mourning period ends <?= formatDate(date('Y-m-d', strtotime($appointment['date_of_death'] . ' +9 days'))) ?>)</span></p>
    <?php endif; ?>
    <?php if ($appointment['requirements']): ?>
      <p><strong>Required Documents:</strong> <?= e($appointment['requirements']) ?></p>
    <?php endif; ?>
    <?php if ($appointment['remarks']): ?><p><strong>Remarks:</strong> <?= e($appointment['remarks']) ?></p><?php endif; ?>

    <?php if ($intention): ?>
      <hr style="border-color: var(--cream-dark); margin: 18px 0;">
      <h4>Mass Intention Details</h4>
      <p><strong>Type:</strong> <?= e($intention['intention_type']) ?></p>
      <p><strong>Offerer:</strong> <?= e($intention['offerer_name']) ?></p>
      <p><strong>Intention For:</strong> <?= e($intention['intention_for']) ?></p>
    <?php endif; ?>

    <hr style="border-color: var(--cream-dark); margin: 18px 0;">
    <h4>Uploaded Documents</h4>
    <?php if (empty($documents)): ?><p class="text-muted">No documents uploaded yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>File</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($documents as $d): ?>
              <tr>
                <td>
                  <a href="<?= documentUrl($d['file_path']) ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:10px;">
                    <?php if (isImageFile($d['file_name'])): ?>
                      <img src="<?= documentUrl($d['file_path']) ?>" alt="<?= e($d['file_name']) ?>" style="width:44px; height:44px; object-fit:cover; border-radius:6px; border:1px solid var(--cream-dark);">
                    <?php else: ?>
                      <span style="display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; background:var(--cream); border-radius:6px; font-size:18px;">📄</span>
                    <?php endif; ?>
                    <span><?= e($d['file_name']) ?></span>
                  </a>
                </td>
                <td><?= $d['verified'] ? '<span class="badge badge-verified">Verified</span>' : '<span class="badge badge-pending">Pending Review</span>' ?></td>
                <td>
                  <?php if (!$d['verified']): ?>
                    <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" style="display:inline;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="verify_document">
                      <input type="hidden" name="document_id" value="<?= $d['document_id'] ?>">
                      <button type="submit" class="btn btn-outline btn-sm">Mark Verified</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><h3>Actions</h3></div>
      <?php if ($appointment['status_name'] === 'Pending'): ?>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-2">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approve">
          <button type="submit" class="btn btn-success btn-block">✔ Approve</button>
        </form>
        <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" onsubmit="return confirm('Reject this appointment?');">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="reason" value="Does not meet requirements">
          <button type="submit" class="btn btn-danger btn-block">✖ Reject</button>
        </form>
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

    <div class="card">
      <div class="card-header"><h3>Assign Priest</h3></div>
      <form method="POST" action="<?= url('secretary/appointment-detail.php?id=' . $id) ?>" class="mb-3">
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
        <button type="submit" class="btn btn-outline btn-block">Assign</button>
      </form>

      <p class="text-muted" style="font-size:12.5px; margin-bottom:6px;">Priest availability (next 5 upcoming appointments each):</p>
      <?php foreach ($priests as $p): ?>
        <div class="mb-2" style="font-size:12.5px; border-bottom: 1px solid var(--cream-dark); padding-bottom:6px;">
          <strong><?= e($p['title']) ?> <?= e($p['full_name']) ?></strong>
          <?php if (empty($priestSchedules[$p['priest_id']])): ?>
            <span class="text-muted"> — no upcoming appointments (fully available)</span>
          <?php else: ?>
            <ul style="margin: 4px 0 0 18px; padding: 0;">
              <?php foreach ($priestSchedules[$p['priest_id']] as $sched): ?>
                <li><?= formatDate($sched['appointment_date']) ?> at <?= date('g:i A', strtotime($sched['appointment_time'])) ?> — <?= e($sched['service_name']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

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
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
