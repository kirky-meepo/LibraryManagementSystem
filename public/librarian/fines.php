<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/partials/stat_card.php';
require_once APP_SRC . '/partials/pager.php';

$me = require_role(['librarian', 'admin']);
$isAdmin = is_admin($me);

// Add fine (any staff) goes through fn_create_fine; Mark paid (any staff) and Waive (admin only, reason required)
// through fn_pay_fine and fn_waive_fine.
if (is_post()) {
    $fineId = int_param('fine_id', 'post');
    $action = post('action');
    $fine = $fineId !== null ? q_row('SELECT borrower_name, amount FROM fn_get_fine(:id)', [':id' => $fineId]) : null;
    try {
        if ($action === 'add') {
            $loanId = int_param('transaction_id', 'post');
            $type = post('fine_type');
            $raw = str_replace([',', '₱', ' '], '', post('amount'));
            if ($loanId === null) {
                throw new InvalidArgumentException('Choose the loan the fine is for.');
            }
            if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $raw)) {
                throw new InvalidArgumentException('Type the amount in pesos, for example 300 or 300.50.');
            }
            $newId = tx(function (PDO $pdo) use ($loanId, $type, $raw, $me) {
                $st = $pdo->prepare('SELECT fn_create_fine(:t, :type, CAST(:a AS numeric), :s)');
                $st->execute([':t' => $loanId, ':type' => $type, ':a' => $raw, ':s' => $me['id']]);
                return (int) $st->fetchColumn();
            });
            $added = q_row('SELECT amount, fine_type, borrower_name, book_title FROM fn_get_fine(:id)', [':id' => $newId]);
            flash('success', 'A ' . peso($added['amount']) . ' ' . $added['fine_type'] . ' fine is added for ' . $added['borrower_name']
                . ' (' . $added['book_title'] . ').');
            redirect('/librarian/fines.php?tab=unpaid#fine-' . $newId);
        }
        if (!$fine) {
            throw new InvalidArgumentException('That fine no longer exists.');
        }
        if ($action === 'pay') {
            tx(function (PDO $pdo) use ($fineId, $me) {
                $pdo->prepare('SELECT fn_pay_fine(:f, :s)')->execute([':f' => $fineId, ':s' => $me['id']]);
            });
            flash('success', peso($fine['amount']) . ' from ' . $fine['borrower_name'] . ' is marked paid.');
        } elseif ($action === 'waive') {
            $reason = clean_text(post('reason'), 300);
            if (trim($reason) === '') {
                throw new InvalidArgumentException('Write the reason for waiving the fine.');
            }
            tx(function (PDO $pdo) use ($fineId, $me, $reason) {
                $pdo->prepare('SELECT fn_waive_fine(:f, :s, :r)')->execute([':f' => $fineId, ':s' => $me['id'], ':r' => trim($reason)]);
            });
            flash('success', 'The ' . peso($fine['amount']) . ' fine for ' . $fine['borrower_name'] . ' is waived.');
        }
    } catch (PDOException $e) {
        flash('error', db_message($e));
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect(current_url());
}

const FINE_TABS = ['unpaid' => 'Unpaid', 'paid' => 'Paid', 'waived' => 'Waived', 'all' => 'All'];
$tab = isset(FINE_TABS[get('tab')]) ? get('tab') : 'unpaid';
$q = mb_substr(get('q'), 0, 100);
$perPage = 15;
[$page, $offset] = page_offset($perPage);

$base = "(:q = '' OR borrower_name ILIKE :q1 OR borrower_email ILIKE :q2 OR book_title ILIKE :q3)";
$baseParams = [':q' => $q, ':q1' => like_contains($q), ':q2' => like_contains($q), ':q3' => like_contains($q)];
$totals = q_row(
    "SELECT coalesce(sum(amount) FILTER (WHERE status = 'unpaid'), 0) AS unpaid, count(*) FILTER (WHERE status = 'unpaid') AS unpaid_n,
            coalesce(sum(amount) FILTER (WHERE status = 'paid'), 0)   AS paid,   count(*) FILTER (WHERE status = 'paid') AS paid_n,
            coalesce(sum(amount) FILTER (WHERE status = 'waived'), 0) AS waived, count(*) FILTER (WHERE status = 'waived') AS waived_n,
            count(*) AS all_n
       FROM v_fine_detail WHERE $base",
    $baseParams
);
// fn_list_fines (04c_crud.sql): one tab, the search, one page and the total for the pager.
[$fines, $total] = list_rows('SELECT * FROM fn_list_fines(:status, :q, NULL, NULL, :limit, :offset)',
    [':status' => $tab, ':q' => $q], $perPage, (int) $offset);
