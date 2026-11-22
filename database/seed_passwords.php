<?php
declare(strict_types=1);

/*
 * Gives the office admin (OFFICE_ADMIN_EMAIL, made by 06_seed.sql) the password in OFFICE_ADMIN_PASSWORD (.env).
 * The reset script runs this right after the seed files. You can also run it by hand:
 *     php database/seed_passwords.php             this PC, as the superuser in .env (PGUSER/PGPASSWORD)
 *     php database/seed_passwords.php --hosted    the hosted database, as the admin in DATABASE_ADMIN_URL
 *
 * Only accounts that still have the seed placeholder '!' are changed, so a password set later is never overwritten.
 * No password or hash is written to any committed file or printed. Filling in the first password is not an event
 * anyone did in the library, so the audit trigger is told to skip it (lms.audit_quiet, which needs no superuser).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/config.php';

$hosted = in_array('--hosted', $argv, true);
$password = (string) env('OFFICE_ADMIN_PASSWORD', '');
if (strlen($password) < 8 || str_contains(strtolower($password), 'change-me')) {
    fwrite(STDERR, "Set OFFICE_ADMIN_PASSWORD in .env to at least 8 characters (not change-me), then run this again.\n");
    exit(1);
}
// A public site needs more: anyone can try the admin's email on its sign-in page.
if (($hosted || app_env() === 'prod') && strlen($password) < 14) {
    fwrite(STDERR, "For a public site OFFICE_ADMIN_PASSWORD must have at least 14 characters.\n");
    exit(1);
}

if ($hosted) {
    $url = parse_url((string) env('DATABASE_ADMIN_URL', ''));
    if (!$url || empty($url['host']) || empty($url['path'])) {
        fwrite(STDERR, "Set DATABASE_ADMIN_URL (the hosted database admin connection string).\n");
        exit(1);
    }
    parse_str($url['query'] ?? '', $query);
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $url['host'], $url['port'] ?? 5432,
        rawurldecode(ltrim($url['path'], '/')), $query['sslmode'] ?? 'require');
    [$user, $pass] = [rawurldecode($url['user'] ?? ''), rawurldecode($url['pass'] ?? '')];
} else {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', 'localhost'), env('DB_PORT', '5432'), env('DB_NAME', 'lms'));
    [$user, $pass] = [env('PGUSER', 'postgres'), env('PGPASSWORD', '')];
}
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, $hosted ? "Could not connect with DATABASE_ADMIN_URL.\n" : "Could not connect as the superuser. Check PGUSER and PGPASSWORD in .env.\n");
    exit(1);
}

$pdo->beginTransaction();
$pdo->exec("SET LOCAL lms.audit_quiet = 'password'");
$st = $pdo->prepare("UPDATE lms.staff SET password_hash = :hash, must_change_password = false WHERE password_hash = '!'");
$st->execute([':hash' => password_hash($password, PASSWORD_DEFAULT)]);
$pdo->commit();

echo $st->rowCount() ? "  office admin password set\n" : "  office admin password already set\n";
