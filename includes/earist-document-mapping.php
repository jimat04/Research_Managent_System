<?php
/** Read-only EARIST mapping. Pure builders below never authorize an HTTP request. */
require_once __DIR__ . '/faculty-research-records.php';

function earistMappingDefinitions(): array
{
    static $definitions;
    return $definitions ??= require __DIR__ . '/../config/earist-documents.php';
}

function earistMappingDocument(string $type): array
{
    $documents = earistMappingDefinitions()['documents'];
    if (!isset($documents[$type])) throw new DomainException('Unsupported document type.', 400);
    return $documents[$type];
}

/** Policy only; authenticated entry points must also verify the live session. */
function earistMappingAssertAccess(array $user, string $type): void
{
    $document = earistMappingDocument($type);
    if ((int) ($user['user_id'] ?? 0) < 1 || ($user['role'] ?? '') !== $document['role']) {
        throw new DomainException('Access denied.', 403);
    }
    if (!$document['enabled']) throw new DomainException('Admin document mapping is not available in this phase.', 403);
}

function earistMappingAssertRecord(?array $record, int $ownerId): void
{
    if (!$record || $ownerId < 1 || (int) ($record['owner_id'] ?? 0) !== $ownerId
        || !empty($record['deleted_at']) || !in_array($record['status'] ?? '', ['active', 'archived'], true)) {
        throw new DomainException('Record unavailable.', 404);
    }
}

/** Live entry point. Context must originate from authentication, not request fields. */
function earistMappingForUser(mysqli $conn, array $context, int $recordId, string $type, array $inputs = []): array
{
    $live = getCurrentUser();
    if (!$live || (int) ($context['user_id'] ?? 0) !== (int) $live['user_id']
        || ($context['role'] ?? '') !== $live['role']) throw new DomainException('Access denied.', 403);
    earistMappingAssertAccess($live, $type);
    if ($recordId < 1) throw new DomainException('Record unavailable.', 404);
    // Own this transaction: parent, child snapshots and profile are one consistent read.
    if (!$conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
        || !$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
        throw new RuntimeException('Unable to start the mapping read.');
    }
    try {
        $ownerId = (int) $live['user_id'];
        $profile = facultyRecordProfile($conn, $ownerId);
        if (!$profile) throw new DomainException('Access denied.', 403);
        $record = facultyRecordLoadOwned($conn, $recordId, $ownerId);
        earistMappingAssertRecord($record, $ownerId);
        $result = earistMappingBuild($profile, $record, $type, $inputs);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function earistMappingValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value && $value >= '1000-01-01';
}

function earistMappingText($raw, int $max, string $key, array &$errors): string
{
    if (!is_string($raw) && !is_int($raw)) {
        $errors[$key] = 'Enter a valid text value.';
        return '';
    }
    $value = trim((string) $raw);
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', $value)) {
        $errors[$key] = 'Enter valid text without unsupported control characters.';
        return '';
    }
    if (mb_strlen($value, 'UTF-8') > $max) $errors[$key] = "Use {$max} characters or fewer; text was not shortened.";
    return $value;
}

