<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    $version = db()->query('SHOW server_version')->fetchColumn();
    // The exact version helps when setting up; in production it is not shown to strangers.
    echo is_dev() ? "OK\nPostgreSQL " . $version . "\n" : "OK\n";
} catch (Throwable $e) {
    http_response_code(503);
    echo "DATABASE DOWN\n";
    echo is_dev() ? db_message($e) . "\n" . $e->getMessage() . "\n" : "Check that PostgreSQL is running and .env is correct.\n";
}
