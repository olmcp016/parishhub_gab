<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/document-storage.php';

/** @return string[] */
function funeralDraftRequiredDocuments(): array
{
    return ['Death Certificate'];
}

function funeralDraftLoad(int $draftId, ?array $user): ?array
{
    if ($draftId < 1 || !isset($_SESSION['funeral_booking_drafts'][$draftId]) || !is_array($_SESSION['funeral_booking_drafts'][$draftId])) {
        return null;
    }

    $draft = $_SESSION['funeral_booking_drafts'][$draftId];
    if ((int) ($draft['id'] ?? 0) !== $draftId || (int) ($draft['expires_at'] ?? 0) <= time()) {
        return null;
    }

    if (!empty($draft['is_guest'])) {
        $rawToken = $_SESSION['funeral_draft_tokens'][$draftId] ?? null;
        $expectedHash = (string) ($draft['guest_token'] ?? '');
        if (!is_string($rawToken) || $rawToken === '' || $expectedHash === '' || !hash_equals($expectedHash, hash('sha256', $rawToken))) {
            return null;
        }
    } else {
        if (!$user || ($user['role_name'] ?? '') !== 'Parishioner') return null;
        $owner = db()->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
        $owner->execute([(int) $user['user_id']]);
        if ((int) $owner->fetchColumn() !== (int) ($draft['parishioner_id'] ?? 0)) return null;
    }

    $draft['uploaded_keys'] = isset($draft['uploaded_keys']) && is_array($draft['uploaded_keys']) ? $draft['uploaded_keys'] : [];
    $draft['katin_awan_payload'] = isset($draft['katin_awan_payload']) && is_array($draft['katin_awan_payload']) ? $draft['katin_awan_payload'] : null;
    $draft['generated_document'] = isset($draft['generated_document']) && is_array($draft['generated_document']) ? $draft['generated_document'] : null;
    return $draft;
}

/** @return string[] */
function funeralDraftUploadedLabels(array $draft): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn(array $upload): string => trim((string) ($upload['label'] ?? '')),
        array_filter($draft['uploaded_keys'] ?? [], 'is_array')
    ))));
}

function funeralDraftHasGeneratedForm(array $draft): bool
{
    $document = $draft['generated_document'] ?? null;
    return !empty($draft['katin_awan_payload'])
        && is_array($document)
        && !empty($document['key'])
        && ($document['mime'] ?? '') === 'application/pdf';
}

/** @return string[] */
function funeralDraftMissingRequirements(array $draft): array
{
    $missing = array_values(array_diff(funeralDraftRequiredDocuments(), funeralDraftUploadedLabels($draft)));
    if (!funeralDraftHasGeneratedForm($draft)) $missing[] = 'Katin-awan sa Paglubong';
    return $missing;
}

function funeralDraftDeletePreview(array $draft): void
{
    $key = trim((string) ($draft['generated_document']['key'] ?? ''));
    if ($key === '') return;
    try {
        documentStorageDelete($key);
    } catch (Throwable $e) {
        error_log('Funeral draft preview cleanup failed: ' . $e->getMessage());
    }
}
