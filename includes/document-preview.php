<?php
declare(strict_types=1);

function rmsDocumentPreviewContentType(string $relativePath, string $detectedMime): ?string
{
    return match (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))) {
        'pdf' => $detectedMime === 'application/pdf' ? 'application/pdf' : null,
        'txt' => $detectedMime === 'text/plain' ? 'text/plain; charset=UTF-8' : null,
        default => null,
    };
}
