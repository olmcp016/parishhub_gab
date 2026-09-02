<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$services = db()->query("SELECT * FROM services WHERE is_active = 1 ORDER BY category, service_name")->fetchAll();

$active = 'services';
$pageTitle = 'Available Services';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="grid-3">
  <?php foreach ($services as $s): ?>
    <div class="card">
      <h3><?= e($s['service_name']) ?></h3>
      <p class="text-muted" style="font-size:12.5px; text-transform:uppercase; letter-spacing:.4px;"><?= e($s['category']) ?></p>
      <p style="font-size:14px;"><?= e($s['description']) ?></p>
      <p class="text-muted" style="font-size:13px;"><strong>Requirements:</strong> <?= e($s['requirements'] ?: 'None') ?></p>
      <div class="flex-between mt-3">
        <span class="text-gold" style="font-weight:700; font-size:18px;"><?= money($s['fee']) ?></span>
        <a href="<?= url('parishioner/book.php?service_id=' . $s['service_id']) ?>" class="btn btn-primary btn-sm">Book Now</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
