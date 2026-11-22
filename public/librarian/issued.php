<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/requests.php';
require_once APP_SRC . '/partials/pager.php';

$me = require_role(['librarian', 'admin']);

tx(fn(PDO $pdo) => $pdo->exec('CALL sp_mark_overdue()'));

const LOAN_TABS = [
    'borrowed' => 'Borrowed',
    'overdue' => 'Overdue',
    'returned' => 'Returned',
    'lost' => 'Lost',
    'all' => 'All',
];
$tab = isset(LOAN_TABS[get('tab')]) ? get('tab') : 'borrowed';
$q = mb_substr(get('q'), 0, 100);
$loanId = int_param('loan');
$perPage = 15;
[$page, $offset] = page_offset($perPage);

// fn_list_transactions and fn_get_transaction (04c_crud.sql). A link to one loan shows just that loan, on its tab.
if ($loanId !== null) {
    $one = q_row('SELECT * FROM fn_get_transaction(:id)', [':id' => $loanId]);
    $loans = $one ? [$one] : [];
    $total = count($loans);
    $counts = ['all' => $total] + ($one ? [$one['display_status'] => 1] : []);
    if (get('tab') === '') {
        $tab = $one && isset(LOAN_TABS[$one['display_status']]) ? $one['display_status'] : 'all';
    }
} else {
    $counts = [];
    foreach (array_keys(LOAN_TABS) as $key) {
        $counts[$key] = (int) q_val('SELECT total_count FROM fn_list_transactions(:s, :q, NULL, NULL, NULL, NULL, 1, 0)', [':s' => $key, ':q' => $q]);
    }
    [$loans, $total] = list_rows(
        'SELECT * FROM fn_list_transactions(:s, :q, NULL, NULL, NULL, NULL, :limit, :offset)',
        [':s' => $tab, ':q' => $q],
        $perPage,
        (int) $offset
    );
}
$finesByLoan = [];
if ($loans) {
    $ids = array_map(fn($l) => (int) $l['transaction_id'], $loans);
    foreach (q_all('SELECT transaction_id, fine_type, amount, status FROM v_fine_detail WHERE transaction_id = ANY (CAST(:ids AS int[])) ORDER BY fine_id', [':ids' => '{' . implode(',', $ids) . '}']) as $f) {
        $finesByLoan[(int) $f['transaction_id']][] = $f;
    }
}
$presets = message_presets(['reminder', 'overdue']);
$presetId = [];
foreach ($presets as $p) {
    $presetId[$p['kind']] = (int) $p['template_id'];
}

function loan_status_html(array $l): string
{
    return match ($l['display_status']) {
        'borrowed' => '<span class="chip chip-info">Borrowed</span><span class="sub">'
            . ((int) $l['days_left'] === 0 ? 'Due today' : 'Due in ' . plural((int) $l['days_left'], 'day')) . '</span>',
        'overdue' => '<span class="chip chip-bad">Overdue, ' . h(plural((int) $l['days_late'], 'day')) . '</span><span class="sub">Fine so far ' . h(peso($l['fine_so_far'])) . '</span>',
        'returned' => '<span class="chip chip-ok">Returned</span><span class="sub">' . h(fmt_date($l['date_returned'])) . '</span>',
        'lost' => '<span class="chip chip-bad">Lost</span>',
        default => '<span class="chip">' . h(ucfirst($l['display_status'])) . '</span>',
    };
}

$pageTitle = 'Issued Books';
$activeNav = 'issued';
$pageScripts = ['/assets/js/requests.js', '/assets/js/loans.js'];
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Issued Books</h1>
    <p class="lead">Every loan from the desk. Fines are worked out by the database when a book comes back.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="/librarian/issue.php"><?= icon('issue') ?> Issue Book</a></div>
</div>

<nav class="tabs" aria-label="Loan status">
  <?php foreach (LOAN_TABS as $key => $label): ?>
    <a class="tab" href="<?= h(url_with(['tab' => $key, 'page' => null, 'loan' => null])) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= h($label) ?> <span class="tab-count"><?= (int) ($counts[$key] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<form class="toolbar" method="get" action="/librarian/issued.php" role="search" data-live-search>
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <div class="field grow">
    <label for="q">Search</label>
    <div class="search-field"><?= icon('search') ?><input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Borrower, email or book" maxlength="100"></div>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Search</button></noscript>