/** Strict allowlist; reserved/common fields cannot be supplied through document inputs. */
function earistMappingValidateInputs(string $type, array $raw, array $authors): array
{
    $definitions = earistMappingDefinitions();
    $schema = earistMappingDocument($type)['inputs'];
    $values = $errors = [];
    foreach ($raw as $key => $_) {
        if (!array_key_exists($key, $schema)) $errors['_inputs'] = 'Unsupported or official fields cannot be supplied here.';
    }
    $authorIds = array_map(static fn(array $author): string => (string) $author['record_author_id'], $authors);
    foreach ($schema as $key => $rule) {
        $value = $raw[$key] ?? (in_array($rule['type'], ['multi', 'ai_rows', 'contributions'], true) ? [] : '');
        if (in_array($rule['type'], ['multi', 'ai_rows', 'contributions'], true)) {
            $values[$key] = [];
            if (!is_array($value)) { $errors[$key] = 'Supply a valid list.'; continue; }
            if ($rule['type'] === 'multi') {
                foreach ($value as $option) {
                    if (!is_string($option) || !isset($rule['options'][$option]) || in_array($option, $values[$key], true)) {
                        $errors[$key] = 'Choose each listed option at most once.';
                    } else $values[$key][] = $option;
                }
                if (in_array('na', $values[$key], true) && count($values[$key]) > 1) $errors[$key] = 'N/A cannot be combined with an indexing service.';
                continue;
            }
            if ($rule['type'] === 'ai_rows') {
                if (count($value) > 100) { $errors[$key] = 'Use at most 100 AI-detail rows in one temporary review.'; continue; }
                foreach ($value as $index => $row) {
                    $rowKey = $key . '.' . $index;
                    if (!is_array($row) || array_diff(array_keys($row), ['tool', 'purpose', 'prompts', 'extent'])) {
                        $errors[$key] = 'AI rows contain unsupported fields.'; continue;
                    }
                    $clean = [];
                    foreach (['tool' => 255, 'purpose' => 2000, 'prompts' => 4000, 'extent' => 2000] as $column => $limit) {
                        $clean[$column] = earistMappingText($row[$column] ?? '', $limit, $rowKey . '.' . $column, $errors);
                    }
                    $values[$key][] = $clean;
                    if (count(array_filter($clean, static fn(string $v): bool => $v !== '')) > 0 && in_array('', $clean, true)) {
                        $errors[$key] = 'Complete tool, purpose, prompts and extent for each used AI row.';
                    }
                }
                continue;
            }
            foreach ($value as $authorId => $row) {
                if (!in_array((string) $authorId, $authorIds, true) || !is_array($row)
                    || array_diff(array_keys($row), ['categories', 'description', 'percentage'])) {
                    $errors[$key] = 'Contributions must refer only to authors on this record and listed fields.'; continue;
                }
                $rowKey = 'contributions.' . $authorId;
                $categories = [];
                if (!is_array($row['categories'] ?? [])) $errors[$rowKey] = 'Choose listed contribution categories.';
                else foreach ($row['categories'] ?? [] as $category) {
                    if (!is_string($category) || !in_array($category, $definitions['contribution_categories'], true) || in_array($category, $categories, true)) {
                        $errors[$rowKey] = 'Choose each listed contribution category at most once.';
                    } else $categories[] = $category;
                }
                $description = earistMappingText($row['description'] ?? '', 4000, $rowKey . '.description', $errors);
                $percentage = earistMappingText($row['percentage'] ?? '', 6, $rowKey . '.percentage', $errors);
                if ($percentage !== '' && (!preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/D', $percentage))) {
                    $errors[$rowKey . '.percentage'] = 'Enter a percentage from 0 to 100 with at most two decimal places.';
                }
                $values[$key][(string) $authorId] = compact('categories', 'description', 'percentage');
            }
            continue;
        }
        $text = earistMappingText($value, $rule['max'], $key, $errors);
        $values[$key] = $text;
        if ($text === '') continue;
        if ($rule['type'] === 'choice' && !isset($rule['options'][$text])) $errors[$key] = 'Choose a listed option.';
        if ($rule['type'] === 'author' && !in_array($text, $authorIds, true)) $errors[$key] = 'Choose an author belonging to this record.';
        if ($rule['type'] === 'date' && !earistMappingValidDate($text)) $errors[$key] = 'Enter a valid calendar date (1000–9999).';
        if ($rule['type'] === 'email' && !filter_var($text, FILTER_VALIDATE_EMAIL)) $errors[$key] = 'Enter a valid email address.';
        if ($rule['type'] === 'decimal' && !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $text)) $errors[$key] = 'Enter a nonnegative amount with at most two decimal places.';
        if ($rule['type'] === 'currency' && !preg_match('/^[A-Z]{3}$/D', $text)) $errors[$key] = 'Enter a three-letter uppercase currency code.';
    }
    if ($type === 'annex_a') {
        $usedRows = array_filter($values['ai_tools'], static fn(array $row): bool => (bool) array_filter($row, static fn(string $v): bool => $v !== ''));
        if ($values['ai_used'] === 'yes' && !$usedRows) $errors['ai_tools'] = 'Describe the AI use you declared.';
        if ($values['ai_used'] !== 'yes' && $usedRows) $errors['ai_tools'] = 'Confirm AI use as Yes before supplying AI details.';
        if ($values['proceedings'] !== 'yes' && $values['conference_indexing']) $errors['conference_indexing'] = 'Confirm proceedings before declaring their indexing.';
        if ($values['scope_aligned'] === 'yes' && $values['scope_explanation'] === '') $errors['scope_explanation'] = 'Explain the alignment you declared.';
        if ($values['scope_aligned'] !== 'yes' && $values['scope_explanation'] !== '') $errors['scope_explanation'] = 'Confirm alignment as Yes or remove the explanation.';
        foreach ([['registration_fee', 'registration_currency'], ['publication_fee', 'publication_currency']] as [$amount, $currency]) {
            if (($values[$amount] === '') !== ($values[$currency] === '')) $errors[$amount] = 'Supply both the amount and currency, or leave both blank.';
        }
    }
    if ($type === 'annex_b' && count($values['contributions']) === count($authors) && $authors) {
        $percentages = array_column($values['contributions'], 'percentage');
        if (!in_array('', $percentages, true) && !$errors) {
            $hundredths = array_sum(array_map(static function (string $percentage): int {
                $parts = explode('.', $percentage);
                return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            }, $percentages));
            if ($hundredths !== 10000) $errors['contributions'] = 'A complete set of author contribution percentages must total 100%.';
        }
    }
    if ($type === 'qsf_rds_019') {
        foreach (['abstract_submitted' => 'abstract_date', 'accepted' => 'acceptance_date'] as $answer => $date) {
            if ($values[$answer] === 'yes' && $values[$date] === '') $errors[$date] = 'Enter the date for the milestone you confirmed.';
            if ($values[$answer] !== 'yes' && $values[$date] !== '') $errors[$date] = 'Confirm the milestone as Yes before supplying its date.';
        }
        if ($values['abstract_date'] !== '' && $values['acceptance_date'] !== '' && $values['acceptance_date'] < $values['abstract_date']) $errors['acceptance_date'] = 'Acceptance cannot precede abstract submission.';
        if ($values['funding_source'] === 'earist' && ($values['estimated_expenses'] === '' || $values['expense_currency'] === '')) $errors['estimated_expenses'] = 'Supply the estimated amount and currency for the assistance request.';
        if ($values['funding_source'] !== 'earist' && ($values['estimated_expenses'] !== '' || $values['expense_currency'] !== '')) $errors['estimated_expenses'] = 'The printed estimated-expense field belongs to an EARIST assistance request.';
        $manual = array_filter($values, static fn($value, $key): bool => str_starts_with($key, 'applicant_') && $key !== 'applicant_source' && $value !== '', ARRAY_FILTER_USE_BOTH);
        if ($values['applicant_source'] !== 'manual' && $manual) $errors['applicant_source'] = 'Choose confirmed applicant details to use the manual fields, or clear them.';
    }
    return ['values' => $values, 'errors' => $errors];
}

