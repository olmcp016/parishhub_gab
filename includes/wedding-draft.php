<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/wedding-forms.php';

// Backward-compatible name used by the draft pages; the generated-form
// definitions themselves live in wedding-forms.php.
const WEDDING_DRAFT_FORMS = WEDDING_FORM_TYPES;

function weddingDraftGuestToken(int $draftId): ?string
{
    $token = $_SESSION['wedding_draft_tokens'][$draftId] ?? null;
    return is_string($token) && $token !== '' ? $token : null;
}

function weddingDraftOwner(PDO $pdo, array $draft, ?array $user, ?string $guestToken): bool
{
    if ($user && ($user['role_name'] ?? '') === 'Parishioner') {
        return (int) $draft['parishioner_id'] === (int) (function () use ($pdo, $user) {
            $q = $pdo->prepare('SELECT parishioner_id FROM parishioners WHERE user_id = ?');
            $q->execute([$user['user_id']]); return $q->fetchColumn();
        })();
    }
    return $guestToken !== null && hash_equals((string) $draft['guest_access_token_hash'], hash('sha256', $guestToken));
}

function weddingDraftLoad(PDO $pdo, int $draftId, ?array $user, ?string $guestToken, bool $lock = false): ?array
{
    $sql = 'SELECT d.*, s.category, s.requirements FROM appointment_drafts d JOIN services s ON s.service_id = d.service_id WHERE d.draft_id = ? AND d.service_type = \'Wedding\'';
    if ($lock) $sql .= ' FOR UPDATE';
    $q = $pdo->prepare($sql); $q->execute([$draftId]); $draft = $q->fetch() ?: null;
    if (!$draft || $draft['category'] !== 'Wedding' || !weddingDraftOwner($pdo, $draft, $user, $guestToken)) return null;
    return $draft;
}

function weddingRequiredDocumentsFromCatalog(?string $requirements): array
{
    return parseRequirementsList($requirements ?? '');
}

function weddingDraftRequiredDocuments(array $draft): array
{
    return weddingRequiredDocumentsFromCatalog($draft['requirements'] ?? '');
}

function weddingDraftComplete(PDO $pdo, array $draft): array
{
    $required = weddingDraftRequiredDocuments($draft);
    $q = $pdo->prepare("SELECT requirement_label FROM uploaded_documents WHERE draft_id = ? AND superseded_by IS NULL AND review_status = 'pending'");
    $q->execute([$draft['draft_id']]); $labels = array_unique($q->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff($required, $labels));
    $q = $pdo->prepare("SELECT f.form_type
        FROM generated_forms f
        INNER JOIN uploaded_documents d ON d.document_id = f.document_id
        WHERE f.service_category = 'Wedding' AND f.draft_id = ?
          AND f.status IN ('generated', 'pending_review', 'approved')
          AND f.document_id IS NOT NULL
          AND d.superseded_by IS NULL
          AND d.document_source = 'generated'
          AND d.generated_form_type = f.form_type
          AND d.file_type = 'application/pdf'");
    $q->execute([$draft['draft_id']]); $forms = array_unique($q->fetchAll(PDO::FETCH_COLUMN));
    foreach (WEDDING_DRAFT_FORMS as $form) if (!in_array($form, $forms, true)) $missing[] = match ($form) {
        'matrimony_application' => 'Marriage Requirement and Application Form',
        'cluster_clearance' => 'Katin-awan sa Kasal',
        default => 'Cluster Clearance for Wedding Sponsors',
    };
    return $missing;
}
