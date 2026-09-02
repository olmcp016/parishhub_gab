<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('Admin');

$active = 'backup';
$pageTitle = 'Backup & Restore';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width: 700px;">
  <div class="card-header"><h3>Database Backup (via phpMyAdmin)</h3></div>
  <ol style="padding-left:20px; line-height:2;">
    <li>Open <strong>phpMyAdmin</strong> and select the <code>parishhub</code> database from the left sidebar.</li>
    <li>Click the <strong>Export</strong> tab at the top.</li>
    <li>Choose <strong>Quick</strong> export method and <strong>SQL</strong> format.</li>
    <li>Click <strong>Go</strong> — this downloads a full <code>.sql</code> backup of all tables and data.</li>
  </ol>
  <p class="text-muted" style="font-size:13px;">Store this file somewhere safe (external drive, cloud storage). We recommend doing this at least once a day, or before any major change.</p>

  <p class="mt-3"><strong>Alternative (command line):</strong></p>
  <pre style="background: var(--brown-darkest); color: var(--gold-light); padding: 14px; border-radius: 8px; overflow-x:auto;">mysqldump -u root -p parishhub > parishhub_backup_<?= date('Y-m-d') ?>.sql</pre>
</div>

<div class="card" style="max-width: 700px;">
  <div class="card-header"><h3>Database Restore (via phpMyAdmin)</h3></div>
  <ol style="padding-left:20px; line-height:2;">
    <li>Open <strong>phpMyAdmin</strong> and select (or create) the <code>parishhub</code> database.</li>
    <li>Click the <strong>Import</strong> tab at the top.</li>
    <li>Click <strong>Choose File</strong> and select your <code>.sql</code> backup file.</li>
    <li>Click <strong>Go</strong> to restore.</li>
  </ol>
  <p class="text-muted" style="font-size:13px;">⚠ Restoring will overwrite existing data. Always back up your current database before restoring an older one.</p>
</div>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
