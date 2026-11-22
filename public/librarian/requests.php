<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/requests.php';
require_once APP_SRC . '/partials/pager.php';

$me = require_role(['librarian', 'admin']);

// Expired reservations go back on the shelf before the list is shown.
tx(fn(PDO $pdo) => $pdo->exec('CALL sp_expire_reservations()'));

const REQUEST_TABS = [
    'pending' => ['label' => 'Pending', 'statuses' => ['pending']],
    'approved' => ['label' => 'Awaiting pickup', 'statuses' => ['approved']],
    'fulfilled' => ['label' => 'Fulfilled', 'statuses' => ['fulfilled']],
    'rejected' => ['label' => 'Rejected', 'statuses' => ['rejected']],
    'expired' => ['label' => 'Expired', 'statuses' => ['expired']],
    'all' => ['label' => 'All', 'statuses' => null],
];
$tab = isset(REQUEST_TABS[get('tab')]) ? get('tab') : 'pending';
$bookId = int_param('book');
$book = $bookId !== null ? q_row('SELECT book_id, title FROM fn_get_book(:id)', [':id' => $bookId]) : null;
$q = mb_substr(get('q'), 0, 100);
$perPage = 15;
[$page, $offset] = page_offset($perPage);

$base = "(CAST(:book AS int) IS NULL OR book_id = CAST(:book2 AS int))
         AND (:q = '' OR borrower_name ILIKE :q1 OR borrower_email ILIKE :q2 OR book_title ILIKE :q3)";
$baseParams = [':book' => $book['book_id'] ?? null, ':book2' => $book['book_id'] ?? null,
               ':q' => $q, ':q1' => like_contains($q), ':q2' => like_contains($q), ':q3' => like_contains($q)];

$counts = ['all' => 0];
foreach (q_all("SELECT status, count(*) AS n FROM v_request_detail WHERE $base GROUP BY status", $baseParams) as $row) {
    $counts[$row['status']] = (int) $row['n'];
    $counts['all'] += (int) $row['n'];
}

$where = $base;
$params = $baseParams;
if (REQUEST_TABS[$tab]['statuses'] !== null) {
    $where .= ' AND status = :status';
    $params[':status'] = REQUEST_TABS[$tab]['statuses'][0];
}
$order = $tab === 'pending' ? 'date_requested ASC' : ($tab === 'approved' ? 'pickup_deadline ASC, request_id' : 'coalesce(date_decided, date_requested) DESC, request_id DESC');
$total = (int) q_val("SELECT count(*) FROM v_request_detail WHERE $where", $params);
$rows = q_all("SELECT * FROM v_request_detail WHERE $where ORDER BY $order LIMIT $perPage OFFSET " . (int) $offset, $params);

$approvePresets = message_presets(['request_approved']);
$rejectPresets = message_presets(['request_rejected']);
$presetIdByLabel = [];
foreach ($rejectPresets as $p) {
    $presetIdByLabel[$p['label']] = (int) $p['template_id'];
}
$lastCopyId = null;
$standardId = null;
foreach ($approvePresets as $p) {
    if ($p['label'] === 'Last copy') { $lastCopyId = (int) $p['template_id']; }
    if ($p['label'] === 'Standard pickup') { $standardId = (int) $p['template_id']; }
}
$hours = library_hours();
$pickup = default_pickup();

$pageTitle = 'Requests';
$activeNav = 'requests';
$pageScripts = ['/assets/js/requests.js'];
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Requests</h1>
    <p class="lead">Approve or reject each request with a message; the borrower gets it right away.</p>
  </div>
</div>

<?php if ($book): ?>
  <div class="alert alert-info"><?= icon('books') ?><p>Showing requests for <strong><?= h($book['title']) ?></strong>. <a href="<?= h(url_with(['book' => null, 'page' => null])) ?>">Show all books</a></p></div>
<?php endif; ?>

