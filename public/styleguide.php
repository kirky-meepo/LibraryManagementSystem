<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require_once APP_SRC . '/partials/stat_card.php';

$me = require_role(['librarian', 'admin']);

$samples = [
    'total_titles' => ['value' => 12, 'note' => '44 copies in the library'],
    'copies_on_loan' => ['value' => 8, 'note' => '34 copies on the shelf'],
    'awaiting_pickup' => ['value' => 2, 'note' => 'Reserved for pickup'],
    'pending_requests' => ['value' => 3, 'note' => 'Waiting for a decision'],
    'overdue_loans' => ['value' => 2, 'note' => 'Past their due date'],
    'unpaid_fines' => ['value' => 3500, 'note' => '2 fines to collect', 'prefix' => '₱', 'decimals' => 2],
];
$styles = dashboard_card_styles();

$pageTitle = 'Style guide';
$activeNav = '';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Style guide</h1>
    <p class="lead">Every shared component on one page, so the design can be reviewed in one place.</p>
  </div>
</div>

<section aria-labelledby="sg-cards">
  <h2 id="sg-cards">Gradient stat cards</h2>
  <p class="muted">Each card has its own palette pair, a label, an icon and a book drawing. Hover to see the lift and tilt; reload to see the numbers count up and the cards rise in.</p>
  <ul class="stat-grid grid-six is-staggered">
    <?php $i = 0; foreach ($styles as $key => $style): ?>
      <li><?= stat_card($style + $samples[$key] + ['href' => '#sg-cards', 'index' => $i++]) ?></li>
    <?php endforeach; ?>
  </ul>

  <h3>Contrast of the card text</h3>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Card</th><th scope="col">Gradient</th><th scope="col">Text colour</th><th scope="col" class="num">Against first stop</th><th scope="col" class="num">Against second stop</th><th scope="col">Result (4.5:1 needed)</th></tr></thead>
      <tbody>
        <?php foreach ($styles as $style):
            $ink = card_ink($style['from'], $style['to']) === 'light' ? '#ffffff' : '#011f4b';
            $a = contrast_ratio($ink, $style['from']);
            $b = contrast_ratio($ink, $style['to']);
            $ok = min($a, $b) >= 4.5; ?>
          <tr>
            <td><?= h($style['label']) ?></td>
            <td class="nowrap"><?= h($style['from']) ?> to <?= h($style['to']) ?></td>
            <td><?= $ink === '#ffffff' ? 'White' : 'Navy #011f4b' ?></td>
            <td class="num"><?= number_format($a, 2) ?>:1</td>
            <td class="num"><?= number_format($b, 2) ?>:1</td>
            <td><span class="chip <?= $ok ? 'chip-ok' : 'chip-bad' ?>"><?= $ok ? 'Pass' : 'Fail' ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted">The pair #005b96 to #6497b1 was first planned for Awaiting pickup, but it fails for any text colour (white 3.18:1 at the light end, navy 2.26:1 at the dark end), so the card uses #005b96 to #011f4b with white text.</p>
</section>

<section class="section" aria-labelledby="sg-colours">
  <h2 id="sg-colours">Colours</h2>
  <div class="swatches">
    <?php foreach (PALETTE as $hex): ?>
      <div class="swatch">
        <div class="swatch-color" style="background: <?= h($hex) ?>"></div>
        <p><strong><?= h($hex) ?></strong><br>
          <span class="muted">White text <?= number_format(contrast_ratio('#ffffff', $hex), 2) ?>:1 · Navy text <?= number_format(contrast_ratio('#011f4b', $hex), 2) ?>:1</span></p>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="small muted">Body text #12233d on white: <?= number_format(contrast_ratio('#12233d', '#ffffff'), 2) ?>:1. Secondary text #4b5a6e on white: <?= number_format(contrast_ratio('#4b5a6e', '#ffffff'), 2) ?>:1. Form borders #7d8ea3 on white: <?= number_format(contrast_ratio('#7d8ea3', '#ffffff'), 2) ?>:1.</p>
