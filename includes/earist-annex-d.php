<?php
/** Pure Annex D preparation; not an Admin reader or an authorization boundary.
 * Stage 4C must supply the source using a separate live Admin-only scoped reader.
 */
require_once __DIR__ . '/earist-document-mapping.php';

function earistAnnexDClassifications(): array
{
    return ['highly_recommended' => 'Highly Recommended', 'recommended' => 'Recommended',
        'moderately_recommended' => 'Moderately Recommended', 'conditionally_recommended' => 'Conditionally Recommended',
        'not_recommended' => 'Not Recommended'];
}

/** No floats, rounding or fixed decimal scale. Preserve the supplied score string. */
function earistAnnexDScoreClassification(string $score): string
{
    if (!preg_match('/^\d{1,3}(?:\.\d+)?$/D', $score)) throw new DomainException('Enter a decimal score string from 0 to 100.');
    [$whole, $fraction] = array_pad(explode('.', $score, 2), 2, '');
    $integer = (int) $whole;
    if ($integer > 100 || ($integer === 100 && trim($fraction, '0') !== '')) throw new DomainException('Score exceeds 100.');
    if ($integer >= 90) return 'highly_recommended';
    if ($integer >= 80) return 'recommended';
    if ($integer >= 70) return 'moderately_recommended';
    if ($integer >= 60) return 'conditionally_recommended';
    return 'not_recommended';
}

function earistAnnexDMappingBuild(array $record, array $raw): array
{
    $owner = (int) ($record['owner_id'] ?? 0);
    earistMappingAssertRecord($record, $owner);
    if ((int) ($record['research_record_id'] ?? 0) < 1 || (int) ($record['version'] ?? 0) < 1) throw new DomainException('Invalid source identity.');
    $errors = [];
    $validatedTitle = earistMappingText($record['title'] ?? '', 2000, 'title', $errors);
    if ($validatedTitle === '') $errors['title'] = 'A saved research title is required.';
    // Validate without rewriting the canonical saved title, including whitespace.
    $title = is_string($record['title'] ?? null) ? $record['title'] : '';
    if (mb_strlen($title, 'UTF-8') > 2000) $errors['title'] = 'Saved research title exceeds its supported limit.';
    $authors = [];
    $ids = [];
    foreach ($record['authors'] ?? [] as $index => $author) {
        if (!is_array($author)) throw new DomainException('Invalid source author.');
        $id = (int) ($author['record_author_id'] ?? 0);
        $name = earistMappingText($author['full_name'] ?? '', 255, 'authors', $errors);
        $position = earistMappingText($author['position'] ?? '', 255, 'authors', $errors);
        $affiliation = earistMappingText($author['affiliation'] ?? '', 500, 'authors', $errors);
        if (is_string($author['full_name'] ?? null) && mb_strlen($author['full_name'], 'UTF-8') > 255) $errors['authors'] = 'Saved author name exceeds its supported limit.';
        if ($position === '' || $affiliation === '') $errors['authors'] = 'Saved author rank and institute are required before rendering.';
        if ($name === '' || $id < 1 || isset($ids[$id]) || (int) ($author['display_order'] ?? 0) !== $index + 1
            || ($author['author_role'] ?? '') !== ($index === 0 ? 'lead' : 'coauthor')) $errors['authors'] = 'Correct saved author names/order before rendering.';
        $ids[$id] = true;
        $authors[] = ['record_author_id' => $id, 'full_name' => is_string($author['full_name'] ?? null) ? $author['full_name'] : '',
            'position' => $position, 'affiliation' => $affiliation,
            'display_order' => $index + 1, 'author_role' => $author['author_role'] ?? ''];
    }
    if (!$authors) $errors['authors'] = 'Saved authors are required.';
    $rules = ['final_score' => 2000, 'classification' => 40, 'certification_statement' => 4000,
        'certification_date' => 10, 'certification_place' => 500, 'record_reference' => 500];
    if (array_diff(array_keys($raw), array_merge(array_keys($rules), ['scoring_policy_confirmed']))) $errors['inputs'] = 'Unsupported Annex D input.';
    $inputs = [];
    foreach ($rules as $key => $max) $inputs[$key] = earistMappingText($raw[$key] ?? '', $max, $key, $errors);
    if (isset($raw['final_score']) && !is_string($raw['final_score'])) $errors['final_score'] = 'Score must be an exact decimal string.';
    $inputs['scoring_policy_confirmed'] = $raw['scoring_policy_confirmed'] ?? false;
    if (!is_bool($inputs['scoring_policy_confirmed'])) $errors['scoring_policy_confirmed'] = 'Policy confirmation must be an explicit Boolean.';
    $classification = $inputs['classification'];
    if ($classification !== '' && !isset(earistAnnexDClassifications()[$classification])) $errors['classification'] = 'Choose a listed classification.';
    if ($inputs['final_score'] !== '') {
        try {
            $expected = earistAnnexDScoreClassification($inputs['final_score']);
            if ($classification !== '' && $classification !== $expected) $errors['classification'] = 'Classification contradicts the exact score.';
        } catch (DomainException $exception) { $errors['final_score'] = $exception->getMessage(); }
    }
    if ($inputs['certification_date'] !== '' && !earistMappingValidDate($inputs['certification_date'])) $errors['certification_date'] = 'Enter a valid certification date.';
    $missing = [];
    foreach (['final_score', 'classification', 'certification_statement', 'certification_date', 'certification_place'] as $key) {
        if ($inputs[$key] === '') $missing['annex_d.' . $key] = 'Missing Required';
    }
    if ($inputs['scoring_policy_confirmed'] !== true) $missing['annex_d.scoring_policy_confirmed'] = 'Missing Required';
    $source = ['research_record_id' => (int) $record['research_record_id'], 'owner_id' => $owner,
        'version' => (int) $record['version'], 'status' => $record['status'], 'title' => $title, 'authors' => $authors];
    return ['document' => ['key' => 'annex_d', 'definition_version' => 1], 'source' => ['record' => $source],
        'inputs' => $inputs, 'fields' => ['research.title' => earistMappingField('Research title', $title, 'record.title'),
            'research.authors' => earistMappingField('Authors', $authors, 'record.authors')],
        'errors' => $errors, 'missing' => $missing, 'review_valid' => !$errors,
        'warnings' => ['Confirm against actual institutional evaluation records. This renderer never calculates a final score from reviews.',
            'EARIST must confirm how the printed whole-number bands apply to decimal scores; Admin must acknowledge the applicable institutional policy.',
            'Physical signatures remain blank. Preparation does not establish signing or actual issuance.']];
}
