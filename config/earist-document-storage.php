<?php
/** Server-owned paths only. Never populate this configuration from request fields. */
$liveGenerationSetting = $_ENV['EARIST_LIVE_GENERATION_ENABLED']
    ?? $_SERVER['EARIST_LIVE_GENERATION_ENABLED']
    ?? getenv('EARIST_LIVE_GENERATION_ENABLED')
    ?: '0';

return [
    'root' => dirname(__DIR__) . '/storage/earist',
    'input_schema_version' => 1,
    'file_manifest_version' => 1,
    // Environment fallback; application_settings may override this for the current site.
    'live_generation_enabled' => filter_var($liveGenerationSetting, FILTER_VALIDATE_BOOLEAN),
    'max_docx_bytes' => 32 * 1024 * 1024,
    'max_uncompressed_bytes' => 64 * 1024 * 1024,
    'max_package_entries' => 2048,
];
