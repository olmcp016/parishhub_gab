<?php
/**
 * PARISHHUB — Document upload validation
 *
 * Technical-only checks (no AI/authenticity claim — that's still a human
 * staff step via uploaded_documents.verified): confirms a file actually
 * arrived, its REAL content type (not the spoofable client-supplied
 * $_FILES[...]['type']), that it isn't corrupted, and — for images — that
 * it's portrait-oriented, per the upload instructions shown to parishioners.
 *
 * Shared by parishioner/book.php and parishioner/appointment-detail.php so
 * both upload paths enforce the exact same rules.
 */

const ALLOWED_DOCUMENT_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

/**
 * @param array $file One entry of $_FILES (already isolated to a single file,
 *                     i.e. $_FILES['documents']['name'][$i] etc. reassembled
 *                     into the normal single-file $_FILES shape by the caller).
 * @return array{valid: bool, mime: ?string, reason: ?string} reason is one of
 *         'missing'|'unreadable'|'invalid_type'|'corrupted'|'orientation' when invalid.
 */
function validateUploadedFile(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'mime' => null, 'reason' => 'missing'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'mime' => null, 'reason' => 'unreadable'];
    }
    if (($file['size'] ?? 0) <= 0) {
        return ['valid' => false, 'mime' => null, 'reason' => 'unreadable'];
    }
    if ((int) $file['size'] > (defined('MAX_DOCUMENT_UPLOAD_MB') ? MAX_DOCUMENT_UPLOAD_MB : 2) * 1024 * 1024) {
        return ['valid' => false, 'mime' => null, 'reason' => 'too_large'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ALLOWED_DOCUMENT_MIME_TYPES, true)) {
        return ['valid' => false, 'mime' => $mime, 'reason' => 'invalid_type'];
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $validExtensions = match ($mime) {
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        default => [],
    };
    if (!in_array($extension, $validExtensions, true)) {
        return ['valid' => false, 'mime' => $mime, 'reason' => 'invalid_type'];
    }

    if ($mime === 'application/pdf') {
        $header = @file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($header !== '%PDF-') {
            return ['valid' => false, 'mime' => $mime, 'reason' => 'corrupted'];
        }
        return ['valid' => true, 'mime' => $mime, 'reason' => null];
    }

    // JPEG/PNG: must actually decode as an image, and be portrait-oriented
    // (height >= width) per the upload instructions shown to parishioners.
    // Phone cameras commonly store a portrait photo with landscape pixel
    // dimensions plus a JPEG EXIF orientation flag. Honor that flag for the
    // orientation check without changing the accepted file formats.
    $dimensions = @getimagesize($file['tmp_name']);
    if ($dimensions === false) {
        return ['valid' => false, 'mime' => $mime, 'reason' => 'corrupted'];
    }
    [$width, $height] = $dimensions;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name'], 'IFD0', true);
        $orientation = (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1);
        if (in_array($orientation, [5, 6, 7, 8], true)) {
            [$width, $height] = [$height, $width];
        }
    }
    if ($height < $width) {
        return ['valid' => false, 'mime' => $mime, 'reason' => 'orientation'];
    }

    return ['valid' => true, 'mime' => $mime, 'reason' => null];
}

/**
 * The user-facing message for a validateUploadedFile() failure, tailored to
 * the specific reason where that's clearer than a generic message — e.g. a
 * wrong orientation gets its own actionable instruction rather than a vague
 * "validation failed". This is a technical check only (file type, integrity,
 * orientation) — it never claims to verify a document's authenticity; that
 * remains a human staff decision (uploaded_documents.verified).
 */
function documentValidationMessage(?string $reason): string
{
    return match ($reason) {
        'missing' => 'This document is required. Please choose a file to upload.',
        'orientation' => 'Invalid document orientation. Please upload the required document in portrait orientation.',
        'invalid_type' => 'Unsupported file type. Please upload a JPG, PNG, or PDF file.',
        'corrupted' => 'This file could not be read — it may be corrupted. Please upload a different copy.',
        'unreadable' => 'This file could not be uploaded. Please try again.',
        'too_large' => 'This document exceeds the 2 MB file-size limit. Please choose a smaller file.',
        default => 'Document validation failed. Please upload the correct required document.',
    };
}

/** Back-compat generic message, kept for any caller not yet passing a reason. */
const DOCUMENT_VALIDATION_ERROR = 'Document validation failed. Please upload the correct required document.';

/**
 * Lightweight validation for announcement images — accepts any standard image
 * format and shape (no portrait-orientation requirement). Only checks that the
 * file actually arrived, is a supported MIME type (JPEG, PNG, GIF, WEBP), is
 * not corrupted, and does not exceed the size limit.
 */
function validateAnnouncementImage(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'mime' => null, 'reason' => 'missing'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'mime' => null, 'reason' => 'unreadable'];
    }
    if (($file['size'] ?? 0) <= 0) {
        return ['valid' => false, 'mime' => null, 'reason' => 'unreadable'];
    }
    if ((int) $file['size'] > (defined('MAX_DOCUMENT_UPLOAD_MB') ? MAX_DOCUMENT_UPLOAD_MB : 2) * 1024 * 1024) {
        return ['valid' => false, 'mime' => null, 'reason' => 'too_large'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedImageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowedImageMimes, true)) {
        return ['valid' => false, 'mime' => $mime, 'reason' => 'invalid_type'];
    }

    // Confirm the file actually decodes as an image.
    if (@getimagesize($file['tmp_name']) === false) {
        return ['valid' => false, 'mime' => $mime, 'reason' => 'corrupted'];
    }

    return ['valid' => true, 'mime' => $mime, 'reason' => null];
}
