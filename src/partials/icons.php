<?php
declare(strict_types=1);

/*
 * Small line icons, drawn for this project (24 x 24, stroke = currentColor).
 * Icons are decorative (aria-hidden); every icon sits next to a visible word or an sr-only label.
 */

function icon(string $name, string $class = ''): string
{
    static $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.3c2.1.6 3.5 2.6 3.5 5.7"/>',
        'book' => '<path d="M5 4.5A2.5 2.5 0 0 1 7.5 2H19v16H7.5A2.5 2.5 0 0 0 5 20.5z"/><path d="M5 20.5A2.5 2.5 0 0 0 7.5 23H19v-5"/><path d="M9 6h6"/>',
        'books' => '<rect x="3" y="4" width="4" height="16" rx="1"/><rect x="8" y="2" width="4" height="18" rx="1"/><path d="m13.5 5.2 3.7-1 4 15.5-3.8 1z"/>',
        'inbox' => '<path d="M3 13l2.6-7.4A2 2 0 0 1 7.5 4.3h9a2 2 0 0 1 1.9 1.3L21 13v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 13h5l1.5 2.5h5L16 13h5"/>',
        'issue' => '<path d="M4 6h9v12H4z"/><path d="M13 12h8m-3-3 3 3-3 3"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'coins' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
        'chart' => '<path d="M3 21h18"/><rect x="5" y="11" width="3" height="7" rx=".5"/><rect x="10.5" y="6" width="3" height="12" rx=".5"/><rect x="16" y="13" width="3" height="5" rx=".5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 6.5 8.5 6.5 8.5-6.5"/>',
        'settings' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'activity' => '<path d="M3 12h4l2.5-6 5 12 2.5-6H21"/>',
        'home' => '<path d="M3 11 12 4l9 7"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5h4v5"/>',
        'logout' => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'alert' => '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4.5M12 17.5v.01"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'archive' => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11h14V8M10 12h4"/>',
        'more' => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'send' => '<path d="M21 3 10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
        'download' => '<path d="M12 4v11m-4.5-4.5L12 15l4.5-4.5"/><path d="M4 19h16"/>',
        'return' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        'restore' => '<path d="M4 12a8 8 0 1 0 2.4-5.7L4 8.5"/><path d="M4 4v4.5h4.5"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8m-3 3 3 3"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.01"/>',
        'printer' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
    ];
    $body = $paths[$name] ?? $paths['info'];
    return '<svg class="icon' . ($class !== '' ? ' ' . h($class) : '') . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/** The library mark: an open book in a rounded square. Used in the top bar, sign-in page and favicon. */
function logo_svg(int $size = 32): string
{
    return '<svg class="logo" viewBox="0 0 40 40" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">'
        . '<rect width="40" height="40" rx="10" fill="#005b96"/>'
        . '<path d="M8 12.5c4.2-1.6 8.1-1.1 12 1.6v15.4c-3.9-2.7-7.8-3.2-12-1.6z" fill="#ffffff"/>'
        . '<path d="M32 12.5c-4.2-1.6-8.1-1.1-12 1.6v15.4c3.9-2.7 7.8-3.2 12-1.6z" fill="#b3cde0"/>'
        . '<path d="M20 14.1v15.4" stroke="#011f4b" stroke-width="1.4"/>'
        . '</svg>';
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'librarian' => 'Librarian',
        'student' => 'Student',
        'teacher' => 'Teacher',
        default => ucfirst($role),
    };
}
