<?php
declare(strict_types=1);

/*
 * Approve, reject or cancel a request. One database transaction each: the status change, the reserved copy
 * and the message to the borrower happen together or not at all (fn_approve_request, fn_reject_request,
 * fn_cancel_request). POST only; CSRF checked by bootstrap.
 */
require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/requests.php';

$me = require_role(['librarian', 'admin']);
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Use the buttons in the pop-up.'], 405);
}

$action = post('action');
$requestId = int_param('request_id', 'post');
$req = $requestId !== null ? q_row('SELECT request_id, status, borrower_first_name, borrower_name, book_title FROM v_request_detail WHERE request_id = :id', [':id' => $requestId]) : null;
if (!$req || !in_array($action, ['approve', 'reject', 'cancel'], true)) {
    json_out(['ok' => false, 'error' => 'That request no longer exists. Reload the page.'], 404);
}

$errors = [];
$subject = clean_text(post('subject'), 151);
$body = clean_text(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')), 1001);
if ($action !== 'cancel') {
    if (trim($subject) === '') {
        $errors['subject'] = 'Write a subject.';
    } elseif (mb_strlen($subject) > 150) {
        $errors['subject'] = 'The subject can be at most 150 characters.';
    }
    if (trim($body) === '') {
        $errors['body'] = 'Write the message, or choose a preset.';
    } elseif (mb_strlen($body) > 1000) {
        $errors['body'] = 'The message can be at most 1,000 characters.';
    }
}

if ($action === 'approve') {
    [$pickupErrors, $date, $time] = validate_pickup(post('pickup_date'), post('pickup_time'));
    $errors += $pickupErrors;
} elseif ($action === 'reject') {
    $reason = clean_text(post('reason'), 201);
    if (trim($reason) === '') {
        $errors['reason'] = 'Write the reason. The borrower sees it on My Requests.';
    } elseif (mb_strlen($reason) > 200) {
        $errors['reason'] = 'The reason can be at most 200 characters.';
    }
} else {
    $note = clean_text(post('note'), 301);
    if (mb_strlen($note) > 300) {
        $errors['note'] = 'The note can be at most 300 characters.';
    }
}
if ($errors) {
    json_out(['ok' => false, 'error' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);
}

try {
    tx(function (PDO $pdo) use ($action, $requestId, $me, $subject, $body, &$date, &$time, &$reason, &$note) {
        if ($action === 'approve') {
            $st = $pdo->prepare('SELECT fn_approve_request(:r, :s, CAST(:d AS date), CAST(:t AS time), :subj, :body)');
            $st->execute([':r' => $requestId, ':s' => $me['id'], ':d' => $date, ':t' => $time, ':subj' => $subject, ':body' => $body]);
        } elseif ($action === 'reject') {
            $st = $pdo->prepare('SELECT fn_reject_request(:r, :s, :reason, :subj, :body)');
            $st->execute([':r' => $requestId, ':s' => $me['id'], ':reason' => trim($reason), ':subj' => $subject, ':body' => $body]);
        } else {
            $st = $pdo->prepare("SELECT fn_cancel_request(:r, 'staff', :s, :note)");
            $st->execute([':r' => $requestId, ':s' => $me['id'], ':note' => trim($note) === '' ? null : trim($note)]);
        }
    });
} catch (PDOException $e) {
    $out = ['ok' => false, 'error' => db_message($e)];
    if (db_rule_code($e) === 'LM003') {
        // No copy is free: offer the matching rejection preset instead.
        $out['suggest'] = 'reject';
        $out['template_id'] = (int) q_val("SELECT template_id FROM message_template WHERE kind = 'request_rejected' AND label = 'All copies on loan'");
    }
    json_out($out, 422);
}

$first = $req['borrower_first_name'];
flash('success', match ($action) {
    'approve' => "Approved. Message sent to $first.",
    'reject' => "Rejected. Message sent to $first.",
    'cancel' => "Reservation cancelled. The copy is back on the shelf and $first got a message.",
});
json_out(['ok' => true]);
