<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/partials/stat_card.php';
require_once APP_SRC . '/partials/pager.php';
require_once APP_SRC . '/books.php';
require_once APP_SRC . '/member.php';

$me = require_role(['student', 'teacher']);
$scope = member_scope($me);

// Request this book: fn_create_request checks every rule (fines, overdue books, the limit, duplicates).
if (is_post()) {
    $bookId = int_param('book_id', 'post');
    try {
        $title = tx(function (PDO $pdo) use ($bookId, $scope) {
            $st = $pdo->prepare('SELECT fn_create_request(:sid, :tid, :b)');
            $st->execute([':sid' => $scope['student_id'], ':tid' => $scope['teacher_id'], ':b' => $bookId]);
            $t = $pdo->prepare('SELECT title FROM fn_get_book(:b)');
            $t->execute([':b' => $bookId]);
            return (string) $t->fetchColumn();
        });
        flash('success', "You requested $title. You will get a message when the library decides.", ['href' => '/member/requests.php', 'label' => 'My Requests']);
    } catch (PDOException $e) {
        flash('error', member_words(db_message($e), $me));
    }
    redirect(current_url());
}

$view = get('view') === 'table' ? 'table' : 'cards';
$q = mb_substr(get('q'), 0, 100);
$category = mb_substr(get('category'), 0, 60);
$perPage = $view === 'cards' ? 12 : 15;
[$page, $offset] = page_offset($perPage);

// fn_list_books never returns archived books unless asked, so members never see them.
[$books, $total] = list_rows(
    'SELECT book_id, isbn, title, author, category, copies_total, copies_available, total_count
       FROM fn_list_books(:q, :cat, false, false, :limit, :offset)',
    [':q' => $q, ':cat' => $category],
    $perPage,
    (int) $offset
);
$categories = book_categories(false);

// What this member already has, so the button can say so.
$mine = [];
foreach (q_all("SELECT book_id, status FROM book_request WHERE {$scope['col']} = :id AND status IN ('pending', 'approved')", [':id' => $scope['id']]) as $r) {
    $mine[(int) $r['book_id']] = $r['status'] === 'approved' ? 'Ready for pickup' : 'Requested';
}
foreach (q_all("SELECT book_id FROM fn_list_transactions('active', NULL, NULL, NULL, :sid, :tid, 100, 0)",
                [':sid' => $scope['student_id'], ':tid' => $scope['teacher_id']]) as $r) {
    $mine[(int) $r['book_id']] = 'You have this book';
}

/** The request button, or what already blocks it. */
function request_control(array $b, array $mine): string
{
    $id = (int) $b['book_id'];
    if (isset($mine[$id])) {
        return '<a class="btn btn-secondary btn-sm" href="/member/' . ($mine[$id] === 'You have this book' ? 'borrowed' : 'requests') . '.php">' . h($mine[$id]) . '</a>';
    }
    $note = (int) $b['copies_available'] > 0 ? '' : ' All copies are out right now; the library can approve it when one comes back.';
    return '<button class="btn btn-primary btn-sm" type="button" data-modal-open="request-modal" data-fill="'
        . h(json_encode(['book_id' => $id, 'title' => $b['title'], 'author' => $b['author'], 'note' => trim($note)], JSON_UNESCAPED_UNICODE))
        . '">Request this book<span class="sr-only">: ' . h($b['title']) . '</span></button>';
}

$pageTitle = 'Catalog';
$activeNav = 'catalog';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Catalog</h1>
    <p class="lead">Find a book and request it. The library tells you when to pick it up.</p>
  </div>
  <nav class="view-toggle" aria-label="Show books as">
    <a class="btn btn-sm <?= $view === 'cards' ? 'btn-primary' : 'btn-secondary' ?>" href="<?= h(url_with(['view' => 'cards', 'page' => null])) ?>"<?= $view === 'cards' ? ' aria-current="page"' : '' ?>><?= icon('dashboard') ?> Cards</a>
    <a class="btn btn-sm <?= $view === 'table' ? 'btn-primary' : 'btn-secondary' ?>" href="<?= h(url_with(['view' => 'table', 'page' => null])) ?>"<?= $view === 'table' ? ' aria-current="page"' : '' ?>><?= icon('list') ?> Table</a>
  </nav>
