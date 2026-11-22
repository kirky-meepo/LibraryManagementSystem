<?php
declare(strict_types=1);

/* Send a reminder or overdue notice about one loan (fn_send_message). POST only; CSRF checked by bootstrap. */
require __DIR__ . '/../../src/bootstrap.php';

$me = require_role(['librarian', 'admin']);
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Use the Send message button.'], 405);
}

$loanId = int_param('transaction_id', 'post');
$loan = $loanId !== null ? q_row('SELECT student_id, teacher_id, display_status, borrower_first_name FROM v_loan_detail WHERE transaction_id = :id', [':id' => $loanId]) : null;
if (!$loan) {
    json_out(['ok' => false, 'error' => 'That loan no longer exists. Reload the page.'], 404);
}

$templateId = int_param('template_id', 'post');
$kind = $templateId !== null ? q_val('SELECT kind FROM message_template WHERE template_id = :id', [':id' => $templateId]) : null;
if (!in_array($kind, ['reminder', 'overdue'], true)) {
    $kind = $loan['display_status'] === 'overdue' ? 'overdue' : 'reminder';   // "Write my own"
}

$subject = clean_text(post('subject'), 151);
$body = clean_text(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')), 1001);
$errors = [];
if (trim($subject) === '' || mb_strlen($subject) > 150) {
    $errors['subject'] = 'Write a subject of up to 150 characters.';
}
if (trim($body) === '' || mb_strlen($body) > 1000) {
    $errors['body'] = 'Write the message, up to 1,000 characters.';
}
if ($errors) {
    json_out(['ok' => false, 'error' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);
}

try {
    tx(function (PDO $pdo) use ($me, $loan, $kind, $subject, $body) {
        $st = $pdo->prepare('SELECT fn_send_message(:s, :sid, :tid, :k, :subj, :body)');
        $st->execute([':s' => $me['id'], ':sid' => $loan['student_id'], ':tid' => $loan['teacher_id'], ':k' => $kind, ':subj' => $subject, ':body' => $body]);
    });
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => db_message($e)], 422);
}

flash('success', ($kind === 'overdue' ? 'Overdue notice' : 'Reminder') . ' sent to ' . $loan['borrower_first_name'] . '.');
json_out(['ok' => true]);
