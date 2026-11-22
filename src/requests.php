<?php
declare(strict_types=1);

/* Library hours, pickup rules and request display helpers. */

const WEEKDAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
const WEEKDAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/**
 * Read library_hours, written like "Mon-Fri 8:00 AM-5:00 PM" (or "Mon-Sat 7:30 AM-6:00 PM").
 * Returns days (1 = Monday ... 7 = Sunday), open and close as HH:MM, and the original text.
 * If the setting cannot be read, Monday to Friday 8:00 AM to 5:00 PM is used.
 */
function library_hours(): array
{
    static $hours = null;
    if ($hours !== null) {
        return $hours;
    }
    $label = (string) setting('library_hours', 'Mon-Fri 8:00 AM-5:00 PM');
    $hours = ['label' => $label, 'days' => [1, 2, 3, 4, 5], 'open' => '08:00', 'close' => '17:00'];
    $day = '(mon|tue|wed|thu|fri|sat|sun)[a-z]*';
    $time = '(\d{1,2}:\d{2}\s*[ap]\.?m\.?)';
    if (preg_match("/^\s*$day\s*(?:-\s*$day)?\s+$time\s*-\s*$time/i", $label, $m)) {
        $from = WEEKDAYS[strtolower($m[1])];
        $to = $m[2] !== '' ? WEEKDAYS[strtolower($m[2])] : $from;
        $hours['days'] = $from <= $to ? range($from, $to) : array_merge(range($from, 7), range(1, $to));
        $open = DateTimeImmutable::createFromFormat('g:i A', strtoupper(str_replace('.', '', preg_replace('/\s+/', ' ', $m[3]))));
        $close = DateTimeImmutable::createFromFormat('g:i A', strtoupper(str_replace('.', '', preg_replace('/\s+/', ' ', $m[4]))));
        if ($open && $close && $open < $close) {
            $hours['open'] = $open->format('H:i');
            $hours['close'] = $close->format('H:i');
        }
    }
    return $hours;
}

function is_open_day(string $ymd): bool
{
    return in_array((int) (new DateTimeImmutable($ymd))->format('N'), library_hours()['days'], true);
}

/** The first open day on or after $ymd. */
function next_open_day(string $ymd): string
{
    $d = new DateTimeImmutable($ymd);
    for ($i = 0; $i < 14; $i++) {
        if (is_open_day($d->format('Y-m-d'))) {
            return $d->format('Y-m-d');
        }
        $d = $d->modify('+1 day');
    }
    return $ymd;
}

/** Default pickup: the next open day after today, at opening time. */
function default_pickup(): array
{
    $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    return ['date' => next_open_day($tomorrow), 'time' => library_hours()['open']];
}

/** Check a pickup date (Y-m-d) and time (H:i). Returns [errors by field, clean date, clean time]. */
function validate_pickup(string $date, string $time): array
{
    $errors = [];
    $hours = library_hours();
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        $errors['pickup_date'] = 'Choose a pickup date.';
    } elseif ($date < today()) {
        $errors['pickup_date'] = 'The pickup date cannot be in the past.';
    } elseif (!is_open_day($date)) {
        $errors['pickup_date'] = 'The library is closed on ' . WEEKDAY_NAMES[(int) $d->format('N')] . 's. Choose an open day (' . $hours['label'] . ').';
    }
    $time = substr($time, 0, 5);
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $errors['pickup_time'] = 'Choose a pickup time.';
    } elseif ($time < $hours['open'] || $time > $hours['close']) {
        $errors['pickup_time'] = 'Choose a time between ' . fmt_time($hours['open']) . ' and ' . fmt_time($hours['close']) . '.';
    }
    return [$errors, $date, $time];
}

/** Active message presets of the given kinds, in display order. */
function message_presets(array $kinds): array
{
    $placeholders = implode(', ', array_map(fn($i) => ':k' . $i, array_keys($kinds)));
    $params = [];
    foreach (array_values($kinds) as $i => $k) {
        $params[':k' . $i] = $k;
    }
    return q_all("SELECT template_id, kind, label FROM message_template WHERE is_active AND kind IN ($placeholders) ORDER BY kind, sort_order, template_id", $params);
}

/** Status chip (with the extra line people need) for a request row. */
function request_status_html(array $r): string
{
    return match ($r['status']) {
        'pending' => '<span class="chip chip-warn">Pending</span>',
        'approved' => '<span class="chip chip-info">Awaiting pickup</span><span class="sub">Pickup ' . h(fmt_date($r['pickup_date'])) . ', ' . h(fmt_time($r['pickup_time']))
            . '<br>Held until ' . h(fmt_date($r['pickup_deadline'])) . '</span>',
        'fulfilled' => '<span class="chip chip-ok">Fulfilled</span>',
        'rejected' => '<span class="chip chip-bad">Rejected</span>' . ($r['decision_note'] ? '<span class="sub">Reason: ' . h($r['decision_note']) . '</span>' : ''),
        'expired' => '<span class="chip">Expired</span><span class="sub">Not picked up by ' . h(fmt_date($r['pickup_deadline'])) . '</span>',
        'cancelled' => '<span class="chip">Cancelled</span>' . ($r['decision_note'] ? '<span class="sub">' . h($r['decision_note']) . '</span>' : ''),
        default => '<span class="chip">' . h(ucfirst($r['status'])) . '</span>',
    };
}

/** The rejection preset that fits this borrower best (by label). */
function suggested_rejection(array $r): string
{
    return match (true) {
        (float) $r['borrower_unpaid_fines'] > 0 => 'Unpaid fine',
        (int) $r['borrower_overdue_loans'] > 0 => 'Overdue book',
        (int) $r['copies_available'] < 1 => 'All copies on loan',
        (int) $r['borrower_active_loans'] >= (int) $r['borrower_max_loans'] => 'Borrowing limit reached',
        default => 'All copies on loan',
    };
}
