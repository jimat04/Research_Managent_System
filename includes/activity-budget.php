<?php

/**
 * Activity budget, expense, contribution response, and budget notice helpers.
 */

function rmsActivityBudgetTableExists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to verify activity budget prerequisites.');
    }
    $stmt->bind_param('s', $tableName);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to verify activity budget prerequisites.');
    }
    $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $exists;
}

function rmsActivityBudgetSchemaAvailable(mysqli $conn): bool
{
    foreach ([
        'activity_budgets', 'activity_budget_expenses', 'activity_budget_revisions',
        'activity_contribution_responses', 'activity_budget_notices',
        'activity_budget_notice_deliveries',
    ] as $tableName) {
        if (!rmsActivityBudgetTableExists($conn, $tableName)) {
            return false;
        }
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
           AND COLUMN_NAME = 'activity_budget_delivery_key'"
    );
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return false;
    }
    $available = (int) $stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $available;
}

function rmsActivityBudgetLoad(mysqli $conn, int $activityId): ?array
{
    $stmt = $conn->prepare(
        'SELECT budget_id, activity_id, activity_format, funding_source, budget_status,
                organizational_allocation, contribution_prompt, created_at, updated_at
         FROM activity_budgets WHERE activity_id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare activity budget data.');
    }
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load activity budget data.');
    }
    $budget = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$budget) {
        return null;
    }
    $budget['budget_id'] = (int) $budget['budget_id'];
    $budget['contribution_prompt'] = (int) $budget['contribution_prompt'];
    $budget['expenses'] = [];

    $expenseStmt = $conn->prepare(
        'SELECT expense_id, schedule_id, expense_category, audience_scope, audience_user_id,
                charge_type, organizational_estimated_amount, organizational_actual_amount,
                participant_estimated_charge, participant_actual_charge, description
         FROM activity_budget_expenses WHERE budget_id = ? ORDER BY expense_id ASC'
    );
    if (!$expenseStmt) {
        throw new RuntimeException('Unable to prepare activity expenses.');
    }
    $expenseStmt->bind_param('i', $budget['budget_id']);
    if (!$expenseStmt->execute()) {
        $expenseStmt->close();
        throw new RuntimeException('Unable to load activity expenses.');
    }
    $result = $expenseStmt->get_result();
    while ($expense = $result->fetch_assoc()) {
        $expense['expense_id'] = (int) $expense['expense_id'];
        $expense['schedule_id'] = $expense['schedule_id'] === null ? null : (int) $expense['schedule_id'];
        $expense['audience_user_id'] = $expense['audience_user_id'] === null ? null : (int) $expense['audience_user_id'];
        $expense['schedule_date'] = null;
        if ($expense['schedule_id'] !== null) {
            $expense['schedule_date'] = null;
        }
        $budget['expenses'][] = $expense;
    }
    $expenseStmt->close();

    if ($budget['expenses']) {
        $dateStmt = $conn->prepare(
            'SELECT schedule_id, schedule_date FROM activity_schedule_dates WHERE activity_id = ?'
        );
        if (!$dateStmt) {
            throw new RuntimeException('Unable to prepare schedule dates for activity expenses.');
        }
        $dateStmt->bind_param('i', $activityId);
        if (!$dateStmt->execute()) {
            $dateStmt->close();
            throw new RuntimeException('Unable to load schedule dates for activity expenses.');
        }
        $scheduleDates = [];
        $dateResult = $dateStmt->get_result();
        while ($dateRow = $dateResult->fetch_assoc()) {
            $scheduleDates[(int) $dateRow['schedule_id']] = (string) $dateRow['schedule_date'];
        }
        $dateStmt->close();
        foreach ($budget['expenses'] as &$expense) {
            if ($expense['schedule_id'] !== null) {
                $expense['schedule_date'] = $scheduleDates[$expense['schedule_id']] ?? null;
            }
        }
        unset($expense);
    }
    return $budget;
}

function rmsActivityBudgetString(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}

function rmsActivityBudgetMoney(mixed $value, string $label, array &$errors): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_scalar($value)) {
        $errors[] = 'Enter a valid amount for ' . $label . '.';
        return null;
    }
    $amount = trim((string) $value);
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $amount)) {
        $errors[] = 'Enter a non-negative amount with up to two decimal places for ' . $label . '.';
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    return $whole . '.' . str_pad($fraction, 2, '0');
}

