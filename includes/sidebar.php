<?php
$__user = currentUser();
$__active = $active ?? '';

/**
 * Renders one sidebar group: a small muted uppercase label followed by its
 * links. Each group after the first gets a divider line above it (see
 * ".nav-section + .nav-section" in style.css), matching a plain flat
 * account-nav layout — no accordion/collapse behavior.
 */
if (!function_exists('renderNavSection')) {
function renderNavSection(string $slug, string $title, array $items, string $activeKey): void
{
    ?>
    <div class="nav-section" data-section="<?= e($slug) ?>">
      <div class="nav-section-title"><?= e($title) ?></div>
      <ul>
        <?php foreach ($items as [$href, $key, $icon, $label]): ?>
          <li><a href="<?= $href ?>" class="<?= $activeKey === $key ? 'active' : '' ?>"><span class="nav-icon"><i data-lucide="<?= e($icon) ?>"></i></span> <?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
}
}
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="crest"><?= crestMarkup() ?></div>
    <div>
      <span class="brand-text">PARISHHUB</span>
      <span class="brand-sub">Parish Service Portal</span>
    </div>
  </div>

  <nav class="sidebar-nav" id="sidebarNav">
    <?php if ($__user['role_name'] === 'Parishioner'): ?>
      <?php renderNavSection('overview', 'Overview', [
        [url('parishioner/dashboard.php'), 'dashboard', 'layout-dashboard', 'Dashboard'],
      ], $__active); ?>
      <?php renderNavSection('parish-services', 'Parish Services', [
        [url('parishioner/services.php'), 'services', 'heart-handshake', 'Services'],
        [url('parishioner/appointments.php'), 'appointments', 'calendar-check', 'My Appointments'],
      ], $__active); ?>
      <?php renderNavSection('donations-projects', 'Donations & Projects', [
        [url('parishioner/donations.php'), 'donations', 'hand-heart', 'My Donations'],
        [url('parishioner/projects.php'), 'projects', 'hard-hat', 'Ongoing Projects'],
      ], $__active); ?>
      <?php renderNavSection('parish-info', 'Parish Information', [
        [url('parishioner/calendar.php'), 'calendar', 'calendar-days', 'Parish Calendar'],
        [url('parishioner/announcements.php'), 'announcements', 'megaphone', 'Announcements'],
      ], $__active); ?>
      <?php renderNavSection('notifications', 'Notifications', [
        [url('parishioner/notifications.php'), 'notifications', 'bell', 'Notifications'],
      ], $__active); ?>
      <?php renderNavSection('account', 'Account', [
        [url('parishioner/profile.php'), 'profile', 'user-circle', 'My Profile'],
      ], $__active); ?>
    <?php elseif ($__user['role_name'] === 'Secretary'): ?>
      <?php renderNavSection('overview', 'Overview', [
        [url('secretary/dashboard.php'), 'dashboard', 'layout-dashboard', 'Dashboard'],
      ], $__active); ?>
      <?php renderNavSection('parishioner-priest', 'Parishioner & Priest Management', [
        [url('secretary/parishioners.php'), 'parishioners', 'users', 'Parishioners'],
        [url('secretary/priest-unavailability.php'), 'priest-unavailability', 'user-x', 'Priest Unavailability'],
      ], $__active); ?>
      <?php renderNavSection('services-scheduling', 'Services & Scheduling', [
        [url('secretary/services.php'), 'services', 'heart-handshake', 'Services'],
        [url('secretary/calendar.php'), 'calendar', 'calendar-days', 'Calendar'],
        [url('secretary/locations.php'), 'locations', 'map-pin', 'Locations'],
        [url('secretary/appointments.php'), 'appointments', 'calendar-check', 'Appointments'],
      ], $__active); ?>
      <?php renderNavSection('parish-content', 'Parish Content', [
        [url('secretary/announcements.php'), 'announcements', 'megaphone', 'Announcements'],
        [url('secretary/projects.php'), 'projects', 'hard-hat', 'Ongoing Projects'],
      ], $__active); ?>
      <?php renderNavSection('reports', 'Reports', [
        [url('secretary/reports.php'), 'reports', 'bar-chart-3', 'Reports'],
      ], $__active); ?>
      <?php renderNavSection('system', 'System', [
        [url('secretary/settings.php'), 'settings', 'settings', 'Settings'],
      ], $__active); ?>
    <?php elseif ($__user['role_name'] === 'Treasurer'): ?>
      <?php renderNavSection('overview', 'Overview', [
        [url('treasurer/dashboard.php'), 'dashboard', 'layout-dashboard', 'Dashboard'],
      ], $__active); ?>
      <?php renderNavSection('payments-transactions', 'Payments & Transactions', [
        [url('treasurer/payments.php'), 'payments', 'history', 'Transaction History'],
        [url('treasurer/donations.php'), 'donations', 'hand-heart', 'Donations'],
        [url('secretary/mass-intentions.php'), 'mass-intentions', 'flame', 'Mass Intentions'],
      ], $__active); ?>
      <?php renderNavSection('scheduling', 'Scheduling', [
        [url('secretary/calendar.php'), 'calendar', 'calendar-days', 'Calendar'],
      ], $__active); ?>
      <?php renderNavSection('financial-reports', 'Financial Reports', [
        [url('treasurer/reports.php'), 'reports', 'bar-chart-3', 'Financial Reports'],
      ], $__active); ?>
    <?php elseif ($__user['role_name'] === 'Admin'): ?>
      <?php renderNavSection('overview', 'Overview', [
        [url('admin/dashboard.php'), 'dashboard', 'layout-dashboard', 'Dashboard'],
      ], $__active); ?>
      <?php renderNavSection('user-access', 'User & Access Management', [
        [url('admin/users.php'), 'users', 'users', 'Users & Roles'],
        [url('secretary/parishioners.php'), 'parishioners', 'user-cog', 'Parishioner Management'],
        [url('admin/priests.php'), 'priests', 'contact', 'Priests'],
        [url('secretary/priest-unavailability.php'), 'priest-unavailability', 'user-x', 'Priest Unavailability'],
      ], $__active); ?>
      <?php renderNavSection('parish-management', 'Parish Management', [
        [url('secretary/locations.php'), 'locations', 'map-pin', 'Locations'],
        [url('admin/services.php'), 'services', 'heart-handshake', 'Services'],
      ], $__active); ?>
      <?php renderNavSection('scheduling-appointments', 'Services & Scheduling', [
        [url('admin/service-schedules.php'), 'service-schedules', 'calendar-clock', 'Regular Schedules'],
        [url('secretary/calendar.php'), 'calendar', 'calendar-days', 'Calendar'],
        [url('secretary/appointments.php'), 'appointments', 'calendar-check', 'Appointments'],
        [url('secretary/mass-intentions.php'), 'mass-intentions', 'flame', 'Mass Intentions'],
      ], $__active); ?>
      <?php renderNavSection('payments-finance', 'Payments & Finance', [
        [url('treasurer/payments.php'), 'payments', 'banknote', 'Payment & Transaction Overview'],
        [url('treasurer/donations.php'), 'donations', 'hand-heart', 'Donations'],
      ], $__active); ?>
      <?php renderNavSection('reports-monitoring', 'Reports & Monitoring', [
        [url('admin/reports.php'), 'reports', 'bar-chart-3', 'Financial Reports'],
        [url('admin/activity-logs.php'), 'logs', 'history', 'Activity Logs'],
      ], $__active); ?>
      <?php renderNavSection('system', 'System', [
        [url('admin/settings.php'), 'settings', 'settings', 'Settings'],
        [url('admin/backup.php'), 'backup', 'database-backup', 'Backup & Restore'],
      ], $__active); ?>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <form method="POST" action="<?= url('auth/logout.php') ?>" id="logoutForm">
      <?= csrfField() ?>
      <button type="button" class="link-btn" onclick="document.getElementById('logoutModal').showModal()" style="background:none; border:none; padding:0; cursor:pointer; color: var(--gold-light); font: inherit; display:inline-flex; align-items:center; gap:6px;">
        <i data-lucide="log-out" style="width:16px; height:16px;"></i> Logout
      </button>
    </form>
    <div style="margin-top:6px;">© <?= date('Y') ?> PARISHHUB</div>
  </div>
</aside>

<dialog class="modal" id="logoutModal">
  <div class="modal-head">
    <h3>Log Out</h3>
    <button type="button" class="modal-close" onclick="document.getElementById('logoutModal').close()">✕</button>
  </div>
  <div class="modal-body">
    <p style="margin-top:0;">Are you sure you want to log out?</p>
    <div class="flex gap-3" style="justify-content:flex-end;">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('logoutModal').close()">Cancel</button>
      <button type="button" class="btn btn-danger" onclick="document.getElementById('logoutForm').submit()">Log Out</button>
    </div>
  </div>
</dialog>
