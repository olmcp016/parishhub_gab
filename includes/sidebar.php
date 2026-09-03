<?php
$__user = currentUser();
$__active = $active ?? '';
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="crest"><?= crestMarkup() ?></div>
    <div>
      <span class="brand-text">PARISHHUB</span>
      <span class="brand-sub">Parish Service Portal</span>
    </div>
  </div>

  <ul class="sidebar-nav">
    <?php if ($__user['role_name'] === 'Parishioner'): ?>
      <li><a href="<?= url('parishioner/dashboard.php') ?>" class="<?= $__active==='dashboard'?'active':'' ?>"><span class="nav-icon"><i data-lucide="layout-dashboard"></i></span> Dashboard</a></li>
      <li><a href="<?= url('parishioner/services.php') ?>" class="<?= $__active==='services'?'active':'' ?>"><span class="nav-icon"><i data-lucide="heart-handshake"></i></span> Services</a></li>
      <li><a href="<?= url('parishioner/book.php') ?>" class="<?= $__active==='book'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-plus"></i></span> Book Appointment</a></li>
      <li><a href="<?= url('parishioner/appointments.php') ?>" class="<?= $__active==='appointments'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-check"></i></span> My Appointments</a></li>
      <li><a href="<?= url('parishioner/calendar.php') ?>" class="<?= $__active==='calendar'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-days"></i></span> Parish Calendar</a></li>
      <li><a href="<?= url('parishioner/announcements.php') ?>" class="<?= $__active==='announcements'?'active':'' ?>"><span class="nav-icon"><i data-lucide="megaphone"></i></span> Announcements</a></li>
      <li><a href="<?= url('parishioner/notifications.php') ?>" class="<?= $__active==='notifications'?'active':'' ?>"><span class="nav-icon"><i data-lucide="bell"></i></span> Notifications</a></li>
      <li><a href="<?= url('parishioner/profile.php') ?>" class="<?= $__active==='profile'?'active':'' ?>"><span class="nav-icon"><i data-lucide="user-circle"></i></span> My Profile</a></li>
    <?php elseif ($__user['role_name'] === 'Secretary'): ?>
      <li><a href="<?= url('secretary/dashboard.php') ?>" class="<?= $__active==='dashboard'?'active':'' ?>"><span class="nav-icon"><i data-lucide="layout-dashboard"></i></span> Dashboard</a></li>
      <li><a href="<?= url('secretary/appointments.php') ?>" class="<?= $__active==='appointments'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-check"></i></span> Appointments</a></li>
      <li><a href="<?= url('secretary/calendar.php') ?>" class="<?= $__active==='calendar'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-days"></i></span> Calendar</a></li>
      <li><a href="<?= url('secretary/announcements.php') ?>" class="<?= $__active==='announcements'?'active':'' ?>"><span class="nav-icon"><i data-lucide="megaphone"></i></span> Announcements</a></li>
      <li><a href="<?= url('secretary/parishioners.php') ?>" class="<?= $__active==='parishioners'?'active':'' ?>"><span class="nav-icon"><i data-lucide="users"></i></span> Parishioners</a></li>
      <li><a href="<?= url('secretary/services.php') ?>" class="<?= $__active==='services'?'active':'' ?>"><span class="nav-icon"><i data-lucide="heart-handshake"></i></span> Services</a></li>
      <li><a href="<?= url('secretary/reports.php') ?>" class="<?= $__active==='reports'?'active':'' ?>"><span class="nav-icon"><i data-lucide="bar-chart-3"></i></span> Reports</a></li>
    <?php elseif ($__user['role_name'] === 'Treasurer'): ?>
      <li><a href="<?= url('treasurer/dashboard.php') ?>" class="<?= $__active==='dashboard'?'active':'' ?>"><span class="nav-icon"><i data-lucide="layout-dashboard"></i></span> Dashboard</a></li>
      <li><a href="<?= url('treasurer/payments.php') ?>" class="<?= $__active==='payments'?'active':'' ?>"><span class="nav-icon"><i data-lucide="banknote"></i></span> Payments</a></li>
      <li><a href="<?= url('secretary/calendar.php') ?>" class="<?= $__active==='calendar'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-days"></i></span> Calendar</a></li>
      <li><a href="<?= url('treasurer/reports.php') ?>" class="<?= $__active==='reports'?'active':'' ?>"><span class="nav-icon"><i data-lucide="bar-chart-3"></i></span> Financial Reports</a></li>
    <?php elseif ($__user['role_name'] === 'Admin'): ?>
      <li><a href="<?= url('admin/dashboard.php') ?>" class="<?= $__active==='dashboard'?'active':'' ?>"><span class="nav-icon"><i data-lucide="layout-dashboard"></i></span> Dashboard</a></li>
      <li><a href="<?= url('admin/users.php') ?>" class="<?= $__active==='users'?'active':'' ?>"><span class="nav-icon"><i data-lucide="users"></i></span> Users & Roles</a></li>
      <li><a href="<?= url('admin/priests.php') ?>" class="<?= $__active==='priests'?'active':'' ?>"><span class="nav-icon"><i data-lucide="contact"></i></span> Priests</a></li>
      <li><a href="<?= url('admin/services.php') ?>" class="<?= $__active==='services'?'active':'' ?>"><span class="nav-icon"><i data-lucide="heart-handshake"></i></span> Services</a></li>
      <li><a href="<?= url('secretary/calendar.php') ?>" class="<?= $__active==='calendar'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-days"></i></span> Calendar</a></li>
      <li><a href="<?= url('secretary/appointments.php') ?>" class="<?= $__active==='appointments'?'active':'' ?>"><span class="nav-icon"><i data-lucide="calendar-check"></i></span> Appointments</a></li>
      <li><a href="<?= url('treasurer/payments.php') ?>" class="<?= $__active==='payments'?'active':'' ?>"><span class="nav-icon"><i data-lucide="banknote"></i></span> Payments</a></li>
      <li><a href="<?= url('admin/reports.php') ?>" class="<?= $__active==='reports'?'active':'' ?>"><span class="nav-icon"><i data-lucide="bar-chart-3"></i></span> System Reports</a></li>
      <li><a href="<?= url('admin/activity-logs.php') ?>" class="<?= $__active==='logs'?'active':'' ?>"><span class="nav-icon"><i data-lucide="history"></i></span> Activity Logs</a></li>
      <li><a href="<?= url('admin/settings.php') ?>" class="<?= $__active==='settings'?'active':'' ?>"><span class="nav-icon"><i data-lucide="settings"></i></span> Settings</a></li>
      <li><a href="<?= url('admin/backup.php') ?>" class="<?= $__active==='backup'?'active':'' ?>"><span class="nav-icon"><i data-lucide="database-backup"></i></span> Backup & Restore</a></li>
    <?php endif; ?>
  </ul>

  <div class="sidebar-footer">
    <form method="POST" action="<?= url('auth/logout.php') ?>">
      <?= csrfField() ?>
      <button type="submit" class="link-btn" style="background:none; border:none; padding:0; cursor:pointer; color: var(--gold-light); font: inherit; display:inline-flex; align-items:center; gap:6px;">
        <i data-lucide="log-out" style="width:16px; height:16px;"></i> Logout
      </button>
    </form>
    <div style="margin-top:6px;">© <?= date('Y') ?> PARISHHUB</div>
  </div>
</aside>
