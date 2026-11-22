<?php
declare(strict_types=1);

/** One shared PDO connection per request. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // DB_SSLMODE: prefer on this PC, require on a hosted database. DB_EMULATE_PREPARES=1 is for a pooled
    // connection string (PgBouncer in transaction mode keeps no server-side prepared statements).
    $sslmode = strtolower(trim((string) env('DB_SSLMODE', 'prefer')));
    if (!in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
        $sslmode = 'prefer';
    }
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        env('DB_HOST', 'localhost'),
        env('DB_PORT', '5432'),
        env('DB_NAME', 'lms'),
        $sslmode
    );
    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => env('DB_EMULATE_PREPARES', '0') === '1',
    ]);
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");
    $pdo->exec('SET search_path TO lms, public');
    return $pdo;
}

/**
 * Run $fn inside one database transaction.
 * The actor is stored with set_config(..., true) so the audit trigger knows who acted.
 * Any exception rolls everything back and is re-thrown.
 */
function db_tx(callable $fn, string $actorType, ?int $actorId)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT set_config('app.actor_type', :type, true), set_config('app.actor_id', :id, true)");
        $st->execute([':type' => $actorType, ':id' => $actorId === null ? '' : (string) $actorId]);
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Prepared query helper: returns the statement after execute. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_row(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $value = q($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/** One value from library_setting (all settings are read once per request). */
function setting(string $key, ?string $default = null): ?string
{
    static $all = null;
    if ($all === null) {
        try {
            $all = [];
            foreach (q_all('SELECT setting_key, setting_value FROM library_setting') as $row) {
                $all[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable) {
            $all = [];
            return $default;
        }
    }
    return $all[$key] ?? $default;
}

/**
 * Turn a database error into a short sentence a librarian can act on.
 * Rule errors from our routines use SQLSTATE class LM and already read well.
 */
function db_message(Throwable $e): string
{
    if (!$e instanceof PDOException) {
        return 'Something went wrong. Please try again.';
    }
    $state = (string) ($e->errorInfo[0] ?? $e->getCode());
    $raw = (string) ($e->errorInfo[2] ?? $e->getMessage());
    $text = preg_match('/ERROR:\s+(.+?)(?:\n|$)/s', $raw, $m) ? trim($m[1]) : $raw;

    if (str_starts_with($state, 'LM')) {
        return $text;
    }
    $constraint = preg_match('/constraint "([^"]+)"/', $raw, $c) ? $c[1] : '';
    $known = [
        'uq_book_isbn' => 'A book with this ISBN already exists. Check the ISBN or edit the existing book.',
        'uq_staff_email' => 'That email is already used by another staff account.',
        'uq_student_email' => 'That email is already used by another student.',
        'uq_teacher_email' => 'That email is already used by another teacher.',
        'ck_book_copies' => 'Copies available must be between 0 and the total number of copies.',
        'ck_book_within_limit' => 'A title cannot have more copies than its copy limit. An admin can raise the limit.',
        'ck_book_copy_limit' => 'The copy limit must be a whole number from 1 to 999.',
        'uq_request_open_student' => 'This borrower already has an open request for this book.',
        'uq_request_open_teacher' => 'This borrower already has an open request for this book.',
        'ck_student_year_level' => 'Year level must be between 1 and 6.',
        'ck_message_body_length' => 'Messages can be at most 1,000 characters.',
    ];
    if ($constraint !== '' && isset($known[$constraint])) {
        return $known[$constraint];
    }
    return match (substr($state, 0, 2)) {
        '23' => 'That change breaks a library rule. Check the values and try again.',
        '08' => 'The database is not reachable right now. Check that PostgreSQL is running.',
        '42' => 'The database refused this action for your account. Ask an admin.',
        default => 'The database could not save this change. Please try again.',
    };
}

/** True when an exception came from one of our rule checks (SQLSTATE LMxxx). */
function db_rule_code(Throwable $e): ?string
{
    if ($e instanceof PDOException) {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        if (str_starts_with($state, 'LM')) {
            return $state;
        }
    }
    return null;
}
