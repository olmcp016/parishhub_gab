<?php
require_once __DIR__ . '/functions.php';
include __DIR__ . '/public-chatbot.php';
$__authUser = currentUser();
?>

<script>
  window.PARISHHUB_BASE_URL = "<?= url('') ?>";
  window.PARISHHUB_AUTH = {
    loggedIn: <?= $__authUser ? 'true' : 'false' ?>,
    userId: <?= $__authUser ? (int) $__authUser['user_id'] : 'null' ?>,
    role: <?= $__authUser ? json_encode($__authUser['role_name']) : 'null' ?>
  };
</script>
<script src="<?= url('public/js/auth-sync.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/auth-sync.js') ?>"></script>
<script src="<?= url('public/js/app.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/app.js') ?>"></script>
<script src="<?= url('public/js/supporting-documents.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/supporting-documents.js') ?>"></script>
<script src="https://unpkg.com/lucide@1.64.0/dist/umd/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