function rmsActivityBudgetValidatePost(array $post, array $eligibleUsers, array $scheduleDates, string $activityStatus, array $inviteeRecipientIds): array
{
    $errors = [];
    $header = [
        'activity_format' => rmsActivityBudgetString($post['activity_format'] ?? 'on_site'),
        'funding_source' => rmsActivityBudgetString($post['funding_source'] ?? 'organizational'),
        'budget_status' => rmsActivityBudgetString($post['budget_status'] ?? 'pending'),
        'organizational_allocation' => rmsActivityBudgetMoney($post['organizational_allocation'] ?? null, 'organizational allocation', $errors),
        'contribution_prompt' => is_scalar($post['contribution_prompt'] ?? null)
            && (string) $post['contribution_prompt'] === '1' ? 1 : 0,
    ];
    if (!in_array($header['activity_format'], ['on_site', 'online', 'hybrid'], true)) {
        $errors[] = 'Choose a valid activity format.';
    }
    if (!in_array($header['funding_source'], ['organizational', 'participants', 'combined', 'free'], true)) {
        $errors[] = 'Choose a valid funding source.';
    }
    if (!in_array($header['budget_status'], ['pending', 'confirmed'], true)) {
        $errors[] = 'Choose a valid budget status.';
    }

    $allowedCategories = ['registration', 'transport', 'accommodation', 'meals', 'online_fee', 'other'];
    $allowedScopes = ['all', 'student', 'faculty', 'staff', 'specific'];
    $allowedCharges = ['none', 'optional', 'mandatory'];
    $supportsParticipantCharges = in_array($header['funding_source'], ['participants', 'combined'], true);
    if (!isset($post['budget_expenses']) || !is_array($post['budget_expenses'])) {
        $errors[] = 'Submit the activity expense list as a valid form array.';
    }
    $postedExpenses = is_array($post['budget_expenses'] ?? null) ? $post['budget_expenses'] : [];
    if (count($postedExpenses) > 50) {
        $errors[] = 'An activity can have no more than 50 budget expense items.';
    }
    $expenses = [];
    $hasMandatoryCharge = false;
    foreach (array_slice($postedExpenses, 0, 50, true) as $row) {
        if (!is_array($row)) {
            $errors[] = 'Enter valid activity expense details.';
            continue;
        }
        $category = rmsActivityBudgetString($row['expense_category'] ?? 'other');
        $scheduleDate = rmsActivityBudgetString($row['schedule_date'] ?? '');
        $audienceScope = rmsActivityBudgetString($row['audience_scope'] ?? 'all');
        $audienceUserId = filter_var($row['audience_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $chargeType = $supportsParticipantCharges
            ? rmsActivityBudgetString($row['charge_type'] ?? 'none')
            : 'none';
        $description = rmsActivityBudgetString($row['description'] ?? '');
        $orgEstimated = rmsActivityBudgetMoney($row['organizational_estimated_amount'] ?? null, 'organizational estimate', $errors);
        $orgActual = rmsActivityBudgetMoney($row['organizational_actual_amount'] ?? null, 'actual organizational expense', $errors);
        $participantEstimate = $supportsParticipantCharges
            ? rmsActivityBudgetMoney($row['participant_estimated_charge'] ?? null, 'estimated participant cost', $errors)
            : null;
        $participantActual = $supportsParticipantCharges
            ? rmsActivityBudgetMoney($row['participant_actual_charge'] ?? null, 'actual participant cost', $errors)
            : null;
        $hasValue = $scheduleDate !== '' || $description !== '' || $orgEstimated !== null || $orgActual !== null
            || $participantEstimate !== null || $participantActual !== null || $chargeType !== 'none';
        if (!$hasValue) {
            continue;
        }
        if (!in_array($category, $allowedCategories, true)) {
            $errors[] = 'Choose a valid activity expense category.';
        }
        if ($scheduleDate !== '' && !isset($scheduleDates[$scheduleDate])) {
            $errors[] = 'An expense must reference a date in this activity schedule.';
        }
        if (!in_array($audienceScope, $allowedScopes, true)) {
            $errors[] = 'Choose a valid audience for each participant expense.';
        }
        if ($audienceScope === 'specific' && (!$audienceUserId || !isset($eligibleUsers[$audienceUserId]))) {
            $errors[] = 'Choose an active eligible user for a user-specific expense.';
        }
        if ($audienceScope !== 'specific') {
            $audienceUserId = null;
        }
        if (!in_array($chargeType, $allowedCharges, true)) {
            $errors[] = 'Choose whether each participant charge is optional or mandatory.';
        }
        $isUpcoming = in_array($activityStatus, ['upcoming', 'scheduled', 'ongoing'], true);
        if ($chargeType === 'none' && ($participantEstimate !== null || ($isUpcoming && $participantActual !== null))) {
            $errors[] = 'Select an optional or mandatory charge type when entering estimated or upcoming participant costs.';
        }
        if ($chargeType !== 'none' && $participantEstimate === null && $participantActual === null) {
            $errors[] = 'Enter an estimated or actual participant amount for a participant charge.';
        }
        if ($chargeType === 'mandatory') {
            $hasMandatoryCharge = true;
        }
        if (strlen($description) > 255) {
            $errors[] = 'Expense descriptions must not exceed 255 characters.';
        }
        $expenses[] = [
            'expense_category' => $category,
            'schedule_date' => $scheduleDate !== '' ? $scheduleDate : null,
            'audience_scope' => $audienceScope,
            'audience_user_id' => $audienceUserId,
            'charge_type' => $chargeType,
            'organizational_estimated_amount' => $orgEstimated,
            'organizational_actual_amount' => $orgActual,
            'participant_estimated_charge' => $participantEstimate,
            'participant_actual_charge' => $participantActual,
            'description' => $description !== '' ? $description : null,
        ];
    }
    $isUpcoming = in_array($activityStatus, ['upcoming', 'scheduled', 'ongoing'], true);
    if ($header['funding_source'] === 'participants' && !$hasMandatoryCharge) {
        $errors[] = 'Participant-funded activities must include at least one mandatory participant charge with an amount.';
    }
    if ($header['funding_source'] === 'free'
        && array_filter($expenses, static fn (array $expense): bool => $expense['charge_type'] !== 'none')) {
        $errors[] = 'A free activity cannot list mandatory fees or optional participant charges.';
    }
    if ($header['contribution_prompt'] && (!$isUpcoming || $header['funding_source'] === 'free' || $header['budget_status'] !== 'pending' || $hasMandatoryCharge)) {
        $errors[] = 'Contribution willingness is only available for upcoming activities with a pending budget and no mandatory participant charges.';
    }

    $notice = [
        'confirmed' => $header['funding_source'] !== 'free'
            && is_scalar($post['send_budget_notice'] ?? null)
            && (string) $post['send_budget_notice'] === '1',
        'message' => rmsActivityBudgetString($post['budget_notice_message'] ?? ''),
        'roles' => [],
        'user_ids' => [],
    ];
    foreach ($inviteeRecipientIds as $inviteeId) {
        $noticeUserId = filter_var($inviteeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($noticeUserId && isset($eligibleUsers[$noticeUserId])) {
            $notice['user_ids'][(int) $noticeUserId] = (int) $noticeUserId;
        }
    }
    $notice['user_ids'] = array_values($notice['user_ids']);
    if ($notice['confirmed']) {
        if (!$notice['user_ids']) {
            $errors[] = 'Select at least one invitee before confirming a budget notice.';
        }
        if (strlen($notice['message']) > 1200) {
            $errors[] = 'Budget notice messages must not exceed 1,200 characters.';
        }
    }

    return ['errors' => array_values(array_unique($errors)), 'header' => $header, 'expenses' => $expenses, 'notice' => $notice];
}

function rmsActivityBudgetSnapshot(mysqli $conn, int $activityId): array
{
    $stmt = $conn->prepare(
        'SELECT activity_format, funding_source, budget_status, organizational_allocation, contribution_prompt
         FROM activity_budgets WHERE activity_id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare activity budget history.');
    }
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load activity budget history.');
    }
    $header = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$header) {
        return [];
    }
    $expenseStmt = $conn->prepare(
        'SELECT schedule_id, expense_category, audience_scope, audience_user_id, charge_type,
                organizational_estimated_amount, organizational_actual_amount,
                participant_estimated_charge, participant_actual_charge, description
         FROM activity_budget_expenses WHERE activity_id = ? ORDER BY expense_id ASC'
    );
    if (!$expenseStmt) {
        throw new RuntimeException('Unable to prepare expense history.');
    }
    $expenseStmt->bind_param('i', $activityId);
    if (!$expenseStmt->execute()) {
        $expenseStmt->close();
        throw new RuntimeException('Unable to load expense history.');
    }
    $expenses = [];
    $result = $expenseStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $expenses[] = $row;
    }
    $expenseStmt->close();
    return ['budget' => $header, 'expenses' => $expenses];
}

