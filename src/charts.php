<?php
declare(strict_types=1);

/*
 * Server-drawn SVG charts for Status and Reports. No chart library.
 * Marks follow one spec: columns at most 24px wide with a 4px rounded top and a square base, a 2px gap between
 * touching columns, hairline solid gridlines, every column labelled with its number, a hover tooltip (<title>),
 * a legend for two series and a one-line text summary. Text never uses the series colour.
 * Palette: series 1 #005b96, series 2 #6497b1 with a diagonal hatch (the second blue is low in chroma, so the
 * hatch, the legend and the labels carry identity as well as colour).
 */

/** A "nice" axis maximum and step for values up to $max (1, 2 or 5 times a power of ten). */
function nice_scale(float $max, int $ticks = 4): array
{
    if ($max <= 0) {
        return [$ticks, 1.0];
    }
    $raw = $max / $ticks;
    $power = 10 ** floor(log10($raw));
    foreach ([1, 2, 5, 10] as $m) {
        if ($raw <= $m * $power) {
            $step = $m * $power;
            break;
        }
    }
    $step = max($step ?? $power, 1);
    return [$step * ceil($max / $step), $step];
}

/** Column path with a rounded top (radius r) and a square base. */
function column_path(float $x, float $y, float $w, float $h): string
{
    if ($h <= 0) {
        return '';
    }
    $r = min(4.0, $w / 2, $h);
    $base = $y + $h;
    return sprintf(
        'M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ',
        $x, $base, $y + $r, $x, $y, $x + $r, $y, $x + $w - $r, $x + $w, $y, $x + $w, $y + $r, $base
    );
}

/**
 * Grouped column chart.
 * $groups: [['label' => 'Sep 2026', 'short' => 'Sep', 'values' => [5, 3]], ...]
 * $series: [['label' => 'Borrows', 'color' => '#005b96', 'hatch' => false], ...]
 * $format: callable(float): string for labels and tooltips.
 */
function column_chart(string $id, array $groups, array $series, callable $format, string $summary): string
{
    $w = 560;
    $h = 260;
    $left = 52;
    $right = 12;
    $top = 26;
    $bottom = 34;
    $plotW = $w - $left - $right;
    $plotH = $h - $top - $bottom;
    $max = 0.0;
    foreach ($groups as $g) {
        foreach ($g['values'] as $v) {
            $max = max($max, (float) $v);
        }
    }
    [$axisMax, $step] = nice_scale($max);
    $n = max(1, count($groups));
    $s = count($series);
    $band = $plotW / $n;
    $barW = min(24.0, ($band * 0.7 - ($s - 1) * 2) / $s);
    $groupW = $s * $barW + ($s - 1) * 2;
    $y = static fn(float $v) => $top + $plotH - ($axisMax > 0 ? $v / $axisMax * $plotH : 0);

    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-labelledby="' . h($id) . '-sum" preserveAspectRatio="xMidYMid meet">';
    $svg .= '<defs><pattern id="' . h($id) . '-hatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
        . '<rect width="6" height="6" fill="#6497b1"/><rect width="2" height="6" fill="#ffffff" opacity="0.55"/></pattern></defs>';

    // Gridlines and y-axis ticks (hairline, solid, recessive).
    for ($t = 0; $t <= $axisMax + 0.0001; $t += $step) {
        $ty = $y($t);
        $svg .= '<line x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . round($ty, 1) . '" y2="' . round($ty, 1) . '" class="chart-grid' . ($t == 0 ? ' chart-base' : '') . '"/>';
        $svg .= '<text x="' . ($left - 8) . '" y="' . round($ty + 4, 1) . '" class="chart-tick" text-anchor="end">' . h($format($t, true)) . '</text>';
    }

    foreach ($groups as $i => $g) {
        $gx = $left + $i * $band + ($band - $groupW) / 2;
        // Labels of neighbouring columns must not touch. Estimate each label's width (about 7.5 units per
        // character at 13px); when two would collide, the smaller value's label moves to the tooltip and table.
        $hideLabel = [];
        for ($k = 1; $k < $s; $k++) {
            $a = (float) ($g['values'][$k - 1] ?? 0);
            $b = (float) ($g['values'][$k] ?? 0);
            $wa = mb_strlen($format($a, true)) * 7.5;
            $wb = mb_strlen($format($b, true)) * 7.5;
            if ($a > 0 && $b > 0 && ($wa + $wb) / 2 + 3 > $barW + 2) {
                $hideLabel[$a >= $b ? $k : $k - 1] = true;
            }
        }
        foreach ($series as $k => $ser) {
            $v = (float) ($g['values'][$k] ?? 0);
            $bx = $gx + $k * ($barW + 2);
            $by = $y($v);
            $bh = $top + $plotH - $by;
            $fill = !empty($ser['hatch']) ? 'url(#' . h($id) . '-hatch)' : $ser['color'];
            $svg .= '<g class="chart-mark"><title>' . h($ser['label'] . ', ' . $g['label'] . ': ' . $format($v, false)) . '</title>';
            if ($bh > 0.5) {
                $svg .= '<path d="' . column_path($bx, $by, $barW, $bh) . '" fill="' . $fill . '"/>';
            }
            // A taller invisible target makes hovering easy even for short bars.
            $svg .= '<rect x="' . round($bx, 1) . '" y="' . $top . '" width="' . round($barW, 1) . '" height="' . $plotH . '" fill="transparent"/>';
            // Every column carries its number; an empty month has no column, so it gets no label (the tooltip and table still say 0).
            if ($v > 0 && empty($hideLabel[$k])) {
                $svg .= '<text x="' . round($bx + $barW / 2, 1) . '" y="' . round($by - 6, 1) . '" class="chart-value" text-anchor="middle">' . h($format($v, true)) . '</text>';
            }
            $svg .= '</g>';
        }
        $svg .= '<text x="' . round($left + $i * $band + $band / 2, 1) . '" y="' . ($h - 12) . '" class="chart-tick" text-anchor="middle">' . h($g['short']) . '</text>';
    }
    $svg .= '</svg>';

    $legend = '';
    if ($s > 1) {
        $legend = '<ul class="chart-legend">';
        foreach ($series as $ser) {
            $swatch = !empty($ser['hatch']) ? 'chart-swatch is-hatched' : 'chart-swatch';
            $legend .= '<li><span class="' . $swatch . '" style="background-color:' . h($ser['color']) . '"></span>' . h($ser['label']) . '</li>';
        }
        $legend .= '</ul>';
    }
    return $legend . $svg . '<p class="chart-summary" id="' . h($id) . '-sum">' . h($summary) . '</p>';
}

/** Horizontal bars (one series): label, bar, value at the tip. */
function bar_list(array $rows, string $labelKey, string $valueKey, callable $format, string $ariaLabel): string
{
    $max = 0;
    foreach ($rows as $r) {
        $max = max($max, (float) $r[$valueKey]);
    }
    $html = '<ol class="bar-list" aria-label="' . h($ariaLabel) . '">';
    foreach ($rows as $r) {
        $v = (float) $r[$valueKey];
        $pct = $max > 0 ? max(1.5, $v / $max * 100) : 0;
        $html .= '<li><span class="bar-label">' . h($r[$labelKey]) . '</span>'
            . '<span class="bar-track"><span class="bar-fill" style="width:' . round($pct * 0.82, 1) . '%" title="' . h($r[$labelKey] . ': ' . $format($v)) . '"></span>'
            . '<span class="bar-value">' . h($format($v)) . '</span></span></li>';
    }
    return $html . '</ol>';
}
