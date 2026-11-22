<?php
declare(strict_types=1);

/*
 * Sign-in, sessions and access control.
 * Roles: admin and librarian (from staff.position), student, teacher.
 * Every page starts with require_role([...]) (or require_login() for pages any signed-in person may open).
 */

const HOME_BY_ROLE = [
    'admin' => '/librarian/dashboard.php',
    'librarian' => '/librarian/dashboard.php',
    'student' => '/member/home.php',
    'teacher' => '/member/home.php',
];
const STAFF_ROLES = ['admin', 'librarian'];
const MEMBER_ROLES = ['student', 'teacher'];

function is_api_request(): bool
{
    return str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/');
}

/* ---------------------------------------------------------------------------
 * Sessions
 * ------------------------------------------------------------------------- */

function idle_minutes(): int
{
    $value = (int) setting('session_idle_minutes', '30');
    return $value >= 5 && $value <= 480 ? $value : 30;
}

/** HTTPS directly, or behind a proxy that says so (Vercel ends HTTPS and forwards X-Forwarded-Proto: https). */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $proto === 'https';
}

function session_boot(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // SESSION_DRIVER=db keeps sessions in PostgreSQL (hosted); files is the default on this PC.
    if (env('SESSION_DRIVER', 'files') === 'db') {
        require_once APP_SRC . '/session.php';
        ini_set('session.gc_maxlifetime', '28800');   // the longest idle setting (480 minutes)
        session_set_save_handler(new DbSessionHandler(), true);
    }
    session_name('lms_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['auth'])) {
        $last = (int) ($_SESSION['last_seen'] ?? 0);
        try {
            $limit = idle_minutes() * 60;
        } catch (Throwable) {
            $limit = 1800;
        }
        if ($last > 0 && time() - $last > $limit) {
            logout_session();
            flash('info', 'You were signed out after ' . intdiv($limit, 60) . ' minutes without activity. Sign in again to continue.');
        }
    }
    $_SESSION['last_seen'] = time();
    presence_touch();
}

/** Tell the database the signed-in person is still here, at most every 2 minutes (Activity column, Manage Users). */
function presence_touch(): void
{
    $auth = $_SESSION['auth'] ?? null;
    if (!is_array($auth) || time() - (int) ($_SESSION['presence_at'] ?? 0) < 120) {
        return;
    }
    $_SESSION['presence_at'] = time();
    try {
        q('SELECT fn_presence_touch(:t, :id)', [':t' => (string) $auth['type'], ':id' => (int) $auth['id']]);
    } catch (Throwable) {
        // The label is a convenience; a page must never fail because of it.
    }
}

/** Destroy the session completely, then start a fresh empty one (so a flash message can still be shown). */
function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
}

/* ---------------------------------------------------------------------------
 * The signed-in person
 * ------------------------------------------------------------------------- */

/**
 * The signed-in person, re-read from the database on every request so a deactivated account
 * stops working at once. Keys: type (staff|student|teacher), id, role, first_name, last_name, name, email,
 * must_change_password, is_staff.
 */