function rmsActivityBudgetSave(mysqli $conn, int $activityId, int $actorId, array $header, array $expenses, bool $manageTransaction = true): bool
{
    if ($manageTransaction) {
        rmsActivityScheduleBegin($conn);
    }
    try {
        $activityStmt = rmsActivitySchedulePrepare($conn, 'SELECT activity_id FROM research_activities WHERE activity_id = ? FOR UPDATE');
        $activityStmt->bind_param('i', $activityId);
        if (!$activityStmt->execute() || !$activityStmt->get_result()->fetch_assoc()) {
            $activityStmt->close();
            throw new RuntimeException('The activity no longer exists.');
        }
        $activityStmt->close();
        $previous = rmsActivityBudgetSnapshot($conn, $activityId);
        $budgetStmt = rmsActivitySchedulePrepare(
            $conn,
            'INSERT INTO activity_budgets (activity_id, activity_format, funding_source, budget_status, organizational_allocation, contribution_prompt)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE activity_format = VALUES(activity_format),
                 funding_source = VALUES(funding_source), budget_status = VALUES(budget_status),
                 organizational_allocation = VALUES(organizational_allocation),
                 contribution_prompt = VALUES(contribution_prompt)'
        );
        $budgetStmt->bind_param('isssdi', $activityId, $header['activity_format'], $header['funding_source'], $header['budget_status'], $header['organizational_allocation'], $header['contribution_prompt']);
        if (!$budgetStmt->execute()) {
            $budgetStmt->close();
            throw new RuntimeException('Unable to save the activity budget.');
        }
        $budgetId = (int) $budgetStmt->insert_id;
        $budgetStmt->close();
        if ($budgetId === 0) {
            $idStmt = rmsActivitySchedulePrepare($conn, 'SELECT budget_id FROM activity_budgets WHERE activity_id = ? FOR UPDATE');
            $idStmt->bind_param('i', $activityId);
            if (!$idStmt->execute()) {
                $idStmt->close();
                throw new RuntimeException('Unable to load the activity budget.');
            }
            $budgetId = (int) $idStmt->get_result()->fetch_row()[0];
            $idStmt->close();
        }
        $deleteStmt = rmsActivitySchedulePrepare($conn, 'DELETE FROM activity_budget_expenses WHERE budget_id = ?');
        $deleteStmt->bind_param('i', $budgetId);
        if (!$deleteStmt->execute()) {
            $deleteStmt->close();
            throw new RuntimeException('Unable to replace activity expense items.');
        }
        $deleteStmt->close();

        $scheduleStmt = rmsActivitySchedulePrepare($conn, 'SELECT schedule_id FROM activity_schedule_dates WHERE activity_id = ? AND schedule_date = ? LIMIT 1');
        $expenseStmt = rmsActivitySchedulePrepare(
            $conn,
            'INSERT INTO activity_budget_expenses
                (budget_id, activity_id, schedule_id, expense_category, audience_scope, audience_user_id,
                 charge_type, organizational_estimated_amount, organizational_actual_amount,
                 participant_estimated_charge, participant_actual_charge, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($expenses as $expense) {
            if ($expense['audience_scope'] === 'specific') {
                $userStmt = rmsActivitySchedulePrepare(
                    $conn,
                    "SELECT user_id FROM users WHERE user_id = ? AND status = 'active'
                       AND role IN ('student','faculty','research_staff') AND deleted_at IS NULL LIMIT 1"
                );
                $userStmt->bind_param('i', $expense['audience_user_id']);
                if (!$userStmt->execute() || !$userStmt->get_result()->fetch_assoc()) {
                    $userStmt->close();
                    throw new RuntimeException('An expense audience user is no longer eligible. Refresh and review the budget.');
                }
                $userStmt->close();
            }
            $scheduleId = null;
            if ($expense['schedule_date'] !== null) {
                $scheduleStmt->bind_param('is', $activityId, $expense['schedule_date']);
                if (!$scheduleStmt->execute()) {
                    throw new RuntimeException('Unable to verify an expense schedule.');
                }
                $scheduleId = (int) ($scheduleStmt->get_result()->fetch_row()[0] ?? 0);
                if (!$scheduleId) {
                    throw new RuntimeException('An expense schedule does not belong to this activity.');
                }
            }
            $expenseStmt->bind_param(
                'iiissisdddds',
                $budgetId,
                $activityId,
                $scheduleId,
                $expense['expense_category'],
                $expense['audience_scope'],
                $expense['audience_user_id'],
                $expense['charge_type'],
                $expense['organizational_estimated_amount'],
                $expense['organizational_actual_amount'],
                $expense['participant_estimated_charge'],
                $expense['participant_actual_charge'],
                $expense['description']
            );
            if (!$expenseStmt->execute()) {
                throw new RuntimeException('Unable to save an activity expense.');
            }
        }
        $scheduleStmt->close();
        $expenseStmt->close();
        $current = rmsActivityBudgetSnapshot($conn, $activityId);
        $previousJson = $previous ? json_encode($previous, JSON_THROW_ON_ERROR) : null;
        $currentJson = json_encode($current, JSON_THROW_ON_ERROR);
        if ($previousJson !== $currentJson) {
            $revisionStmt = rmsActivitySchedulePrepare(
                $conn,
                'INSERT INTO activity_budget_revisions (activity_id, changed_by, previous_values, new_values)
                 VALUES (?, ?, ?, ?)'
            );
            $revisionStmt->bind_param('iiss', $activityId, $actorId, $previousJson, $currentJson);
            if (!$revisionStmt->execute()) {
                $revisionStmt->close();
                throw new RuntimeException('Unable to record the activity budget revision.');
            }
            $revisionStmt->close();
        }
        if ($manageTransaction) {
            rmsActivityScheduleCommit($conn);
        }
        return true;
    } catch (Throwable $exception) {
        if ($manageTransaction) {
            $conn->rollback();
        }
        throw $exception;
    }
}

function rmsActivityBudgetBuildNotice(array $budget, array $expenses, string $customMessage): string
{
    $lines = [];
    $sourceLabels = [
        'organizational' => 'The activity is funded by an institutional budget.',
        'participants' => 'Participant contributions fund the activity.',
        'combined' => 'The activity uses institutional funding and participant contributions.',
        'free' => 'Participation is free of charge.',
    ];
    $lines[] = $sourceLabels[$budget['funding_source']] ?? 'The activity funding details are being finalized.';
    $formatLabels = ['on_site' => 'on-site', 'online' => 'online', 'hybrid' => 'hybrid'];
    $lines[] = 'Participation format: ' . ($formatLabels[$budget['activity_format']] ?? 'not specified') . '.';
    if ($budget['budget_status'] === 'pending') {
        $lines[] = 'Funding arrangements and expenses are still being finalized and may change.';
    }
    $expenseLabels = [
        'registration' => 'Registration', 'transport' => 'Transportation / travel',
        'accommodation' => 'Accommodation', 'meals' => 'Meals',
        'online_fee' => 'Online participation fee', 'other' => 'Other related cost',
    ];
    $audienceLabels = [
        'student' => ' for students', 'faculty' => ' for faculty',
        'staff' => ' for staff', 'specific' => ' for a selected user',
    ];
    $charges = [];
    foreach ($expenses as $expense) {
        if ($budget['funding_source'] === 'organizational') {
            continue;
        }
        if ($expense['charge_type'] === 'none'
            && $expense['participant_estimated_charge'] === null
            && $expense['participant_actual_charge'] === null) {
            continue;
        }
        $amount = $expense['participant_estimated_charge'] ?? $expense['participant_actual_charge'];
        $amountText = $amount === null ? 'amount to be confirmed' : '₱' . number_format((float) $amount, 2);
        $chargeLabel = $expense['charge_type'] === 'mandatory'
            ? 'Required'
            : ($expense['charge_type'] === 'optional' ? 'Optional' : 'Recorded participant cost');
        $dateText = !empty($expense['schedule_date']) ? ' on ' . date('M j, Y', strtotime((string) $expense['schedule_date'])) : '';
        $scopeText = $audienceLabels[(string) ($expense['audience_scope'] ?? 'all')] ?? '';
        $categoryLabel = $expenseLabels[(string) $expense['expense_category']] ?? 'Other related cost';
        $charges[$expense['expense_category'] . ':' . $chargeLabel . ':' . $amountText . $dateText . $scopeText] = $chargeLabel . ' ' . $categoryLabel . $scopeText . $dateText . ': ' . $amountText . '.';
    }
    if ($charges) {
        $lines[] = implode(' ', array_values($charges));
    } elseif ($budget['funding_source'] !== 'free') {
        $lines[] = 'No participant registration fee or other participant expense is currently listed.';
    }
    if ($customMessage !== '') {
        $lines[] = trim($customMessage);
    }
    return implode("\n\n", $lines);
}

function rmsActivityBudgetCreateNotice(mysqli $conn, int $activityId, int $actorId, array $budget, array $expenses, array $eligibleUsers, array $noticeInput, string $confirmationKey): array
{
    $currentEligibleStmt = rmsActivitySchedulePrepare(
        $conn,
        "SELECT user_id, role FROM users
         WHERE status = 'active' AND role IN ('student','faculty','research_staff') AND deleted_at IS NULL"
    );
    if (!$currentEligibleStmt->execute()) {
        $currentEligibleStmt->close();
        throw new RuntimeException('Unable to recheck budget notice recipients.');
    }
    $currentEligibleUsers = [];
    $currentResult = $currentEligibleStmt->get_result();
    while ($eligibleUser = $currentResult->fetch_assoc()) {
        $currentEligibleUsers[(int) $eligibleUser['user_id']] = $eligibleUser;
    }
    $currentEligibleStmt->close();
    foreach ($noticeInput['user_ids'] as $selectedUserId) {
        if (!isset($currentEligibleUsers[(int) $selectedUserId])) {
            throw new RuntimeException('A selected budget notice recipient is no longer eligible. Review the recipient list.');
        }
    }
    $recipientIds = $noticeInput['user_ids'];
    foreach ($currentEligibleUsers as $eligibleUser) {
        if (in_array((string) $eligibleUser['role'], $noticeInput['roles'], true)) {
            $recipientIds[] = (int) $eligibleUser['user_id'];
        }
    }
    $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));
    if (!$recipientIds) {
        throw new RuntimeException('No selected budget notice recipients remain active. Review the recipients before sending.');
    }
    $noticeText = rmsActivityBudgetBuildNotice($budget, $expenses, (string) $noticeInput['message']);
    $noticeType = $budget['budget_status'] === 'pending' ? 'pending' : 'budget_update';
    $conn->begin_transaction();
    try {
        $noticeStmt = rmsActivitySchedulePrepare(
            $conn,
            'INSERT INTO activity_budget_notices (activity_id, created_by, confirmation_key, notice_type, message, confirmed_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE notice_id = LAST_INSERT_ID(notice_id)'
        );
        $noticeStmt->bind_param('iisss', $activityId, $actorId, $confirmationKey, $noticeType, $noticeText);
        if (!$noticeStmt->execute()) {
            $noticeStmt->close();
            throw new RuntimeException('The budget notice could not be recorded.');
        }
        $noticeId = (int) $noticeStmt->insert_id;
        $noticeStmt->close();
        $deliveryStmt = rmsActivitySchedulePrepare(
            $conn,
            "INSERT INTO activity_budget_notice_deliveries (notice_id, user_id, delivery_key, status)
             VALUES (?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE delivery_id = LAST_INSERT_ID(delivery_id)"
        );
        foreach ($recipientIds as $recipientId) {
            $deliveryKey = hash('sha256', $noticeId . ':' . $recipientId);
            $deliveryStmt->bind_param('iis', $noticeId, $recipientId, $deliveryKey);
            if (!$deliveryStmt->execute()) {
                $deliveryStmt->close();
                throw new RuntimeException('The budget notice recipients could not be recorded.');
            }
        }
        $deliveryStmt->close();
        if (!$conn->commit()) {
            $conn->rollback();
            $reconcileStmt = rmsActivitySchedulePrepare($conn, 'SELECT notice_id FROM activity_budget_notices WHERE confirmation_key = ? LIMIT 1');
            $reconcileStmt->bind_param('s', $confirmationKey);
            $reconciled = $reconcileStmt->execute() ? (int) ($reconcileStmt->get_result()->fetch_row()[0] ?? 0) : 0;
            $reconcileStmt->close();
            if ($reconciled < 1) {
                throw new RuntimeException('The notice confirmation result is uncertain. Review notice history before sending again.');
            }
            $noticeId = $reconciled;
        }
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
    $result = rmsActivityBudgetDeliverNotice($conn, $noticeId, $activityId, false);
    $result['notice_id'] = $noticeId;
    return $result;
}

function rmsActivityBudgetDeliverNotice(mysqli $conn, int $noticeId, int $activityId, bool $failedOnly): array
{
    $noticeStmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT n.message, ra.title FROM activity_budget_notices n
         INNER JOIN research_activities ra ON ra.activity_id = n.activity_id
         WHERE n.notice_id = ? AND n.activity_id = ? LIMIT 1'
    );
    $noticeStmt->bind_param('ii', $noticeId, $activityId);
    if (!$noticeStmt->execute()) {
        $noticeStmt->close();
        throw new RuntimeException('Unable to load the confirmed budget notice.');
    }
    $notice = $noticeStmt->get_result()->fetch_assoc();
    $noticeStmt->close();
    if (!$notice) {
        throw new RuntimeException('The requested budget notice was not found for this activity.');
    }
    $statusFilter = $failedOnly ? "AND d.status = 'failed'" : "AND d.status IN ('pending','failed')";
    $deliveryStmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT d.delivery_id, d.user_id, d.delivery_key, d.status
         FROM activity_budget_notice_deliveries d WHERE d.notice_id = ? ' . $statusFilter . ' ORDER BY d.delivery_id ASC'
    );
    $deliveryStmt->bind_param('i', $noticeId);
    if (!$deliveryStmt->execute()) {
        $deliveryStmt->close();
        throw new RuntimeException('Unable to load budget notice deliveries.');
    }
    $deliveries = [];
    $deliveryResult = $deliveryStmt->get_result();
    while ($row = $deliveryResult->fetch_assoc()) {
        $deliveries[] = $row;
    }
    $deliveryStmt->close();
    $delivered = 0;
    $failed = 0;
    foreach ($deliveries as $delivery) {
        $deliveryId = (int) $delivery['delivery_id'];
        $deliveryKey = (string) $delivery['delivery_key'];
        $existingStmt = rmsActivitySchedulePrepare($conn, 'SELECT notification_id FROM notifications WHERE activity_budget_delivery_key = ? LIMIT 1');
        $existingStmt->bind_param('s', $deliveryKey);
        if (!$existingStmt->execute()) {
            $existingStmt->close();
            $failed++;
            continue;
        }
        $existingNotificationId = (int) ($existingStmt->get_result()->fetch_row()[0] ?? 0);
        $existingStmt->close();
        if ($existingNotificationId > 0) {
            $markStmt = rmsActivitySchedulePrepare($conn, "UPDATE activity_budget_notice_deliveries SET notification_id = ?, status = 'delivered', delivered_at = COALESCE(delivered_at, NOW()), error_message = NULL WHERE delivery_id = ?");
            $markStmt->bind_param('ii', $existingNotificationId, $deliveryId);
            $markStmt->execute();
            $markStmt->close();
            $delivered++;
            continue;
        }
        try {
            $conn->begin_transaction();
            $lockStmt = rmsActivitySchedulePrepare($conn, 'SELECT status FROM activity_budget_notice_deliveries WHERE delivery_id = ? FOR UPDATE');
            $lockStmt->bind_param('i', $deliveryId);
            $lockStmt->execute();
            $lockedStatus = (string) ($lockStmt->get_result()->fetch_row()[0] ?? '');
            $lockStmt->close();
            if ($lockedStatus === 'delivered') {
        if (!$conn->commit()) {
            $conn->rollback();
            $reconcileStmt = rmsActivitySchedulePrepare($conn, 'SELECT notice_id FROM activity_budget_notices WHERE confirmation_key = ? LIMIT 1');
            $reconcileStmt->bind_param('s', $confirmationKey);
            $reconciled = $reconcileStmt->execute() ? (int) ($reconcileStmt->get_result()->fetch_row()[0] ?? 0) : 0;
            $reconcileStmt->close();
            if ($reconciled < 1) {
                throw new RuntimeException('The notice confirmation result is uncertain. Review notice history before sending again.');
            }
            $noticeId = $reconciled;
        }
                $delivered++;
                continue;
            }
            if ($lockedStatus !== 'pending' && $lockedStatus !== 'failed') {
                $conn->rollback();
                $failed++;
                continue;
            }
            $title = 'Activity budget update: ' . (string) $notice['title'];
            $type = 'info';
            $link = SITE_URL . 'pages/shared/activity-detail.php?id=' . $activityId;
            $insertStmt = rmsActivitySchedulePrepare(
                $conn,
                'INSERT INTO notifications (user_id, title, message, type, link, activity_budget_delivery_key)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE notification_id = LAST_INSERT_ID(notification_id)'
            );
            $insertStmt->bind_param('isssss', $delivery['user_id'], $title, $notice['message'], $type, $link, $deliveryKey);
            if (!$insertStmt->execute()) {
                $insertStmt->close();
                throw new RuntimeException('The in-app notice could not be delivered.');
            }
            $notificationId = (int) $insertStmt->insert_id;
            $insertStmt->close();
            $updateStmt = rmsActivitySchedulePrepare($conn, "UPDATE activity_budget_notice_deliveries SET notification_id = ?, status = 'delivered', delivered_at = NOW(), error_message = NULL WHERE delivery_id = ?");
            $updateStmt->bind_param('ii', $notificationId, $deliveryId);
            if (!$updateStmt->execute()) {
                $updateStmt->close();
                throw new RuntimeException('The notice delivery result could not be recorded.');
            }
            $updateStmt->close();
            if (!$conn->commit()) {
                throw new RuntimeException('The notice delivery result is uncertain.');
            }
            $delivered++;
        } catch (Throwable $exception) {
            $conn->rollback();
            $recheckStmt = rmsActivitySchedulePrepare($conn, 'SELECT notification_id FROM notifications WHERE activity_budget_delivery_key = ? LIMIT 1');
            $recheckStmt->bind_param('s', $deliveryKey);
            $exists = $recheckStmt->execute() ? (int) ($recheckStmt->get_result()->fetch_row()[0] ?? 0) : 0;
            $recheckStmt->close();
            if ($exists > 0) {
                $markStmt = rmsActivitySchedulePrepare($conn, "UPDATE activity_budget_notice_deliveries SET notification_id = ?, status = 'delivered', delivered_at = COALESCE(delivered_at, NOW()), error_message = NULL WHERE delivery_id = ?");
                $markStmt->bind_param('ii', $exists, $deliveryId);
                $markStmt->execute();
                $markStmt->close();
                $delivered++;
            } else {
                $errorText = substr($exception->getMessage(), 0, 500);
                $markStmt = rmsActivitySchedulePrepare($conn, "UPDATE activity_budget_notice_deliveries SET status = 'failed', error_message = ? WHERE delivery_id = ? AND status <> 'delivered'");
                $markStmt->bind_param('si', $errorText, $deliveryId);
                $markStmt->execute();
                $markStmt->close();
                $failed++;
            }
        }
    }
    return ['delivered' => $delivered, 'failed' => $failed];
}

