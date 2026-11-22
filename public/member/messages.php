<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/member.php';

$me = require_role(['student', 'teacher']);
$scope = member_scope($me);

// Opening a message marks it read through fn_mark_message_read, which only touches the member's own messages.
$openId = int_param('open');
$open = null;
if ($openId !== null) {
    $open = q_row("SELECT * FROM v_inbox WHERE message_id = :m AND {$scope['msg']} = :id", [':m' => $openId, ':id' => $scope['id']]);
    if ($open && !$open['is_read']) {
        tx(function (PDO $pdo) use ($openId, $scope) {
            $pdo->prepare('SELECT fn_mark_message_read(:m, :sid, :tid)')
                ->execute([':m' => $openId, ':sid' => $scope['student_id'], ':tid' => $scope['teacher_id']]);
        });
        $open['is_read'] = true;
    }
}

// Unread first, newest first.
$messages = q_all(
    "SELECT message_id, kind, subject, body, sent_at, read_at, is_read, sender_name FROM v_inbox
      WHERE {$scope['msg']} = :id ORDER BY is_read, sent_at DESC, message_id DESC LIMIT 100",
    [':id' => $scope['id']]
);
if ($open) {
    foreach ($messages as &$m) {
        if ((int) $m['message_id'] === (int) $open['message_id']) {
            $m['is_read'] = true;
        }
    }
    unset($m);
}
$unread = count(array_filter($messages, fn($m) => !$m['is_read']));

$pageTitle = 'Messages';
$activeNav = 'messages';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Messages</h1>
    <p class="lead"><?= $unread > 0 ? h(plural($unread, 'unread message')) . '. Unread messages are in bold.' : 'Messages from the library about your requests and loans.' ?></p>
  </div>
</div>

<?php if ($openId !== null && !$open): ?>
  <div class="alert alert-error" role="alert"><?= icon('alert') ?><p>That message is not in your inbox.</p></div>
<?php endif; ?>

<div class="inbox<?= $open ? ' has-open' : '' ?>">
  <section aria-label="Inbox">
    <?php if (!$messages): ?>
      <div class="empty"><?= icon('mail') ?><strong>No messages yet</strong><p>When the library approves or rejects a request, or sends a reminder, it shows up here.</p></div>
    <?php else: ?>
      <ul class="plain-list message-list">
        <?php foreach ($messages as $m): ?>
          <li>
            <a class="message-item<?= $m['is_read'] ? '' : ' is-unread' ?>" href="/member/messages.php?open=<?= (int) $m['message_id'] ?>"<?= $open && (int) $open['message_id'] === (int) $m['message_id'] ? ' aria-current="true"' : '' ?>>
              <span class="message-subject"><?= $m['is_read'] ? '' : '<span class="unread-dot" aria-hidden="true"></span><span class="sr-only">Unread: </span>' ?><?= h($m['subject']) ?></span>
              <span class="sub"><?= h($m['sender_name']) ?> · <?= h(time_ago($m['sent_at'])) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($open): ?>
    <article class="card message-open" aria-labelledby="open-subject">
      <h2 id="open-subject"><?= h($open['subject']) ?></h2>
      <p class="muted small">From <?= h($open['sender_name']) ?> · <?= h(fmt_datetime($open['sent_at'])) ?></p>
      <?php if ($open['kind'] === 'request_approved' && $open['request_status'] === 'approved'): ?>
        <div class="pickup-card"><?= icon('calendar') ?>
          <div><strong>Pick up <?= h($open['book_title']) ?> on <?= h(fmt_date($open['pickup_date'])) ?> at <?= h(fmt_time($open['pickup_time'])) ?></strong>
            <span class="sub">Held until <?= h(fmt_date($open['pickup_deadline'])) ?>. Bring your school ID.</span></div></div>
      <?php endif; ?>
      <p class="message-text"><?= h($open['body']) ?></p>
      <p><a class="btn btn-secondary btn-sm" href="/member/messages.php">Back to all messages</a></p>
    </article>
  <?php endif; ?>
</div>
<?php require APP_SRC . '/partials/footer.php'; ?>
