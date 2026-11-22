<?php
declare(strict_types=1);

/*
 * Save the Edit book pop-up. POST only, CSRF checked by bootstrap, staff only.
 * Catalogue details go through fn_update_book_details, the number of copies through fn_set_copies_total and
 * the limit through fn_set_copy_limit, all in one transaction.
 */
require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/books.php';

$me = require_role(['librarian', 'admin']);
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Send this form with the Save changes button.'], 405);
}

$bookId = int_param('book_id', 'post');
$current = $bookId !== null ? q_row('SELECT book_id, title, copies_total, copy_limit FROM fn_get_book(:id)', [':id' => $bookId]) : null;
if (!$current) {
    json_out(['ok' => false, 'error' => 'That book no longer exists. Reload the page.'], 404);
}

// Only an admin may change the copy limit; for everyone else the current limit applies.
$newLimit = (int) $current['copy_limit'];
$errors = [];
if (is_admin($me) && post('copy_limit') !== '') {
    $raw = post('copy_limit');
    if (!preg_match('/^\d{1,3}$/', $raw) || (int) $raw < 1) {
        $errors['copy_limit'] = 'The copy limit must be a whole number from 1 to 999.';
    } else {
        $newLimit = (int) $raw;
    }
}

[$book, $bookErrors] = validate_book($_POST, $bookId, $newLimit);
$errors += $bookErrors;
if ($errors) {
    json_out(['ok' => false, 'error' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);
}

try {
    tx(function (PDO $pdo) use ($book, $bookId, $current, $me, $newLimit) {
        $st = $pdo->prepare('SELECT fn_update_book_details(:id, :isbn, :title, :author, :category, :cost, :staff)');
        $st->execute([
            ':id' => $bookId, ':isbn' => $book['isbn'], ':title' => $book['title'], ':author' => $book['author'],
            ':category' => $book['category'], ':cost' => $book['replacement_cost'], ':staff' => $me['id'],
        ]);
        $setCopies = function () use ($pdo, $book, $bookId, $current, $me) {
            if ($book['copies_total'] !== (int) $current['copies_total']) {
                $pdo->prepare('SELECT fn_set_copies_total(:id, :total, :staff)')
                    ->execute([':id' => $bookId, ':total' => $book['copies_total'], ':staff' => $me['id']]);
            }
        };
        $setLimit = function () use ($pdo, $bookId, $current, $me, $newLimit) {
            if ($newLimit !== (int) $current['copy_limit']) {
                $pdo->prepare('SELECT fn_set_copy_limit(:id, :limit, :staff)')
                    ->execute([':id' => $bookId, ':limit' => $newLimit, ':staff' => $me['id']]);
            }
        };
        // Raise the limit before adding copies; remove copies before lowering the limit.
        if ($newLimit > (int) $current['copy_limit']) {
            $setLimit();
            $setCopies();
        } else {
            $setCopies();
            $setLimit();
        }
    });
} catch (PDOException $e) {
    $message = db_message($e);
    $field = match (true) {
        str_contains($message, 'cannot go down'), str_contains($message, 'at most') => 'copies_total',
        str_contains($message, 'its limit'), str_contains($message, 'copy limit') => 'copy_limit',
        str_contains($message, 'ISBN') => 'isbn',
        default => null,
    };
    json_out(['ok' => false, 'error' => $message, 'errors' => $field ? [$field => $message] : []], 422);
}

flash('success', $book['title'] . ' is saved.');
json_out(['ok' => true]);
