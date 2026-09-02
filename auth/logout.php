<?php
require_once __DIR__ . '/../includes/auth.php';
$_SESSION = [];
session_destroy();
session_start();
redirect(url('auth/login.php'));
