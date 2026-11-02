<?php
/** Server-owned paths only. Never populate this configuration from request fields. */
return [
    'root' => dirname(__DIR__) . '/storage/earist',
    'input_schema_version' => 1,
    'file_manifest_version' => 1,
    // Enabled only for the user's approved controlled disposable testing window.
    'live_generation_enabled' => true,
    'max_docx_bytes' => 32 * 1024 * 1024,
    'max_uncompressed_bytes' => 64 * 1024 * 1024,
    'max_package_entries' => 2048,
];