function current_user(): ?array
{
    static $cache = [];
    $auth = $_SESSION['auth'] ?? null;
    if (!is_array($auth) || !isset($auth['type'], $auth['id'])) {
        return null;
    }
    $key = $auth['type'] . ':' . $auth['id'];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $params = [':id' => (int) $auth['id']];
    // fn_get_staff / fn_get_student / fn_get_teacher (04c_crud.sql): never a password hash.
    $row = match ($auth['type']) {
        'staff' => q_row('SELECT staff_id AS id, first_name, last_name, email, position AS role, contact_number, is_active, must_change_password
                            FROM fn_get_staff(:id)', $params),
        'student' => q_row("SELECT student_id AS id, first_name, last_name, email, 'student' AS role, contact_number, is_active, must_change_password
                              FROM fn_get_student(:id)", $params),
        'teacher' => q_row("SELECT teacher_id AS id, first_name, last_name, email, 'teacher' AS role, contact_number, is_active, must_change_password
                              FROM fn_get_teacher(:id)", $params),
        default => null,
    };
    if (!$row || !$row['is_active']) {
        logout_session();
        flash('error', 'Your account is not active any more. Ask the library desk for help.');
        return $cache[$key] = null;
    }
    $row['type'] = $auth['type'];
    $row['id'] = (int) $row['id'];
    $row['name'] = full_name($row);
    $row['is_staff'] = $auth['type'] === 'staff';
    $row['must_change_password'] = (bool) $row['must_change_password'];
    return $cache[$key] = $row;
}

function is_admin(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && $user['role'] === 'admin';
}

function home_for(array $user): string
{
    return HOME_BY_ROLE[$user['role']] ?? '/login.php';
}

/** Run $fn in one database transaction as the signed-in person (for the audit trail). */
function tx(callable $fn)
{
    $user = current_user();
    return db_tx($fn, $user ? $user['type'] : 'system', $user ? $user['id'] : null);
}

/* ---------------------------------------------------------------------------
 * Gates
 * ------------------------------------------------------------------------- */

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'You are signed out. Sign in again.'], 401);
        }
        $next = current_url();
        redirect('/login.php' . ($next !== '/' && !str_starts_with($next, '/login.php') ? '?next=' . rawurlencode($next) : ''));
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($user['must_change_password'] && !in_array($script, ['change_password.php', 'logout.php'], true)) {
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'Change your password first.'], 403);
        }
        redirect('/change_password.php');
    }
    return $user;
}

/** Allow only these roles. Anyone else gets the 403 page. */
function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        forbidden($user);
    }
    return $user;
}

function forbidden(?array $user = null): never
{
    http_response_code(403);
    if (is_api_request()) {
        json_out(['ok' => false, 'error' => 'Your account cannot do this.'], 403);
    }
    $user ??= current_user();
    render_message_page(
        'This page is not for your account',
        $user && !$user['is_staff']
            ? 'This part of the site is for library staff. Your own requests, loans and messages are on your home page.'
            : 'This page is only for admins. Ask an admin if you need something changed here.',
        ['href' => $user ? home_for($user) : '/login.php', 'label' => $user ? 'Go to my home page' : 'Sign in'],
        '403'
    );
}

/* ---------------------------------------------------------------------------
 * Signing in and out (the rules live in database/04b_auth.sql; PHP only hashes and verifies)
 * ------------------------------------------------------------------------- */

/**
 * The visitor's IP address, for the sign-in lock-out and the sign-up and reset limits.
 * Behind a proxy (TRUST_PROXY=1) the platform's header counts: Vercel sets X-Real-IP and X-Forwarded-For to the
 * client's public address and overwrites what the client sent. Otherwise only REMOTE_ADDR, which cannot be faked.
 */
