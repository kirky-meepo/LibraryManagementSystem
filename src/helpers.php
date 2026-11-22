<?php
declare(strict_types=1);

/** Escape any value for HTML output (text and attributes). */
function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Send the browser to a path on this site and stop. Only local paths are allowed. */
function redirect(string $path): never
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        $path = '/';
    }
    header('Location: ' . $path, true, 303);
    exit;
}

/** The current path plus query string, for redirect-after-POST. */
function current_url(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return str_starts_with($uri, '/') && !str_starts_with($uri, '//') ? $uri : '/';
}

/* ---------- flash messages (shown once, as toasts) ---------- */

function flash(string $type, string $message, ?array $action = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'action' => $action];
}

function flash_take(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* ---------- formatting ---------- */

/** ₱1,234.50 */
function peso($amount): string
{
    return '₱' . number_format((float) ($amount ?? 0), 2);
}

function to_datetime($value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    if ($value instanceof DateTimeImmutable) {
        return $value;
    }
    try {
        return new DateTimeImmutable((string) $value, new DateTimeZone('Asia/Manila'));
    } catch (Exception) {
        return null;
    }
}

/** Sep 20, 2026 */
function fmt_date($value): string
{
    $d = to_datetime($value);
    return $d ? $d->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y') : '';
}

/** 9:00 AM (accepts a time like 09:00:00 or a timestamp) */
function fmt_time($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', (string) $value)) {
        $value = '2000-01-01 ' . $value;
    }
    $d = to_datetime($value);
    return $d ? $d->setTimezone(new DateTimeZone('Asia/Manila'))->format('g:i A') : '';
}

/** Sep 20, 2026, 9:00 AM */
function fmt_datetime($value): string
{
    $d = to_datetime($value);
    return $d ? $d->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y, g:i A') : '';
}

/** 10 min ago, 3 h ago, Yesterday, or the date. */
function time_ago($value): string
{
    $d = to_datetime($value);
    if (!$d) {
        return '';
    }
    $seconds = time() - $d->getTimestamp();
    if ($seconds < 60) {
        return 'Just now';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min ago';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . ' h ago';
    }
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $day = $d->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0, 0);
    $days = (int) $day->diff($today)->format('%a');
    if ($days === 1) {
        return 'Yesterday';
    }
    if ($days < 7) {
        return $days . ' days ago';
    }
    return fmt_date($d);
}

/** "1 book", "3 books" */
function plural(int $n, string $one, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

function today(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

/* ---------- request input ---------- */

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string from POST (empty string if missing). */
function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Trimmed string from GET (empty string if missing). */
function get(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Whole number from GET/POST, or null if missing or not a whole number. */
function int_param(string $key, string $source = 'get'): ?int
{
    $raw = $source === 'post' ? post($key) : get($key);
    return preg_match('/^-?\d{1,9}$/', $raw) ? (int) $raw : null;
}

/** Keep text plain and short: strip control characters except new lines and tabs. */
function clean_text(string $text, int $max): string
{
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
    return mb_substr($text, 0, $max);
}

/**
 * Run one of the fn_list_* functions (database/04c_crud.sql) and return [rows, total].
 * Every row carries total_count. The SQL must bind :limit and :offset; when a page past the end comes back empty,
 * the total is read from the first row so the pager can still say how many there are.
 */
function list_rows(string $sql, array $params, int $limit, int $offset): array
{
    $rows = q_all($sql, $params + [':limit' => $limit, ':offset' => $offset]);
    if (!$rows && $offset > 0) {
        $first = q_row($sql, $params + [':limit' => 1, ':offset' => 0]);
        return [[], $first ? (int) $first['total_count'] : 0];
    }
    return [$rows, $rows ? (int) $rows[0]['total_count'] : 0];
}

/** Escape LIKE wildcards in user search text and wrap it for a "contains" match (always used as a bound value). */
function like_contains(string $text): string
{
    return '%' . addcslashes($text, '%_\\') . '%';
}

/** Build a URL for the current page with some query values changed. */
function url_with(array $changes, ?string $path = null): string
{
    $query = array_merge($_GET, $changes);
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    $path ??= strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $path . ($query ? '?' . http_build_query($query) : '');
}

/** Paging: returns [page, offset]. */
function page_offset(int $perPage): array
{
    $page = max(1, int_param('page') ?? 1);
    return [$page, ($page - 1) * $perPage];
}

/* ---------- form field errors (server-rendered forms) ---------- */

/** aria-invalid and aria-describedby for a field, pointing at its hint and its error line. */
function field_attrs(array $errors, string $name, string $hintId = ''): string
{
    $described = trim($hintId . (isset($errors[$name]) ? ' err-' . $name : ''));
    return (isset($errors[$name]) ? ' aria-invalid="true"' : '') . ($described !== '' ? ' aria-describedby="' . h($described) . '"' : '');
}

/** The error line under a field (empty when the field is fine). */
function field_error(array $errors, string $name): string
{
    return isset($errors[$name]) ? '<p class="field-error" id="err-' . h($name) . '">' . h($errors[$name]) . '</p>' : '';
}

/* ---------- JSON responses for api/ ---------- */

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- names ---------- */

function full_name(array $row, string $prefix = ''): string
{
    $first = trim((string) ($row[$prefix . 'first_name'] ?? ''));
    $last = trim((string) ($row[$prefix . 'last_name'] ?? ''));
    return trim($first . ' ' . $last);
}

/** "Ella's" / "Charles'" */
function possessive(string $name): string
{
    return $name . (str_ends_with(strtolower($name), 's') ? "'" : "'s");
}
