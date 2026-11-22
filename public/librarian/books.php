<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/books.php';
require_once APP_SRC . '/partials/pager.php';

$me = require_role(['librarian', 'admin']);

// Archive and restore go through fn_set_book_archived, which blocks archiving while copies are out or reserved.
if (is_post()) {
    $bookId = int_param('book_id', 'post');
    $action = post('action');
    if ($bookId !== null && in_array($action, ['archive', 'restore'], true)) {
        try {
            $title = tx(function (PDO $pdo) use ($bookId, $action, $me) {
                $st = $pdo->prepare('SELECT fn_set_book_archived(:id, :archived, :staff)');
                $st->execute([':id' => $bookId, ':archived' => $action === 'archive' ? 'true' : 'false', ':staff' => $me['id']]);
                $t = $pdo->prepare('SELECT title FROM fn_get_book(:id)');
                $t->execute([':id' => $bookId]);
                return (string) $t->fetchColumn();
            });
            flash('success', $action === 'archive'
                ? "$title is archived. Members no longer see it in the catalog."
                : "$title is back in the catalog.");
        } catch (PDOException $e) {
            flash('error', db_message($e));
        }
    }
    redirect(current_url());
}

$q = mb_substr(get('q'), 0, 100);
$category = mb_substr(get('category'), 0, 60);
$showArchived = get('archived') === '1';
$perPage = 15;
[$page, $offset] = page_offset($perPage);

// fn_list_books (04c_crud.sql): search, category and archived filters, one page, and the total for the pager.
[$books, $total] = list_rows(
    'SELECT * FROM fn_list_books(:q, :cat, false, :arch, :limit, :offset)',
    [':q' => $q, ':cat' => $category, ':arch' => $showArchived ? 'true' : 'false'],
    $perPage,
    (int) $offset
);
$categories = book_categories();

$pageTitle = 'Manage Books';
$activeNav = 'books';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Manage Books</h1>
    <p class="lead">Every title in the library, with copies on the shelf and copies out.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-primary" href="/librarian/book_add.php"><?= icon('plus') ?> Add New Book</a>
  </div>
</div>

<form class="toolbar" method="get" action="/librarian/books.php" role="search" data-live-search>
  <div class="field grow">
    <label for="q">Search</label>
    <div class="search-field"><?= icon('search') ?><input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Title, author or ISBN" maxlength="100"></div>
  </div>
  <div class="field">
    <label for="category">Category</label>
    <select id="category" name="category" data-autosubmit>
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= h($c['category']) ?>"<?= $c['category'] === $category ? ' selected' : '' ?>><?= h($c['category']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <span class="field-label" aria-hidden="true">&nbsp;</span>
    <label class="check"><input type="checkbox" name="archived" value="1"<?= $showArchived ? ' checked' : '' ?> data-autosubmit> Show archived</label>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Search</button></noscript>
</form>

<?php if (!$books): ?>
  <div class="empty"><?= icon('books') ?>
    <strong><?= $q !== '' || $category !== '' ? 'No books match your search' : 'No books yet' ?></strong>
    <p><?= $q !== '' || $category !== '' ? 'Try fewer words, another category, or tick Show archived.' : 'Add the first title with Add New Book.' ?></p>
  </div>
