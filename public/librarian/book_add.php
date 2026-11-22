<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/books.php';

$me = require_role(['librarian', 'admin']);

$values = ['isbn' => '', 'title' => '', 'author' => '', 'category' => '', 'copies_total' => '1', 'replacement_cost' => ''];
$errors = [];
$formError = '';

if (is_post()) {
    foreach ($values as $key => $_) {
        $values[$key] = mb_substr(post($key), 0, 220);
    }
    [$book, $errors] = validate_book($values);
    if (!$errors) {
        try {
            // fn_create_book checks the rules again (ISBN, limit, category) and puts every copy on the shelf.
            $newId = tx(function (PDO $pdo) use ($book, $me) {
                $st = $pdo->prepare('SELECT fn_create_book(:isbn, :title, :author, :category, :copies, :cost, :staff)');
                $st->execute([
                    ':isbn' => $book['isbn'], ':title' => $book['title'], ':author' => $book['author'], ':category' => $book['category'],
                    ':copies' => $book['copies_total'], ':cost' => $book['replacement_cost'], ':staff' => $me['id'],
                ]);
                return (int) $st->fetchColumn();
            });
            flash('success', $book['title'] . ' is added with ' . plural($book['copies_total'], 'copy', 'copies') . ' on the shelf.',
                ['href' => '/librarian/book_add.php', 'label' => 'Add another']);
            redirect('/librarian/books.php?q=' . rawurlencode($book['title']) . '#book-' . $newId);
        } catch (PDOException $e) {
            $formError = db_message($e);
        }
    } else {
        $formError = 'Please fix the highlighted fields.';
    }
}

$categories = book_categories();

$pageTitle = 'Add New Book';
$activeNav = 'books';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Add New Book</h1>
    <p class="lead">All copies go on the shelf as soon as you save.</p>
  </div>
  <div class="page-actions"><a class="btn btn-secondary" href="/librarian/books.php">Back to Manage Books</a></div>
</div>

<form class="card form-card" method="post" action="/librarian/book_add.php" novalidate style="max-width: 760px">
  <?= csrf_field() ?>
  <?php if ($formError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert') ?><p><?= h($formError) ?></p></div>
  <?php endif; ?>

  <div class="field">
    <label for="isbn">ISBN <span class="muted">(optional)</span></label>
    <input id="isbn" name="isbn" type="text" maxlength="20" inputmode="numeric" value="<?= h($values['isbn']) ?>"<?= field_attrs($errors, 'isbn', 'isbn-hint') ?> autofocus>
    <p class="field-hint" id="isbn-hint">10 or 13 digits from the back cover; hyphens are fine. ISBN-10s are saved as ISBN-13.</p>
    <?= field_error($errors, 'isbn') ?>
  </div>
  <div class="field">
    <label for="title">Title <span class="req" aria-hidden="true">*</span></label>
    <input id="title" name="title" type="text" maxlength="200" required value="<?= h($values['title']) ?>"<?= field_attrs($errors, 'title') ?>>
    <?= field_error($errors, 'title') ?>
  </div>
  <div class="form-row">
    <div class="field">
      <label for="author">Author <span class="req" aria-hidden="true">*</span></label>
      <input id="author" name="author" type="text" maxlength="150" required value="<?= h($values['author']) ?>"<?= field_attrs($errors, 'author') ?>>
      <?= field_error($errors, 'author') ?>
    </div>
    <div class="field">
      <label for="category">Category <span class="req" aria-hidden="true">*</span></label>
      <input id="category" name="category" type="text" maxlength="60" required list="category-list" value="<?= h($values['category']) ?>"<?= field_attrs($errors, 'category', 'category-hint') ?>>
      <p class="field-hint" id="category-hint">Pick one from the list or type a new category.</p>
      <?= field_error($errors, 'category') ?>
      <datalist id="category-list">
        <?php foreach ($categories as $c): ?><option value="<?= h($c['category']) ?>"></option><?php endforeach; ?>
      </datalist>
    </div>
  </div>
  <div class="form-row">
    <div class="field">
      <label for="copies_total">Copies <span class="req" aria-hidden="true">*</span></label>
      <input id="copies_total" name="copies_total" type="number" min="1" max="<?= DEFAULT_COPY_LIMIT ?>" step="1" required value="<?= h($values['copies_total']) ?>"<?= field_attrs($errors, 'copies_total', 'copies-hint') ?>>
      <p class="field-hint" id="copies-hint">Up to <?= DEFAULT_COPY_LIMIT ?> copies. An admin can raise the limit for a title.</p>
      <?= field_error($errors, 'copies_total') ?>
    </div>
    <div class="field">
      <label for="replacement_cost">Replacement cost (₱) <span class="req" aria-hidden="true">*</span></label>
      <input id="replacement_cost" name="replacement_cost" type="text" inputmode="decimal" maxlength="12" required placeholder="2450.00" value="<?= h($values['replacement_cost']) ?>"<?= field_attrs($errors, 'replacement_cost', 'cost-hint') ?>>
      <p class="field-hint" id="cost-hint">Charged as the fine when a copy is lost.</p>
      <?= field_error($errors, 'replacement_cost') ?>
    </div>
  </div>
  <p class="small muted"><span class="req" aria-hidden="true">*</span> Required</p>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Add book</button>
    <a class="btn btn-secondary" href="/librarian/books.php">Cancel</a>
  </div>
</form>
<?php require APP_SRC . '/partials/footer.php'; ?>
