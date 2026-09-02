<?php require_once __DIR__ . '/../includes/auth.php'; $pageTitle = 'Page Not Found'; include __DIR__ . '/../includes/header.php'; ?>
<div class="auth-wrap">
  <div class="auth-card text-center">
    <h1 style="font-size:64px; margin:0;">404</h1>
    <p class="subtitle">The page you're looking for doesn't exist.</p>
    <a href="<?= url('index.php') ?>" class="btn btn-primary">Go Home</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