<?php else: ?>
  <div class="table-wrap" role="region" aria-label="Books" tabindex="0">
    <table>
      <thead>
        <tr>
          <th scope="col">Title</th>
          <th scope="col">Author</th>
          <th scope="col">Category</th>
          <th scope="col">ISBN</th>
          <th scope="col" class="num">Available / total</th>
          <th scope="col">Status</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($books as $b):
            $out = (int) $b['on_loan'] + (int) $b['reserved'];
            $fill = [
                'book_id' => (int) $b['book_id'], 'isbn' => $b['isbn'] ?? '', 'title' => $b['title'], 'author' => $b['author'],
                'category' => $b['category'], 'copies_total' => (int) $b['copies_total'], 'replacement_cost' => $b['replacement_cost'],
                'copy_limit' => (int) $b['copy_limit'],
                'out_note' => 'Up to ' . plural((int) $b['copy_limit'], 'copy', 'copies') . ' for this title.'
                    . ($out > 0 ? ' ' . plural($out, 'copy is', 'copies are') . ' on loan or reserved, so the total cannot go below ' . $out . '.' : ''),
            ];
            $details = [
                'd_title' => $b['title'], 'd_author' => $b['author'], 'd_isbn' => $b['isbn'] ?: 'None', 'd_category' => $b['category'],
                'd_total' => (string) $b['copies_total'], 'd_available' => (string) $b['copies_available'],
                'd_limit' => plural((int) $b['copy_limit'], 'copy', 'copies'),
                'd_on_loan' => (string) $b['on_loan'], 'd_reserved' => (string) $b['reserved'],
                'd_pending' => (string) $b['pending_requests'], 'd_requests' => (string) $b['total_requests'],
                'd_loans' => (string) $b['total_loans'], 'd_cost' => peso($b['replacement_cost']),
                'd_added' => fmt_date($b['date_added']) . ' by ' . $b['added_by_name'],
                'd_archived' => $b['is_archived'] ? 'Archived: members cannot see or request it.' : '',
            ]; ?>
          <tr id="book-<?= (int) $b['book_id'] ?>"<?= $b['is_archived'] ? ' class="is-inactive"' : '' ?>>
            <td><strong><?= h($b['title']) ?></strong></td>
            <td><?= h($b['author']) ?></td>
            <td><?= h($b['category']) ?></td>
            <td class="nowrap"><?= $b['isbn'] ? h($b['isbn']) : '<span class="muted">None</span>' ?></td>
            <td class="num"><?= (int) $b['copies_available'] ?> / <?= (int) $b['copies_total'] ?></td>
            <td><?= availability_chip((int) $b['copies_available'], (bool) $b['is_archived']) ?></td>
            <td class="actions">
              <button class="btn btn-secondary btn-sm" type="button" data-modal-open="book-details" data-fill="<?= h(json_encode($details, JSON_UNESCAPED_UNICODE)) ?>">Details<span class="sr-only"> for <?= h($b['title']) ?></span></button>
              <button class="btn btn-secondary btn-sm" type="button" data-modal-open="book-edit" data-fill="<?= h(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><?= icon('edit') ?> Edit<span class="sr-only"> <?= h($b['title']) ?></span></button>
              <form method="post" class="inline-form"
                    data-confirm="<?= $b['is_archived'] ? h('Restore ' . $b['title'] . '? Members will see it in the catalog again.') : h('Archive ' . $b['title'] . '? Members will no longer see it, and its pending requests are cancelled with a message.') ?>"
                    data-confirm-label="<?= $b['is_archived'] ? 'Restore book' : 'Archive book' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="book_id" value="<?= (int) $b['book_id'] ?>">
                <input type="hidden" name="action" value="<?= $b['is_archived'] ? 'restore' : 'archive' ?>">
                <button class="btn btn-secondary btn-sm" type="submit"><?= icon($b['is_archived'] ? 'restore' : 'archive') ?> <?= $b['is_archived'] ? 'Restore' : 'Archive' ?><span class="sr-only"> <?= h($b['title']) ?></span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= render_pager($total, $page, $perPage, 'books') ?>

<datalist id="category-list">
  <?php foreach ($categories as $c): ?><option value="<?= h($c['category']) ?>"></option><?php endforeach; ?>
</datalist>

