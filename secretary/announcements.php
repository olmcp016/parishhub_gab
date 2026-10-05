<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document-validation.php';
requireRole('Secretary', 'Admin');

$userId = currentUser()['user_id'];

/** Resolves a duration_type + its form fields into concrete start_date/end_date. */
function resolveAnnouncementDuration(string $durationType, array $post): array
{
    switch ($durationType) {
        case 'specific_date':
            $d = $post['specific_date'] ?? null;
            return [$d, $d];
        case 'month':
            $month = $post['duration_month'] ?? null; // "YYYY-MM"
            if (!$month) return [null, null];
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            return [$start, $end];
        case 'year':
            $year = $post['duration_year'] ?? null;
            if (!$year) return [null, null];
            return ["$year-01-01", "$year-12-31"];
        case 'custom_range':
            return [$post['start_date'] ?? null, $post['end_date'] ?? null];
        default:
            return [null, null];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $durationType = $_POST['duration_type'] ?: null;
        [$startDate, $endDate] = $durationType ? resolveAnnouncementDuration($durationType, $_POST) : [null, null];

        $imagePath = null;
        $imageProvided = !empty($_FILES['image']['name']);
        if ($imageProvided) {
            $result = validateUploadedFile($_FILES['image']);
            if (!$result['valid']) {
                flash('error', DOCUMENT_VALIDATION_ERROR);
                redirect(url('secretary/announcements.php'));
            }
            $safeName = time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES['image']['name']);
            $dest = __DIR__ . '/../public/uploads/' . $safeName;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                $imagePath = 'public/uploads/' . $safeName;
            }
        }

        if ($action === 'create') {
            $stmt = db()->prepare(
                "INSERT INTO announcements (title, content, posted_by, is_pinned, status, start_date, end_date, duration_type, image, category)
                 VALUES (?, ?, ?, ?, 'published', ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $_POST['title'], $_POST['content'], $userId, !empty($_POST['is_pinned']) ? 1 : 0,
                $startDate, $endDate, $durationType, $imagePath, normalizeAnnouncementCategory($_POST['category'] ?? null),
            ]);
            logActivity($userId, "Created announcement: {$_POST['title']}", 'Announcements');
            flash('success', 'Announcement published.');
        } else {
            $id = (int) $_POST['announcement_id'];
            $stmt = db()->prepare('SELECT start_date, end_date, image FROM announcements WHERE announcement_id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if ($existing && announcementStatus($existing['start_date'], $existing['end_date']) === 'Expired') {
                flash('error', 'This announcement has expired and can no longer be edited.');
                redirect(url('secretary/announcements.php'));
            }

            $finalImage = $imagePath ?: ($existing['image'] ?? null);
            $stmt = db()->prepare(
                "UPDATE announcements SET title = ?, content = ?, is_pinned = ?, start_date = ?, end_date = ?, duration_type = ?, image = ?, category = ?
                 WHERE announcement_id = ?"
            );
            $stmt->execute([
                $_POST['title'], $_POST['content'], !empty($_POST['is_pinned']) ? 1 : 0,
                $startDate, $endDate, $durationType, $finalImage, normalizeAnnouncementCategory($_POST['category'] ?? null), $id,
            ]);
            logActivity($userId, "Updated announcement #$id", 'Announcements');
            flash('success', 'Announcement updated.');
        }
    } elseif ($action === 'delete') {
        $stmt = db()->prepare('SELECT title FROM announcements WHERE announcement_id = ?');
        $stmt->execute([$_POST['announcement_id']]);
        $deletedTitle = $stmt->fetchColumn();
        db()->prepare('DELETE FROM announcements WHERE announcement_id = ?')->execute([$_POST['announcement_id']]);
        logActivity($userId, "Deleted announcement: " . ($deletedTitle ?: '#' . $_POST['announcement_id']), 'Announcements');
        flash('success', 'Announcement removed.');
    }
    redirect(url('secretary/announcements.php'));
}

$announcements = db()->query('SELECT * FROM announcements ORDER BY created_at DESC')->fetchAll();

