<?php
declare(strict_types=1);

/*
 * Settings come from the .env file in the project root (one KEY=value per line).
 * .env holds secrets and is never committed. .env.example shows the keys.
 */

const APP_ROOT = __DIR__ . '/..';
const APP_SRC = __DIR__;

/** Parse .env once and cache the values. Lines starting with # are comments. */
function env_values(): array
{
    static $values = null;
    if ($values !== null) {
        return $values;
    }
    $values = [];
    $file = APP_ROOT . '/.env';
    if (!is_readable($file)) {
        return $values;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && strlen($value) >= 2 && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }
    return $values;
}

/**
 * Read one setting: the real environment first, then .env, then the default.
 * A real environment variable wins, so a test or a host can force a value (for example MAIL_DRIVER=outbox)
 * whatever .env says.
 */
function env(string $key, ?string $default = null): ?string
{
    $real = getenv($key);
    if ($real !== false) {
        return $real;
    }
    $values = env_values();
    return array_key_exists($key, $values) ? $values[$key] : $default;
}

function app_env(): string
{
    return env('APP_ENV', 'prod') === 'dev' ? 'dev' : 'prod';
}

function is_dev(): bool
{
    return app_env() === 'dev';
}

date_default_timezone_set('Asia/Manila');
mb_internal_encoding('UTF-8');
