<?php
declare(strict_types=1);

/*
 * What happened to an email sent through Brevo (last day):
 *     php database/tools/mail_status.php "<messageId from test_mail.php>"
 *     php database/tools/mail_status.php you@example.com
 * Prints each event (requests, delivered, deferred, soft or hard bounce, blocked, spam ...) with its time and reason.
 * Never prints the API key.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/errors.php';
require __DIR__ . '/../../src/mail.php';

$what = trim((string) ($argv[1] ?? ''));
if ($what === '') {
    fwrite(STDERR, "Give a messageId (from test_mail.php) or an email address.\n");
    exit(2);
}
$key = trim((string) env('BREVO_API_KEY', ''));
if ($key === '') {
    fwrite(STDERR, "BREVO_API_KEY is empty in .env.\n");
    exit(1);
}

$query = ['days' => 1, 'sort' => 'desc', 'limit' => 50];
if (filter_var($what, FILTER_VALIDATE_EMAIL)) {
    $query['email'] = $what;
} else {
    $query['messageId'] = $what;
}
[$status, $body, $curlError] = brevo_request('GET', BREVO_EVENTS_URL . '?' . http_build_query($query), $key);

if ($status === 401 || $status === 403) {
    echo "HTTP $status: this API key cannot read transactional email logs.\n";
    echo "In Brevo, SMTP & API, API keys, give the key Transactional email = All (or check the email in Brevo's Transactional, Logs page).\n";
    exit(1);
}
if ($status !== 200) {
    echo 'FAILED  ' . brevo_error_text($status, $body, $curlError, $key) . "\n";
    exit(1);
}

$data = json_decode($body, true);
$events = is_array($data) ? ($data['events'] ?? []) : [];
if (!$events) {
    echo "No events yet. Brevo can take a minute to log them; try again shortly.\n";
    exit(0);
}
foreach ($events as $event) {
    $time = isset($event['date']) ? date('M j, Y g:i:s A', strtotime((string) $event['date'])) : '';
    $line = sprintf('%-12s %s', (string) ($event['event'] ?? '?'), $time);
    if (!empty($event['reason'])) {
        $line .= '  reason: ' . str_replace($key, '***', (string) $event['reason']);
    }
    echo $line . "\n";
}
exit(0);