function rmsActivityBudgetSaveContributionResponse(mysqli $conn, int $activityId, int $scheduleParticipantId, int $userId, string $response): bool
{
    if (!in_array($response, ['willing', 'unwilling', 'needs_information'], true)) {
        throw new RuntimeException('Choose a valid contribution response.');
    }
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        "INSERT INTO activity_contribution_responses (schedule_participant_id, user_id, response)
         SELECT sp.participant_id, sp.user_id, ?
         FROM activity_schedule_participants sp
         INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id
         INNER JOIN research_activities ra ON ra.activity_id = d.activity_id
         INNER JOIN activity_budgets b ON b.activity_id = ra.activity_id
         INNER JOIN users u ON u.user_id = sp.user_id AND u.status = 'active' AND u.deleted_at IS NULL
         WHERE sp.participant_id = ? AND sp.user_id = ? AND sp.is_invited = 1
           AND sp.invitation_response = 'accepted'
           AND d.activity_id = ? AND d.status = 'scheduled'
           AND ra.status IN ('upcoming','scheduled','ongoing') AND b.contribution_prompt = 1
           AND b.budget_status = 'pending' AND b.funding_source <> 'free'
           AND NOT EXISTS (
             SELECT 1 FROM activity_budget_expenses e
             WHERE e.budget_id = b.budget_id AND e.charge_type = 'mandatory'
           )
         ON DUPLICATE KEY UPDATE response = VALUES(response), responded_at = NOW()"
    );
    $stmt->bind_param('siii', $response, $scheduleParticipantId, $userId, $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Your contribution response could not be saved.');
    }
    $saved = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$saved) {
        throw new RuntimeException('You are not an invited participant for this activity date, or contribution responses are unavailable.');
    }
    return true;
}

