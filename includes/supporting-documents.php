<?php
require_once __DIR__ . '/document-validation.php';

function supportingDocumentAcceptedFiles(): string
{
    return 'PDF, JPG, JPEG, PNG';
}

function supportingDocumentMaxSizeLabel(): string
{
    return (string) (defined('MAX_DOCUMENT_UPLOAD_MB') ? MAX_DOCUMENT_UPLOAD_MB : 2) . ' MB';
}

function supportingDocumentDisplayLabel(string $label): string
{
    return trim((string) preg_replace('/\s*\(\s*if\s+married\s*\)/i', '', $label));
}

function supportingDocumentRequirementIsOptional(string $label): bool
{
    return (bool) preg_match('/\(\s*if\s+married\s*\)/i', $label);
}

/**
 * Render one self-contained card per supporting-document requirement.
 * $documents is keyed by the exact database requirement_label.
 */
function renderSupportingDocumentCards(array $requirements, array $documents, string $context, int $contextId, bool $allowUpload = true): void
{
    $csrf = csrfToken();
    ?>
    <div class="supporting-document-grid" data-supporting-upload-root
         data-upload-url="<?= e(url('document-upload.php')) ?>"
         data-upload-context="<?= e($context) ?>"
         data-context-id="<?= $contextId ?>"
         data-csrf-token="<?= e($csrf) ?>">
      <?php foreach ($requirements as $label):
          $document = $documents[$label] ?? null;
          $optional = supportingDocumentRequirementIsOptional($label);
          $displayLabel = supportingDocumentDisplayLabel($label);
          $fileName = is_array($document) ? (string) ($document['file_name'] ?? '') : '';
          $documentId = is_array($document) ? (int) ($document['document_id'] ?? 0) : 0;
      ?>
        <article class="supporting-document-card" data-supporting-document-card data-requirement-label="<?= e($label) ?>" data-document-id="<?= $documentId ?: '' ?>">
          <div class="supporting-document-card-heading">
            <h3><?= e($displayLabel) ?></h3>
            <span class="supporting-document-requirement <?= $optional ? 'is-optional' : '' ?>">
              <?= $optional ? 'Optional — only if parents are married' : 'Required' ?>
            </span>
          </div>
          <p class="supporting-document-help">Accepted files: <?= e(supportingDocumentAcceptedFiles()) ?><br>Maximum size: <?= e(supportingDocumentMaxSizeLabel()) ?></p>
          <div class="supporting-document-status" data-upload-status aria-live="polite">
            <?php if ($fileName): ?>
              <span class="supporting-document-success">✓ Uploaded</span>
              <span class="supporting-document-filename" data-upload-filename><?= e($fileName) ?></span>
              <?php if ($documentId): ?><a class="btn btn-outline btn-sm" data-upload-view href="<?= url('document.php?id=' . $documentId) ?>" target="_blank" rel="noopener">View</a><?php endif; ?>
              <span class="supporting-document-replace-label">Replace:</span>
            <?php else: ?>
              <span class="supporting-document-empty">No file uploaded yet</span>
            <?php endif; ?>
          </div>
          <?php if ($allowUpload): ?>
            <label class="btn btn-outline btn-sm supporting-document-picker">
              <?= $fileName ? 'Choose Replacement' : 'Choose File' ?>
              <input type="file" data-supporting-upload-input accept=".pdf,.jpg,.jpeg,.png" hidden>
            </label>
            <div class="supporting-document-progress" data-upload-progress hidden>
              <span data-upload-progress-label>Uploading…</span>
              <progress max="100" value="0" data-upload-progress-bar></progress>
            </div>
            <div class="supporting-document-error" data-upload-error hidden></div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
}
