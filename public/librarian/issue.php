<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/books.php';

$me = require_role(['librarian', 'admin']);

/** Parse "student:3" / "teacher:1" from a form or link. */
function parse_borrower(string $value): ?array
{
    return preg_match('/^(student|teacher):(\d{1,9})$/', $value, $m) ? ['type' => $m[1], 'id' => (int) $m[2]] : null;
}

if (is_post()) {
    $action = post('action');
    $back = preg_match('#^/librarian/[a-z_]+\.php(\?[^\s]*)?$#', post('back')) ? post('back') : '/librarian/issue.php';
    try {
        if ($action === 'issue_request') {
            $requestId = int_param('request_id', 'post');
            $loanId = tx(function (PDO $pdo) use ($requestId, $me) {
                $st = $pdo->prepare('SELECT fn_issue_book(:r, :s)');
                $st->execute([':r' => $requestId, ':s' => $me['id']]);
                return (int) $st->fetchColumn();
            });
        } elseif ($action === 'issue_direct') {
            $who = parse_borrower(post('borrower'));
            $bookId = int_param('book_id', 'post');
            if (!$who || $bookId === null) {
                throw new InvalidArgumentException('Choose a borrower and a book first.');
            }
            $loanId = tx(function (PDO $pdo) use ($who, $bookId, $me) {
                $st = $pdo->prepare('SELECT fn_issue_book_direct(:sid, :tid, :b, :s)');
                $st->execute([
                    ':sid' => $who['type'] === 'student' ? $who['id'] : null,
                    ':tid' => $who['type'] === 'teacher' ? $who['id'] : null,
                    ':b' => $bookId, ':s' => $me['id'],
                ]);
                return (int) $st->fetchColumn();
            });
        } else {
            throw new InvalidArgumentException('Choose how to issue the book.');
        }
        $loan = q_row('SELECT borrower_first_name, book_title, due_date FROM v_loan_detail WHERE transaction_id = :id', [':id' => $loanId]);
        flash('success', 'Issued ' . $loan['book_title'] . ' to ' . $loan['borrower_first_name'] . '. Due ' . fmt_date($loan['due_date']) . '.');
        redirect('/librarian/issued.php?tab=borrowed&loan=' . $loanId);
    } catch (PDOException $e) {
        flash('error', db_message($e));
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$tab = get('tab') === 'walkin' ? 'walkin' : 'request';

// From an approved request
$who = mb_substr(get('who'), 0, 100);
$approved = q_all(
    "SELECT r.*, current_date + CASE r.borrower_type WHEN 'student' THEN fn_setting_int('loan_days_student', 14)
                                                    ELSE fn_setting_int('loan_days_teacher', 14) END AS due_if_today
       FROM v_request_detail r
      WHERE r.status = 'approved'
        AND (:q = '' OR r.borrower_name ILIKE :q1 OR r.borrower_email ILIKE :q2 OR r.book_title ILIKE :q3)
      ORDER BY r.pickup_deadline, r.request_id",
    [':q' => $who, ':q1' => like_contains($who), ':q2' => like_contains($who), ':q3' => like_contains($who)]
);

// Walk-in
$borrower = parse_borrower(get('borrower'));
$person = $borrower ? q_row('SELECT * FROM v_borrower_status WHERE borrower_type = :t AND borrower_id = :id', [':t' => $borrower['type'], ':id' => $borrower['id']]) : null;
$bookId = int_param('book');
$book = $bookId !== null ? q_row('SELECT * FROM v_book_detail WHERE book_id = :id AND NOT is_archived', [':id' => $bookId]) : null;
$bq = mb_substr(get('bq'), 0, 100);
$kq = mb_substr(get('kq'), 0, 100);
$people = (!$person && $bq !== '') ? q_all(
    'SELECT * FROM v_borrower_status WHERE full_name ILIKE :q1 OR email ILIKE :q2 ORDER BY is_active DESC, full_name LIMIT 10',
    [':q1' => like_contains($bq), ':q2' => like_contains($bq)]
) : [];
$books = (!$book && $kq !== '') ? q_all(
    "SELECT * FROM v_book_detail WHERE NOT is_archived AND (title ILIKE :q1 OR author ILIKE :q2 OR replace(coalesce(isbn, ''), '-', '') ILIKE :q3)
      ORDER BY copies_available = 0, title LIMIT 10",
    [':q1' => like_contains($kq), ':q2' => like_contains($kq), ':q3' => like_contains(str_replace('-', '', $kq))]
) : [];

/** What will stop this person borrowing, in words. */
function borrower_blocks(array $p): array
{
    $blocks = [];
    if (!$p['is_active']) {
        $blocks[] = 'The account is inactive.';
    }
    if ((float) $p['unpaid_fines'] > 0) {
        $blocks[] = 'Unpaid fine of ' . peso($p['unpaid_fines']) . '. It must be settled first.';
    }
    if ((int) $p['overdue_loans'] > 0) {
        $blocks[] = plural((int) $p['overdue_loans'], 'overdue book') . ' must be returned first.';
    }
    if ((int) $p['active_loans'] + (int) $p['open_requests'] >= (int) $p['max_loans']) {
        $blocks[] = 'Borrowing limit reached: ' . ((int) $p['active_loans'] + (int) $p['open_requests']) . ' of ' . (int) $p['max_loans'] . ' loans and open requests.';
    }
    return $blocks;
}

function borrower_summary(array $p): string
{
    $html = '<strong>' . h($p['full_name']) . '</strong> <span class="chip chip-plain ' . ($p['borrower_type'] === 'student' ? 'chip-info' : '') . '">'
        . ($p['borrower_type'] === 'student' ? 'Student' : 'Teacher') . '</span>'
        . '<span class="sub">' . h($p['email']) . ' · ' . h($p['detail']) . '</span>'
        . '<span class="sub">' . (int) $p['active_loans'] . ' of ' . (int) $p['max_loans'] . ' loans'
        . ((int) $p['open_requests'] > 0 ? ', ' . plural((int) $p['open_requests'], 'open request') : '') . '</span>';
    return $html;
}

$pageTitle = 'Issue Book';
$activeNav = 'issue';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Issue Book</h1>
    <p class="lead">Hand over a reserved book, or lend one to a walk-in borrower. The due date follows the loan rules.</p>
  </div>
</div>

<nav class="tabs" aria-label="How to issue">
  <a class="tab" href="/librarian/issue.php"<?= $tab === 'request' ? ' aria-current="page"' : '' ?>>From an approved request <span class="tab-count"><?= count($approved) ?></span></a>
  <a class="tab" href="/librarian/issue.php?tab=walkin"<?= $tab === 'walkin' ? ' aria-current="page"' : '' ?>>Walk-in</a>
</nav>

<?php if ($tab === 'request'): ?>
  <form class="toolbar" method="get" action="/librarian/issue.php" role="search" data-live-search>
    <div class="field grow">
      <label for="who">Find the borrower</label>
      <div class="search-field"><?= icon('search') ?><input id="who" name="who" type="search" value="<?= h($who) ?>" placeholder="Name, email or book" maxlength="100"></div>
    </div>
    <noscript><button class="btn btn-secondary" type="submit">Search</button></noscript>
  </form>
  <?php if (!$approved): ?>
    <div class="empty"><?= icon('inbox') ?><strong><?= $who !== '' ? 'No reserved books for that search' : 'No books are waiting for pickup' ?></strong>
      <p>Approved requests appear here until they are issued. For someone without a request, use Walk-in.</p></div>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Books waiting for pickup" tabindex="0">
      <table>
        <thead><tr><th scope="col">Borrower</th><th scope="col">Book</th><th scope="col">Pickup</th><th scope="col">Due if issued today</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          <?php foreach ($approved as $r): ?>
            <tr>
              <td><strong><?= h($r['borrower_name']) ?></strong><span class="sub"><?= h($r['borrower_email']) ?></span></td>
              <td><?= h($r['book_title']) ?></td>
              <td class="nowrap"><?= h(fmt_date($r['pickup_date'])) ?>, <?= h(fmt_time($r['pickup_time'])) ?>
                <span class="sub">Held until <?= h(fmt_date($r['pickup_deadline'])) ?></span></td>
              <td class="nowrap"><strong><?= h(fmt_date($r['due_if_today'])) ?></strong></td>
              <td class="actions">
                <form method="post" action="/librarian/issue.php" class="inline-form"
                      data-confirm="<?= h('Issue ' . $r['book_title'] . ' to ' . $r['borrower_name'] . '? It is due on ' . fmt_date($r['due_if_today']) . '.') ?>" data-confirm-label="Issue book">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="issue_request">
                  <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                  <button class="btn btn-primary btn-sm" type="submit"><?= icon('issue') ?> Issue book<span class="sr-only"> to <?= h($r['borrower_name']) ?></span></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php else: ?>
  <div class="grid-2">
    <section class="card" aria-labelledby="pick-borrower">
      <h2 class="card-title" id="pick-borrower">1. Borrower</h2>
      <?php if ($person): ?>
        <p><?= borrower_summary($person) ?></p>
        <p><a href="<?= h(url_with(['borrower' => null, 'bq' => null])) ?>">Choose someone else</a></p>
      <?php else: ?>
        <form method="get" action="/librarian/issue.php" role="search">
          <input type="hidden" name="tab" value="walkin">
          <?php if ($book): ?><input type="hidden" name="book" value="<?= (int) $book['book_id'] ?>"><?php endif; ?>
          <div class="field">
            <label for="bq">Student or teacher</label>
            <div class="row"><div class="search-field grow" style="flex:1"><?= icon('search') ?><input id="bq" name="bq" type="search" value="<?= h($bq) ?>" placeholder="Name or email" maxlength="100"></div>
              <button class="btn btn-secondary" type="submit">Find</button></div>
          </div>
        </form>
        <?php if ($bq !== '' && !$people): ?><p class="muted">Nobody matches “<?= h($bq) ?>”.</p><?php endif; ?>
        <?php if ($people): ?>
          <ul class="plain-list attention-list">
            <?php foreach ($people as $p): ?>
              <li><?= icon('user') ?><span style="flex:1"><?= borrower_summary($p) ?>
                <?php foreach (borrower_blocks($p) as $b): ?><span class="sub"><span class="chip chip-bad"><?= h($b) ?></span></span><?php endforeach; ?></span>
                <a class="btn btn-secondary btn-sm" href="<?= h(url_with(['borrower' => $p['borrower_type'] . ':' . $p['borrower_id'], 'bq' => null])) ?>">Choose<span class="sr-only"> <?= h($p['full_name']) ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="pick-book">
      <h2 class="card-title" id="pick-book">2. Book</h2>
      <?php if ($book): ?>
        <p><strong><?= h($book['title']) ?></strong><span class="sub"><?= h($book['author']) ?> · <?= $book['isbn'] ? h($book['isbn']) : 'No ISBN' ?></span>
          <span class="sub"><?= availability_chip((int) $book['copies_available']) ?> <?= (int) $book['copies_available'] ?> of <?= (int) $book['copies_total'] ?> on the shelf</span></p>
        <p><a href="<?= h(url_with(['book' => null, 'kq' => null])) ?>">Choose another book</a></p>
      <?php else: ?>
        <form method="get" action="/librarian/issue.php" role="search">
          <input type="hidden" name="tab" value="walkin">
          <?php if ($person): ?><input type="hidden" name="borrower" value="<?= h($person['borrower_type'] . ':' . $person['borrower_id']) ?>"><?php endif; ?>
          <div class="field">
            <label for="kq">Title, author or ISBN</label>
            <div class="row"><div class="search-field grow" style="flex:1"><?= icon('search') ?><input id="kq" name="kq" type="search" value="<?= h($kq) ?>" placeholder="For example Rizal" maxlength="100"></div>
              <button class="btn btn-secondary" type="submit">Find</button></div>
          </div>
        </form>
        <?php if ($kq !== '' && !$books): ?><p class="muted">No book matches “<?= h($kq) ?>”.</p><?php endif; ?>
        <?php if ($books): ?>
          <ul class="plain-list attention-list">
            <?php foreach ($books as $b): ?>
              <li><?= icon('books') ?><span style="flex:1"><strong><?= h($b['title']) ?></strong><span class="sub"><?= h($b['author']) ?></span>
                <span class="sub"><?= availability_chip((int) $b['copies_available']) ?> <?= (int) $b['copies_available'] ?> of <?= (int) $b['copies_total'] ?> on the shelf</span></span>
                <a class="btn btn-secondary btn-sm" href="<?= h(url_with(['book' => $b['book_id'], 'kq' => null])) ?>">Choose<span class="sr-only"> <?= h($b['title']) ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($person && $book):
      $blocks = borrower_blocks($person); ?>
    <section class="card section" aria-labelledby="confirm-issue">
      <h2 class="card-title" id="confirm-issue">3. Check and issue</h2>
      <dl class="dl">
        <dt>Book</dt><dd><?= h($book['title']) ?></dd>
        <dt>Borrower</dt><dd><?= h($person['full_name']) ?> (<?= $person['borrower_type'] === 'student' ? 'Student' : 'Teacher' ?>)</dd>
        <dt>Due date</dt><dd><?= h(fmt_date($person['due_if_today'])) ?> (<?= (int) $person['loan_days'] ?> days)</dd>
      </dl>
      <?php if ($blocks): ?>
        <div class="alert alert-warning section" role="status"><?= icon('alert') ?><div><p><strong>This loan will probably be refused:</strong></p>
          <ul><?php foreach ($blocks as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul></div></div>
      <?php endif; ?>
      <?php if ((int) $book['copies_available'] < 1): ?>
        <div class="alert alert-warning" role="status"><?= icon('alert') ?><p>No copy of <?= h($book['title']) ?> is on the shelf right now.</p></div>
      <?php endif; ?>
      <form method="post" action="/librarian/issue.php" class="form-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="issue_direct">
        <input type="hidden" name="borrower" value="<?= h($person['borrower_type'] . ':' . $person['borrower_id']) ?>">
        <input type="hidden" name="book_id" value="<?= (int) $book['book_id'] ?>">
        <input type="hidden" name="back" value="<?= h(current_url()) ?>">
        <button class="btn btn-primary" type="submit"><?= icon('issue') ?> Issue book, due <?= h(fmt_date($person['due_if_today'])) ?></button>
      </form>
    </section>
  <?php endif; ?>
<?php endif; ?>
<?php require APP_SRC . '/partials/footer.php'; ?>
