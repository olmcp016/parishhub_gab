<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        db()->prepare(
            "INSERT INTO announcements (title, content, posted_by, is_pinned, status) VALUES (?, ?, ?, ?, 'published')"
        )->execute([$_POST['title'], $_POST['content'], $userId, !empty($_POST['is_pinned']) ? 1 : 0]);
        logActivity($userId, "Created announcement: {$_POST['title']}", 'Announcements');
        flash('success', 'Announcement published.');
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM announcements WHERE announcement_id = ?')->execute([$_POST['announcement_id']]);
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

<div class="card" style="max-width:640px;">
  <div class="card-header"><h3>Post Announcement</h3></div>
  <form method="POST" action="<?= url('secretary/announcements.php') ?>">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-group"><label>Title</label><input type="text" name="title" required></div>
    <div class="form-group"><label>Content</label><textarea name="content" rows="4" required></textarea></div>
    <div class="form-group">
      <label><input type="checkbox" name="is_pinned" value="1" style="width:auto; display:inline-block; margin-right:6px;"> Pin to top</label>
    </div>
    <button type="submit" class="btn btn-primary">Publish</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><h3>All Announcements</h3></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Posted</th><th>Pinned</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($announcements as $a): ?>
          <tr>
            <td><?= e($a['title']) ?></td>
            <td><?= formatDate($a['created_at']) ?></td>
            <td><?= $a['is_pinned'] ? 'Yes' : 'No' ?></td>
            <td>
              <form method="POST" action="<?= url('secretary/announcements.php') ?>" onsubmit="return confirm('Delete this announcement?');" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
