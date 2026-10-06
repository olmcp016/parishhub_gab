<?php
require_once __DIR__ . '/functions.php';
include __DIR__ . '/public-chatbot.php';
?>

<script>
  window.PARISHHUB_BASE_URL = "<?= url('') ?>";
</script>
<script src="<?= url('public/js/app.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/app.js') ?>"></script>
<script src="<?= url('public/js/supporting-documents.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/supporting-documents.js') ?>"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
</body>
</html>
