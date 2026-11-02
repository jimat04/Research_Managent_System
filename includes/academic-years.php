<?php

/** Academic years advance on January 1 using the application's timezone. */
function rms_current_academic_year(?DateTimeImmutable $date = null): string
{
    $year = (int) ($date ?? new DateTimeImmutable('now'))->format('Y');
    return $year . '-' . ($year + 1);
}

/**
 * One option per year, including the current year before it has a database row.
 * Keep a project's saved row even when inactive or duplicated by a semester.
 * Reading the form never creates or reactivates academic-year records.
 */
function rms_academic_year_options(mysqli $conn, int $saved_ay_id = 0): array
{
    $current_year = rms_current_academic_year();
    $stmt = $conn->prepare('SELECT ay_id, label FROM academic_years
        WHERE is_active = 1 OR label = ? OR ay_id = ?
        ORDER BY (ay_id = ?) DESC, is_active DESC, ay_id ASC');
    if (!$stmt) {
        throw new RuntimeException('Unable to load academic years.');
    }
    $stmt->bind_param('sii', $current_year, $saved_ay_id, $saved_ay_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load academic years.');
    }
    $result = $stmt->get_result();
    $options = [];
    while ($row = $result->fetch_assoc()) {
        $label = (string) $row['label'];
        if (!array_key_exists($label, $options)) {
            $options[$label] = (int) $row['ay_id'];
        }
    }
    $stmt->close();
    if (!array_key_exists($current_year, $options)) {
        $options[$current_year] = 0;
    }
    krsort($options, SORT_STRING);
    return $options;
}

function rms_validate_academic_year($raw_year, array $options): string
{
    if (!is_string($raw_year) || trim($raw_year) === '') {
        throw new InvalidArgumentException('Academic Year is required.');
    }
    $label = trim($raw_year);
    if (!array_key_exists($label, $options)) {
        throw new InvalidArgumentException('Invalid Academic Year selected.');
    }
    return $label;
}

/** Public and signed-in archives share year options without semester grouping. */
function rms_archive_academic_year_options(mysqli $conn, bool $has_deleted_at): array
{
    $current_year = rms_current_academic_year();
    $deleted_filter = $has_deleted_at ? ' AND rp.deleted_at IS NULL' : '';
    $stmt = $conn->prepare("SELECT DISTINCT ay.label
        FROM academic_years ay
        INNER JOIN research_projects rp ON rp.ay_id = ay.ay_id
        WHERE rp.status IN ('completed', 'archived'){$deleted_filter}
          AND ay.label <= ?
        ORDER BY ay.label DESC");
    if (!$stmt) {
        throw new RuntimeException('Unable to load archive academic years.');
    }
    $stmt->bind_param('s', $current_year);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load archive academic years.');
    }
    $years = [$current_year];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $years[] = (string) $row['label'];
    }
    $stmt->close();
    $years = array_values(array_unique($years));
    rsort($years, SORT_STRING);
    return $years;
}

/** Call only inside the authorized project save transaction. */
function rms_resolve_academic_year(mysqli $conn, string $label, int $saved_ay_id = 0): int
{
    // Recheck availability at save time, rather than trusting the posted label.
    $options = rms_academic_year_options($conn, $saved_ay_id);
    rms_validate_academic_year($label, $options);
    if ($options[$label] > 0) {
        return $options[$label];
    }

    // The legacy schema requires a semester. Retain its existing convention
    // internally; researchers choose only the year and existing IDs stay intact.
    $stmt = $conn->prepare("INSERT INTO academic_years (label, semester, is_active) VALUES (?, '1st', 1)");
    if (!$stmt) {
        throw new RuntimeException('Unable to save the current academic year.');
    }
    $stmt->bind_param('s', $label);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to save the current academic year.');
    }
    $ay_id = (int) $stmt->insert_id;
    $stmt->close();
    return $ay_id;
}
