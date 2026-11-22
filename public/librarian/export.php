<?php
declare(strict_types=1);

/*
 * CSV downloads for Books, Issued loans, Fines and Users, with the report period applied to loans and fines.
 * Safe in Excel: UTF-8 with a byte-order mark (so ₱ and accented names show correctly), and any cell that starts
 * with = + - or @ is prefixed with an apostrophe so it cannot run as a formula. No password hash is ever exported.
 */
require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/reports.php';

$me = require_role(['librarian', 'admin']);

[$range, $from, $rangeLabel] = report_range(get('range'));
$type = get('type');

if (isset(EXPORTS[$type])) {
    $export = EXPORTS[$type];
    $st = db()->prepare($export['sql']);
    $st->execute($export['dated'] ? [':from' => $from] : []);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $name = 'library-' . $type . ($export['dated'] ? '-from-' . $from : '') . '-' . today() . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    $first = true;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($first) {
            fputcsv($out, array_keys($row), ',', '"', '', "\r\n");
            $first = false;
        }
        fputcsv($out, array_map('csv_safe', array_values($row)), ',', '"', '', "\r\n");
    }
    if ($first) {
        // No rows: still send the column names so the file opens cleanly.
        $cols = [];
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $cols[] = $st->getColumnMeta($i)['name'];
        }
        fputcsv($out, $cols, ',', '"', '', "\r\n");
    }
    fclose($out);
    exit;
}

$pageTitle = 'Export';
$activeNav = 'status';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Export</h1>
    <p class="lead">Download the library's records as CSV files that open in Excel.</p>
  </div>
  <div class="page-actions"><a class="btn btn-secondary" href="/librarian/status.php?range=<?= h($range) ?>">Back to Status and Reports</a></div>
</div>
<form class="toolbar" method="get" action="/librarian/export.php">
  <div class="field">
    <label for="range">Period for loans and fines</label>
    <select id="range" name="range" data-autosubmit>
      <?php foreach (REPORT_RANGES as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $key === $range ? ' selected' : '' ?>><?= h(ucfirst($label)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Apply</button></noscript>
</form>
<div class="grid-2">
  <?php foreach (EXPORTS as $key => $meta): ?>
    <div class="card">
      <h2 class="card-title"><?= h($meta['label']) ?></h2>
      <p class="muted"><?= $meta['dated'] ? 'From ' . h(fmt_date($from)) . ' (' . h($rangeLabel) . ').' : 'Everything, as of today.' ?>
        <?= $key === 'users' ? ' Names, emails and status only; passwords are never exported.' : '' ?></p>
      <a class="btn btn-primary" href="/librarian/export.php?type=<?= h($key) ?>&amp;range=<?= h($range) ?>"><?= icon('download') ?> Download <?= h(strtolower($meta['label'])) ?> CSV</a>
    </div>
  <?php endforeach; ?>
</div>
<?php require APP_SRC . '/partials/footer.php'; ?>
