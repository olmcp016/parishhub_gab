<?php
/**
 * Alias redirect — admin/login.php → staff/login.php
 * Kept for backward-compatibility with any bookmarked or hard-coded links.
 */
require_once __DIR__ . '/../includes/auth.php';
redirect(url('staff/login.php'));