// Loans to choose from in Add fine: the last year, newest first (fn_list_transactions).
$loanChoices = q_all("SELECT transaction_id, borrower_name, book_title, date_borrowed, display_status
                        FROM fn_list_transactions('all', NULL, CAST(:from AS date), NULL, NULL, NULL, 300, 0)",
                     [':from' => date('Y-m-d', strtotime('-365 days'))]);

$pageTitle = 'Fines';
$activeNav = 'fines';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Fines</h1>
    <p class="lead">Fines are created by the database when a book comes back late, damaged or lost. Members pay at the desk.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="button" data-modal-open="add-fine-modal"<?= $loanChoices ? '' : ' disabled' ?>><?= icon('plus') ?> Add fine</button>
  </div>
</div>

<ul class="stat-grid is-compact">
  <li><?= stat_card(['label' => 'Unpaid', 'value' => $totals['unpaid'], 'prefix' => '₱', 'decimals' => 2, 'note' => plural((int) $totals['unpaid_n'], 'fine') . ' to collect',
      'from' => '#011f4b', 'to' => '#03396c', 'icon' => 'coins', 'art' => 'stack', 'href' => '/librarian/fines.php?tab=unpaid']) ?></li>
  <li><?= stat_card(['label' => 'Collected', 'value' => $totals['paid'], 'prefix' => '₱', 'decimals' => 2, 'note' => plural((int) $totals['paid_n'], 'fine') . ' paid',
      'from' => '#03396c', 'to' => '#005b96', 'icon' => 'check', 'art' => 'open', 'index' => 1, 'href' => '/librarian/fines.php?tab=paid']) ?></li>
  <li><?= stat_card(['label' => 'Waived', 'value' => $totals['waived'], 'prefix' => '₱', 'decimals' => 2, 'note' => plural((int) $totals['waived_n'], 'fine') . ' waived',
      'from' => '#6497b1', 'to' => '#b3cde0', 'icon' => 'info', 'art' => 'bookmark', 'index' => 2, 'href' => '/librarian/fines.php?tab=waived']) ?></li>
</ul>

<nav class="tabs" aria-label="Fine status">
  <?php foreach (FINE_TABS as $key => $label): ?>
    <a class="tab" href="<?= h(url_with(['tab' => $key, 'page' => null])) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= h($label) ?> <span class="tab-count"><?= (int) $totals[$key . '_n'] ?></span></a>
  <?php endforeach; ?>
</nav>

<form class="toolbar" method="get" action="/librarian/fines.php" role="search" data-live-search>
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <div class="field grow">
    <label for="q">Search</label>
    <div class="search-field"><?= icon('search') ?><input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Borrower, email or book" maxlength="100"></div>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Search</button></noscript>
</form>

<?php if (!$fines): ?>
  <div class="empty"><?= icon('coins') ?><strong><?= $tab === 'unpaid' ? 'No unpaid fines' : 'No fines here' ?></strong>
    <p>Late, damaged and lost books create fines when they are marked returned on Issued Books.</p></div>