$active = 'announcements';
$pageTitle = 'Manage Announcements';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card">
  <div class="card-header"><h3>Post Announcement</h3></div>
  <form method="POST" action="<?= url('secretary/announcements.php') ?>" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    
    <div style="display:flex; flex-wrap:wrap; gap:32px;">
      <div style="flex: 1 1 350px;">
        <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
        <div class="form-group">
          <label>Category</label>
          <select name="category">
            <?php foreach (ANNOUNCEMENT_CATEGORIES as $cat): ?><option value="<?= e($cat) ?>"><?= e($cat) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Content</label><textarea name="content" rows="4" required></textarea></div>
        
        <div class="form-group">
          <label>Duration</label>
          <select name="duration_type" id="durationType" onchange="toggleDurationFields()">
            <option value="">No expiry (always active)</option>
            <option value="specific_date">Specific Date</option>
            <option value="month">Whole Month</option>
            <option value="year">Whole Year</option>
            <option value="custom_range">Custom Date Range</option>
          </select>
        </div>
        <div class="form-group duration-field" data-for="specific_date" style="display:none;">
          <label>Date</label><input type="date" name="specific_date">
        </div>
        <div class="form-group duration-field" data-for="month" style="display:none;">
          <label>Month</label><input type="month" name="duration_month">
        </div>
        <div class="form-group duration-field" data-for="year" style="display:none;">
          <label>Year</label><input type="number" name="duration_year" min="2020" max="2100" value="<?= date('Y') ?>">
        </div>
        <div class="form-row duration-field" data-for="custom_range" style="display:none;">
          <div class="form-group"><label>Start Date</label><input type="date" name="start_date"></div>
          <div class="form-group"><label>End Date</label><input type="date" name="end_date"></div>
        </div>

        <div class="form-group">
          <label><input type="checkbox" name="is_pinned" value="1" style="width:auto; display:inline-block; margin-right:6px;"> Pin to top</label>
        </div>
        <button type="submit" class="btn btn-primary">Publish</button>
      </div>

      <div style="flex: 0 0 300px; display:flex; flex-direction:column;">
        <div class="form-group">
          <label>Poster / Image (optional)</label>
          <input type="file" name="image" id="announcementImage" accept=".jpg,.jpeg,.png,.pdf" onchange="previewAnnouncementImage(event)">
        </div>
        <div id="imagePreviewContainer" style="display:none; flex: 1; min-height: 200px; border: 1.5px dashed var(--cream-dark); border-radius: 10px; padding: 12px; text-align: center; background: #faf8f0; align-items:center; justify-content:center; flex-direction:column;">
          <span style="font-size: 12px; font-weight: 700; color: var(--brown-mid); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Preview</span>
          <img id="imagePreview" src="" alt="Preview" style="max-width: 100%; max-height: 250px; border-radius: 6px; object-fit: contain; margin-bottom: 12px; display: none;">
          <div id="pdfPreview" style="display:none; color: var(--brown-dark); font-weight:600; margin: 20px 0;">📄 PDF Document Selected</div>
          <button type="button" class="btn btn-outline btn-sm" onclick="clearAnnouncementImage()">Remove File</button>
        </div>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Announcements</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Posted</th><th>Duration</th><th>Status</th><th>Pinned</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($announcements as $a): ?>
          <?php $status = announcementStatus($a['start_date'], $a['end_date']); ?>
          <tr>
            <td><?= e($a['title']) ?></td>
            <td><?= formatDate($a['created_at']) ?></td>
            <td>
              <?php if ($a['start_date'] || $a['end_date']): ?>
                <?= $a['start_date'] ? formatDate($a['start_date']) : '—' ?> to <?= $a['end_date'] ? formatDate($a['end_date']) : '—' ?>
              <?php else: ?>
                <span class="text-muted">No expiry</span>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $status === 'Active' ? 'verified' : ($status === 'Upcoming' ? 'regular' : 'cancelled') ?>"><?= $status ?></span></td>
            <td><?= $a['is_pinned'] ? 'Yes' : 'No' ?></td>
            <td style="white-space:nowrap;">
              <?php if ($status !== 'Expired'): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('editModal-<?= $a['announcement_id'] ?>').showModal()">Edit</button>
              <?php endif; ?>
              <button type="button" class="btn btn-danger btn-sm js-delete-announcement" data-id="<?= $a['announcement_id'] ?>">Delete</button>
            </td>
          </tr>
          <dialog class="modal" id="editModal-<?= $a['announcement_id'] ?>">
            <div class="modal-head">
              <h3>Edit Announcement</h3>
              <button type="button" class="modal-close" onclick="document.getElementById('editModal-<?= $a['announcement_id'] ?>').close()">✕</button>
            </div>
            <div class="modal-body">
              <form method="POST" action="<?= url('secretary/announcements.php') ?>" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
                <div class="form-group"><label>Title</label><input type="text" name="title" value="<?= e($a['title']) ?>" required></div>
                <div class="form-group">
                  <label>Category</label>
                  <select name="category">
                    <?php foreach (ANNOUNCEMENT_CATEGORIES as $cat): ?><option value="<?= e($cat) ?>" <?= ($a['category'] ?? 'Announcement') === $cat ? 'selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group"><label>Content</label><textarea name="content" rows="4" required><?= e($a['content']) ?></textarea></div>
                <div class="form-group">
                  <label>Poster / Image (optional — leave blank to keep current)</label>
                  <?php if ($a['image']): ?><p><img src="<?= documentUrl($a['image']) ?>" style="max-width:120px; border-radius:8px;"></p><?php endif; ?>
                  <input type="file" name="image" accept=".jpg,.jpeg,.png,.pdf">
                </div>
                <div class="form-group">
                  <label>Duration</label>
                  <select name="duration_type">
                    <option value="" <?= !$a['duration_type'] ? 'selected' : '' ?>>No expiry (always active)</option>
                    <option value="custom_range" <?= $a['duration_type'] ? 'selected' : '' ?>>Custom Date Range</option>
                  </select>
                </div>
                <div class="form-row">
                  <div class="form-group"><label>Start Date</label><input type="date" name="start_date" value="<?= e($a['start_date'] ?? '') ?>"></div>
                  <div class="form-group"><label>End Date</label><input type="date" name="end_date" value="<?= e($a['end_date'] ?? '') ?>"></div>
                </div>
                <div class="form-group">
                  <label><input type="checkbox" name="is_pinned" value="1" <?= $a['is_pinned'] ? 'checked' : '' ?> style="width:auto; display:inline-block; margin-right:6px;"> Pin to top</label>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
              </form>
            </div>
          </dialog>
        <?php endforeach; ?>
        <?php if (empty($announcements)): ?>
          <tr><td colspan="6" class="text-muted">No announcements yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<dialog id="deleteAnnouncementModal" style="max-width:400px;padding:24px;border-radius:8px;border:none;">
  <h3 style="margin-top:0;">Delete Announcement?</h3>
  <p style="color:var(--text-muted,#555);">Are you sure you want to delete this announcement?</p>
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
    <button type="button" class="btn btn-outline" onclick="document.getElementById('deleteAnnouncementModal').close()">Cancel</button>
    <form method="POST" action="<?= url('secretary/announcements.php') ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="announcement_id" id="deleteAnnouncementId">
      <button type="submit" class="btn btn-danger">Yes, Delete</button>
    </form>
  </div>