function client_ip(): ?string
{
    if (env('TRUST_PROXY', '0') === '1') {
        $candidates = [(string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''), explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/** One sign-in try, written by fn_record_login (a success also writes the audit row 'login'). */
function record_login(string $email, string $kind, bool $success, ?string $ip, ?string $type = null, ?int $id = null): void
{
    q('SELECT fn_record_login(:e, CAST(:ip AS inet), :k, :s, :t, :id)', [
        ':e' => mb_substr($email, 0, 254), ':ip' => $ip, ':k' => $kind, ':s' => $success ? 'true' : 'false',
        ':t' => $type, ':id' => $id,
    ]);
}

/** The account behind an email, through fn_login_lookup (null fields when unknown, no account data when locked). */
function login_lookup(string $kind, string $email, ?string $ip): array
{
    return q_row('SELECT * FROM fn_login_lookup(:e, CAST(:ip AS inet), :k)', [':e' => $email, ':ip' => $ip, ':k' => $kind]);
}

/**
 * Try to sign in. $kind is member or staff.
 * Returns [true, ''] or [false, message, state] where state is wrong, locked or inactive.
 * Every wrong email or password gets the same message, so nobody can find out which emails exist.
 */
function login(string $kind, string $email, string $password): array
{
    $kind = $kind === 'staff' ? 'staff' : 'member';
    $email = mb_substr(mb_strtolower(trim($email)), 0, 254);
    $ip = client_ip();

    $found = login_lookup($kind, $email, $ip);
    $minutes = (int) $found['lock_minutes'];
    if ($found['locked']) {
        record_login($email, $kind, false, $ip);
        return [false, "Too many failed sign-ins. Sign-in is locked for $minutes minutes. Wait, then try again.", 'locked'];
    }

    // Always run password_verify, so the response time does not reveal whether the email exists.
    static $dummy = null;
    $dummy ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $hash = $found['password_hash'] ?? $dummy;
    $ok = password_verify($password, $hash) && $found['user_id'] !== null;

    if (!$ok) {
        record_login($email, $kind, false, $ip);
        $left = (int) $found['tries_left'] - 1;
        return [false, $left <= 0
            ? "Wrong email or password. Sign-in is now locked for $minutes minutes."
            : 'Wrong email or password.', $left <= 0 ? 'locked' : 'wrong'];
    }
    if (!$found['is_active']) {
        record_login($email, $kind, false, $ip);
        return [false, 'This account is inactive. Visit the library desk to reactivate it.', 'inactive'];
    }

    $type = (string) $found['user_type'];
    $id = (int) $found['user_id'];
    record_login($email, $kind, true, $ip, $type, $id);
    session_regenerate_id(true);
    csrf_rotate();
    $_SESSION['auth'] = ['type' => $type, 'id' => $id];
    $_SESSION['last_seen'] = time();
    $_SESSION['presence_at'] = time();

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        set_password($type, $id, $password, (bool) $found['must_change_password']);
    }
    return [true, '', 'ok'];
}

/** Sign out: the audit row 'logout' first (fn_logout), then the session is destroyed. */
function logout(): void
{
    $auth = $_SESSION['auth'] ?? null;
    if (is_array($auth) && isset($auth['type'], $auth['id'])) {
        try {
            q('SELECT fn_logout(:t, :id)', [':t' => (string) $auth['type'], ':id' => (int) $auth['id']]);
        } catch (Throwable $e) {
            log_error($e);   // signing out must work even when the database does not
        }
    }
    logout_session();
}

/** Your own new password (fn_set_password checks that the account is the one acting). Only the hash is sent. */
function set_password(string $type, int $id, string $password, bool $mustChange): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db_tx(function (PDO $pdo) use ($type, $id, $hash, $mustChange) {
        $pdo->prepare('SELECT fn_set_password(:t, :id, :h, :m)')
            ->execute([':t' => $type, ':id' => $id, ':h' => $hash, ':m' => $mustChange ? 'true' : 'false']);
    }, $type, $id);
}

/** Manage Users, Reset password: a temporary password the person must change (fn_staff_reset_password). */
function staff_reset_password(array $staff, string $type, int $id, string $temporary): void
{
    $hash = password_hash($temporary, PASSWORD_DEFAULT);
    db_tx(function (PDO $pdo) use ($staff, $type, $id, $hash) {
        $pdo->prepare('SELECT fn_staff_reset_password(:staff, :t, :id, :h)')
            ->execute([':staff' => $staff['id'], ':t' => $type, ':id' => $id, ':h' => $hash]);
    }, 'staff', $staff['id']);
}

/**
 * Check the signed-in person's current password (Change password, Profile).
 * The hash comes from fn_login_lookup with their own email, so the same lock-out applies.
 */
function check_password(string $type, int $id, string $password): bool
{
    $me = current_user();
    if (!$me || $me['type'] !== $type || $me['id'] !== $id) {
        return false;
    }
    $found = login_lookup($type === 'staff' ? 'staff' : 'member', $me['email'], client_ip());
    if ($found['locked'] || $found['user_type'] !== $type || (int) $found['user_id'] !== $id) {
        return false;
    }
    return is_string($found['password_hash']) && password_verify($password, $found['password_hash']);
}

/** Password rules for new passwords. Returns an error message or null. */
function password_problem(string $new, string $confirm, ?string $current = null): ?string
{
    if (mb_strlen($new) < 8) {
        return 'Use at least 8 characters for the new password.';
    }
    if (mb_strlen($new) > 200) {
        return 'Use at most 200 characters for the new password.';
    }
    if ($new !== $confirm) {
        return 'The two new passwords do not match. Type the same password twice.';
    }
    if ($current !== null && hash_equals($current, $new)) {
        return 'Choose a new password that is different from the current one.';
    }
    return null;
}