function rmsActivityBudgetLoadContributionResponses(mysqli $conn, int $activityId): array
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT r.schedule_participant_id, r.response, r.responded_at
         FROM activity_contribution_responses r
         INNER JOIN activity_schedule_participants sp ON sp.participant_id = r.schedule_participant_id
         INNER JOIN activity_schedule_dates d ON d.schedule_id = sp.schedule_id
         WHERE d.activity_id = ?'
    );
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load contribution responses.');
    }
    $responses = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $responses[(int) $row['schedule_participant_id']] = $row;
    }
    $stmt->close();
    return $responses;
}

function rmsActivityBudgetLoadNotices(mysqli $conn, int $activityId): array
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT n.notice_id, n.notice_type, n.message, n.confirmed_at,
                SUM(d.status = \'delivered\') AS delivered_count,
                SUM(d.status = \'pending\') AS pending_count,
                SUM(d.status = \'failed\') AS failed_count
         FROM activity_budget_notices n
         LEFT JOIN activity_budget_notice_deliveries d ON d.notice_id = n.notice_id
         WHERE n.activity_id = ? GROUP BY n.notice_id, n.notice_type, n.message, n.confirmed_at
         ORDER BY n.notice_id DESC LIMIT 20'
    );
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load budget notice history.');
    }
    $notices = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notices[] = $row;
    }
    $stmt->close();
    return $notices;
}

