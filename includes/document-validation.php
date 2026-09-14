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
 * @return array{valid: bool, mime: ?string}
 */
function validateUploadedFile(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'mime' => null];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'mime' => null];
    }
    if (($file['size'] ?? 0) <= 0) {
        return ['valid' => false, 'mime' => null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ALLOWED_DOCUMENT_MIME_TYPES, true)) {
        return ['valid' => false, 'mime' => $mime];
    }

    if ($mime === 'application/pdf') {
        $header = @file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($header !== '%PDF-') {
            return ['valid' => false, 'mime' => $mime];
        }
        return ['valid' => true, 'mime' => $mime];
    }

    // JPEG/PNG: must actually decode as an image, and be portrait-oriented
    // (height >= width) per the upload instructions shown to parishioners.
    $dimensions = @getimagesize($file['tmp_name']);
    if ($dimensions === false) {
        return ['valid' => false, 'mime' => $mime];
    }
    [$width, $height] = $dimensions;
    if ($height < $width) {
        return ['valid' => false, 'mime' => $mime];
    }

    return ['valid' => true, 'mime' => $mime];
}

/** The exact user-facing message required whenever validateUploadedFile() fails. */
const DOCUMENT_VALIDATION_ERROR = 'Document validation failed. Please upload the correct required document.';
