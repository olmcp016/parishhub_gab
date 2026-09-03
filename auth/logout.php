<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('auth/login.php'));
}
verifyCsrf();

$_SESSION = [];
session_destroy();
session_start();
redirect(url('auth/login.php'));