function rmsActivityBudgetLoadNoticeDeliveries(mysqli $conn, int $noticeId, int $activityId): array
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT d.user_id, d.status, d.error_message, d.delivered_at,
                u.first_name, u.last_name, u.role
         FROM activity_budget_notice_deliveries d
         INNER JOIN activity_budget_notices n ON n.notice_id = d.notice_id
         LEFT JOIN users u ON u.user_id = d.user_id
         WHERE d.notice_id = ? AND n.activity_id = ? ORDER BY u.role ASC, u.last_name ASC, u.first_name ASC'
    );
    $stmt->bind_param('ii', $noticeId, $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load budget notice recipients.');
    }
    $recipients = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recipients[] = $row;
    }
    $stmt->close();
    return $recipients;
}

function rmsActivityBudgetLoadRevisions(mysqli $conn, int $activityId): array
{
    $stmt = rmsActivitySchedulePrepare(
        $conn,
        'SELECT r.revision_id, r.previous_values, r.new_values, r.created_at,
                u.first_name, u.last_name
         FROM activity_budget_revisions r
         LEFT JOIN users u ON u.user_id = r.changed_by
         WHERE r.activity_id = ? ORDER BY r.revision_id DESC LIMIT 20'
    );
    $stmt->bind_param('i', $activityId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load activity budget history.');
    }
    $revisions = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['previous_values'] = $row['previous_values'] !== null ? json_decode((string) $row['previous_values'], true) : null;
        $row['new_values'] = json_decode((string) $row['new_values'], true);
        $revisions[] = $row;
    }
    $stmt->close();
    return $revisions;
}

