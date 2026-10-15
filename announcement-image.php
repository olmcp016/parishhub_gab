<?php
/**
 * Public announcement poster (no login needed: announcements are public).
 *
 * announcement-image.php?id=<announcement_id>&slot=<1-3>
 * Serves only the Supabase object stored in that announcement's image column,
 * and only under announcements/images/ — never any other (private) document.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/announcement-images.php';
session_write_close();

function announcementImageNotFound(): void
{
    header('Cache-Control: no-store');
    http_response_code(404);
    exit('Image not found.');
}

$announcementId = (int) ($_GET['id'] ?? 0);
$column = [1 => 'image', 2 => 'image2', 3 => 'image3'][(int) ($_GET['slot'] ?? 0)] ?? null;
if ($announcementId < 1 || $column === null) announcementImageNotFound();

$stmt = db()->prepare("SELECT $column FROM announcements WHERE announcement_id = ?");
$stmt->execute([$announcementId]);
$path = (string) $stmt->fetchColumn();
if (!preg_match(ANNOUNCEMENT_IMAGE_KEY_PATTERN, $path, $match)) announcementImageNotFound();

// The object key is random and never reused, so the image can be cached.
$etag = '"' . sha1($path) . '"';
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

try {
    $stored = documentStorageRead($path);
} catch (Throwable $e) {
    error_log("Announcement #$announcementId image read failed: " . $e->getMessage());
    announcementImageNotFound();
}

$mime = array_search($match[1], ANNOUNCEMENT_IMAGE_EXTENSIONS, true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen((string) $stored['body']));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $stored['body'];
