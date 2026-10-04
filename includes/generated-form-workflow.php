<?php

/**
 * Shared presentation rules for generated parish forms.
 *
 * The form tables and uploaded_documents table are intentionally kept as the
 * source of truth. This helper only normalizes their existing values for the
 * applicant, guest, and Secretary screens.
 */
function generatedFormWorkflowBoolean(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1'
        || (is_string($value) && in_array(strtolower($value), ['t', 'true', 'yes'], true));
}

/**
 * @return array{code:string,label:string,description:string,has_document:bool}
 */
function generatedFormWorkflowState(?array $form, ?array $document = null, bool $isDraft = false): array
{
    $hasForm = is_array($form);
    $status = strtolower(trim((string) ($form['status'] ?? '')));
    $reviewStatus = strtolower(trim((string) ($document['review_status'] ?? $form['review_status'] ?? '')));
    $hasDocument = (int) ($form['document_id'] ?? $document['document_id'] ?? 0) > 0
        || !empty($form['_has_document']);
    $verified = generatedFormWorkflowBoolean($document['verified'] ?? $form['verified'] ?? false);

    if (!$hasForm || (array_key_exists('form_exists', $form) && !generatedFormWorkflowBoolean($form['form_exists']))) {
        return [
            'code' => 'not_started',
            'label' => 'Not started',
            'description' => 'Complete this form to continue.',
            'has_document' => false,
        ];
    }

    if ($status === 'rejected' || $reviewStatus === 'rejected') {
        return [
            'code' => 'needs_revision',
            'label' => 'Needs Revision',
            'description' => 'Review the Secretary\'s note, update the form, and generate a new PDF.',
            'has_document' => $hasDocument,
        ];
    }

    if (($status === 'approved' || $reviewStatus === 'approved' || $verified) && !$hasDocument) {
        return [
            'code' => 'not_started',
            'label' => 'Not started',
            'description' => 'Generate the PDF again before this form can be reviewed.',
            'has_document' => false,
        ];
    }

    if ($status === 'approved' || $reviewStatus === 'approved' || $verified) {
        return [
            'code' => 'approved',
            'label' => 'Approved',
            'description' => 'This form was approved by the Secretary.',
            'has_document' => $hasDocument,
        ];
    }

    if ($status === 'draft') {
        return [
            'code' => 'draft',
            'label' => 'Draft saved',
            'description' => $hasDocument
                ? 'Your answers are saved. Generate a new PDF before submitting these changes for review.'
                : 'Your answers are saved, but this form is not complete yet. Continue editing and select Generate PDF when finished.',
            'has_document' => $hasDocument,
        ];
    }

    if ($isDraft && $hasDocument) {
        return [
            'code' => 'generated',
            'label' => 'Generated — Ready to Submit',
            'description' => 'The PDF is ready. Submit the booking request to send it for Secretary review.',
            'has_document' => true,
        ];
    }

    if ($hasDocument) {
        return [
            'code' => 'pending_review',
            'label' => 'Pending Secretary review',
            'description' => 'The generated PDF has been submitted and is waiting for Secretary review.',
            'has_document' => true,
        ];
    }

    return [
        'code' => 'draft',
        'label' => 'Draft saved',
        'description' => 'Your answers are saved, but this form is not complete yet. Continue editing and select Generate PDF when finished.',
        'has_document' => false,
    ];
}

function generatedFormWorkflowActionLabel(array $state): string
{
    return match ($state['code'] ?? '') {
        'not_started' => 'Complete Form',
        'draft' => 'Continue Editing',
        'needs_revision' => 'Edit and Regenerate',
        'approved' => 'View Form',
        'generated', 'pending_review' => 'Edit and Regenerate',
        default => 'Open Form',
    };
}

function generatedFormWorkflowActionHelper(): void
{
    ?>
    <p class="generated-form-action-helper" role="note">
      Kompleto na ang impormasyon? Himoa ang PDF aron mahuman kini nga requirement.
      <span class="generated-form-guide-translation">(Information complete? Generate the PDF to complete this requirement.)</span>
    </p>
    <?php
}
