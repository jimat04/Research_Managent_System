<?php
/** Shared inline SVG icons for authenticated role shells. */
if (!function_exists('rmsShellIcon')) {
    function rmsShellIcon(string $name): string
    {
        $paths = [
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'activity' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h3M8 17h7"/>',
            'seminar' => '<circle cx="12" cy="8" r="3"/><path d="M5 20c.7-3.2 3.1-5 7-5s6.3 1.8 7 5M4 4v4M2 6h4"/>',
            'presentation' => '<path d="M3 4h18v12H3zM12 16v4M8 20h8M7 13l3-3 2 2 4-5"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8v6M23 11h-6"/>',
            'records' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'publication' => '<path d="M5 3h12a2 2 0 0 1 2 2v16H7a2 2 0 0 1-2-2V3ZM5 17h14M9 7h6M9 11h6"/><path d="M7 21a2 2 0 0 1-2-2"/>',
            'document' => '<path d="M6 3h9l5 5v13H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v6h6M8 13h8M8 17h8"/>',
            'repository' => '<path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/>',
            'monitor' => '<path d="M4 19V5M4 19h17M8 15l3-4 3 2 5-7"/><circle cx="19" cy="6" r="1"/>',
            'copyright' => '<circle cx="12" cy="12" r="9"/><path d="M15 9.5a3.5 3.5 0 1 0 0 5"/>',
            'output' => '<path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5zM4 7.5l8 4.5 8-4.5M12 12v9M8 5.2l8 4.6"/>',
            'reports' => '<path d="M4 20V4M4 20h17"/><path d="M8 16v-4M12 16V7M16 16v-6M20 16V9"/>',
            'settings' => '<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>',
            'contact' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
            'logs' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'backup' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
            'messages' => '<path d="M4 5h16v12H9l-5 4z"/><path d="M8 9h8M8 13h5"/>',
            'notifications' => '<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
            'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'logout' => '<path d="M10 17l5-5-5-5M15 12H3M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>',
        ];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['records']) . '</svg>';
    }
}
