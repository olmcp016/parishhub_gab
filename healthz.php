<?php
/**
 * PARISHHUB — health check for Render (Health Check Path: /healthz.php).
 *
 * Deliberately loads nothing: no config/config.php (so no session start and
 * no database connection), no authentication. It only proves Apache + PHP are
 * up and serving, so a deploy never waits on Supabase being reachable.
 */
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'OK';