<nav class="tabs" aria-label="Request status">
  <?php foreach (REQUEST_TABS as $key => $t): ?>
    <a class="tab" href="<?= h(url_with(['tab' => $key, 'page' => null])) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>>
      <?= h($t['label']) ?> <span class="tab-count"><?= (int) ($counts[$key] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<form class="toolbar" method="get" action="/librarian/requests.php" role="search" data-live-search>
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <?php if ($book): ?><input type="hidden" name="book" value="<?= (int) $book['book_id'] ?>"><?php endif; ?>
  <div class="field grow">
    <label for="q">Search</label>
    <div class="search-field"><?= icon('search') ?><input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Borrower, email or book" maxlength="100"></div>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Search</button></noscript>
</form>

<?php if (!$rows): ?>
  <div class="empty"><?= icon('inbox') ?>
    <?php if ($tab === 'pending'): ?><strong>No pending requests</strong><p>New requests show up here.</p>
    <?php elseif ($tab === 'approved'): ?><strong>Nothing is waiting for pickup</strong><p>Approved requests stay here until the book is issued, cancelled or the hold runs out.</p>
    <?php else: ?><strong>No requests here</strong><p>Try another tab<?= $q !== '' ? ' or clear the search' : '' ?>.</p><?php endif; ?>
  </div>
<?php else: ?>
  <div class="table-wrap" role="region" aria-label="Requests" tabindex="0">
    <table>
      <thead>
        <tr>
          <th scope="col">Borrower</th>
          <th scope="col">Book</th>
          <th scope="col">Requested</th>
          <th scope="col" class="num">Copies left</th>
          <th scope="col">Borrower's record</th>
          <th scope="col">Status</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
            $decide = [
                'request_id' => (int) $r['request_id'],
                'who' => $r['borrower_name'] . ' · ' . $r['book_title'],
                'first' => $r['borrower_first_name'],
                'copies' => (int) $r['copies_available'],
            ];
            $approveFill = $decide + ['template_id' => ((int) $r['copies_available'] === 1 && $lastCopyId) ? $lastCopyId : $standardId,
                                      'pickup_date' => $pickup['date'], 'pickup_time' => $pickup['time']];
            $suggest = suggested_rejection($r);
            $rejectFill = $decide + ['template_id' => $presetIdByLabel[$suggest] ?? '', 'reason' => $suggest];
            $unpaid = (float) $r['borrower_unpaid_fines']; ?>
          <tr id="request-<?= (int) $r['request_id'] ?>">
            <td><strong><?= h($r['borrower_name']) ?></strong>
              <span class="sub"><span class="chip chip-plain <?= $r['borrower_type'] === 'student' ? 'chip-info' : '' ?>"><?= $r['borrower_type'] === 'student' ? 'Student' : 'Teacher' ?></span></span></td>
            <td><?= h($r['book_title']) ?><span class="sub"><?= h($r['book_author']) ?></span></td>
            <td class="nowrap"><?= h(fmt_date($r['date_requested'])) ?><span class="sub"><?= h(time_ago($r['date_requested'])) ?></span></td>
            <td class="num"><?= (int) $r['copies_available'] ?> of <?= (int) $r['copies_total'] ?></td>
            <td>
              <span class="nowrap"><?= (int) $r['borrower_active_loans'] ?> of <?= (int) $r['borrower_max_loans'] ?> loans</span>
              <?php if ($unpaid > 0): ?><span class="sub"><span class="chip chip-bad">Unpaid <?= h(peso($unpaid)) ?></span></span><?php endif; ?>
              <?php if ((int) $r['borrower_overdue_loans'] > 0): ?><span class="sub"><span class="chip chip-bad"><?= h(plural((int) $r['borrower_overdue_loans'], 'overdue book')) ?></span></span><?php endif; ?>
            </td>
            <td><?= request_status_html($r) ?>
              <?php if ($r['message_id']): ?>
                <span class="sub"><button class="btn btn-link btn-sm" type="button" data-modal-open="message-view" data-fill="<?= h(json_encode([
                    'm_subject' => $r['message_subject'], 'm_body' => $r['message_body'],
                    'm_sent' => 'Sent ' . fmt_datetime($r['message_sent_at']) . ($r['decided_by_name'] ? ' by ' . $r['decided_by_name'] : '') . ' to ' . $r['borrower_name'],
                    'm_read' => $r['message_read_at'] ? 'Read ' . fmt_datetime($r['message_read_at']) : '',
                    'm_unread' => $r['message_read_at'] ? '' : 'Unread',
                ], JSON_UNESCAPED_UNICODE)) ?>">View message</button>
                <?= $r['message_read_at'] ? '<span class="chip chip-ok">Read</span>' : '<span class="chip">Unread</span>' ?></span>
              <?php endif; ?>
            </td>
            <td class="actions">
              <?php if ($r['status'] === 'pending'): ?>
                <button class="btn btn-primary btn-sm" type="button" data-modal-open="approve-modal" data-fill="<?= h(json_encode($approveFill, JSON_UNESCAPED_UNICODE)) ?>"><?= icon('check') ?> Approve<span class="sr-only"> <?= h($r['borrower_name']) ?>'s request for <?= h($r['book_title']) ?></span></button>
                <button class="btn btn-secondary btn-sm" type="button" data-modal-open="reject-modal" data-fill="<?= h(json_encode($rejectFill, JSON_UNESCAPED_UNICODE)) ?>"><?= icon('x') ?> Reject<span class="sr-only"> <?= h($r['borrower_name']) ?>'s request for <?= h($r['book_title']) ?></span></button>
              <?php elseif ($r['status'] === 'approved'): ?>
                <form method="post" action="/librarian/issue.php" class="inline-form"
                      data-confirm="<?= h('Issue ' . $r['book_title'] . ' to ' . $r['borrower_name'] . ' now? The due date is set from today.') ?>" data-confirm-label="Issue book">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="issue_request">
                  <input type="hidden" name="back" value="<?= h(current_url()) ?>">
                  <input type="hidden" name="request_id" value="<?= (int) $r['request_id'] ?>">
                  <button class="btn btn-primary btn-sm" type="submit"><?= icon('issue') ?> Issue now<span class="sr-only"> to <?= h($r['borrower_name']) ?></span></button>
                </form>
                <button class="btn btn-secondary btn-sm" type="button" data-modal-open="cancel-modal" data-fill="<?= h(json_encode(['request_id' => (int) $r['request_id'], 'who' => $r['borrower_name'] . ' · ' . $r['book_title'], 'note' => ''], JSON_UNESCAPED_UNICODE)) ?>">Cancel reservation<span class="sr-only"> for <?= h($r['borrower_name']) ?></span></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= render_pager($total, $page, $perPage, 'requests') ?>

