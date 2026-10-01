<?php
/**
 * Private application document storage.
 *
 * Existing rows use public/uploads/... and remain readable. New files use the
 * configured storage root when UPLOAD_STORAGE_PATH is set; otherwise the
 * legacy directory is retained for local/backward-compatible operation.
 */
function documentStorageRoot(): string
{
    $configured = trim((string) getenv('UPLOAD_STORAGE_PATH'));
    $root = $configured !== '' ? $configured : dirname(__DIR__) . '/public/uploads';
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Document storage directory is unavailable.');
    }
    $real = realpath($root);
    if ($real === false) throw new RuntimeException('Document storage directory is unavailable.');
    return rtrim($real, DIRECTORY_SEPARATOR);
}

function documentStorageSupabaseEnabled(): bool
{
    return defined('DOCUMENT_STORAGE_DRIVER') && DOCUMENT_STORAGE_DRIVER === 'supabase';
}

function documentStorageRequireSupabaseConfig(): void
{
    if (!SUPABASE_URL || !SUPABASE_SECRET_KEY || !SUPABASE_DOCUMENT_BUCKET) {
        throw new RuntimeException('Private document storage is not configured.');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('Server cURL support is required for document storage.');
}

function documentStorageSupabaseRequest(string $method, string $objectKey, ?string $body = null, ?string $contentType = null): array
{
    documentStorageRequireSupabaseConfig();
    if (!preg_match('#^[A-Za-z0-9._/-]+$#', $objectKey) || str_contains($objectKey, '..') || str_starts_with($objectKey, '/')) throw new InvalidArgumentException('Invalid storage object key.');
    $url = SUPABASE_URL . '/storage/v1/object/' . rawurlencode(SUPABASE_DOCUMENT_BUCKET) . '/' . str_replace('%2F', '/', rawurlencode($objectKey));
    $ch = curl_init($url);
    $headers = ['apikey: ' . SUPABASE_SECRET_KEY];
    if ($contentType) $headers[] = 'Content-Type: ' . $contentType;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    if ($error || $status < 200 || $status >= 300) throw new RuntimeException('Private document storage request failed.');
    return ['body' => $response === false ? '' : $response, 'status' => $status];
}

function documentStorageKey(string $filename): string
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) throw new InvalidArgumentException('Invalid stored filename.');
    return 'storage://' . $filename;
}

function documentStoragePathFromKey(string $storedPath): ?string
{
    $value = ltrim(str_replace('\\', '/', $storedPath), '/');
    if (str_starts_with($value, 'storage://')) {
        $filename = substr($value, 10);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) return null;
        return documentStorageRoot() . DIRECTORY_SEPARATOR . $filename;
    }
    // Historical rows were stored as public/uploads/name or uploads/name.
    $value = preg_replace('#^public/uploads/#i', '', $value);
    $value = preg_replace('#^uploads/#i', '', $value);
    if ($value === '' || str_contains($value, '/') || str_contains($value, '\0')) return null;
    $legacyRoot = realpath(dirname(__DIR__) . '/public/uploads');
    if ($legacyRoot === false) return null;
    return $legacyRoot . DIRECTORY_SEPARATOR . basename($value);
}

function documentStorageWriteBytes(string $bytes): array
{
    if (strlen($bytes) > (defined('MAX_DOCUMENT_UPLOAD_MB') ? MAX_DOCUMENT_UPLOAD_MB : 2) * 1024 * 1024) {
        throw new RuntimeException('Generated document exceeds the 2 MB storage limit.');
    }
    $filename = 'document-' . bin2hex(random_bytes(20)) . '.pdf';
    if (documentStorageSupabaseEnabled()) {
        $key = 'appointments/generated/' . $filename;
        documentStorageSupabaseRequest('POST', $key, $bytes, 'application/pdf');
        return ['key' => 'supabase://' . $key, 'path' => null];
    }
    $path = documentStorageRoot() . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('Document could not be stored.');
    return ['key' => documentStorageKey($filename), 'path' => $path];
}

function documentStorageMoveUpload(string $tmpPath, string $extension): array
{
    if (!is_file($tmpPath) || filesize($tmpPath) > (defined('MAX_DOCUMENT_UPLOAD_MB') ? MAX_DOCUMENT_UPLOAD_MB : 2) * 1024 * 1024) {
        throw new RuntimeException('Document exceeds the 2 MB storage limit.');
    }
    $extension = strtolower(ltrim($extension, '.'));
    if (!preg_match('/^[a-z0-9]{1,8}$/', $extension)) throw new InvalidArgumentException('Invalid file extension.');
    $filename = 'document-' . bin2hex(random_bytes(20)) . '.' . $extension;
    if (documentStorageSupabaseEnabled()) {
        $key = 'appointments/uploads/' . $filename;
        $mime = function_exists('mime_content_type') ? (mime_content_type($tmpPath) ?: 'application/octet-stream') : 'application/octet-stream';
        documentStorageSupabaseRequest('POST', $key, file_get_contents($tmpPath), $mime);
        return ['key' => 'supabase://' . $key, 'path' => null, 'mime' => $mime];
    }
    $path = documentStorageRoot() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpPath, $path)) throw new RuntimeException('Document could not be stored.');
    $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
    return ['key' => documentStorageKey($filename), 'path' => $path, 'mime' => $mime];
}

function documentStorageRead(string $storedPath): array
{
    if (str_starts_with($storedPath, 'supabase://')) {
        $key = substr($storedPath, 11);
        $result = documentStorageSupabaseRequest('GET', $key);
        return ['body' => $result['body'], 'path' => null];
    }
    $path = documentStoragePathFromKey($storedPath);
    if (!$path || !is_file($path)) throw new RuntimeException('Document file is unavailable.');
    return ['body' => file_get_contents($path), 'path' => $path];
}

function documentStorageDelete(string $storedPath): void
{
    if (str_starts_with($storedPath, 'supabase://')) {
        documentStorageSupabaseRequest('DELETE', substr($storedPath, 11));
        return;
    }
    $path = documentStoragePathFromKey($storedPath);
    if ($path && is_file($path)) @unlink($path);
}