function earistMappingHasValue($value): bool
{
    if (is_array($value)) {
        foreach ($value as $item) if (earistMappingHasValue($item)) return true;
        return false;
    }
    return $value !== null && $value !== '';
}

function earistMappingField(string $label, $value, string $source, string $type = 'text', bool $required = false, string $origin = 'common', bool $applicable = true): array
{
    $classification = earistMappingHasValue($value) ? 'Available' : ($required ? 'Missing Required' : ($origin === 'document_specific' ? 'Document-Specific' : 'Missing Optional'));
    if (!$applicable) { $value = null; $classification = 'Missing Optional'; }
    return compact('label', 'value', 'source', 'type', 'required', 'origin', 'applicable', 'classification') + ['errors' => []];
}

/** Internal pure mapping, with explicit source-owner consistency and lifecycle checks. */
function earistMappingBuild(array $profile, array $record, string $type, array $rawInputs = []): array
{
    $definitions = earistMappingDefinitions();
    $document = earistMappingDocument($type);
    if (!$document['enabled'] || $document['role'] !== 'faculty') throw new DomainException('This document mapping is unavailable.', 403);
    earistMappingAssertRecord($record, (int) ($profile['user_id'] ?? 0));
    $authors = $record['authors'] ?? [];
    $validated = earistMappingValidateInputs($type, $rawInputs, $authors);
    $inputs = $validated['values'];
    $errors = $validated['errors'];
    foreach (facultyRecordTextFields() + ['work_type_label' => 120] as $key => $limit) {
        earistMappingText($record[$key] ?? '', $limit, 'source.' . $key, $errors);
    }
    $warnings = ['This is a field-mapping review. No document is generated and temporary answers are not saved.'];
    $fields = [];
    $name = trim(implode(' ', array_filter([$profile['first_name'] ?? '', $profile['middle_name'] ?? '', $profile['last_name'] ?? ''], static fn($part): bool => $part !== null && $part !== '')));
    $profileContext = array_intersect_key($profile, array_flip(['user_id', 'first_name', 'middle_name', 'last_name', 'academic_rank', 'designation', 'appointment_status', 'department', 'email', 'contact', 'affiliation']));
    $profileContext['full_name'] = $name;
    // Deliberately exclude creation tokens and all unrelated parent/account fields.
    $sourceRecord = array_intersect_key($record, array_flip(array_merge(array_keys(facultyRecordTextFields()), ['research_record_id', 'owner_id', 'version', 'status', 'work_type_label', 'authors', 'sdgs'])));
    $sourceRecord['authors'] = array_map(static fn(array $author): array => array_intersect_key($author, array_flip(['record_author_id', 'faculty_user_id', 'author_type', 'full_name', 'position', 'affiliation', 'author_role', 'display_order'])), $authors);
    $mappedAuthors = $sourceRecord['authors'];
    $sdgs = array_values($record['sdgs'] ?? []);
    $values = [
        'research.title' => $record['title'] ?? '', 'research.authors' => $mappedAuthors,
        'research.work_type' => ($record['work_type_label'] ?? '') . (($record['work_type'] ?? '') === 'other' && !empty($record['work_type_other']) ? ': ' . $record['work_type_other'] : ''),
        'research.date_started' => $record['date_started'] ?? '', 'research.date_completed' => $record['date_completed'] ?? '',
        'research.sdgs' => $sdgs,
        'research.sdg_other' => $record['sdg_other'] ?? '',
        'conference.title' => $record['conference_title'] ?? '',
        'conference.host_organizer' => ['Host' => $record['conference_host'] ?? '', 'Organizer' => $record['conference_organizer'] ?? ''],
        'conference.dates' => ['start' => $record['conference_start_date'] ?? '', 'end' => $record['conference_end_date'] ?? ''],
        'conference.classification' => facultyRecordConferenceClasses()[$record['conference_classification'] ?? ''] ?? '',
        'conference.modality' => facultyRecordModalities()[$record['presentation_modality'] ?? ''] ?? '',
        'conference.venue' => $record['conference_venue'] ?? '', 'conference.organizer' => $record['conference_organizer'] ?? '',
        'journal.title' => $record['journal_title'] ?? '', 'journal.publisher' => $record['journal_publisher'] ?? '',
    ];
    foreach ($document['common'] as $key) {
        $rule = $definitions['fields'][$key];
        $applicable = true;
        if ($type === 'annex_a' && ($inputs['submission_target'] ?? '') !== '') {
            if (str_starts_with($key, 'conference.')) $applicable = $inputs['submission_target'] !== 'journal';
            if (str_starts_with($key, 'journal.')) $applicable = $inputs['submission_target'] !== 'conference';
        }
        $fields[$key] = earistMappingField($rule['label'], $values[$key], $rule['source'], $rule['type'], $rule['required'], 'common', $applicable);
    }
    foreach ($document['inputs'] as $key => $rule) {
        $applicable = true;
        if ($type === 'annex_a') {
            $conferenceKeys = ['conference_deadline', 'proceedings', 'conference_indexing', 'registration_fee', 'registration_currency'];
            $journalKeys = ['journal_deadline', 'peer_review_clear', 'journal_indexing', 'scope_aligned', 'scope_explanation', 'publication_fee', 'publication_currency'];
            if (($inputs['submission_target'] === 'journal' && in_array($key, $conferenceKeys, true)) || ($inputs['submission_target'] === 'conference' && in_array($key, $journalKeys, true))) $applicable = false;
            if (($key === 'ai_tools' && $inputs['ai_used'] === 'no')
                || ($key === 'conference_indexing' && $inputs['proceedings'] === 'no')
                || ($key === 'scope_explanation' && $inputs['scope_aligned'] === 'no')) $applicable = false;
        }
        if ($type === 'qsf_rds_019') {
            if ((str_starts_with($key, 'applicant_') && $key !== 'applicant_source' && $inputs['applicant_source'] === 'profile')
                || (in_array($key, ['estimated_expenses', 'expense_currency'], true) && in_array($inputs['funding_source'], ['personal', 'organizer_grant'], true))
                || ($key === 'abstract_date' && $inputs['abstract_submitted'] === 'no')
                || ($key === 'acceptance_date' && $inputs['accepted'] === 'no')) $applicable = false;
        }
        $fields['input.' . $key] = earistMappingField($rule['label'], $inputs[$key], 'temporary.' . $type . '.' . $key, $rule['type'], false, 'document_specific', $applicable);
    }
    if ($type === 'annex_b') {
        foreach ($mappedAuthors as $author) {
            $id = (string) $author['record_author_id'];
            foreach (['categories', 'description', 'percentage'] as $column) {
                $key = 'contributions.' . $id . '.' . $column;
                $fields[$key] = earistMappingField($author['full_name'] . ' — ' . $column, $inputs['contributions'][$id][$column] ?? null, 'temporary.' . $key, 'text', false, 'document_specific');
                if (isset($errors[$key]) || isset($errors['contributions.' . $id]) || isset($errors['contributions'])) {
                    $fields[$key]['value'] = null;
                    $fields[$key]['classification'] = 'Document-Specific';
                    $fields[$key]['errors'][] = 'Correct the contribution validation errors before using this value.';
                }
            }
        }
    }
    if ($type === 'qsf_rds_019') {
        $warnings[] = 'Confirm QSF conference classification independently; Annex A classification and venue do not determine it.';
        $confirmed = in_array($inputs['applicant_source'], ['profile', 'manual'], true) && !isset($errors['applicant_source']);
        $position = implode(' / ', array_unique(array_filter([$profile['academic_rank'] ?? '', $profile['designation'] ?? ''], static fn($v): bool => $v !== null && $v !== '')));
        $applicant = ['name' => $name, 'appointment' => $profile['appointment_status'] ?? '', 'position' => $position, 'department' => $profile['department'] ?? '', 'contact' => $profile['contact'] ?? '', 'email' => $profile['email'] ?? ''];
        foreach ($applicant as $key => $suggestion) {
            $value = !$confirmed ? null : ($inputs['applicant_source'] === 'profile' ? $suggestion : $inputs['applicant_' . $key]);
            $fields['applicant.' . $key] = earistMappingField('Applicant ' . $key, $value, !$confirmed ? 'confirmation_pending' : ($inputs['applicant_source'] === 'profile' ? 'confirmed_profile.' . $key : 'temporary.applicant_' . $key), 'text', $key === 'name', 'document_specific');
        }
        $presenter = null;
        if (!isset($errors['presenter_author_id'])) foreach ($mappedAuthors as $author) if ((string) $author['record_author_id'] === $inputs['presenter_author_id']) $presenter = $author;
        $fields['presentation.presenter'] = earistMappingField('Confirmed presenting author', $presenter, 'record.authors + temporary.presenter_author_id', 'author', false, 'document_specific');
        $fields['research.authors']['value'] = array_map(static fn(array $author): array => $author + ['is_presenter' => $presenter !== null && (int) $author['record_author_id'] === (int) $presenter['record_author_id']], $mappedAuthors);
        if (!$confirmed) $warnings[] = 'The faculty profile is only an applicant suggestion until explicitly confirmed.';
        if (!$presenter) $warnings[] = 'No presenting author has been confirmed.';
    }
    if ($type === 'annex_a') $warnings[] = 'The supplied Annex A has four prechecked documentary requirements. They are template states, not proof of supplied documents; all four answers above require explicit confirmation.';
    if ($type === 'annex_c') $warnings[] = 'Mabansag copy is provisional. Both Annex C files are preserved; visual layout confirmation is required before Phase 4.';
    if ($type === 'annex_f') $warnings[] = 'The seven review-signatory rows belong to reviewers and officials, not the research authors.';
    $lead = array_values(array_filter($mappedAuthors, static fn(array $author): bool => ($author['author_role'] ?? '') === 'lead'));
    if (count($lead) !== 1 || ($mappedAuthors[0]['author_role'] ?? '') !== 'lead') $errors['source.authors'] = 'Saved author roles/order need correction through Edit Research Record; mapping did not change them.';
    if (!$authors) $errors['source.authors'] = 'The source record has no authors.';
    $seenAuthorIds = [];
    foreach ($mappedAuthors as $index => $author) {
        $authorErrors = [];
        foreach (['full_name' => 255, 'position' => 255, 'affiliation' => 500] as $column => $limit) {
            $text = earistMappingText($author[$column] ?? '', $limit, $column, $authorErrors);
            if ($text === '') $authorErrors[$column] = 'Saved author information is missing.';
        }
        $id = (int) ($author['record_author_id'] ?? 0);
        if ($id < 1 || isset($seenAuthorIds[$id]) || (int) ($author['display_order'] ?? 0) !== $index + 1
            || !in_array($author['author_role'] ?? '', ['lead', 'coauthor'], true)) $authorErrors['identity'] = 'Invalid saved author identity/order.';
        $seenAuthorIds[$id] = true;
        if ($authorErrors) $errors['source.authors'] = 'Saved author information needs correction through Edit Research Record; no snapshot was changed.';
    }
    if (($record['title'] ?? '') === '' || mb_strlen($record['title'], 'UTF-8') > 2000) $errors['source.title'] = 'The saved research title is missing or outside its supported limit.';
    foreach (['date_started', 'date_completed'] as $key) if (!empty($record[$key]) && !preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $record[$key])) $errors['source.' . $key] = 'Invalid saved research month.';
    if (!empty($record['date_started']) && !empty($record['date_completed']) && $record['date_completed'] < $record['date_started']) $errors['source.date_completed'] = 'Saved completion month precedes start month.';
    foreach (['conference_start_date', 'conference_end_date'] as $key) if (!empty($record[$key]) && !earistMappingValidDate($record[$key])) $errors['source.' . $key] = 'Invalid saved conference date.';
    if (!empty($record['conference_start_date']) && !empty($record['conference_end_date']) && $record['conference_end_date'] < $record['conference_start_date']) $errors['source.conference_end_date'] = 'Saved conference end precedes start.';
    if (count($authors) > 2) $warnings[] = 'All ' . count($authors) . ' authors are retained. The supplied forms commonly show two initial author examples/rows; a renderer must verify expansion and signature layout.';
    if (mb_strlen($record['title'] ?? '', 'UTF-8') > 200) $warnings[] = 'Long title retained in full; verify wrapping and pagination before document generation.';
    foreach ($errors as $key => $message) {
        $fieldKey = 'input.' . explode('.', $key)[0];
        if (isset($fields[$fieldKey])) {
            $fields[$fieldKey]['errors'][] = $message;
            $fields[$fieldKey]['classification'] = 'Document-Specific';
            $fields[$fieldKey]['value'] = null;
        }
        if (str_starts_with($key, 'source.')) {
            $column = substr($key, strlen('source.'));
            foreach ($fields as &$sourceField) {
                if ($sourceField['origin'] === 'common' && (str_contains($sourceField['source'], 'record.' . $column)
                    || ($column === 'authors' && $sourceField['type'] === 'authors'))) {
                    $sourceField['value'] = null;
                    $sourceField['errors'][] = $message;
                    $sourceField['classification'] = $sourceField['required'] ? 'Missing Required' : 'Missing Optional';
                }
            }
            unset($sourceField);
        }
    }
    // Invalid or unconfirmed applicant input must never become a usable mapping value.
    foreach ($fields as $key => &$field) {
        if (str_starts_with($key, 'applicant.')) {
            $inputKey = 'applicant_' . substr($key, strlen('applicant.'));
            if (isset($errors[$inputKey])) { $field['errors'][] = $errors[$inputKey]; $field['value'] = null; $field['classification'] = 'Document-Specific'; }
        }
    }
    unset($field);
    foreach (['reserved' => 'Authorized-Only', 'fixed' => 'Fixed Template Content'] as $group => $classification) {
        foreach ($document[$group] as $key => $label) $fields[$group . '.' . $key] = [
            'label' => $label, 'value' => null, 'source' => $group === 'fixed' ? 'approved_template' : 'no_authorized_source',
            'type' => 'text', 'required' => false, 'origin' => $group, 'applicable' => true, 'classification' => $classification, 'errors' => [],
        ];
    }
    $missing = [];
    foreach ($fields as $key => $field) if ($field['applicable'] && in_array($field['classification'], ['Missing Required', 'Missing Optional', 'Document-Specific'], true)) $missing[$key] = $field['classification'];
    return [
        'document' => ['key' => $type, 'label' => $document['label'], 'definition_version' => $definitions['version']],
        'source' => ['record_id' => (int) $record['research_record_id'], 'record_version' => (int) $record['version'], 'owner_id' => (int) $profile['user_id'], 'profile' => $profileContext, 'record' => $sourceRecord,
            'authorship' => ['lead_author' => count($lead) === 1 ? $lead[0] : null, 'coauthors' => array_values(array_filter($mappedAuthors, static fn(array $author): bool => ($author['author_role'] ?? '') === 'coauthor'))]],
        'fields' => $fields, 'inputs' => $inputs, 'errors' => $errors, 'missing' => $missing, 'warnings' => $warnings,
        'review_valid' => !$errors, 'temporary_inputs_saved' => false,
        'prefill_information_complete' => !$errors && !in_array('Missing Required', $missing, true) && !in_array('Document-Specific', $missing, true),
        'official_fields_pending' => (bool) $document['reserved'],
    ];
}

