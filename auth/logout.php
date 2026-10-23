<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('auth/login.php'));
}
verifyCsrf();

// Capture role before the session is destroyed so we can redirect
// the user back to the correct portal.
$role = $_SESSION['user']['role_name'] ?? null;

$_SESSION = [];
session_destroy();
session_start();

$staffRoles = ['Admin', 'Secretary', 'Treasurer', 'Priest'];
$target = ($role && in_array($role, $staffRoles, true))
    ? url('staff/login.php')
    : url('auth/login.php');

redirect($target);
