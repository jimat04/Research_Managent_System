<?php

require_once __DIR__ . '/../../includes/research-workflow.php';

it('allows chapter submissions after title approval', function (string $status, bool $allowed): void {
    expect(rms_project_status_allows_chapter_submission($status))->toBe($allowed);
})->with([
    'title approved' => ['title_approved', true],
    'approved legacy status' => ['approved', true],
    'ongoing' => ['ongoing', true],
    'draft title' => ['draft', false],
    'submitted title' => ['submitted', false],
    'returned title' => ['for_revision', false],
    'completed project' => ['completed', false],
    'archived project' => ['archived', false],
]);

it('enforces sequential chapter submission', function (int $chapterNumber, ?string $previousStatus, bool $allowed): void {
    expect(rms_chapter_sequence_allows_submission($chapterNumber, $previousStatus))->toBe($allowed);
})->with([
    'chapter one starts the sequence' => [1, null, true],
    'chapter two follows approved chapter one' => [2, 'approved', true],
    'chapter two waits for chapter one review' => [2, 'submitted', false],
    'chapter two requires an existing chapter one' => [2, null, false],
    'chapter three follows approved chapter two' => [3, 'approved', true],
    'chapter five follows approved chapter four' => [5, 'approved', true],
    'chapter five waits when chapter four is returned' => [5, 'revision_required', false],
    'chapter zero is invalid' => [0, null, false],
    'chapter six is invalid' => [6, 'approved', false],
]);

it('requires all five distinct approved chapter numbers for final manuscript readiness', function (array $chapterNumbers, bool $complete): void {
    expect(rms_approved_chapter_numbers_are_complete($chapterNumbers))->toBe($complete);
})->with([
    'all five chapters are approved' => [[1, 2, 3, 4, 5], true],
    'one chapter is missing' => [[1, 2, 3, 5], false],
    'a duplicate cannot replace a missing chapter' => [[1, 2, 3, 4, 4], false],
    'duplicates do not invalidate a complete set' => [[1, 2, 3, 4, 5, 5], true],
    'an out-of-range chapter cannot replace chapter five' => [[1, 2, 3, 4, 6], false],
    'out-of-range chapters do not invalidate a complete set' => [[0, 1, 2, 3, 4, 5, 6], true],
    'no approved chapters is incomplete' => [[], false],
]);

it('completes projects only when a final manuscript is approved', function (string $submissionType, string $decision, bool $completes): void {
    expect(rms_submission_review_completes_project($submissionType, $decision))->toBe($completes);
})->with([
    'approved final manuscript' => ['final_manuscript', 'approved', true],
    'returned final manuscript' => ['final_manuscript', 'returned', false],
    'approved title' => ['title', 'approved', false],
    'returned title' => ['title', 'returned', false],
    'approved chapter' => ['chapter', 'approved', false],
    'returned chapter' => ['chapter', 'returned', false],
    'unknown submission type' => ['unknown', 'approved', false],
]);

it('maps review decisions to their specific staff permissions', function (string $decision, ?string $permission): void {
    expect(rms_submission_review_permission($decision))->toBe($permission);
})->with([
    'approval requires approval permission' => ['approved', 'research_approval'],
    'return requires review permission' => ['returned', 'research_review'],
    'unknown decision has no permission' => ['deleted', null],
]);

it('applies the submission review role and permission matrix', function (string $role, string $decision, ?string $grantedPermission, bool $allowed): void {
    expect(rms_review_role_can_decide($role, $decision, $grantedPermission))->toBe($allowed);
})->with([
    'admin may approve without staff permission' => ['admin', 'approved', null, true],
    'admin may return without staff permission' => ['admin', 'returned', null, true],
    'staff may approve with approval permission' => ['research_staff', 'approved', 'research_approval', true],
    'staff cannot approve with only review permission' => ['research_staff', 'approved', 'research_review', false],
    'staff may return with review permission' => ['research_staff', 'returned', 'research_review', true],
    'staff cannot return with only approval permission' => ['research_staff', 'returned', 'research_approval', false],
    'student is denied even with approval permission' => ['student', 'approved', 'research_approval', false],
    'faculty is denied even with review permission' => ['faculty', 'returned', 'research_review', false],
    'unknown role is denied' => ['observer', 'approved', 'research_approval', false],
    'admin is denied an invalid decision' => ['admin', 'deleted', null, false],
    'staff is denied an invalid decision' => ['research_staff', 'deleted', 'research_approval', false],
]);