function earistMappingDisplay($value): string
{
    if (!is_array($value)) return $value === null || $value === '' ? 'Blank' : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value);
    if (isset($value['full_name'])) return $value['full_name'] . (!empty($value['position']) ? ' · ' . $value['position'] : '') . (!empty($value['affiliation']) ? ' · ' . $value['affiliation'] : '');
    $lines = [];
    foreach ($value as $key => $item) {
        if (is_array($item) && isset($item['full_name'])) {
            $lines[] = $item['full_name'] . (!empty($item['position']) ? ' · ' . $item['position'] : '') . (!empty($item['affiliation']) ? ' · ' . $item['affiliation'] : '') . (isset($item['author_role']) ? ' · ' . ($item['author_role'] === 'lead' ? 'Lead Author' : 'Co-Author') : '') . (!empty($item['is_presenter']) ? ' · Presenter' : '');
        } else $lines[] = (is_string($key) ? $key . ': ' : '') . earistMappingDisplay($item);
    }
    return $lines ? implode("\n", $lines) : 'Blank';
}

function earistMappingTemplateInfo(string $type): array
{
    earistMappingDocument($type);
    $manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
    $template = $manifest['documents'][$type];
    $path = __DIR__ . '/../templates/earist/blank/' . $template['filename'];
    return ['filename' => $template['filename'], 'matches' => is_file($path) && hash_equals($template['sha256'], hash_file('sha256', $path)), 'provisional' => $type === 'annex_c'];
}
