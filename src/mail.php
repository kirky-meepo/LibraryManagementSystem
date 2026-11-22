<?php
declare(strict_types=1);

/*
 * Email, plain PHP with curl (no packages). The driver comes from MAIL_DRIVER in .env:
 *   outbox (default)  each message becomes a text file in storage/outbox/ (outside public/, never committed)
 *   brevo             Brevo's transactional email API (https://api.brevo.com/v3/smtp/email)
 * If Brevo fails, the message still goes to the outbox and only the HTTP status is logged:
 * never the API key, the token in a link, or the address.
 */

const BREVO_SEND_URL = 'https://api.brevo.com/v3/smtp/email';
const BREVO_EVENTS_URL = 'https://api.brevo.com/v3/smtp/statistics/events';

function mail_driver(): string
{
    return strtolower(trim((string) env('MAIL_DRIVER', 'outbox'))) === 'brevo' ? 'brevo' : 'outbox';
}

/** Base address of the site for links in emails. Never taken from the request's Host header. */
function app_url(): string
{
    $url = rtrim(trim((string) env('APP_URL', '')), '/');
    return preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', $url) ? $url : 'http://localhost:8000';
}

/**
 * Send one plain-text email.
 * Returns ['ok' => bool, 'driver' => outbox|brevo, 'message_id' => ?string, 'file' => ?string (outbox file name),
 *          'status' => ?int (Brevo HTTP status), 'fallback' => bool].
 */
function send_mail(string $to, string $subject, string $text): array
{
    $to = trim($to);
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'driver' => mail_driver(), 'message_id' => null, 'file' => null, 'status' => null, 'fallback' => false];
    }

    if (mail_driver() === 'brevo') {
        $sent = brevo_send($to, $subject, $text);
        if ($sent['ok']) {
            return ['ok' => true, 'driver' => 'brevo', 'message_id' => $sent['message_id'], 'file' => null, 'status' => $sent['status'], 'fallback' => false];
        }
        log_line('mail: Brevo did not accept a message (' . ($sent['status'] ? 'HTTP ' . $sent['status'] : $sent['error_kind']) . '); it was written to storage/outbox instead.');
        $file = outbox_write($to, $subject, $text);
        return ['ok' => false, 'driver' => 'outbox', 'message_id' => null, 'file' => $file, 'status' => $sent['status'], 'fallback' => true];
    }

    $file = outbox_write($to, $subject, $text);
    return ['ok' => $file !== null, 'driver' => 'outbox', 'message_id' => null, 'file' => $file, 'status' => null, 'fallback' => false];
}

/** Write the message to storage/outbox/<timestamp>-<random>.txt. Returns the file name, or null. */
function outbox_write(string $to, string $subject, string $text): ?string
{
    $dir = APP_ROOT . '/storage/outbox';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        log_line('mail: storage/outbox could not be created.');
        return null;
    }
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';
    $from = trim((string) env('MAIL_FROM_NAME', 'Library')) . ' <' . (trim((string) env('MAIL_FROM', '')) ?: 'library@localhost') . '>';
    $content = "To: $to\nFrom: $from\nSubject: $subject\nDate: " . date('M j, Y g:i A') . "\n\n" . $text . "\n";
    if (@file_put_contents($dir . '/' . $name, $content, LOCK_EX) === false) {
        log_line('mail: a message could not be written to storage/outbox.');
        return null;
    }
    return $name;
}

/**
 * Send through Brevo. Certificate checks stay on. Returns
 * ['ok' => bool, 'status' => ?int, 'message_id' => ?string, 'error_kind' => string, 'error' => string].
 * 'error' is Brevo's own short code and message, safe to show on the command line; it never holds the key.
 */
function brevo_send(string $to, string $subject, string $text): array
{
    $key = trim((string) env('BREVO_API_KEY', ''));
    $from = trim((string) env('MAIL_FROM', ''));
    if ($key === '' || $from === '') {
        return ['ok' => false, 'status' => null, 'message_id' => null, 'error_kind' => 'not configured',
                'error' => 'BREVO_API_KEY or MAIL_FROM is empty in .env.'];
    }
    $payload = json_encode([
        'sender' => ['name' => trim((string) env('MAIL_FROM_NAME', 'Library')) ?: 'Library', 'email' => $from],
        'to' => [['email' => $to]],
        'subject' => $subject,
        'textContent' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    [$status, $body, $curlError] = brevo_request('POST', BREVO_SEND_URL, $key, $payload);
    if ($status === 201) {
        $data = json_decode((string) $body, true);
        return ['ok' => true, 'status' => 201, 'message_id' => is_array($data) ? ($data['messageId'] ?? null) : null,
                'error_kind' => '', 'error' => ''];
    }
    return ['ok' => false, 'status' => $status ?: null, 'message_id' => null,
            'error_kind' => $status ? 'http' : 'network', 'error' => brevo_error_text($status, $body, $curlError, $key)];
}

/**
 * One HTTPS request to Brevo with the api-key header. Returns [status (0 when no answer), body, curl error].
 * On Windows, PHP's curl often has no CA bundle: then the Windows certificate store is used (CURLSSLOPT_NATIVE_CA).
 * Verification is never switched off.
 */
function brevo_request(string $method, string $url, string $key, ?string $json = null): array
{
    if (!function_exists('curl_init')) {
        return [0, '', 'the curl extension is not enabled in php.ini'];
    }
    $ch = curl_init($url);
    $headers = ['api-key: ' . $key, 'accept: application/json'];
    if ($json !== null) {
        $headers[] = 'content-type: application/json';
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($json !== null) {
        $options[CURLOPT_POSTFIELDS] = $json;
    }
    if (PHP_OS_FAMILY === 'Windows' && trim((string) ini_get('curl.cainfo')) === '' && defined('CURLSSLOPT_NATIVE_CA')) {
        $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $body === false ? curl_error($ch) : '';
    unset($ch);   // the handle closes itself (curl_close() is deprecated from PHP 8.5)
    return [$status, $body === false ? '' : (string) $body, $error];
}

/** Brevo's short error code and message (or the network problem), with the key removed just in case. */
function brevo_error_text(int $status, string $body, string $curlError, string $key): string
{
    if ($status === 0) {
        $text = 'no answer from Brevo: ' . $curlError;
        if (stripos($curlError, 'certificate') !== false || stripos($curlError, 'SSL') !== false) {
            $text .= ' (point curl.cainfo and openssl.cafile in php.ini to a CA bundle; see README, Real password-reset email)';
        }
    } else {
        $data = json_decode($body, true);
        $text = 'HTTP ' . $status;
        if (is_array($data)) {
            $text .= ': ' . trim(($data['code'] ?? '') . ' ' . ($data['message'] ?? ''));
        }
    }
    $text = mb_substr(str_replace(["\r", "\n"], ' ', $text), 0, 300);
    return $key !== '' ? str_replace($key, '***', $text) : $text;
}
