<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/partials/stat_card.php';
require_once APP_SRC . '/charts.php';
require_once APP_SRC . '/reports.php';

$me = require_role(['librarian', 'admin']);

[$range, $from, $rangeLabel] = report_range(get('range'));

// Every number on this page comes from v_monthly_activity, v_popular_books, v_category_breakdown and
// v_dashboard_stats. The date range reaches the views through app.report_from, set in the same transaction.
$data = tx(function (PDO $pdo) use ($from) {
    $pdo->prepare("SELECT set_config('app.report_from', :d, true)")->execute([':d' => $from]);
    return [
        'monthly' => $pdo->query('SELECT * FROM v_monthly_activity ORDER BY month')->fetchAll(),
        'popular' => $pdo->query('SELECT * FROM v_popular_books')->fetchAll(),
        'category' => $pdo->query('SELECT * FROM v_category_breakdown')->fetchAll(),
        'stats' => $pdo->query('SELECT * FROM v_dashboard_stats')->fetch(),
    ];
});

$months = array_map(fn($m) => [
    'label' => date('M Y', strtotime($m['month'])),
    'short' => date('M', strtotime($m['month'])) . (count($data['monthly']) > 6 ? '' : ' ' . date('Y', strtotime($m['month']))),
    'row' => $m,
], $data['monthly']);

$borrows = array_sum(array_column($data['monthly'], 'borrows'));
$returns = array_sum(array_column($data['monthly'], 'returns'));
$issued = array_sum(array_map('floatval', array_column($data['monthly'], 'fines_issued_amount')));
$collected = array_sum(array_map('floatval', array_column($data['monthly'], 'fines_collected_amount')));
$busiest = null;
foreach ($data['monthly'] as $m) {
    if ($busiest === null || (int) $m['borrows'] > (int) $busiest['borrows']) {
        $busiest = $m;
    }
}

$count = static fn(float $v, bool $compact = false) => number_format($v);
$money = static fn(float $v, bool $compact = false) => $compact && $v >= 10000 ? '₱' . number_format($v / 1000, 1) . 'K' : '₱' . number_format($v, $compact ? 0 : 2);

$activityGroups = array_map(fn($m) => ['label' => $m['label'], 'short' => $m['short'], 'values' => [(int) $m['row']['borrows'], (int) $m['row']['returns']]], $months);
$fineGroups = array_map(fn($m) => ['label' => $m['label'], 'short' => $m['short'], 'values' => [(float) $m['row']['fines_issued_amount'], (float) $m['row']['fines_collected_amount']]], $months);

$activitySummary = $borrows + $returns === 0
    ? "No books were borrowed or returned in $rangeLabel."
    : sprintf('%s: %s borrowed and %s returned%s.', ucfirst($rangeLabel), plural($borrows, 'book'), plural($returns, 'book'),
        $busiest && (int) $busiest['borrows'] > 0 ? '; the busiest month was ' . date('F Y', strtotime($busiest['month'])) . ' with ' . plural((int) $busiest['borrows'], 'borrow') : '');
$fineSummary = $issued + $collected == 0
    ? "No fines were issued or collected in $rangeLabel."
    : sprintf('%s: %s in fines issued and %s collected.', ucfirst($rangeLabel), peso($issued), peso($collected));
$topSummary = $data['popular']
    ? 'Most borrowed in ' . $rangeLabel . ': ' . $data['popular'][0]['title'] . ' (' . plural((int) $data['popular'][0]['borrow_count'], 'borrow') . ').'
    : 'No books were borrowed in ' . $rangeLabel . '.';
$maxOnLoan = max(1, ...array_map(fn($c) => (int) $c['copies'], $data['category'] ?: [['copies' => 1]]));
$stats = $data['stats'];

$pageTitle = 'Status and Reports';
$activeNav = 'status';
$bodyClass = 'report-page';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Status and Reports</h1>
    <p class="lead">Loans, returns, popular books and fines for <?= h($rangeLabel) ?> (from <?= h(fmt_date($from)) ?>). As of <?= h(fmt_date(today())) ?>.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-secondary" type="button" data-print><?= icon('printer') ?> Print this page</button>
  </div>
</div>

