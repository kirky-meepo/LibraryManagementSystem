<?php
declare(strict_types=1);

/*
 * Gradient stat cards with book art.
 *   echo stat_card([
 *     'label' => 'Total titles', 'value' => 12, 'href' => '/librarian/books.php',
 *     'from' => '#011f4b', 'to' => '#03396c', 'icon' => 'books', 'art' => 'stack',
 *     'note' => '44 copies', 'prefix' => '₱', 'decimals' => 2, 'index' => 0,
 *   ]);
 * Text colour is chosen by the contrast rule, never by hand.
 */
require_once __DIR__ . '/icons.php';

const PALETTE = ['#011f4b', '#03396c', '#005b96', '#6497b1', '#b3cde0'];
const BOOK_ART = ['stack', 'open', 'bookmark', 'shelf', 'leaning'];

/** Relative luminance (WCAG 2) of a #rrggbb colour. */
function luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channel = static function (string $part): float {
        $c = hexdec($part) / 255;
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));
}

function contrast_ratio(string $a, string $b): float
{
    $la = luminance($a);
    $lb = luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * Contrast rule: white text only when the lightest stop is #005b96 or darker, otherwise navy #011f4b.
 * Returns 'light' (white text) or 'dark' (navy text).
 */
function card_ink(string $from, string $to): string
{
    $lightest = max(luminance($from), luminance($to));
    return $lightest <= luminance('#005b96') + 1e-9 ? 'light' : 'dark';
}

/** Inline SVG of one of our book illustrations (they use currentColor, so CSS tints them). */
function book_art(string $name): string
{
    static $cache = [];
    if (!in_array($name, BOOK_ART, true)) {
        $name = 'stack';
    }
    if (!isset($cache[$name])) {
        $file = APP_ROOT . '/public/assets/img/books/' . $name . '.svg';
        $svg = is_file($file) ? (string) file_get_contents($file) : '';
        $cache[$name] = preg_replace('/<\?xml.*?\?>\s*/s', '', $svg) ?? '';
    }
    return $cache[$name];
}

function stat_card(array $o): string
{
    $from = in_array(strtolower($o['from'] ?? ''), PALETTE, true) ? strtolower($o['from']) : '#011f4b';
    $to = in_array(strtolower($o['to'] ?? ''), PALETTE, true) ? strtolower($o['to']) : '#03396c';
    $ink = card_ink($from, $to);
    $decimals = (int) ($o['decimals'] ?? 0);
    $prefix = (string) ($o['prefix'] ?? '');
    $value = (float) ($o['value'] ?? 0);
    $display = $prefix . number_format($value, $decimals);
    $index = (int) ($o['index'] ?? 0);
    $angle = preg_match('/^\d{1,3}deg$/', (string) ($o['angle'] ?? '')) ? $o['angle'] : '135deg';
    $style = sprintf('--g1:%s;--g2:%s;--i:%d;--angle:%s', $from, $to, $index, $angle);
    $tag = !empty($o['href']) ? 'a' : 'div';
    $href = $tag === 'a' ? ' href="' . h($o['href']) . '"' : '';

    return '<' . $tag . ' class="stat-card ink-' . $ink . '"' . $href . ' style="' . h($style) . '" data-tilt>'
        . '<span class="stat-top"><span class="stat-icon">' . icon($o['icon'] ?? 'books') . '</span><span class="stat-label">' . h($o['label'] ?? '') . '</span></span>'
        . '<span class="stat-value"><span aria-hidden="true" data-count-to="' . h((string) $value) . '" data-decimals="' . $decimals . '" data-prefix="' . h($prefix) . '">'
        . h($display) . '</span><span class="sr-only">' . h($display) . '</span></span>'
        . (!empty($o['note']) ? '<span class="stat-note">' . h($o['note']) . '</span>' : '')
        . '<span class="stat-art" aria-hidden="true">' . book_art($o['art'] ?? 'stack') . '</span>'
        . '</' . $tag . '>';
}

/**
 * The six dashboard cards: label, icon, art and gradient pair. One place, used by the dashboard and the style guide.
 * Awaiting pickup uses #005b96 -> #011f4b: the suggested #005b96 -> #6497b1 fails 4.5:1 for both white and navy.
 */
function dashboard_card_styles(): array
{
    return [
        'total_titles' => ['label' => 'Total titles', 'from' => '#011f4b', 'to' => '#03396c', 'icon' => 'books', 'art' => 'shelf', 'angle' => '135deg'],
        'copies_on_loan' => ['label' => 'Copies on loan', 'from' => '#03396c', 'to' => '#005b96', 'icon' => 'issue', 'art' => 'open', 'angle' => '150deg'],
        'awaiting_pickup' => ['label' => 'Awaiting pickup', 'from' => '#005b96', 'to' => '#011f4b', 'icon' => 'clock', 'art' => 'bookmark', 'angle' => '160deg'],
        'pending_requests' => ['label' => 'Pending requests', 'from' => '#6497b1', 'to' => '#b3cde0', 'icon' => 'inbox', 'art' => 'stack', 'angle' => '135deg'],
        'overdue_loans' => ['label' => 'Overdue', 'from' => '#011f4b', 'to' => '#005b96', 'icon' => 'alert', 'art' => 'leaning', 'angle' => '120deg'],
        'unpaid_fines' => ['label' => 'Unpaid fines', 'from' => '#b3cde0', 'to' => '#6497b1', 'icon' => 'coins', 'art' => 'open', 'angle' => '145deg'],
    ];
}