<!-- Approve: pickup date and time, a preset, and the message the borrower receives. -->
<dialog class="modal modal-wide" id="approve-modal" aria-labelledby="approve-title">
  <form class="modal-form" method="post" action="/api/request_decide.php" novalidate data-composer
        data-preview-url="/api/request_preview.php" data-preview-fields="request_id template_id pickup_date pickup_time">
    <div class="modal-head">
      <div><h2 id="approve-title">Approve request</h2><p class="sub" data-fill-text="who"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="request_id">
      <div class="alert alert-error" data-form-error hidden role="alert"><?= icon('alert') ?><div><p></p>
        <button class="btn btn-secondary btn-sm" type="button" data-switch-reject hidden>Reject with the All copies on loan preset</button></div></div>
      <div class="form-row">
        <div class="field">
          <label for="ap-date">Pickup date</label>
          <input id="ap-date" name="pickup_date" type="date" min="<?= h(today()) ?>" required data-open-days="<?= h(implode(',', $hours['days'])) ?>" aria-describedby="ap-hours">
          <p class="field-error" data-error-for="pickup_date" hidden></p>
        </div>
        <div class="field">
          <label for="ap-time">Pickup time</label>
          <input id="ap-time" name="pickup_time" type="time" min="<?= h($hours['open']) ?>" max="<?= h($hours['close']) ?>" step="900" required aria-describedby="ap-hours">
          <p class="field-error" data-error-for="pickup_time" hidden></p>
        </div>
      </div>
      <p class="field-hint" id="ap-hours">Library hours: <?= h($hours['label']) ?>. The copy is held for <?= (int) setting('pickup_window_days', '2') ?> days after the pickup date.</p>
      <?php composer_fields('ap', $approvePresets); ?>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit" data-send><?= icon('send') ?> Approve and send message</button>
    </div>
  </form>
</dialog>