</div>

<form class="toolbar" method="get" action="/member/catalog.php" role="search" data-live-search>
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div class="field grow">
    <label for="q">Search</label>
    <div class="search-field"><?= icon('search') ?><input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Title, author or ISBN" maxlength="100"></div>
  </div>
  <div class="field">
    <label for="category">Category</label>
    <select id="category" name="category" data-autosubmit>
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?><option value="<?= h($c['category']) ?>"<?= $c['category'] === $category ? ' selected' : '' ?>><?= h($c['category']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Search</button></noscript>
</form>

<?php if (!$books): ?>
  <div class="empty"><?= icon('books') ?><strong>No books match your search</strong><p>Try fewer words, the author's last name, or another category.</p></div>
<?php elseif ($view === 'cards'): ?>
  <ul class="book-grid">
    <?php foreach ($books as $b):
        [$from, $to] = cover_style($b['category']);
        $art = BOOK_ART[(int) $b['book_id'] % count(BOOK_ART)]; ?>
      <li class="book-card">
        <div class="book-cover ink-<?= card_ink($from, $to) ?>" style="--g1: <?= h($from) ?>; --g2: <?= h($to) ?>" aria-hidden="true">
          <span class="book-cover-art"><?= book_art($art) ?></span>
        </div>
        <div class="book-body">
          <h2 class="book-title"><?= h($b['title']) ?></h2>
          <p class="book-meta"><?= h($b['author']) ?><br><span class="muted"><?= h($b['category']) ?></span></p>
          <p class="book-avail"><?= availability_chip((int) $b['copies_available']) ?> <span class="small muted"><?= (int) $b['copies_available'] ?> of <?= (int) $b['copies_total'] ?> on the shelf</span></p>
          <div class="book-action"><?= request_control($b, $mine) ?></div>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php else: ?>
  <div class="table-wrap" role="region" aria-label="Books" tabindex="0">
    <table>
      <thead><tr><th scope="col">Title</th><th scope="col">Author</th><th scope="col">Category</th><th scope="col">ISBN</th><th scope="col">On the shelf</th><th scope="col"><span class="sr-only">Request</span></th></tr></thead>
      <tbody>
        <?php foreach ($books as $b): ?>
          <tr>
            <td><strong><?= h($b['title']) ?></strong></td>
            <td><?= h($b['author']) ?></td>
            <td><?= h($b['category']) ?></td>
            <td class="nowrap"><?= $b['isbn'] ? h($b['isbn']) : '<span class="muted">None</span>' ?></td>
            <td class="nowrap"><?= availability_chip((int) $b['copies_available']) ?> <span class="small muted"><?= (int) $b['copies_available'] ?> of <?= (int) $b['copies_total'] ?></span></td>
            <td class="actions"><?= request_control($b, $mine) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= render_pager($total, $page, $perPage, 'books') ?>

<dialog class="modal" id="request-modal" aria-labelledby="request-title">
  <form class="modal-form" method="post" action="<?= h(current_url()) ?>">
    <div class="modal-head">
      <div><h2 id="request-title">Request this book?</h2><p class="sub"><span data-fill-text="title"></span> · <span data-fill-text="author"></span></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="book_id">
      <p>The library will approve or reject your request and send you a message. If it is approved, you get a pickup date and time.</p>
      <p class="alert alert-info" data-fill-show="note" hidden><span data-fill-text="note"></span></p>
      <p class="small muted">You can cancel it on My Requests while it is pending or waiting for pickup.</p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit">Request this book</button>
    </div>
  </form>
</dialog>
<?php require APP_SRC . '/partials/footer.php'; ?>