<form class="toolbar" method="get" action="/librarian/status.php">
  <div class="field">
    <label for="range">Period</label>
    <select id="range" name="range" data-autosubmit>
      <?php foreach (REPORT_RANGES as $key => $label): ?>
        <option value="<?= h($key) ?>"<?= $key === $range ? ' selected' : '' ?>><?= h(ucfirst($label)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Show</button></noscript>
  <div class="field grow export-links">
    <span class="field-label">Download CSV (opens in Excel)</span>
    <div class="row">
      <?php foreach (EXPORTS as $key => $meta): ?>
        <a class="btn btn-secondary btn-sm" href="/librarian/export.php?type=<?= h($key) ?>&amp;range=<?= h($range) ?>"><?= icon('download') ?> <?= h($meta['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<h2 class="sr-only">Right now</h2>
<ul class="stat-grid is-compact">
  <li><?= stat_card(['label' => 'Titles', 'value' => $stats['total_titles'], 'note' => plural((int) $stats['total_copies'], 'copy', 'copies'), 'from' => '#011f4b', 'to' => '#03396c', 'icon' => 'books', 'art' => 'shelf']) ?></li>
  <li><?= stat_card(['label' => 'On loan now', 'value' => $stats['copies_on_loan'], 'note' => plural((int) $stats['copies_available'], 'copy', 'copies') . ' on the shelf', 'from' => '#03396c', 'to' => '#005b96', 'icon' => 'issue', 'art' => 'open', 'index' => 1]) ?></li>
  <li><?= stat_card(['label' => 'Overdue now', 'value' => $stats['overdue_loans'], 'note' => plural((int) $stats['active_members'], 'active member'), 'from' => '#011f4b', 'to' => '#005b96', 'icon' => 'alert', 'art' => 'leaning', 'index' => 2]) ?></li>
  <li><?= stat_card(['label' => 'Unpaid fines now', 'value' => $stats['unpaid_fines_total'], 'prefix' => '₱', 'decimals' => 2, 'note' => plural((int) $stats['unpaid_fines_count'], 'fine'), 'from' => '#b3cde0', 'to' => '#6497b1', 'icon' => 'coins', 'art' => 'stack', 'index' => 3]) ?></li>
</ul>

<div class="report-grid">
  <figure class="card chart-card">
    <figcaption><h2 class="card-title">Borrows and returns per month</h2></figcaption>
    <?= column_chart('activity', $activityGroups, [
        ['label' => 'Borrows', 'color' => '#005b96'],
        ['label' => 'Returns', 'color' => '#6497b1', 'hatch' => true],
    ], $count, $activitySummary) ?>
    <details class="chart-table"><summary>Show the numbers as a table</summary>
      <table><thead><tr><th scope="col">Month</th><th scope="col" class="num">Borrows</th><th scope="col" class="num">Returns</th></tr></thead><tbody>
        <?php foreach ($months as $m): ?><tr><td><?= h($m['label']) ?></td><td class="num"><?= (int) $m['row']['borrows'] ?></td><td class="num"><?= (int) $m['row']['returns'] ?></td></tr><?php endforeach; ?>
      </tbody></table></details>
  </figure>

  <figure class="card chart-card">
    <figcaption><h2 class="card-title">Fines issued and collected per month</h2></figcaption>
    <?= column_chart('fines', $fineGroups, [
        ['label' => 'Issued', 'color' => '#005b96'],
        ['label' => 'Collected', 'color' => '#6497b1', 'hatch' => true],
    ], $money, $fineSummary) ?>
    <details class="chart-table"><summary>Show the numbers as a table</summary>
      <table><thead><tr><th scope="col">Month</th><th scope="col" class="num">Fines issued</th><th scope="col" class="num">Amount issued</th><th scope="col" class="num">Collected</th></tr></thead><tbody>
        <?php foreach ($months as $m): ?><tr><td><?= h($m['label']) ?></td><td class="num"><?= (int) $m['row']['fines_issued'] ?></td><td class="num"><?= h(peso($m['row']['fines_issued_amount'])) ?></td><td class="num"><?= h(peso($m['row']['fines_collected_amount'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></details>
  </figure>

  <figure class="card chart-card">
    <figcaption><h2 class="card-title">Top 10 most borrowed books</h2></figcaption>
    <?php if ($data['popular']): ?>
      <?= bar_list($data['popular'], 'title', 'borrow_count', fn($v) => number_format($v), 'Borrows per book') ?>
    <?php else: ?>
      <div class="empty"><?= icon('chart') ?><p>No books were borrowed in this period.</p></div>
    <?php endif; ?>
    <p class="chart-summary"><?= h($topSummary) ?></p>
  </figure>

  <figure class="card chart-card">
    <figcaption><h2 class="card-title">Books by category</h2></figcaption>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Category</th><th scope="col" class="num">Titles</th><th scope="col" class="num">Copies</th><th scope="col">On loan now</th><th scope="col" class="num">Borrows</th></tr></thead>
        <tbody>
          <?php foreach ($data['category'] as $c): ?>
            <tr>
              <th scope="row"><?= h($c['category']) ?></th>
              <td class="num"><?= (int) $c['titles'] ?></td>
              <td class="num"><?= (int) $c['copies'] ?></td>
              <td class="nowrap"><span class="mini-bar"><span style="width: <?= round((int) $c['on_loan'] / $maxOnLoan * 100, 1) ?>%"></span></span> <?= (int) $c['on_loan'] ?> of <?= (int) $c['copies'] ?></td>
              <td class="num"><?= (int) $c['borrows'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="chart-summary">Titles, copies and books on loan are as of today; borrows count <?= h($rangeLabel) ?>.</p>
  </figure>
</div>
<?php require APP_SRC . '/partials/footer.php'; ?>