<!-- Reject: a preset, the reason saved on the request, and the message. -->
<dialog class="modal modal-wide" id="reject-modal" aria-labelledby="reject-title">
  <form class="modal-form" method="post" action="/api/request_decide.php" novalidate data-composer
        data-preview-url="/api/request_preview.php" data-preview-fields="request_id template_id">
    <div class="modal-head">
      <div><h2 id="reject-title">Reject request</h2><p class="sub" data-fill-text="who"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="request_id">
      <div class="alert alert-error" data-form-error hidden role="alert"><?= icon('alert') ?><p></p></div>
      <?php composer_fields('rj', $rejectPresets); ?>
      <div class="field">
        <label for="rj-reason">Reason <span class="req" aria-hidden="true">*</span></label>
        <input id="rj-reason" name="reason" type="text" maxlength="200" required data-required aria-describedby="rj-reason-hint">
        <p class="field-hint" id="rj-reason-hint">Saved on the request and shown on the borrower's My Requests page.</p>
        <p class="field-error" data-error-for="reason" hidden></p>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit" data-send><?= icon('send') ?> Reject and send message</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="cancel-modal" aria-labelledby="cancel-title">
  <form class="modal-form" method="post" action="/api/request_decide.php" data-ajax novalidate>
    <div class="modal-head">
      <div><h2 id="cancel-title">Cancel reservation</h2><p class="sub" data-fill-text="who"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="request_id">
      <div class="alert alert-error" data-form-error hidden role="alert"><?= icon('alert') ?><p></p></div>
      <p>The reserved copy goes back on the shelf, and the borrower gets a short message saying the request was cancelled.</p>
      <div class="field">
        <label for="cn-note">Note for the borrower <span class="muted">(optional)</span></label>
        <textarea id="cn-note" name="note" maxlength="300" rows="3" style="min-height: 90px"></textarea>
        <p class="char-count" data-count-for="cn-note" aria-live="polite"></p>
        <p class="field-error" data-error-for="note" hidden></p>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Keep reservation</button>
      <button class="btn btn-primary" type="submit">Cancel reservation and send message</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="message-view" aria-labelledby="mv-title">
  <div class="modal-form">
    <div class="modal-head">
      <div><h2 id="mv-title" data-fill-text="m_subject"></h2><p class="sub" data-fill-text="m_sent"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <p class="message-text" data-fill-text="m_body"></p>
      <p><span class="chip chip-ok" data-fill-show="m_read" hidden data-fill-text="m_read"></span><span class="chip" data-fill-show="m_unread" hidden>Unread</span></p>
    </div>
    <div class="modal-foot"><button class="btn btn-secondary" type="button" data-modal-close>Close</button></div>
  </div>
</dialog>
<?php
/** Preset dropdown, subject and message box shared by the approve and reject pop-ups. */
function composer_fields(string $p, array $presets): void
{
    ?>
    <div class="field">
      <label for="<?= $p ?>-preset">Message preset</label>
      <select id="<?= $p ?>-preset" name="template_id" data-preset>
        <?php foreach ($presets as $t): ?><option value="<?= (int) $t['template_id'] ?>"><?= h($t['label']) ?></option><?php endforeach; ?>
        <option value="own">Write my own</option>
      </select>
    </div>
    <div class="field">
      <label for="<?= $p ?>-subject">Subject <span class="req" aria-hidden="true">*</span></label>
      <input id="<?= $p ?>-subject" name="subject" type="text" maxlength="150" required data-required>
      <p class="field-error" data-error-for="subject" hidden></p>
    </div>
    <div class="field">
      <label for="<?= $p ?>-body">Message <span class="req" aria-hidden="true">*</span></label>
      <textarea id="<?= $p ?>-body" name="body" maxlength="1000" required data-required rows="7" aria-describedby="<?= $p ?>-status"></textarea>
      <div class="row row-between">
        <p class="field-hint" id="<?= $p ?>-status" data-composer-status aria-live="polite">Plain text. You can edit it freely.</p>
        <p class="char-count" data-count-for="<?= $p ?>-body" aria-live="off"></p>
      </div>
      <p class="field-error" data-error-for="body" hidden></p>
    </div>
    <?php
}
require APP_SRC . '/partials/footer.php';