</form>
<?php if ($loanId !== null): ?>
  <div class="alert alert-info"><?= icon('info') ?><p>Showing one loan. <a href="<?= h(url_with(['loan' => null])) ?>">Show all loans</a></p></div>
<?php endif; ?>

<?php if (!$loans): ?>
  <div class="empty"><?= icon('list') ?>
    <strong><?= $tab === 'overdue' ? 'Nothing is overdue' : 'No loans here' ?></strong>
    <p><?= $tab === 'borrowed' ? 'Books handed over at the desk show up here.' : 'Try another tab or clear the search.' ?></p>
  </div>
<?php else: ?>
  <div class="table-wrap" role="region" aria-label="Loans" tabindex="0">
    <table>
      <thead>
        <tr><th scope="col">Borrower</th><th scope="col">Book</th><th scope="col">Borrowed</th><th scope="col">Due</th>
            <th scope="col">Status</th><th scope="col" class="num">Fines</th><th scope="col"><span class="sr-only">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php foreach ($loans as $l):
            $active = in_array($l['status'], ['borrowed', 'overdue'], true);
            $who = $l['borrower_name'] . ' · ' . $l['book_title'];
            $fines = $finesByLoan[(int) $l['transaction_id']] ?? []; ?>
          <tr id="loan-<?= (int) $l['transaction_id'] ?>">
            <td><strong><?= h($l['borrower_name']) ?></strong>
              <span class="sub"><span class="chip chip-plain <?= $l['borrower_type'] === 'student' ? 'chip-info' : '' ?>"><?= $l['borrower_type'] === 'student' ? 'Student' : 'Teacher' ?></span></span></td>
            <td><?= h($l['book_title']) ?><span class="sub">Issued by <?= h($l['issued_by_name']) ?></span></td>
            <td class="nowrap"><?= h(fmt_date($l['date_borrowed'])) ?></td>
            <td class="nowrap"><?= h(fmt_date($l['due_date'])) ?></td>
            <td><?= loan_status_html($l) ?></td>
            <td class="num">
              <?php if ($active): ?><?= (float) $l['fine_so_far'] > 0 ? h(peso($l['fine_so_far'])) . '<span class="sub">so far</span>' : '<span class="muted">None</span>' ?>
              <?php elseif ($fines): ?>
                <?php foreach ($fines as $f): ?><span class="nowrap"><?= h(peso($f['amount'])) ?> <?= h($f['fine_type']) ?></span><span class="sub"><?= h(ucfirst($f['status'])) ?></span><?php endforeach; ?>
              <?php else: ?><span class="muted">None</span><?php endif; ?>
            </td>
            <td class="actions">
              <?php if ($active): ?>
                <button class="btn btn-primary btn-sm" type="button" data-modal-open="return-modal" data-fill="<?= h(json_encode([
                    'transaction_id' => (int) $l['transaction_id'], 'who' => $who, 'condition' => 'good', 'damage_amount' => '',
                    'r_due' => 'Due ' . fmt_date($l['due_date']) . ((int) $l['days_late'] > 0 ? ', ' . plural((int) $l['days_late'], 'day') . ' late' : ''),
                    'r_late' => (float) $l['fine_so_far'] > 0 ? 'An overdue fine of ' . peso($l['fine_so_far']) . ' will be added.' : '',
                    'r_cost' => peso($l['replacement_cost']),
                ], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('return') ?> Mark returned<span class="sr-only">: <?= h($who) ?></span></button>
                <?php if ($l['display_status'] === 'overdue'): ?>
                  <button class="btn btn-secondary btn-sm" type="button" data-modal-open="loan-message" data-fill="<?= h(json_encode(['transaction_id' => (int) $l['transaction_id'], 'who' => $who, 'template_id' => $presetId['overdue'] ?? ''], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('mail') ?> Send overdue notice<span class="sr-only"> to <?= h($l['borrower_name']) ?></span></button>
                <?php elseif ((int) $l['days_left'] <= 3): ?>
                  <button class="btn btn-secondary btn-sm" type="button" data-modal-open="loan-message" data-fill="<?= h(json_encode(['transaction_id' => (int) $l['transaction_id'], 'who' => $who, 'template_id' => $presetId['reminder'] ?? ''], JSON_UNESCAPED_UNICODE)) ?>"><?= icon('mail') ?> Send reminder<span class="sr-only"> to <?= h($l['borrower_name']) ?></span></button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= render_pager($total, $page, $perPage, 'loans') ?>

