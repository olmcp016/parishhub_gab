<?php $pageTitle = 'Server Error'; include __DIR__ . '/../includes/header.php'; ?>
<div class="auth-wrap">
  <div class="auth-card text-center">
    <h1 style="font-size:64px; margin:0;">500</h1>
    <p class="subtitle">Something went wrong on our end. Please try again later.</p>
    <a href="<?= url('index.php') ?>" class="btn btn-primary">Go Home</a>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
