<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/requests.php';
require_once APP_SRC . '/member.php';

$me = require_role(['student', 'teacher']);
$scope = member_scope($me);

// Cancel: fn_cancel_request checks that the request belongs to this member (an id typed into a form is not trusted).
if (is_post()) {
    $requestId = int_param('request_id', 'post');
    try {
        tx(function (PDO $pdo) use ($requestId, $me) {
            $st = $pdo->prepare('SELECT fn_cancel_request(:r, :type, :id, NULL)');
            $st->execute([':r' => $requestId, ':type' => $me['type'], ':id' => $me['id']]);
        });
        flash('success', 'Your request is cancelled.');
    } catch (PDOException $e) {
        flash('error', member_words(db_message($e), $me));
    }
    redirect(current_url());
}

refresh_statuses();

const MEMBER_TABS = ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Ready for pickup', 'closed' => 'Past'];
$tab = isset(MEMBER_TABS[get('tab')]) ? get('tab') : 'all';
$rows = q_all(
    "SELECT * FROM v_request_detail WHERE {$scope['col']} = :id ORDER BY date_requested DESC, request_id DESC",
    [':id' => $scope['id']]
);
$counts = ['all' => count($rows), 'pending' => 0, 'approved' => 0, 'closed' => 0];
foreach ($rows as $r) {
    $counts[in_array($r['status'], ['pending', 'approved'], true) ? $r['status'] : 'closed']++;
}
$rows = array_values(array_filter($rows, fn($r) => match ($tab) {
    'pending', 'approved' => $r['status'] === $tab,
    'closed' => !in_array($r['status'], ['pending', 'approved'], true),
    default => true,
}));

/** Status chip with the details a member needs. */
function member_request_status(array $r): string
{
    return match ($r['status']) {
        'pending' => '<span class="chip chip-warn">Pending</span><span class="sub">Waiting for the library to decide</span>',
        'approved' => '<span class="chip chip-info">Approved</span>',
        'rejected' => '<span class="chip chip-bad">Rejected</span>',
        'fulfilled' => '<span class="chip chip-ok">Fulfilled</span><span class="sub">You picked it up</span>',
        'expired' => '<span class="chip">Expired</span><span class="sub">Not picked up by ' . h(fmt_date($r['pickup_deadline'])) . '</span>',
        'cancelled' => '<span class="chip">Cancelled</span>' . ($r['decision_note'] ? '<span class="sub">' . h($r['decision_note']) . '</span>' : ''),
        default => '<span class="chip">' . h(ucfirst($r['status'])) . '</span>',
    };
}

$pageTitle = 'My Requests';
$activeNav = 'requests';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>My Requests</h1>
    <p class="lead">Every book you asked for, and what the library decided.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="/member/catalog.php"><?= icon('search') ?> Find a book</a></div>
</div>

<nav class="tabs" aria-label="Request status">
  <?php foreach (MEMBER_TABS as $key => $label): ?>
    <a class="tab" href="/member/requests.php?tab=<?= h($key) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= h($label) ?> <span class="tab-count"><?= (int) $counts[$key] ?></span></a>
  <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
  <div class="empty"><?= icon('inbox') ?><strong><?= $tab === 'all' ? 'You have not requested any books yet' : 'Nothing here' ?></strong>
    <p>Find a book in the Catalog and choose Request this book.</p></div>
<?php else: ?>
  <ul class="request-list plain-list">
    <?php foreach ($rows as $r): ?>
      <li class="card request-card" id="request-<?= (int) $r['request_id'] ?>">
        <div class="request-main">
          <h2 class="book-title"><?= h($r['book_title']) ?></h2>
          <p class="muted small"><?= h($r['book_author']) ?> · requested <?= h(fmt_datetime($r['date_requested'])) ?></p>
          <?php if ($r['status'] === 'approved'): ?>
            <div class="pickup-card">
              <?= icon('calendar') ?>
              <div><strong>Pick it up on <?= h(fmt_date($r['pickup_date'])) ?> at <?= h(fmt_time($r['pickup_time'])) ?></strong>
                <span class="sub">We hold it until <?= h(fmt_date($r['pickup_deadline'])) ?>. Bring your school ID to the library desk.</span></div>
            </div>
          <?php elseif ($r['status'] === 'rejected'): ?>
            <p class="alert alert-error"><?= icon('info') ?><span>Reason: <?= h($r['decision_note']) ?></span></p>
          <?php endif; ?>
          <?php if ($r['message_id']): ?>
            <p class="small"><a href="/member/messages.php?open=<?= (int) $r['message_id'] ?>">Read the library's message</a><?= $r['message_read_at'] ? '' : ' <span class="chip chip-info chip-plain">New</span>' ?></p>
          <?php endif; ?>
        </div>
        <div class="request-side">
          <?= member_request_status($r) ?>
          <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?>
            <form method="post" action="/member/requests.php?tab=<?= h($tab) ?>"
                  data-confirm="<?= h('Cancel your request for ' . $r['book_title'] . '?' . ($r['status'] === 'approved' ? ' The copy held for you goes back on the shelf.' : '')) ?>" data-confirm-label="Cancel request">
              <?= csrf_field() ?>
              <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
              <button class="btn btn-secondary btn-sm" type="submit">Cancel request<span class="sr-only"> for <?= h($r['book_title']) ?></span></button>
            </form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php require APP_SRC . '/partials/footer.php'; ?>