<dialog class="modal" id="return-modal" aria-labelledby="return-title">
  <form class="modal-form" method="post" action="/api/loan_return.php" novalidate data-return-form>
    <div class="modal-head">
      <div><h2 id="return-title">Mark returned</h2><p class="sub" data-fill-text="who"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body" data-return-ask>
      <?= csrf_field() ?>
      <input type="hidden" name="transaction_id">
      <div class="alert alert-error" data-form-error hidden role="alert"><?= icon('alert') ?><p></p></div>
      <p><span data-fill-text="r_due"></span>. <strong data-fill-show="r_late" data-fill-text="r_late"></strong></p>
      <fieldset class="radio-group">
        <legend>Condition</legend>
        <label class="radio-card"><input type="radio" name="condition" value="good" checked><span><strong>Good</strong><br><span class="small muted">The copy goes back on the shelf.</span></span></label>
        <label class="radio-card"><input type="radio" name="condition" value="damaged"><span><strong>Damaged</strong><br><span class="small muted">Back on the shelf, with a damage fine.</span></span></label>
        <label class="radio-card"><input type="radio" name="condition" value="lost"><span><strong>Lost</strong><br><span class="small muted">The copy is removed from the total, with a lost fine of <span data-fill-text="r_cost"></span> (replacement cost).</span></span></label>
      </fieldset>
      <div class="field" data-condition="damaged" hidden>
        <label for="rt-amount">Damage amount (₱)</label>
        <input id="rt-amount" name="damage_amount" type="text" inputmode="decimal" maxlength="12" placeholder="300.00">
        <p class="field-error" data-error-for="damage_amount" hidden></p>
      </div>
    </div>
    <div class="modal-body" data-return-done hidden>
      <div class="alert alert-success" role="status"><?= icon('check') ?><div><p data-return-message></p><ul data-return-fines></ul></div></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close data-return-cancel>Cancel</button>
      <button class="btn btn-primary" type="submit" data-return-submit>Mark returned</button>
      <button class="btn btn-primary" type="button" data-return-close hidden>Done</button>
    </div>
  </form>
</dialog>

<dialog class="modal modal-wide" id="loan-message" aria-labelledby="loan-message-title">
  <form class="modal-form" method="post" action="/api/loan_message.php" novalidate data-composer
        data-preview-url="/api/loan_preview.php" data-preview-fields="transaction_id template_id">
    <div class="modal-head">
      <div><h2 id="loan-message-title">Send a message about this loan</h2><p class="sub" data-fill-text="who"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="transaction_id">
      <div class="alert alert-error" data-form-error hidden role="alert"><?= icon('alert') ?><p></p></div>
      <div class="field">
        <label for="lm-preset">Message preset</label>
        <select id="lm-preset" name="template_id" data-preset>
          <?php foreach ($presets as $t): ?><option value="<?= (int) $t['template_id'] ?>"><?= h($t['label']) ?></option><?php endforeach; ?>
          <option value="own">Write my own</option>
        </select>
      </div>
      <div class="field">
        <label for="lm-subject">Subject <span class="req" aria-hidden="true">*</span></label>
        <input id="lm-subject" name="subject" type="text" maxlength="150" required>
        <p class="field-error" data-error-for="subject" hidden></p>
      </div>
      <div class="field">
        <label for="lm-body">Message <span class="req" aria-hidden="true">*</span></label>
        <textarea id="lm-body" name="body" maxlength="1000" required rows="6"></textarea>
        <div class="row row-between">
          <p class="field-hint" data-composer-status aria-live="polite">Plain text. You can edit it freely.</p>
          <p class="char-count" data-count-for="lm-body"></p>
        </div>
        <p class="field-error" data-error-for="body" hidden></p>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit" data-send><?= icon('send') ?> Send message</button>
    </div>
  </form>
</dialog>
<?php require APP_SRC . '/partials/footer.php'; ?>
