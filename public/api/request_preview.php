<?php
declare(strict_types=1);

/*
 * Fill a message preset for a request with real names and dates (GET, read-only).
 * Rendering happens only in fn_render_template, so the pop-up, the tests and the database always agree.
 * It runs in a transaction as the signed-in librarian, so {librarian_name} is their name.
 */
require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/requests.php';

$me = require_role(['librarian', 'admin']);

$requestId = int_param('request_id');
$templateId = int_param('template_id');
$date = get('pickup_date');
$time = substr(get('pickup_time'), 0, 5);

$template = $templateId !== null ? q_row('SELECT kind FROM message_template WHERE template_id = :id AND is_active', [':id' => $templateId]) : null;
if ($requestId === null || !$template || !in_array($template['kind'], ['request_approved', 'request_rejected'], true)) {
    json_out(['ok' => false, 'error' => 'Choose a request and a message preset.'], 422);
}

// A date or time that is not filled in yet stays visible as {pickup_date} / {pickup_time}.
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $date) ? $date : null;
$time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : null;

try {
    $out = tx(function (PDO $pdo) use ($templateId, $requestId, $date, $time) {
        $st = $pdo->prepare('SELECT subject, body FROM fn_render_template(:t, :r, NULL, CAST(:d AS date), CAST(:tm AS time))');
        $st->execute([':t' => $templateId, ':r' => $requestId, ':d' => $date, ':tm' => $time]);
        return $st->fetch();
    });
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => db_message($e)], 422);
}

json_out(['ok' => true, 'subject' => $out['subject'], 'body' => $out['body']]);
