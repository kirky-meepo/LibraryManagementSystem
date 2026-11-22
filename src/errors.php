<?php
declare(strict_types=1);

/*
 * Error handling.
 * dev  (APP_ENV=dev): the error page shows the details, so you can fix the problem.
 * prod (APP_ENV=prod): people see a short friendly page; the details go to storage/logs/app-YYYY-MM-DD.log,
 *                      which is outside public/ and never shown in the browser.
 */

/**
 * Write text to today's log in storage/logs, or to stderr when LOG_TO_STDERR=1 or the folder cannot be written
 * (a hosted container has a read-only disk; its platform collects stderr). Callers never pass secrets.
 */
function write_log(string $text): void
{
    if (env('LOG_TO_STDERR', '0') !== '1') {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (@file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $text, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
    }
    @file_put_contents('php://stderr', $text);
}

/** One short line in the log (no secrets: callers pass only codes and plain words). */
function log_line(string $message): void
{
    write_log(sprintf("[%s] %s\n", date('Y-m-d H:i:s'), str_replace(["\r", "\n"], ' ', $message)));
}

function log_error(Throwable $e): string
{
    $id = substr(bin2hex(random_bytes(4)), 0, 8);
    $line = sprintf(
        "[%s] %s %s %s: %s in %s:%d\n%s\n\n",
        date('Y-m-d H:i:s'),
        $id,
        $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        $_SERVER['REQUEST_URI'] ?? '',
        get_class($e) . ' ' . $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    write_log($line);
    return $id;
}

function install_error_handlers(): void
{
    ini_set('display_errors', is_dev() ? '1' : '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $e): void {
        $id = log_error($e);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $e . "\n");
            exit(1);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $isDb = $e instanceof PDOException;
        $friendly = $isDb ? db_message($e) : 'Something went wrong on our side. Please try again.';
        $detail = is_dev() ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString() : null;
        if (headers_sent()) {
            // Part of the page already went out: finish with a short message instead of a broken page.
            echo '<div class="alert alert-error" role="alert"><p>' . h($friendly) . ' Code: ' . h($id) . '.</p></div>';
            if ($detail !== null) {
                echo '<pre class="message-detail">' . h($detail) . '</pre>';
            }
            return;
        }
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => $friendly . (is_dev() ? ' [' . $e->getMessage() . ']' : ''), 'ref' => $id], 500);
        }
        http_response_code(500);
        render_message_page(
            $isDb ? 'The library database had a problem' : 'Something went wrong',
            $friendly . ' If it keeps happening, tell the admin this code: ' . $id . '.',
            ['href' => '/', 'label' => 'Go to my home page'],
            '500',
            $detail
        );
    });
}

/**
 * A full page with one message and one button: used for 403, expired forms and errors.
 * It never touches the database, so it works even when PostgreSQL is down.
 */
function render_message_page(string $title, string $text, ?array $action = null, ?string $code = null, ?string $detail = null): never
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $pageTitle = $title;
    require APP_SRC . '/partials/message_page.php';
    exit;
}
