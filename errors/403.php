<?php $pageTitle = 'Access Denied'; include __DIR__ . '/../includes/header.php'; ?>
<div class="auth-wrap">
  <div class="auth-card text-center">
    <h1 style="font-size:64px; margin:0;">403</h1>
    <p class="subtitle">You don't have permission to access this page.</p>
    <a href="<?= url('index.php') ?>" class="btn btn-primary">Go Home</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
