<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Secretary', 'Admin');

// Secretary / Admin access to manage priests & priest portal logins.
// Delegates directly to admin/priests.php where full CRUD, portal creation,
// and deactivation with reason logging are handled.
redirect(url('admin/priests.php'));
