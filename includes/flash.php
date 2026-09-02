<?php if (!empty($__flash['success'])): foreach ($__flash['success'] as $msg): ?>
  <div class="alert alert-success">✔ <?= e($msg) ?></div>
<?php endforeach; endif; ?>
<?php if (!empty($__flash['error'])): foreach ($__flash['error'] as $msg): ?>
  <div class="alert alert-error">⚠ <?= e($msg) ?></div>
<?php endforeach; endif; ?>
