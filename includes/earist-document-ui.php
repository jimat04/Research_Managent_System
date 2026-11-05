<?php
/** Escaped document status presentation shared by authenticated preparation/history pages. */
function earistDocumentEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function earistDocumentMissingLabel(string $key, array $mapping): string
{
    $annexD = ['final_score' => 'Final score', 'classification' => 'Classification',
        'certification_statement' => 'Certification result', 'certification_date' => 'Certification date',
        'certification_place' => 'Certification place', 'scoring_policy_confirmed' => 'Institutional scoring policy confirmation'];
    return (string) ($mapping['fields'][$key]['label'] ?? $annexD[substr($key, 8)] ?? 'Required document information');
}

function earistDocumentRenderHistoryStatus(array $row): void
{
    $snapshot = $row['_snapshot'];
    $missing = json_decode((string) $row['missing_fields_json'], true, 128, JSON_THROW_ON_ERROR);
    $warnings = json_decode((string) $row['warnings_json'], true, 128, JSON_THROW_ON_ERROR);
    if (!$missing && !$warnings) return;
    echo '<details><summary>Warnings and missing information</summary>';
    if ($missing) {
        echo '<p>Missing information:</p><ul>';
        foreach ($missing as $key => $status) echo '<li>' . earistDocumentEscape(earistDocumentMissingLabel((string) $key, $snapshot)) . '</li>';
        echo '</ul>';
    }
    if ($warnings) {
        echo '<p>Warnings acknowledged at generation:</p><ul>';
        foreach ($warnings as $warning) echo '<li>' . earistDocumentEscape($warning) . '</li>';
        echo '</ul>';
    }
    echo '</details>';
}

/** Load the local DOCX renderer and shared preview surface on generated-document history pages. */
function earistDocumentPreviewAssets(): void
{
    $root = dirname(__DIR__);
    $css = $root . '/css/earist-documents.css';
    $jszip = $root . '/public/assets/vendor/docx-preview/jszip.min.js';
    $renderer = $root . '/public/assets/vendor/docx-preview/docx-preview.min.js';
    $script = $root . '/public/assets/js/earist-document-preview.js';
    echo '<link rel="stylesheet" href="' . earistDocumentEscape(SITE_URL . 'css/earist-documents.css?v=' . (is_file($css) ? filemtime($css) : '1')) . '">';
    echo '<script src="' . earistDocumentEscape(SITE_URL . 'public/assets/vendor/docx-preview/jszip.min.js?v=' . (is_file($jszip) ? filemtime($jszip) : '1')) . '" defer></script>';
    echo '<script src="' . earistDocumentEscape(SITE_URL . 'public/assets/vendor/docx-preview/docx-preview.min.js?v=' . (is_file($renderer) ? filemtime($renderer) : '1')) . '" defer></script>';
    echo '<script src="' . earistDocumentEscape(SITE_URL . 'public/assets/js/earist-document-preview.js?v=' . (is_file($script) ? filemtime($script) : '1')) . '" defer></script>';
}

/** Shared accessible DOCX preview dialog. */
function earistDocumentRenderPreviewDialog(bool $facultyEdit = false): void
{
    $editorEnabled = false;
    if ($facultyEdit) {
        $editorConfig = require __DIR__ . '/../config/earist-document-editor.php';
        $editorEnabled = (bool) $editorConfig['enabled'];
    }
    ?>
    <dialog class="ed-docx-preview" data-earist-preview-dialog aria-labelledby="ed-docx-preview-title">
      <div class="ed-docx-preview__inner">
        <header class="ed-docx-preview__header">
          <div>
            <h2 id="ed-docx-preview-title" data-earist-preview-title>Generated document preview</h2>
            <p>Browser rendering may differ slightly from Microsoft Word or LibreOffice. Use the download for final layout and printing checks.</p>
          </div>
          <button class="ed-docx-preview__close" type="button" data-earist-preview-close>Close</button>
        </header>
        <p class="ed-docx-preview__status" data-earist-preview-status role="status" aria-live="polite">Select Preview to load a saved document.</p>
        <div class="ed-docx-preview__scroll" data-earist-preview-scroll tabindex="0" aria-label="Generated document pages" hidden>
          <div class="ed-docx-preview__content" data-earist-preview-content></div>
        </div>
        <footer class="ed-docx-preview__footer">
          <?php if ($facultyEdit): ?><form class="ed-docx-preview__edit-form" method="post" action="<?= earistDocumentEscape(SITE_URL . 'pages/faculty/faculty-document-editor.php') ?>" data-earist-preview-edit-form>
            <?= csrfField() ?><input type="hidden" name="generated_document_id" data-earist-edit-id><input type="hidden" name="file" data-earist-edit-file>
            <button class="ed-docx-preview__edit" type="submit" data-earist-edit-submit <?= $editorEnabled ? '' : 'disabled' ?>>Edit DOCX</button>
          </form><?php if (!$editorEnabled): ?><span class="ed-docx-preview__edit-note">DOCX editing is not configured. Preview and download remain available.</span><?php endif; ?><?php endif; ?>
          <a class="ed-docx-preview__download" data-earist-preview-download href="#" hidden>Download DOCX</a>
        </footer>
      </div>
    </dialog>
    <?php
}
