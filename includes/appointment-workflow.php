<?php
/**
 * Shared appointment-review decisions.
 *
 * The Secretary UI and the approval POST endpoint must use the same decision
 * so a disabled button is only a usability hint, never the source of truth.
 */

require_once __DIR__ . '/wedding-forms.php';
require_once __DIR__ . '/baptism-forms.php';
require_once __DIR__ . '/funeral-forms.php';

function appointmentCategoryRequiresPriest(?string $category): bool
{
    // Mass Intentions are read at the scheduled Mass and are handled by the
    // Cashier workflow. Donations are not parish-service appointments.
    return $category !== null && !in_array($category, ['Mass Intention', 'Donation'], true);
}

function appointmentWorkflowBoolean(mixed $value): bool
{
    // PDO PostgreSQL commonly returns BOOLEAN columns as 't'/'f' strings;
    // a plain PHP cast would incorrectly treat 'f' as true.
    return $value === true || $value === 1 || $value === '1'
        || (is_string($value) && in_array(strtolower($value), ['t', 'true', 'yes'], true));
}

function appointmentGeneratedFormTitle(string $formType): string
{
    return [
        'matrimony_application' => 'Marriage Requirement and Application Form',
        'cluster_clearance' => 'Katin-awan sa Kasal',
        'wedding_sponsor_clearance' => 'Cluster Clearance for Wedding Sponsors',
        'katin_awan_bunyag' => 'Katin-awan sa Bunyag',
        'cluster_clearance_baptism_sponsor' => 'Cluster Clearance for Baptism Sponsor',
        'katin_awan_paglubong' => 'Katin-awan sa Paglubong',
    ][$formType] ?? $formType;
}

/** @return string[] */
function appointmentRequiredGeneratedFormTypes(string $category): array
{
    return match ($category) {
        'Wedding' => WEDDING_FORM_TYPES,
        'Baptism' => BAPTISM_FORM_TYPES,
        'Funeral' => FUNERAL_FORM_TYPES,
        default => [],
    };
}

/** @return string[] */
function appointmentReviewRequirementLabels(array $appointment): array
{
    // Finalized appointments carry the exact requirement set shown during
    // booking. Prefer that snapshot so later catalog edits cannot silently
    // change the review checklist for an existing appointment.
    if (!empty($appointment['requirements_snapshot'])) {
        $snapshot = json_decode((string) $appointment['requirements_snapshot'], true);
        if (is_array($snapshot)) return array_values(array_filter(array_map('strval', $snapshot)));
    }

    if (($appointment['category'] ?? '') === 'Wedding' && function_exists('weddingDraftRequiredDocuments')) {
        return weddingDraftRequiredDocuments($appointment);
    }
    if (($appointment['category'] ?? '') === 'Baptism' && function_exists('baptismDraftRequiredDocuments')) {
        return baptismDraftRequiredDocuments($appointment);
    }
    if (($appointment['category'] ?? '') === 'Funeral' && function_exists('funeralDraftRequiredDocuments')) {
        // Funeral's generated form is persisted in requirements_snapshot and
        // is reviewed alongside the uploaded Death Certificate.
        $snapshot = !empty($appointment['requirements_snapshot'])
            ? (json_decode((string) $appointment['requirements_snapshot'], true) ?: [])
            : [];
        return $snapshot ?: funeralDraftRequiredDocuments();
    }

    return parseRequirementsList($appointment['requirements'] ?? '');
}

/** @return array<int, array<string, mixed>> */
function appointmentGeneratedFormReviewRows(PDO $pdo, int $appointmentId, string $category): array
{
    if (!in_array($category, ['Wedding', 'Baptism', 'Funeral'], true)) return [];

    $stmt = $pdo->prepare(
        "SELECT g.form_type, g.status AS generated_status, g.document_id,
                d.document_id AS active_document_id, d.file_name,
                d.review_status, d.verified, d.rejection_reason,
                TRUE AS form_exists
         FROM generated_forms g
         LEFT JOIN uploaded_documents d
           ON d.document_id = g.document_id
          AND d.appointment_id = g.appointment_id
          AND d.superseded_by IS NULL
          AND d.document_source = 'generated'
          AND d.generated_form_type = g.form_type
          AND d.file_type = 'application/pdf'
         WHERE g.service_category = ? AND g.appointment_id = ?
         ORDER BY g.form_type"
    );
    $stmt->execute([$category, $appointmentId]);
    $rowsByType = [];
    foreach ($stmt->fetchAll() as $row) {
        // Only an active, correctly-owned generated document is reviewable.
        // Keep the form row visible, but expose the active document as the
        // document_id consumed by the UI and eligibility checks.
        $row['document_id'] = $row['active_document_id'] ?? null;
        unset($row['active_document_id']);
        $rowsByType[(string) $row['form_type']] = $row;
    }

    $rows = [];
    foreach (appointmentRequiredGeneratedFormTypes($category) as $formType) {
        $rows[] = $rowsByType[$formType] ?? [
            'form_type' => $formType,
            'generated_status' => null,
            'document_id' => null,
            'file_name' => null,
            'review_status' => null,
            'verified' => false,
            'rejection_reason' => null,
            'form_exists' => false,
        ];
        unset($rowsByType[$formType]);
    }

    // Preserve unexpected legacy rows for visibility without allowing them to
    // satisfy the required-form set above.
    foreach ($rowsByType as $row) $rows[] = $row;
    return $rows;
}

