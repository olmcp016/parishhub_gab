<?php
/**
 * PARISHHUB — announcement poster images.
 *
 * Render's container filesystem is wiped on every redeploy/restart, so posters
 * saved in public/uploads/ disappear while their database rows remain. When
 * Supabase storage is configured (production), new posters are stored in the
 * existing private document bucket under announcements/images/ and served to
 * everyone (guests included) by announcement-image.php, which only ever serves
 * an object an announcement row points to. Without Supabase (local
 * development) they stay in public/uploads/, served directly as before.
 *
 * Stored values (announcements.image / image2 / image3):
 *   supabase://announcements/images/announcement-<32 hex>.<jpg|png|gif|webp>
 *   public/uploads/<name>   (local development and older rows)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/document-storage.php';

/** Validated MIME type -> file extension (the extension never comes from the uploaded name). */
const ANNOUNCEMENT_IMAGE_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const ANNOUNCEMENT_IMAGE_KEY_PATTERN = '#^supabase://announcements/images/announcement-[a-f0-9]{32}\.(jpg|png|gif|webp)$#';

/** Saves one validated upload; returns the value to store in the image column. Throws on failure. */
function announcementImageStore(string $tmpPath, string $mime): string
{
    $extension = ANNOUNCEMENT_IMAGE_EXTENSIONS[$mime] ?? null;
    if ($extension === null) throw new InvalidArgumentException('Unsupported announcement image type.');
    $filename = 'announcement-' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (documentStorageSupabaseEnabled()) {
        $key = 'announcements/images/' . $filename;
        documentStorageSupabaseRequest('POST', $key, (string) file_get_contents($tmpPath), $mime);
        return 'supabase://' . $key;
    }
    if (!move_uploaded_file($tmpPath, dirname(__DIR__) . '/public/uploads/' . $filename)) {
        throw new RuntimeException('Announcement image could not be saved.');
    }
    return 'public/uploads/' . $filename;
}

/** True for posters kept in Supabase storage (served by announcement-image.php). */
function announcementImageIsStored(string $path): bool
{
    return (bool) preg_match(ANNOUNCEMENT_IMAGE_KEY_PATTERN, $path);
}

/** Browser URL of an announcement's poster in one slot, from any page (root or role folder). */
function announcementImageUrl(int $announcementId, int $slot, string $path): string
{
    if (announcementImageIsStored($path)) {
        // v= changes whenever the slot holds a different file, so long browser caching stays correct.
        return url('announcement-image.php?id=' . $announcementId . '&slot=' . $slot . '&v=' . substr(sha1($path), 0, 12));
    }
    return documentUrl($path);
}

/** Removes stored posters that are no longer used (best effort; never throws). */
function announcementImagesDelete(array $paths): void
{
    foreach ($paths as $path) {
        if (!announcementImageIsStored((string) $path)) continue; // older public/uploads files are left as they were
        try {
            documentStorageDelete((string) $path);
        } catch (Throwable $e) {
            error_log('Announcement image cleanup failed: ' . $e->getMessage());
        }
    }
}
