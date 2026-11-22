<?php
declare(strict_types=1);

/*
 * Every page in public/ starts with:  require __DIR__ . '/../src/bootstrap.php';
 * (or ../../src/bootstrap.php one folder down). It loads the shared code, sends the security headers,
 * starts the session and refuses any POST without a valid CSRF token.
 */

// Buffer the page, so an error halfway through is replaced by a clean error page instead of half a page.
if (PHP_SAPI !== 'cli') {
    ob_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/errors.php';

install_error_handlers();

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');   // do not announce the PHP version
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

session_boot();
csrf_check();
