<?php
declare(strict_types=1);

/*
 * Send ONE plain test email through Brevo, whatever MAIL_DRIVER says:
 *     php database/tools/test_mail.php you@example.com
 * Prints OK with the HTTP status and Brevo's messageId, or a short error. Never prints the API key.
 * A 201 means Brevo accepted the message, not that it reached the inbox: check it with
 *     php database/tools/mail_status.php <messageId>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/errors.php';
require __DIR__ . '/../../src/mail.php';

$to = trim((string) ($argv[1] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Give the address to send to, for example: php database/tools/test_mail.php you@example.com\n");
    exit(2);
}
if (trim((string) env('BREVO_API_KEY', '')) === '' || trim((string) env('MAIL_FROM', '')) === '') {
    fwrite(STDERR, "BREVO_API_KEY or MAIL_FROM is empty in .env. Fill both in first (README, \"Real password-reset email\").\n");
    exit(1);
}

$sent = brevo_send(
    $to,
    'Library test email',
    "This is a test email from the library system.\n\n"
    . "If you can read this, Brevo accepted and delivered it. You can now set MAIL_DRIVER=brevo in .env.\n\n"
    . 'Sent ' . date('M j, Y g:i A') . ' (Asia/Manila).'
);

if ($sent['ok']) {
    echo 'OK  HTTP ' . $sent['status'] . '  messageId ' . ($sent['message_id'] ?? '(none in the answer)') . "\n";
    echo "Brevo accepted the message. That is not yet delivery: check the inbox (and Junk), or run mail_status.php with the messageId.\n";
    exit(0);
}
echo 'FAILED  ' . $sent['error'] . "\n";
if ($sent['status'] === 401) {
    echo "The key was refused. Check BREVO_API_KEY in .env (Brevo, SMTP & API, API keys).\n";
} elseif ($sent['status'] === 400) {
    echo "Brevo refused the message. A common cause is a MAIL_FROM that is not a verified sender (Brevo, Senders).\n";
}
exit(1);
