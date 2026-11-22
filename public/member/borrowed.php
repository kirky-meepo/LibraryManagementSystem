<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/member.php';

$me = require_role(['student', 'teacher']);
$scope = member_scope($me);
refresh_statuses();

// Always your own id from the session (fn_list_transactions and fn_list_fines in 04c_crud.sql).
$mine = [':sid' => $scope['student_id'], ':tid' => $scope['teacher_id']];
$current = q_all("SELECT * FROM fn_list_transactions('active', NULL, NULL, NULL, :sid, :tid, 100, 0)", $mine);
$past = q_all("SELECT * FROM fn_list_transactions('past', NULL, NULL, NULL, :sid, :tid, 20, 0)", $mine);
$fines = q_all('SELECT * FROM fn_list_fines(NULL, NULL, :sid, :tid, 200, 0)', $mine);
$totals = q_row(
    "SELECT coalesce(sum(amount) FILTER (WHERE status = 'unpaid'), 0) AS unpaid, coalesce(sum(amount) FILTER (WHERE status = 'paid'), 0) AS paid
       FROM v_fine_detail WHERE {$scope['col']} = :id",
    [':id' => $scope['id']]
);

$pageTitle = 'My Borrowed Books';
$activeNav = 'borrowed';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>My Borrowed Books</h1>
    <p class="lead">Return books and pay fines at the library desk.</p>
  </div>
</div>

<section aria-labelledby="now-title">
  <h2 id="now-title">Borrowed now</h2>
  <?php if (!$current): ?>
    <div class="empty"><?= icon('books') ?><strong>You have no books out</strong><p>Request a book in the Catalog, then pick it up at the library desk.</p></div>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Books borrowed now" tabindex="0">
      <table>
        <thead><tr><th scope="col">Book</th><th scope="col">Borrowed</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col" class="num">Fine so far</th></tr></thead>
        <tbody>
          <?php foreach ($current as $l): ?>
            <tr id="loan-<?= (int) $l['transaction_id'] ?>">
              <td><strong><?= h($l['book_title']) ?></strong><span class="sub"><?= h($l['book_author']) ?></span></td>
              <td class="nowrap"><?= h(fmt_date($l['date_borrowed'])) ?></td>
              <td class="nowrap"><?= h(fmt_date($l['due_date'])) ?></td>
              <td><?= due_chip($l) ?></td>
              <td class="num"><?= (float) $l['fine_so_far'] > 0 ? '<strong>' . h(peso($l['fine_so_far'])) . '</strong>' : '<span class="muted">None</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small muted">A late book adds <?= h(peso(setting('fine_per_day', '10'))) ?> a day. The fine is charged when you return it.</p>
  <?php endif; ?>
</section>

<section class="section" aria-labelledby="fines-title" id="fines">
  <div class="section-head"><h2 id="fines-title">Fines</h2>
    <span class="row"><span class="chip <?= (float) $totals['unpaid'] > 0 ? 'chip-bad' : 'chip-ok' ?>">Unpaid <?= h(peso($totals['unpaid'])) ?></span><span class="chip">Paid <?= h(peso($totals['paid'])) ?></span></span></div>
  <?php if ((float) $totals['unpaid'] > 0): ?>
    <div class="alert alert-warning" role="status"><?= icon('alert') ?><p>Pay <?= h(peso($totals['unpaid'])) ?> at the library desk. You cannot request new books until it is paid.</p></div>
  <?php endif; ?>
  <?php if (!$fines): ?>
    <div class="empty"><?= icon('check') ?><strong>No fines</strong><p>Return books on time and in good condition to keep it that way.</p></div>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Fines" tabindex="0">
      <table>
        <thead><tr><th scope="col">Book</th><th scope="col">Reason</th><th scope="col" class="num">Amount</th><th scope="col">Issued</th><th scope="col">Status</th></tr></thead>
        <tbody>
          <?php foreach ($fines as $f): ?>
            <tr>
              <td><?= h($f['book_title']) ?></td>
              <td><?= h(match ($f['fine_type']) { 'overdue' => 'Returned late', 'lost' => 'Lost book', 'damaged' => 'Damaged book', default => ucfirst($f['fine_type']) }) ?></td>
              <td class="num"><strong><?= h(peso($f['amount'])) ?></strong></td>
              <td class="nowrap"><?= h(fmt_date($f['date_issued'])) ?></td>
              <td><?= match ($f['status']) {
                  'unpaid' => '<span class="chip chip-bad">Unpaid</span>',
                  'paid' => '<span class="chip chip-ok">Paid</span><span class="sub">' . h(fmt_date($f['date_paid'])) . '</span>',
                  default => '<span class="chip">Waived</span>',
              } ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($past): ?>
  <section class="section" aria-labelledby="past-title">
    <h2 id="past-title">Returned books</h2>
    <div class="table-wrap" role="region" aria-label="Returned books" tabindex="0">
      <table>
        <thead><tr><th scope="col">Book</th><th scope="col">Borrowed</th><th scope="col">Returned</th></tr></thead>
        <tbody>
          <?php foreach ($past as $l): ?>
            <tr><td><?= h($l['book_title']) ?></td><td class="nowrap"><?= h(fmt_date($l['date_borrowed'])) ?></td>
              <td class="nowrap"><?= $l['status'] === 'lost' ? '<span class="chip chip-bad">Lost</span>' : h(fmt_date($l['date_returned'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
<?php require APP_SRC . '/partials/footer.php'; ?>
