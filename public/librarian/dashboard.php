<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/partials/stat_card.php';
require_once APP_SRC . '/activity.php';

$me = require_role(['librarian', 'admin']);

// Keep the numbers fresh even without a scheduled job: mark late loans overdue and expire old reservations.
tx(function (PDO $pdo) {
    $pdo->exec('CALL sp_mark_overdue()');
    $pdo->exec('CALL sp_expire_reservations()');
});

$stats = q_row('SELECT * FROM v_dashboard_stats');

// Needs attention: each line links straight to the record.
$oldPending = q_all(
    "SELECT request_id, borrower_name, book_title, date_requested, current_date - date_requested::date AS days_waiting
       FROM v_request_detail
      WHERE status = 'pending' AND date_requested < now() - interval '2 days'
      ORDER BY date_requested"
);
$pickupsDue = q_all(
    "SELECT request_id, borrower_name, book_title, pickup_deadline, pickup_deadline = current_date AS is_today
       FROM v_request_detail
      WHERE status = 'approved' AND pickup_deadline BETWEEN current_date AND current_date + 1
      ORDER BY pickup_deadline, request_id"
);
$overdue = q_all(
    "SELECT transaction_id, borrower_name, book_title, due_date, days_late, fine_so_far
       FROM v_active_loans
      WHERE display_status = 'overdue'
      ORDER BY days_late DESC, transaction_id"
);
$emptyShelves = q_all(
    "SELECT book_id, book_title, count(*) AS waiting
       FROM v_request_detail
      WHERE status = 'pending' AND copies_available = 0
      GROUP BY book_id, book_title
      ORDER BY book_title"
);
$attentionCount = count($oldPending) + count($pickupsDue) + count($overdue) + count($emptyShelves);

$activity = activity_rows(['meaningful' => true], 8);

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$cards = [
    'total_titles' => ['value' => $stats['total_titles'], 'href' => '/librarian/books.php',
        'note' => plural((int) $stats['total_copies'], 'copy', 'copies') . ' in the library'],
    'copies_on_loan' => ['value' => $stats['copies_on_loan'], 'href' => '/librarian/issued.php',
        'note' => plural((int) $stats['copies_available'], 'copy', 'copies') . ' on the shelf'],
    'awaiting_pickup' => ['value' => $stats['awaiting_pickup'], 'href' => '/librarian/requests.php?tab=approved',
        'note' => 'Reserved, not picked up yet'],
    'pending_requests' => ['value' => $stats['pending_requests'], 'href' => '/librarian/requests.php?tab=pending',
        'note' => 'Waiting for a decision'],
    'overdue_loans' => ['value' => $stats['overdue_loans'], 'href' => '/librarian/issued.php?tab=overdue',
        'note' => 'Past their due date'],
    'unpaid_fines' => ['value' => $stats['unpaid_fines_total'], 'href' => '/librarian/fines.php', 'prefix' => '₱', 'decimals' => 2,
        'note' => plural((int) $stats['unpaid_fines_count'], 'fine') . ' to collect'],
];

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= h($greeting) ?>, <?= h($me['first_name']) ?></h1>
    <p class="lead"><?= h(date('l')) ?>, <?= h(fmt_date(today())) ?> · <?= h(plural((int) $stats['active_members'], 'active member')) ?></p>
  </div>
</div>

<h2 class="sr-only">Library at a glance</h2>
<ul class="stat-grid grid-six is-staggered">
  <?php $i = 0; foreach (dashboard_card_styles() as $key => $style): ?>
    <li><?= stat_card($style + $cards[$key] + ['index' => $i++]) ?></li>
  <?php endforeach; ?>
</ul>

<div class="grid-2">
  <section class="card" aria-labelledby="attention-title">
    <div class="section-head">
      <h2 id="attention-title" class="card-title">Needs attention</h2>
      <?php if ($attentionCount > 0): ?><span class="chip chip-warn"><?= h(plural($attentionCount, 'item')) ?></span><?php endif; ?>
    </div>
    <?php if ($attentionCount === 0): ?>
      <div class="empty"><?= icon('check') ?><strong>Nothing needs attention</strong><p>Old requests, pickups due soon and overdue books show up here.</p></div>
    <?php else: ?>
      <ul class="plain-list attention-list">
        <?php foreach ($overdue as $loan): ?>
          <li class="is-urgent"><?= icon('alert') ?>
            <span><a href="/librarian/issued.php?tab=overdue#loan-<?= (int) $loan['transaction_id'] ?>"><?= h($loan['borrower_name']) ?>: <?= h($loan['book_title']) ?></a>
              is <?= h(plural((int) $loan['days_late'], 'day')) ?> late
              <span class="sub muted">Due <?= h(fmt_date($loan['due_date'])) ?> · fine so far <?= h(peso($loan['fine_so_far'])) ?></span></span>
          </li>
        <?php endforeach; ?>
        <?php foreach ($pickupsDue as $r): ?>
          <li><?= icon('clock') ?>
            <span><a href="/librarian/requests.php?tab=approved#request-<?= (int) $r['request_id'] ?>"><?= h($r['borrower_name']) ?>: <?= h($r['book_title']) ?></a>
              must be picked up by <?= $r['is_today'] ? 'today' : 'tomorrow' ?>
              <span class="sub muted">Pickup deadline <?= h(fmt_date($r['pickup_deadline'])) ?>, then the copy goes back on the shelf</span></span>
          </li>
        <?php endforeach; ?>
        <?php foreach ($oldPending as $r): ?>
          <li><?= icon('inbox') ?>
            <span><a href="/librarian/requests.php?tab=pending#request-<?= (int) $r['request_id'] ?>"><?= h($r['borrower_name']) ?>: <?= h($r['book_title']) ?></a>
              has waited <?= h(plural((int) $r['days_waiting'], 'day')) ?> for a decision
              <span class="sub muted">Requested <?= h(fmt_datetime($r['date_requested'])) ?></span></span>
          </li>
        <?php endforeach; ?>
        <?php foreach ($emptyShelves as $b): ?>
          <li><?= icon('books') ?>
            <span><a href="/librarian/requests.php?tab=pending&amp;book=<?= (int) $b['book_id'] ?>"><?= h($b['book_title']) ?></a>
              has no copy on the shelf and <?= h(plural((int) $b['waiting'], 'pending request')) ?>
              <span class="sub muted">Approve when a copy comes back, or reject with the All copies on loan preset</span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="activity-title">
    <div class="section-head">
      <h2 id="activity-title" class="card-title">Recent activity</h2>
      <?php if (is_admin($me)): ?><a class="small" href="/librarian/activity.php">See all activity</a><?php endif; ?>
    </div>
    <?php if (!$activity): ?>
      <div class="empty"><?= icon('activity') ?><strong>No activity yet</strong><p>Approvals, loans, returns and fines show up here as they happen.</p></div>
    <?php else: ?>
      <ul class="plain-list activity-list">
        <?php foreach ($activity as $row): ?>
          <li><?= icon(activity_icon($row)) ?>
            <span><?= h(activity_sentence($row)) ?><span class="sub muted"><time datetime="<?= h(date('c', strtotime($row['created_at']))) ?>"><?= h(time_ago($row['created_at'])) ?></time></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<?php require APP_SRC . '/partials/footer.php'; ?>
