<?php
declare(strict_types=1);

/** "Showing 16 to 30 of 42" with Previous and Next links that keep the current filters. */
function render_pager(int $total, int $page, int $perPage, string $noun = 'rows'): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
    $to = min($total, $page * $perPage);
    $html = '<nav class="pager" aria-label="Pages"><span>' . ($total === 0 ? 'No ' . h($noun) : 'Showing ' . $from . ' to ' . $to . ' of ' . $total . ' ' . h($noun)) . '</span>';
    if ($pages > 1) {
        $html .= '<span class="pager-links">';
        $html .= $page > 1
            ? '<a class="btn btn-secondary btn-sm" href="' . h(url_with(['page' => $page - 1])) . '">Previous</a>'
            : '<span class="btn btn-secondary btn-sm" aria-disabled="true">Previous</span>';
        $html .= '<span class="muted">Page ' . $page . ' of ' . $pages . '</span>';
        $html .= $page < $pages
            ? '<a class="btn btn-secondary btn-sm" href="' . h(url_with(['page' => $page + 1])) . '">Next</a>'
            : '<span class="btn btn-secondary btn-sm" aria-disabled="true">Next</span>';
        $html .= '</span>';
    }
    return $html . '</nav>';
}
