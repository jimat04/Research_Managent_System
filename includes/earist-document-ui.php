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
