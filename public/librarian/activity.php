<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/activity.php';
require_once APP_SRC . '/partials/pager.php';

$me = require_role(['admin']);

const AUDIT_TABLES = [
    'book_request' => 'Requests', 'transaction' => 'Loans', 'fine' => 'Fines', 'book' => 'Books',
    'student' => 'Students', 'teacher' => 'Teachers', 'staff' => 'Staff', 'library_setting' => 'Settings',
];

$validDate = static fn(string $d) => DateTimeImmutable::createFromFormat('!Y-m-d', $d) && DateTimeImmutable::createFromFormat('!Y-m-d', $d)->format('Y-m-d') === $d ? $d : '';
$filters = [
    'actor' => preg_match('/^((staff|student|teacher):\d{1,9}|system)$/', get('actor')) ? get('actor') : '',
    'table' => isset(AUDIT_TABLES[get('table')]) ? get('table') : '',
    'from' => $validDate(get('from')),
    'to' => $validDate(get('to')),
    'meaningful' => get('all') !== '1',
];
$perPage = 25;
[$page, $offset] = page_offset($perPage);
$total = activity_count($filters);
$rows = activity_rows($filters, $perPage, $offset);

$actors = q_all(
    "SELECT DISTINCT a.actor_type, a.actor_id,
            CASE a.actor_type
              WHEN 'system' THEN 'The system'
              ELSE (SELECT d.full_name FROM v_user_directory d WHERE d.user_type = a.actor_type AND d.user_id = a.actor_id)
            END AS name
       FROM audit_log a
      ORDER BY a.actor_type, name"
);

/** Changed fields of an update as "field: old → new" lines (password values are never stored). */
function change_lines(array $row): array
{
    $detail = json_decode((string) $row['detail'], true) ?: [];
    if ($row['action'] !== 'update') {
        return [];
    }
    $lines = [];
    foreach ($detail as $field => $change) {
        if ($field === 'password_changed') {
            $lines[] = 'password changed (not stored)';
            continue;
        }
        if (!is_array($change)) {
            continue;
        }
        $show = static fn($v) => $v === null ? 'empty' : (is_bool($v) ? ($v ? 'yes' : 'no') : mb_strimwidth((string) $v, 0, 60, '…'));
        $lines[] = str_replace('_', ' ', $field) . ': ' . $show($change['old'] ?? null) . ' → ' . $show($change['new'] ?? null);
    }
    return $lines;
}

$pageTitle = 'Activity';
$activeNav = 'activity';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Activity</h1>
    <p class="lead">Every change to people, books, requests, loans, fines and settings, with who made it and when (from the audit log).</p>
  </div>
</div>

<form class="toolbar" method="get" action="/librarian/activity.php">
  <div class="field">
    <label for="actor">Person</label>
    <select id="actor" name="actor" data-autosubmit>
      <option value="">Everyone</option>
      <?php foreach ($actors as $a):
          $key = $a['actor_type'] === 'system' ? 'system' : $a['actor_type'] . ':' . $a['actor_id']; ?>
        <option value="<?= h($key) ?>"<?= $key === $filters['actor'] ? ' selected' : '' ?>><?= h($a['name'] ?? ucfirst($a['actor_type']) . ' #' . $a['actor_id']) ?><?= $a['actor_type'] !== 'system' ? ' (' . h(ucfirst($a['actor_type'])) . ')' : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="table">Record type</label>
    <select id="table" name="table" data-autosubmit>
      <option value="">All records</option>
      <?php foreach (AUDIT_TABLES as $key => $label): ?><option value="<?= h($key) ?>"<?= $key === $filters['table'] ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="<?= h($filters['from']) ?>" max="<?= h(today()) ?>"></div>
  <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="<?= h($filters['to']) ?>" max="<?= h(today()) ?>"></div>
  <div class="field">
    <span class="field-label" aria-hidden="true">&nbsp;</span>
    <label class="check"><input type="checkbox" name="all" value="1"<?= $filters['meaningful'] ? '' : ' checked' ?> data-autosubmit> Include sign-ins and copy-count updates</label>
  </div>
  <div class="field"><span class="field-label" aria-hidden="true">&nbsp;</span><button class="btn btn-secondary" type="submit">Show</button></div>
</form>

<?php if (!$rows): ?>
  <div class="empty"><?= icon('activity') ?><strong>Nothing matches these filters</strong><p>Choose Everyone and All records, or clear the dates.</p></div>
<?php else: ?>
  <div class="table-wrap" role="region" aria-label="Activity" tabindex="0">
    <table>
      <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What happened</th><th scope="col">Record</th><th scope="col">Changes</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $lines = change_lines($r); ?>
          <tr>
            <td class="nowrap"><?= h(fmt_datetime($r['created_at'])) ?><span class="sub"><?= h(time_ago($r['created_at'])) ?></span></td>
            <td><?= h($r['actor_type'] === 'system' ? 'The system' : ($r['actor_name'] ?? 'Deleted account')) ?><span class="sub"><?= h(ucfirst($r['actor_type'])) ?></span></td>
            <td><?= h(activity_sentence($r, true)) ?></td>
            <td class="nowrap"><?= h(AUDIT_TABLES[$r['table_name']] ?? $r['table_name']) ?> #<?= h($r['record_id']) ?><span class="sub"><?= h(ucfirst($r['action'])) ?></span></td>
            <td class="small"><?php if ($lines): ?><ul class="change-list"><?php foreach ($lines as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ul>
              <?php else: ?><span class="muted"><?= $r['action'] === 'insert' ? 'New record' : ($r['action'] === 'delete' ? 'Removed' : '') ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= render_pager($total, $page, $perPage, 'events') ?>
<?php require APP_SRC . '/partials/footer.php'; ?>
