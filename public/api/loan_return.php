<?php
declare(strict_types=1);

/*
 * Mark a loan returned (good or damaged) or lost. fn_return_book works out every fine: days late times the
 * daily fine, the damage amount, or the replacement cost for a lost copy. This page only reports what it created.
 */
require __DIR__ . '/../../src/bootstrap.php';

$me = require_role(['librarian', 'admin']);
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Use the Mark returned button.'], 405);
}

$loanId = int_param('transaction_id', 'post');
$condition = post('condition');
$loan = $loanId !== null ? q_row('SELECT transaction_id, status, borrower_first_name, book_title FROM v_loan_detail WHERE transaction_id = :id', [':id' => $loanId]) : null;
if (!$loan) {
    json_out(['ok' => false, 'error' => 'That loan no longer exists. Reload the page.'], 404);
}
if (!in_array($condition, ['good', 'damaged', 'lost'], true)) {
    json_out(['ok' => false, 'error' => 'Choose the condition: Good, Damaged or Lost.'], 422);
}
$amount = null;
if ($condition === 'damaged') {
    $raw = str_replace([',', '₱', ' '], '', post('damage_amount'));
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $raw)) {
        json_out(['ok' => false, 'error' => 'Enter the damage amount in pesos, for example 300 or 300.50.',
                  'errors' => ['damage_amount' => 'Enter the damage amount in pesos, for example 300 or 300.50.']], 422);
    }
    $amount = $raw;
}

try {
    $fines = tx(function (PDO $pdo) use ($loanId, $condition, $amount, $me) {
        $st = $pdo->prepare('SELECT fn_return_book(:t, :s, :c, CAST(:a AS numeric))');
        $st->execute([':t' => $loanId, ':s' => $me['id'], ':c' => $condition, ':a' => $amount]);
        $f = $pdo->prepare("SELECT fine_type, amount FROM v_fine_detail WHERE transaction_id = :t AND status = 'unpaid' ORDER BY fine_id");
        $f->execute([':t' => $loanId]);
        return $f->fetchAll();
    });
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => db_message($e)], 422);
}

$lines = array_map(fn($f) => ucfirst($f['fine_type']) . ' fine: ' . peso($f['amount']), $fines);
$headline = match ($condition) {
    'lost' => $loan['book_title'] . ' is marked lost. One copy is removed from the total.',
    'damaged' => $loan['book_title'] . ' is back on the shelf, marked damaged.',
    default => $loan['book_title'] . ' is back on the shelf.',
};
$headline .= $lines ? '' : ' No fine.';
flash('success', $headline . ($lines ? ' ' . implode('. ', $lines) . '.' : ''));
json_out(['ok' => true, 'message' => $headline, 'fines' => $lines]);
