<?php
declare(strict_types=1);

/* Fill a reminder or overdue preset for a loan (GET, read-only). Rendering happens only in fn_render_template. */
require __DIR__ . '/../../src/bootstrap.php';

$me = require_role(['librarian', 'admin']);

$loanId = int_param('transaction_id');
$templateId = int_param('template_id');
$kind = $templateId !== null ? q_val('SELECT kind FROM message_template WHERE template_id = :id AND is_active', [':id' => $templateId]) : null;
if ($loanId === null || !in_array($kind, ['reminder', 'overdue'], true)) {
    json_out(['ok' => false, 'error' => 'Choose a loan and a preset.'], 422);
}

try {
    $out = tx(function (PDO $pdo) use ($templateId, $loanId) {
        $st = $pdo->prepare('SELECT subject, body FROM fn_render_template(:t, NULL, :l)');
        $st->execute([':t' => $templateId, ':l' => $loanId]);
        return $st->fetch();
    });
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => db_message($e)], 422);
}
json_out(['ok' => true, 'subject' => $out['subject'], 'body' => $out['body']]);