</section>

<section class="section" aria-labelledby="sg-type">
  <h2 id="sg-type">Type</h2>
  <div class="card">
    <p class="type-h1">Heading one, Source Serif 4</p>
    <h2>Heading two</h2>
    <h3>Heading three</h3>
    <p>Body text is Source Sans 3. Dates read like <?= h(fmt_date(today())) ?>, times like <?= h(fmt_time('14:30')) ?>, money like <?= h(peso(1234.5)) ?>.</p>
    <p class="muted">Secondary text for hints and details.</p>
    <p class="small">Small text for notes.</p>
  </div>
</section>

<section class="section" aria-labelledby="sg-buttons">
  <h2 id="sg-buttons">Buttons</h2>
  <div class="row">
    <button class="btn btn-primary" type="button">Approve and send message</button>
    <button class="btn btn-secondary" type="button">Cancel</button>
    <button class="btn btn-danger" type="button">Delete user</button>
    <button class="btn btn-link" type="button">View message</button>
    <button class="btn btn-primary" type="button" disabled>Reject and send message</button>
    <button class="btn btn-secondary btn-sm" type="button"><?= icon('edit') ?> Edit</button>
  </div>
  <p class="small muted">One primary colour (#005b96), grey outlined secondary buttons, red only for delete.</p>
</section>

<section class="section" aria-labelledby="sg-forms">
  <h2 id="sg-forms">Form fields</h2>
  <form class="card form-card" action="#sg-forms" novalidate>
    <div class="form-row">
      <div class="field">
        <label for="sg-title">Title <span class="req" aria-hidden="true">*</span></label>
        <input id="sg-title" type="text" value="Clean Code" required>
        <p class="field-hint">As printed on the cover.</p>
      </div>
      <div class="field">
        <label for="sg-cat">Category</label>
        <select id="sg-cat"><option>Programming</option><option>Databases</option></select>
      </div>
      <div class="field">
        <label for="sg-isbn">ISBN</label>
        <input id="sg-isbn" type="text" value="978-0132350885" aria-invalid="true" aria-describedby="sg-isbn-err">
        <p class="field-error" id="sg-isbn-err">This ISBN's check digit is wrong. Check the last digit.</p>
      </div>
    </div>
    <div class="field">
      <label for="sg-msg">Message</label>
      <textarea id="sg-msg" maxlength="1000">Hi Ella, good news: your request for “Clean Code” is approved.</textarea>
      <p class="char-count" data-count-for="sg-msg" aria-live="polite"></p>
    </div>
    <fieldset class="radio-group">
      <legend>Condition</legend>
      <label class="radio-card"><input type="radio" name="sg-cond" checked><span><strong>Good</strong><br><span class="muted small">Back on the shelf.</span></span></label>
      <label class="radio-card"><input type="radio" name="sg-cond"><span><strong>Damaged</strong><br><span class="muted small">Asks for a damage amount.</span></span></label>
    </fieldset>
    <label class="check"><input type="checkbox"> Show archived</label>
  </form>
</section>

<section class="section" aria-labelledby="sg-feedback">
  <h2 id="sg-feedback">Alerts, chips and toasts</h2>
  <div class="alert alert-error" role="alert"><?= icon('alert') ?><p>Wrong email or password.</p></div>
  <div class="alert alert-locked"><?= icon('lock') ?><p>Too many failed sign-ins. Sign-in is locked for 15 minutes.</p></div>
  <div class="alert alert-success"><?= icon('check') ?><p>Approved. Message sent to Ella.</p></div>
  <div class="alert alert-info"><?= icon('info') ?><p>Members return books and pay fines at the library desk.</p></div>
  <p class="row">
    <span class="chip chip-ok">Available</span>
    <span class="chip chip-warn">Low</span>
    <span class="chip chip-bad">None</span>
    <span class="chip chip-warn">Pending</span>
    <span class="chip chip-info">Approved</span>
    <span class="chip chip-bad">Overdue, 6 days</span>
    <span class="chip chip-ok">Returned</span>
    <span class="chip">Inactive</span>
    <span class="chip chip-info chip-plain">Student</span>
    <span class="chip chip-plain">Teacher</span>
  </p>
  <div class="row">
    <button class="btn btn-secondary" type="button" data-toast-demo="success" data-message="Approved. Message sent to Ella.">Show success toast</button>
    <button class="btn btn-secondary" type="button" data-toast-demo="error" data-message="No copy of Cosmos is free right now.">Show error toast</button>
    <button class="btn btn-secondary" type="button" data-modal-open="sg-modal">Open sample modal</button>
  </div>
</section>

<section class="section" aria-labelledby="sg-table">
  <h2 id="sg-table">Table, tabs, pager and empty state</h2>
  <nav class="tabs" aria-label="Sample tabs">
    <a class="tab" href="#sg-table" aria-current="page">Pending <span class="tab-count">3</span></a>
    <a class="tab" href="#sg-table">Awaiting pickup <span class="tab-count">2</span></a>
    <a class="tab" href="#sg-table">All <span class="tab-count">8</span></a>
  </nav>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Sample table">
    <table>
      <thead><tr><th scope="col">Borrower</th><th scope="col">Book</th><th scope="col">Requested</th><th scope="col" class="num">Copies left</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
        <tr><td>Ella Ramirez <span class="sub"><span class="chip chip-info chip-plain">Student</span></span></td><td>Clean Code</td><td><?= h(fmt_date(today())) ?></td><td class="num">4</td>
          <td class="actions">
            <details class="row-menu"><summary class="btn btn-secondary btn-sm"><?= icon('more') ?><span class="sr-only">Actions for Ella Ramirez</span></summary>
              <div class="menu-list"><button type="button"><?= icon('edit') ?> Edit</button><button type="button"><?= icon('key') ?> Reset password</button><button type="button" class="is-danger"><?= icon('x') ?> Delete</button></div>
            </details>
          </td></tr>
        <tr class="is-inactive"><td>Liza Aquino <span class="sub"><span class="chip">Inactive</span></span></td><td>A Brief History of Time</td><td><?= h(fmt_date(date('Y-m-d', strtotime('-13 days')))) ?></td><td class="num">3</td><td class="actions"></td></tr>
      </tbody>
    </table>
  </div>
  <div class="pager"><span>Showing 1 to 2 of 2</span><span class="pager-links"><a class="btn btn-secondary btn-sm" aria-disabled="true">Previous</a><a class="btn btn-secondary btn-sm" aria-disabled="true">Next</a></span></div>
  <div class="section empty"><?= icon('inbox') ?><strong>No pending requests</strong><p>New requests show up here.</p></div>
</section>

<section class="section" aria-labelledby="sg-art">
  <h2 id="sg-art">Book drawings</h2>
  <div class="grid-3">
    <?php foreach (BOOK_ART as $art): ?>
      <div class="panel" style="color: var(--blue-600)"><div style="width:96px;height:96px"><?= book_art($art) ?></div><p class="small"><?= h($art) ?>.svg</p></div>
    <?php endforeach; ?>
  </div>
</section>

<dialog class="modal" id="sg-modal" aria-labelledby="sg-modal-title">
  <form class="modal-form" method="dialog">
    <div class="modal-head">
      <div><h2 id="sg-modal-title">Sample modal</h2><p class="sub">Focus stays inside. Esc closes it and focus returns to the button.</p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <div class="field"><label for="sg-modal-note">Note</label><input id="sg-modal-note" type="text" autofocus></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="button" data-modal-close>Save note</button>
    </div>
  </form>
</dialog>
<?php require APP_SRC . '/partials/footer.php'; ?>