<dialog class="modal" id="book-edit" aria-labelledby="book-edit-title">
  <form class="modal-form" method="post" action="/api/book_save.php" data-ajax novalidate>
    <div class="modal-head">
      <div><h2 id="book-edit-title">Edit book</h2><p class="sub" data-fill-text="title"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="book_id">
      <div class="alert alert-error" data-form-error hidden role="alert"><?= icon('alert') ?><p></p></div>
      <div class="field">
        <label for="e-title">Title</label>
        <input id="e-title" name="title" type="text" maxlength="200" required>
        <p class="field-error" data-error-for="title" hidden></p>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="e-author">Author</label>
          <input id="e-author" name="author" type="text" maxlength="150" required>
          <p class="field-error" data-error-for="author" hidden></p>
        </div>
        <div class="field">
          <label for="e-category">Category</label>
          <input id="e-category" name="category" type="text" maxlength="60" list="category-list" required>
          <p class="field-error" data-error-for="category" hidden></p>
        </div>
      </div>
      <div class="field">
        <label for="e-isbn">ISBN <span class="muted">(optional)</span></label>
        <input id="e-isbn" name="isbn" type="text" maxlength="20" inputmode="numeric" aria-describedby="e-isbn-hint">
        <p class="field-hint" id="e-isbn-hint">10 or 13 digits; hyphens are fine. ISBN-10s are saved as ISBN-13.</p>
        <p class="field-error" data-error-for="isbn" hidden></p>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="e-copies">Total copies</label>
          <input id="e-copies" name="copies_total" type="number" min="1" max="<?= DEFAULT_COPY_LIMIT ?>" step="1" required aria-describedby="e-copies-hint" data-fill-max="copy_limit">
          <p class="field-hint" id="e-copies-hint" data-fill-text="out_note"></p>
          <p class="field-error" data-error-for="copies_total" hidden></p>
        </div>
        <div class="field">
          <label for="e-cost">Replacement cost (₱)</label>
          <input id="e-cost" name="replacement_cost" type="text" inputmode="decimal" maxlength="12" required>
          <p class="field-error" data-error-for="replacement_cost" hidden></p>
        </div>
      </div>
      <?php if (is_admin($me)): ?>
        <div class="field">
          <label for="e-limit">Copy limit</label>
          <input id="e-limit" name="copy_limit" type="number" min="1" max="999" step="1" required aria-describedby="e-limit-hint">
          <p class="field-hint" id="e-limit-hint">The most copies this title may have. Rare titles use 1 or 2. Admin only.</p>
          <p class="field-error" data-error-for="copy_limit" hidden></p>
        </div>
      <?php endif; ?>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit">Save changes</button>
    </div>
  </form>
</dialog>

<dialog class="modal drawer" id="book-details" aria-labelledby="book-details-title">
  <div class="modal-form">
    <div class="modal-head">
      <div><h2 id="book-details-title" data-fill-text="d_title"></h2><p class="sub" data-fill-text="d_author"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <p class="alert alert-info" data-fill-show="d_archived" hidden><span data-fill-text="d_archived"></span></p>
      <h3>Right now</h3>
      <dl class="dl">
        <dt>On the shelf</dt><dd data-fill-text="d_available"></dd>
        <dt>On loan</dt><dd data-fill-text="d_on_loan"></dd>
        <dt>Reserved for pickup</dt><dd data-fill-text="d_reserved"></dd>
        <dt>Pending requests</dt><dd data-fill-text="d_pending"></dd>
        <dt>Total copies</dt><dd data-fill-text="d_total"></dd>
        <dt>Copy limit</dt><dd data-fill-text="d_limit"></dd>
      </dl>
      <h3 class="section">History</h3>
      <dl class="dl">
        <dt>Requests, all time</dt><dd data-fill-text="d_requests"></dd>
        <dt>Loans, all time</dt><dd data-fill-text="d_loans"></dd>
      </dl>
      <h3 class="section">Details</h3>
      <dl class="dl">
        <dt>ISBN</dt><dd data-fill-text="d_isbn"></dd>
        <dt>Category</dt><dd data-fill-text="d_category"></dd>
        <dt>Replacement cost</dt><dd data-fill-text="d_cost"></dd>
        <dt>Added</dt><dd data-fill-text="d_added"></dd>
      </dl>
    </div>
    <div class="modal-foot"><button class="btn btn-secondary" type="button" data-modal-close>Close</button></div>
  </div>
</dialog>
<?php require APP_SRC . '/partials/footer.php'; ?>
