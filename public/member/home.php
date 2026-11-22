<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/partials/stat_card.php';
require_once APP_SRC . '/member.php';

$me = require_role(['student', 'teacher']);
$scope = member_scope($me);
refresh_statuses();

$status = q_row("SELECT * FROM v_borrower_status WHERE {$scope['col']} = :id", [':id' => $scope['id']]);
$unread = (int) q_val("SELECT count(*) FROM v_inbox WHERE {$scope['msg']} = :id AND NOT is_read", [':id' => $scope['id']]);
$dueSoon = q_all(
    "SELECT * FROM v_active_loans WHERE {$scope['col']} = :id AND (display_status = 'overdue' OR days_left <= 7) ORDER BY due_date",
    [':id' => $scope['id']]
);
$pickups = q_all(
    "SELECT * FROM v_request_detail WHERE {$scope['col']} = :id AND status = 'approved' ORDER BY pickup_date",
    [':id' => $scope['id']]
);

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$pageTitle = 'Home';
$activeNav = 'home';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= h($greeting) ?>, <?= h($me['first_name']) ?></h1>
    <p class="lead"><?= h(date('l')) ?>, <?= h(fmt_date(today())) ?> · You can have <?= (int) $status['max_loans'] ?> books and requests at a time; loans last <?= (int) $status['loan_days'] ?> days.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="/member/catalog.php"><?= icon('search') ?> Find a book</a></div>
</div>

<h2 class="sr-only">Your library at a glance</h2>
<ul class="stat-grid is-compact">
  <li><?= stat_card(['label' => 'Borrowed now', 'value' => $status['active_loans'], 'href' => '/member/borrowed.php',
      'note' => (int) $status['overdue_loans'] > 0 ? plural((int) $status['overdue_loans'], 'book') . ' overdue' : 'All on time',
      'from' => '#011f4b', 'to' => '#03396c', 'icon' => 'list', 'art' => 'open', 'index' => 0]) ?></li>
  <li><?= stat_card(['label' => 'Awaiting pickup', 'value' => $status['awaiting_pickup'], 'href' => '/member/requests.php?tab=approved',
      'note' => 'Ready at the library desk', 'from' => '#005b96', 'to' => '#011f4b', 'icon' => 'clock', 'art' => 'bookmark', 'index' => 1]) ?></li>
  <li><?= stat_card(['label' => 'Unread messages', 'value' => $unread, 'href' => '/member/messages.php',
      'note' => $unread > 0 ? 'Open them on Messages' : 'You are all caught up', 'from' => '#6497b1', 'to' => '#b3cde0', 'icon' => 'mail', 'art' => 'stack', 'index' => 2]) ?></li>
  <li><?= stat_card(['label' => 'Fines to pay', 'value' => $status['unpaid_fines'], 'prefix' => '₱', 'decimals' => 2, 'href' => '/member/borrowed.php#fines',
      'note' => (float) $status['unpaid_fines'] > 0 ? 'Pay at the library desk' : 'Nothing to pay', 'from' => '#03396c', 'to' => '#005b96', 'icon' => 'coins', 'art' => 'leaning', 'index' => 3]) ?></li>
</ul>

<?php
$blocks = [];
if ((float) $status['unpaid_fines'] > 0) {
    $blocks[] = 'your fine of ' . peso($status['unpaid_fines']) . ' is paid';
}
if ((int) $status['overdue_loans'] > 0) {
    $blocks[] = (int) $status['overdue_loans'] === 1 ? 'your overdue book is returned' : 'your overdue books are returned';
}
if ($blocks): ?>
  <div class="alert alert-warning" role="status"><?= icon('alert') ?>
    <p>You cannot request new books until <?= h(implode(' and ', $blocks)) ?>. <?= count($blocks) > 1 ? 'Both are done' : 'This is done' ?> at the library desk.</p></div>
<?php endif; ?>

<div class="grid-2">
  <section class="card" aria-labelledby="due-title">
    <h2 class="card-title" id="due-title">Due soon</h2>
    <?php if (!$dueSoon): ?>
      <div class="empty"><?= icon('check') ?><strong>Nothing is due this week</strong><p>Books due in the next 7 days show up here.</p></div>
    <?php else: ?>
      <ul class="plain-list attention-list">
        <?php foreach ($dueSoon as $l): ?>
          <li class="<?= $l['display_status'] === 'overdue' ? 'is-urgent' : '' ?>"><?= icon($l['display_status'] === 'overdue' ? 'alert' : 'clock') ?>
            <span style="flex:1"><a href="/member/borrowed.php#loan-<?= (int) $l['transaction_id'] ?>"><?= h($l['book_title']) ?></a>
              <span class="sub">Due <?= h(fmt_date($l['due_date'])) ?><?= (float) $l['fine_so_far'] > 0 ? ' · fine so far ' . h(peso($l['fine_so_far'])) : '' ?></span></span>
            <span><?= due_chip($l) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="pickup-title">
    <h2 class="card-title" id="pickup-title">Ready for pickup</h2>
    <?php if (!$pickups): ?>
      <div class="empty"><?= icon('books') ?><strong>Nothing to pick up</strong><p>When the library approves a request, the pickup date and time show up here.</p></div>
    <?php else: ?>
      <ul class="plain-list attention-list">
        <?php foreach ($pickups as $r): ?>
          <li><?= icon('calendar') ?>
            <span><strong><?= h($r['book_title']) ?></strong>
              <span class="sub">Pick it up on <?= h(fmt_date($r['pickup_date'])) ?> at <?= h(fmt_time($r['pickup_time'])) ?>. Held until <?= h(fmt_date($r['pickup_deadline'])) ?>. Bring your school ID.</span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<?php require APP_SRC . '/partials/footer.php'; ?>