/**
 * @return array{can_approve: bool, blocking_reasons: string[], requirements: string[], generated_forms: array}
 */
function appointmentApprovalEligibility(array $appointment): array
{
    $pdo = db();
    $appointmentId = (int) ($appointment['appointment_id'] ?? 0);
    $category = (string) ($appointment['category'] ?? '');
    $blocking = [];
    $requirements = appointmentReviewRequirementLabels($appointment);

    $addBlocking = static function (string $reason) use (&$blocking): void {
        if ($reason !== '' && !in_array($reason, $blocking, true)) $blocking[] = $reason;
    };

    if (appointmentCategoryRequiresPriest($category)) {
        $priestId = (int) ($appointment['priest_id'] ?? 0);
        $priestValid = false;
        if ($priestId > 0) {
            $stmt = $pdo->prepare("SELECT status FROM priests WHERE priest_id = ?");
            $stmt->execute([$priestId]);
            $priestValid = $stmt->fetchColumn() === 'active';
        }
        if (!$priestValid) {
            $addBlocking('Please assign a priest before approving this appointment.');
        } elseif (!empty($appointment['appointment_date']) && !empty($appointment['appointment_time'])) {
            $availability = priestIsAvailable(
                $priestId,
                (string) $appointment['appointment_date'],
                (string) $appointment['appointment_time'],
                $appointmentId > 0 ? $appointmentId : null
            );
            if (!$availability['available']) {
                $addBlocking('The assigned priest is no longer available for this appointment. Please reassign the priest.');
            }
        }
    }

    if (in_array($category, ['Baptism', 'Wedding', 'Funeral'], true)
        && !in_array((string) ($appointment['pss_classification'] ?? ''), ['pss', 'non_pss'], true)) {
        $addBlocking('PSS classification must be verified before approval.');
    }

    if (!in_array($category, ['Mass Intention', 'Donation'], true)) {
        $stmt = $pdo->prepare(
            'SELECT document_id, requirement_label, review_status, verified, rejection_reason
             FROM uploaded_documents
             WHERE appointment_id = ? AND superseded_by IS NULL
             ORDER BY document_id DESC'
        );
        $stmt->execute([$appointmentId]);
        $documents = $stmt->fetchAll();
        $latestByLabel = [];
        $hasLabeledDocument = false;
        foreach ($documents as $document) {
            $label = trim((string) ($document['requirement_label'] ?? ''));
            if ($label === '') continue;
            $hasLabeledDocument = true;
            if (!isset($latestByLabel[$label])) $latestByLabel[$label] = $document;
        }

        if ($hasLabeledDocument || $requirements) {
            foreach ($requirements as $label) {
                $document = $latestByLabel[$label] ?? null;
                if (!$document) {
                    $addBlocking($label . ' is missing.');
                } elseif (!appointmentWorkflowBoolean($document['verified'] ?? false)) {
                    $addBlocking($label . (($document['review_status'] ?? '') === 'rejected'
                        ? ' needs replacement.'
                        : ' is still pending review.'));
                }
            }
            // A newly uploaded labeled requirement must not be ignored simply
            // because the legacy service requirement text did not list it.
            foreach ($latestByLabel as $label => $document) {
                if (!appointmentWorkflowBoolean($document['verified'] ?? false) && !in_array($label, $requirements, true)) {
                    $addBlocking($label . (($document['review_status'] ?? '') === 'rejected'
                        ? ' needs replacement.'
                        : ' is still pending review.'));
                }
            }
        } elseif ($documents && !array_filter($documents, static fn(array $document): bool => appointmentWorkflowBoolean($document['verified'] ?? false))) {
            // Preserve the legacy rule for old unlabeled uploads.
            $addBlocking('At least one submitted supporting document must be approved.');
        }
    }

    $generatedForms = appointmentGeneratedFormReviewRows($pdo, $appointmentId, $category);
    foreach ($generatedForms as $form) {
        $title = appointmentGeneratedFormTitle((string) $form['form_type']);
        if (empty($form['form_exists'])) {
            $addBlocking($title . ' has not been completed.');
        } elseif (($form['generated_status'] ?? '') === 'draft') {
            $addBlocking($title . ' is still a draft.');
        } elseif (($form['generated_status'] ?? '') === 'rejected'
            || ($form['review_status'] ?? '') === 'rejected') {
            $addBlocking($title . ' needs revision.');
        } elseif (empty($form['document_id'])) {
            $addBlocking($title . ' has not been generated.');
        } elseif (($form['review_status'] ?? '') !== 'approved'
            && !appointmentWorkflowBoolean($form['verified'] ?? false)) {
            $addBlocking($title . ' is still pending review.');
        }
    }

    return [
        'can_approve' => $blocking === [],
        'blocking_reasons' => $blocking,
        'requirements' => $requirements,
        'generated_forms' => $generatedForms,
    ];
}
