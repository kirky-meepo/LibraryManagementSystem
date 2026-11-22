<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/partials/pager.php';

$me = require_role(['librarian', 'admin']);

const AUDIENCES = [
    'person' => 'One person',
    'all_students' => 'All students',
    'all_teachers' => 'All teachers',
    'everyone' => 'Everyone',
];
const KIND_FILTERS = [
    '' => 'All kinds',
    'request_approved' => 'Approvals',
    'request_rejected' => 'Rejections',
    'reminder' => 'Reminders',
    'overdue' => 'Overdue',
    'general' => 'General',
];
const KIND_CHIPS = [
    'request_approved' => ['Approval', 'chip-ok'],
    'request_rejected' => ['Rejection', 'chip-bad'],
    'reminder' => ['Reminder', 'chip-info'],
    'overdue' => ['Overdue', 'chip-warn'],
    'general' => ['General', ''],
];

$values = ['audience' => 'person', 'recipient' => '', 'subject' => '', 'body' => ''];
$errors = [];

// This is the only place a message can be sent without a request or a loan.
if (is_post()) {
    $values['audience'] = isset(AUDIENCES[post('audience')]) ? post('audience') : 'person';
    $values['recipient'] = mb_strtolower(mb_substr(post('recipient'), 0, 254));
    $values['subject'] = clean_text(post('subject'), 151);
    $values['body'] = clean_text(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')), 1001);

    $person = null;
    if ($values['audience'] === 'person') {
        if (preg_match('/<([^>]+)>\s*$/', $values['recipient'], $m)) {
            $values['recipient'] = trim($m[1]);    // "Juan Cruz <juan.cruz@school.edu>"
        }
        $person = $values['recipient'] !== ''
            ? q_row('SELECT borrower_type, student_id, teacher_id, first_name, full_name FROM v_borrower_status WHERE email = :e', [':e' => $values['recipient']])
            : null;
        if (!$person) {
            $errors['recipient'] = 'Pick a student or teacher from the list, or type their full email address.';
        }
    }
    if (trim($values['subject']) === '' || mb_strlen($values['subject']) > 150) {
        $errors['subject'] = 'Write a subject of up to 150 characters.';
    }
    if (trim($values['body']) === '' || mb_strlen($values['body']) > 1000) {
        $errors['body'] = 'Write the message, up to 1,000 characters.';
    }

    if (!$errors) {
        try {
            $sent = tx(function (PDO $pdo) use ($values, $person, $me) {
                if ($person) {
                    $st = $pdo->prepare("SELECT fn_send_message(:s, :sid, :tid, 'general', :subj, :body)");
                    $st->execute([':s' => $me['id'], ':sid' => $person['student_id'], ':tid' => $person['teacher_id'], ':subj' => $values['subject'], ':body' => $values['body']]);
                    return 1;
                }
                $st = $pdo->prepare('SELECT fn_broadcast_message(:a, :subj, :body, :s)');
                $st->execute([':a' => $values['audience'], ':subj' => $values['subject'], ':body' => $values['body'], ':s' => $me['id']]);
                return (int) $st->fetchColumn();
            });
            flash('success', $person
                ? 'Message sent to ' . $person['full_name'] . '.'
                : 'Message sent to ' . plural($sent, 'person', 'people') . ' (' . strtolower(AUDIENCES[$values['audience']]) . ', active accounts only).');
            redirect('/librarian/messages.php');
        } catch (PDOException $e) {
            $errors['form'] = db_message($e);
        }
    }
}

$kind = isset(KIND_FILTERS[get('kind')]) ? get('kind') : '';
$q = mb_substr(get('q'), 0, 100);
$perPage = 15;
[$page, $offset] = page_offset($perPage);
$where = "(:kind = '' OR kind = :kind2) AND (:q = '' OR recipient_name ILIKE :q1 OR recipient_email ILIKE :q2 OR subject ILIKE :q3)";
$params = [':kind' => $kind, ':kind2' => $kind, ':q' => $q, ':q1' => like_contains($q), ':q2' => like_contains($q), ':q3' => like_contains($q)];
$total = (int) q_val("SELECT count(*) FROM v_inbox WHERE $where", $params);
$log = q_all("SELECT * FROM v_inbox WHERE $where ORDER BY sent_at DESC, message_id DESC LIMIT $perPage OFFSET " . (int) $offset, $params);
$members = q_all('SELECT full_name, email, borrower_type FROM v_borrower_status WHERE is_active ORDER BY full_name');
$audienceCounts = q_row("SELECT count(*) FILTER (WHERE borrower_type = 'student') AS s, count(*) FILTER (WHERE borrower_type = 'teacher') AS t FROM v_borrower_status WHERE is_active");

$pageTitle = 'Messages';
$activeNav = 'messages';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Messages</h1>
    <p class="lead">Send an announcement or a note. Approvals, rejections and reminders are sent from Requests and Issued Books.</p>
  </div>
</div>

<form class="card form-card" method="post" action="/librarian/messages.php" novalidate style="max-width: 820px">
  <h2 class="card-title">New message</h2>
  <?= csrf_field() ?>
  <?php if ($errors): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert') ?><p><?= h($errors['form'] ?? 'Please fix the highlighted fields.') ?></p></div>
  <?php endif; ?>
  <fieldset class="radio-group">
    <legend>To</legend>
    <div class="audience-options">
      <?php foreach (AUDIENCES as $key => $label):
          $hint = match ($key) {
              'all_students' => plural((int) $audienceCounts['s'], 'active student'),
              'all_teachers' => plural((int) $audienceCounts['t'], 'active teacher'),
              'everyone' => plural((int) $audienceCounts['s'] + (int) $audienceCounts['t'], 'active member'),
              default => 'A student or teacher',
          }; ?>
        <label class="radio-card"><input type="radio" name="audience" value="<?= h($key) ?>"<?= $values['audience'] === $key ? ' checked' : '' ?> data-type-switch-radio>
          <span><strong><?= h($label) ?></strong><br><span class="small muted"><?= h($hint) ?></span></span></label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <div class="field" data-audience-section="person">
    <label for="recipient">Name or email</label>
    <input id="recipient" name="recipient" type="text" list="member-list" maxlength="254" autocomplete="off" value="<?= h($values['recipient']) ?>"<?= field_attrs($errors, 'recipient', 'recipient-hint') ?>>
    <p class="field-hint" id="recipient-hint">Start typing a name and pick from the list.</p>
    <?= field_error($errors, 'recipient') ?>
    <datalist id="member-list">
      <?php foreach ($members as $m): ?><option value="<?= h($m['full_name'] . ' <' . $m['email'] . '>') ?>"><?= h(ucfirst($m['borrower_type'])) ?></option><?php endforeach; ?>
    </datalist>
  </div>
  <div class="field">
    <label for="subject">Subject</label>
    <input id="subject" name="subject" type="text" maxlength="150" required value="<?= h($values['subject']) ?>"<?= field_attrs($errors, 'subject') ?>>
    <?= field_error($errors, 'subject') ?>
  </div>
  <div class="field">
    <label for="body">Message</label>
    <textarea id="body" name="body" maxlength="1000" required rows="6"<?= field_attrs($errors, 'body', 'body-count') ?>><?= h($values['body']) ?></textarea>
    <div class="row row-between"><p class="field-hint">Plain text, saved exactly as written.</p><p class="char-count" id="body-count" data-count-for="body" aria-live="polite"></p></div>
    <?= field_error($errors, 'body') ?>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('send') ?> Send message</button></div>
</form>

<section class="section" aria-labelledby="log-title">
  <div class="section-head"><h2 id="log-title">Sent messages</h2><span class="muted small"><?= h(plural($total, 'message')) ?></span></div>
  <form class="toolbar" method="get" action="/librarian/messages.php" role="search" data-live-search>
    <div class="field">
      <label for="kind">Kind</label>
      <select id="kind" name="kind" data-autosubmit>
        <?php foreach (KIND_FILTERS as $key => $label): ?><option value="<?= h($key) ?>"<?= $key === $kind ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field grow">
      <label for="q">Search</label>
      <div class="search-field"><?= icon('search') ?><input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Recipient or subject" maxlength="100"></div>
    </div>
    <noscript><button class="btn btn-secondary" type="submit">Show</button></noscript>
  </form>
  <?php if (!$log): ?>
    <div class="empty"><?= icon('mail') ?><strong>No messages here</strong><p>Messages you send, and the ones sent with approvals, rejections and reminders, are listed here.</p></div>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Sent messages" tabindex="0">
      <table>
        <thead><tr><th scope="col">Kind</th><th scope="col">To</th><th scope="col">Subject</th><th scope="col">Sent</th><th scope="col">Read</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
          <?php foreach ($log as $m):
              [$kindLabel, $kindClass] = KIND_CHIPS[$m['kind']] ?? [ucfirst($m['kind']), '']; ?>
            <tr>
              <td><span class="chip chip-plain <?= h($kindClass) ?>"><?= h($kindLabel) ?></span></td>
              <td><?= h($m['recipient_name']) ?><span class="sub"><?= h(ucfirst($m['recipient_type'])) ?></span></td>
              <td><?= h($m['subject']) ?></td>
              <td class="nowrap"><?= h(fmt_datetime($m['sent_at'])) ?><span class="sub">by <?= h($m['sender_name']) ?></span></td>
              <td><?= $m['is_read'] ? '<span class="chip chip-ok">Read</span>' : '<span class="chip">Unread</span>' ?></td>
              <td class="actions"><button class="btn btn-secondary btn-sm" type="button" data-modal-open="message-view" data-fill="<?= h(json_encode([
                  'm_subject' => $m['subject'], 'm_body' => $m['body'],
                  'm_sent' => 'Sent ' . fmt_datetime($m['sent_at']) . ' by ' . $m['sender_name'] . ' to ' . $m['recipient_name'],
                  'm_read' => $m['read_at'] ? 'Read ' . fmt_datetime($m['read_at']) : '', 'm_unread' => $m['read_at'] ? '' : 'Unread',
              ], JSON_UNESCAPED_UNICODE)) ?>">View<span class="sr-only"> message to <?= h($m['recipient_name']) ?></span></button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?= render_pager($total, $page, $perPage, 'messages') ?>
</section>

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
<?php require APP_SRC . '/partials/footer.php'; ?>