</dialog>

<script>
function previewAnnouncementImage(event) {
  const file = event.target.files[0];
  const container = document.getElementById('imagePreviewContainer');
  const imgPreview = document.getElementById('imagePreview');
  const pdfPreview = document.getElementById('pdfPreview');

  if (file && file.type.startsWith('image/')) {
    const reader = new FileReader();
    reader.onload = function(e) {
      imgPreview.src = e.target.result;
      imgPreview.style.display = 'block';
      pdfPreview.style.display = 'none';
      container.style.display = 'flex';
    };
    reader.readAsDataURL(file);
  } else if (file && file.type === 'application/pdf') {
    imgPreview.style.display = 'none';
    imgPreview.src = '';
    pdfPreview.style.display = 'block';
    container.style.display = 'flex';
  } else {
    clearAnnouncementImage();
  }
}

function clearAnnouncementImage() {
  document.getElementById('announcementImage').value = '';
  document.getElementById('imagePreviewContainer').style.display = 'none';
  document.getElementById('imagePreview').src = '';
  document.getElementById('imagePreview').style.display = 'none';
  document.getElementById('pdfPreview').style.display = 'none';
}

function toggleDurationFields() {
  var type = document.getElementById('durationType').value;
  document.querySelectorAll('.duration-field').forEach(function (el) {
    el.style.display = el.dataset.for === type ? '' : 'none';
  });
}

document.querySelectorAll('.js-delete-announcement').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('deleteAnnouncementId').value = this.dataset.id;
    document.getElementById('deleteAnnouncementModal').showModal();
  });
});
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
