<?php
/**
 * EARIST faculty ranks and salary grades, using the list supplied for this update
 * (Faculty Manual section 3.8.1, page 11). Store rank names, not display labels.
 */
function rmsFacultyAcademicRanks(): array
{
    return [
        'Instructor I' => 12,
        'Instructor II' => 13,
        'Instructor III' => 14,
        'Assistant Professor I' => 15,
        'Assistant Professor II' => 16,
        'Assistant Professor III' => 17,
        'Assistant Professor IV' => 18,
        'Associate Professor I' => 19,
        'Associate Professor II' => 20,
        'Associate Professor III' => 21,
        'Associate Professor IV' => 22,
        'Associate Professor V' => 23,
        'Professor I' => 24,
        'Professor II' => 25,
        'Professor III' => 26,
        'Professor IV' => 27,
        'Professor V' => 28,
        'Professor VI' => 29,
        'College Professor' => 30,
    ];
}

function rmsFacultyAcademicRankLabel(?string $rank): string
{
    return $rank ?? '';
}

/** Existing broad ranks may be retained, but never assigned to another account. */
function rmsFacultyAcademicRankIsValid($rank, ?string $existingRank = null): bool
{
    return is_string($rank)
        && ($rank === '' || isset(rmsFacultyAcademicRanks()[$rank])
            || ($existingRank !== null && $rank === $existingRank));
}
