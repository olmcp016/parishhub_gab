<?php
require_once __DIR__ . '/functions.php';
?>
<!--Start of Tawk.to Script-->
<script type="text/javascript">
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
(function(){
var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
s1.async=true;
s1.src='https://embed.tawk.to/6ac4c7ee43f3e034cadce656/1k48apv72';
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
<!--End of Tawk.to Script-->

<script>
  window.PARISHHUB_BASE_URL = "<?= url('') ?>";
</script>
<script src="<?= url('public/js/app.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/app.js') ?>"></script>
<script src="<?= url('public/js/supporting-documents.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/supporting-documents.js') ?>"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
</body>
</html>