<?php else: ?>
  <div class="table-wrap" role="region" aria-label="Fines" tabindex="0">
    <table>
      <thead><tr><th scope="col">Borrower</th><th scope="col">Book and loan</th><th scope="col">Type</th><th scope="col" class="num">Amount</th>
        <th scope="col">Issued</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <?php foreach ($fines as $f): ?>
          <tr id="fine-<?= (int) $f['fine_id'] ?>">
            <td><strong><?= h($f['borrower_name']) ?></strong>
              <span class="sub"><span class="chip chip-plain <?= $f['borrower_type'] === 'student' ? 'chip-info' : '' ?>"><?= $f['borrower_type'] === 'student' ? 'Student' : 'Teacher' ?></span></span></td>
            <td><a href="/librarian/issued.php?loan=<?= (int) $f['transaction_id'] ?>"><?= h($f['book_title']) ?></a>
              <span class="sub">Borrowed <?= h(fmt_date($f['date_borrowed'])) ?>, due <?= h(fmt_date($f['due_date'])) ?></span></td>
            <td><span class="chip chip-plain <?= $f['fine_type'] === 'overdue' ? 'chip-warn' : 'chip-bad' ?>"><?= h(ucfirst($f['fine_type'])) ?></span></td>
            <td class="num"><strong><?= h(peso($f['amount'])) ?></strong></td>
            <td class="nowrap"><?= h(fmt_date($f['date_issued'])) ?></td>
            <td><?php if ($f['status'] === 'unpaid'): ?><span class="chip chip-bad">Unpaid</span>
                <?php elseif ($f['status'] === 'paid'): ?><span class="chip chip-ok">Paid</span><span class="sub"><?= h(fmt_date($f['date_paid'])) ?></span>
                <?php else: ?><span class="chip">Waived</span><span class="sub"><?= h($f['waived_reason']) ?></span><?php endif; ?></td>
            <td class="actions">
              <?php if ($f['status'] === 'unpaid'): ?>
                <form method="post" class="inline-form" data-confirm="<?= h('Mark ' . peso($f['amount']) . ' from ' . $f['borrower_name'] . ' as paid?') ?>" data-confirm-label="Mark paid">
                  <?= csrf_field() ?>
                  <input type="hidden" name="fine_id" value="<?= (int) $f['fine_id'] ?>"><input type="hidden" name="action" value="pay">
                  <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?> Mark paid<span class="sr-only"> for <?= h($f['borrower_name']) ?></span></button>
                </form>
                <?php if ($isAdmin): ?>
                  <button class="btn btn-secondary btn-sm" type="button" data-modal-open="waive-modal" data-fill="<?= h(json_encode(['fine_id' => (int) $f['fine_id'], 'who' => peso($f['amount']) . ' ' . $f['fine_type'] . ' fine · ' . $f['borrower_name'], 'reason' => ''], JSON_UNESCAPED_UNICODE)) ?>">Waive<span class="sr-only"> fine for <?= h($f['borrower_name']) ?></span></button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= render_pager($total, $page, $perPage, 'fines') ?>

<dialog class="modal" id="add-fine-modal" aria-labelledby="add-fine-title">
  <form class="modal-form" method="post" action="/librarian/fines.php">
    <div class="modal-head">
      <div><h2 id="add-fine-title">Add fine</h2><p class="sub">For something found after the return, for example damage. Late and lost fines are added for you when a book is marked returned.</p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="field">
        <label for="af-loan">Loan <span class="req" aria-hidden="true">*</span></label>
        <select id="af-loan" name="transaction_id" required>
          <option value="">Choose a loan</option>
          <?php foreach ($loanChoices as $c): ?>
            <option value="<?= (int) $c['transaction_id'] ?>"><?= h($c['borrower_name'] . ' · ' . $c['book_title'] . ' · borrowed ' . fmt_date($c['date_borrowed']) . ' (' . ucfirst($c['display_status']) . ')') ?></option>
          <?php endforeach; ?>
        </select>
        <p class="field-hint">Loans from the last 12 months, newest first.</p>
      </div>
      <div class="form-row">
        <div class="field">
          <label for="af-type">Kind of fine</label>
          <select id="af-type" name="fine_type" required>
            <option value="damaged">Damaged</option>
            <option value="lost">Lost</option>
            <option value="overdue">Overdue</option>
          </select>
        </div>
        <div class="field">
          <label for="af-amount">Amount (₱) <span class="req" aria-hidden="true">*</span></label>
          <input id="af-amount" name="amount" type="text" inputmode="decimal" maxlength="12" placeholder="300.00" required>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit">Add fine</button>
    </div>
  </form>
</dialog>

<?php if ($isAdmin): ?>
<dialog class="modal" id="waive-modal" aria-labelledby="waive-title">
  <form class="modal-form" method="post" action="<?= h(current_url()) ?>">
    <div class="modal-head">
      <div><h2 id="waive-title">Waive fine</h2><p class="sub" data-fill-text="who"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="fine_id"><input type="hidden" name="action" value="waive">
      <div class="field">
        <label for="wv-reason">Reason <span class="req" aria-hidden="true">*</span></label>
        <textarea id="wv-reason" name="reason" maxlength="300" rows="3" required style="min-height: 90px"></textarea>
        <p class="char-count" data-count-for="wv-reason"></p>
        <p class="field-hint">Saved with the fine and shown on the Fines page.</p>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit">Waive fine</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php require APP_SRC . '/partials/footer.php'; ?>
