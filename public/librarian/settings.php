<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/activity.php';

$me = require_role(['admin']);

/*
 * The editable library settings. Each has a label, a hint, and a check that returns the clean value or an error.
 * Changes apply to new loans and requests straight away and are written to the audit log by its trigger.
 */
$whole = static fn(int $min, int $max) => static function (string $v) use ($min, $max): array {
    return preg_match('/^\d{1,4}$/', $v) && (int) $v >= $min && (int) $v <= $max
        ? [(string) (int) $v, null]
        : [null, "Use a whole number from $min to $max."];
};
$fields = [
    'library_name' => ['Library name', 'Shown on the sign-in page and at the end of messages.', static function (string $v): array {
        return $v !== '' && mb_strlen($v) <= 80 ? [$v, null] : [null, 'Use 1 to 80 characters.'];
    }],
    'library_hours' => ['Library hours', 'Written like Mon-Fri 8:00 AM-5:00 PM. Pickup dates and times must fall inside these hours.', static function (string $v): array {
        $day = '(mon|tue|wed|thu|fri|sat|sun)[a-z]*';
        $time = '\d{1,2}:\d{2}\s*[ap]\.?m\.?';
        return preg_match("/^\s*$day\s*(-\s*$day)?\s+$time\s*-\s*$time\s*$/i", $v) && mb_strlen($v) <= 60
            ? [$v, null]
            : [null, 'Write the days and hours like Mon-Fri 8:00 AM-5:00 PM.'];
    }],
    'loan_days_student' => ['Loan days for students', 'How long a student keeps a book.', $whole(1, 120)],
    'loan_days_teacher' => ['Loan days for teachers', 'How long a teacher keeps a book.', $whole(1, 180)],
    'max_loans_student' => ['Borrowing limit for students', 'Loans plus open requests a student can have at once.', $whole(1, 30)],
    'max_loans_teacher' => ['Borrowing limit for teachers', 'Loans plus open requests a teacher can have at once.', $whole(1, 50)],
    'fine_per_day' => ['Fine per day (₱)', 'Charged for each day a book is late, when it comes back.', static function (string $v): array {
        $v = str_replace([',', '₱', ' '], '', $v);
        return preg_match('/^\d{1,5}(\.\d{1,2})?$/', $v) ? [number_format((float) $v, 2, '.', ''), null] : [null, 'Use 0 or more pesos, for example 10 or 10.50.'];
    }],
    'pickup_window_days' => ['Pickup window (days)', 'How long an approved book is held after the pickup date.', $whole(0, 30)],
    'session_idle_minutes' => ['Sign out after (minutes idle)', 'People are signed out after this long without activity.', $whole(5, 480)],
];

$current = [];
foreach (q_all('SELECT setting_key, setting_value FROM library_setting') as $row) {
    $current[$row['setting_key']] = $row['setting_value'];
}
$values = $current;
$errors = [];

if (is_post()) {
    $changes = [];
    foreach ($fields as $key => [$label, , $check]) {
        $raw = mb_substr(post($key), 0, 100);
        $values[$key] = $raw;
        [$clean, $error] = $check($raw);
        if ($error !== null) {
            $errors[$key] = $error;
        } elseif ($clean !== ($current[$key] ?? null)) {
            $changes[$key] = $clean;
        }
    }
    if (!$errors) {
        if ($changes) {
            tx(function (PDO $pdo) use ($changes) {
                $st = $pdo->prepare('UPDATE library_setting SET setting_value = :v WHERE setting_key = :k');
                foreach ($changes as $k => $v) {
                    $st->execute([':v' => $v, ':k' => $k]);
                }
            });
            flash('success', 'Saved: ' . implode(', ', array_map(fn($k) => SETTING_LABELS[$k] ?? $k, array_keys($changes))) . '. New loans and requests use them from now on.');
        } else {
            flash('info', 'Nothing changed.');
        }
        redirect('/librarian/settings.php');
    }
}

$pageTitle = 'Settings';
$activeNav = 'settings';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Settings</h1>
    <p class="lead">Library rules used by every loan, request and message. Changes apply straight away to new loans and requests; loans already out keep their due dates.</p>
  </div>
</div>

<form class="card form-card" method="post" action="/librarian/settings.php" novalidate style="max-width: 820px">
  <?= csrf_field() ?>
  <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><p>Nothing was saved. Please fix the highlighted fields.</p></div><?php endif; ?>
  <?php foreach ([['Library', ['library_name', 'library_hours']], ['Loans', ['loan_days_student', 'loan_days_teacher', 'max_loans_student', 'max_loans_teacher']],
                  ['Fines and pickups', ['fine_per_day', 'pickup_window_days']], ['Security', ['session_idle_minutes']]] as [$group, $keys]): ?>
    <fieldset class="settings-group">
      <legend><?= h($group) ?></legend>
      <div class="form-row">
        <?php foreach ($keys as $key): [$label, $hint] = $fields[$key]; ?>
          <div class="field">
            <label for="<?= h($key) ?>"><?= h($label) ?></label>
            <input id="<?= h($key) ?>" name="<?= h($key) ?>" type="text" maxlength="100" required
                   <?= in_array($key, ['library_name', 'library_hours'], true) ? '' : 'inputmode="decimal"' ?> value="<?= h($values[$key] ?? '') ?>"<?= field_attrs($errors, $key, 'hint-' . $key) ?>>
            <p class="field-hint" id="hint-<?= h($key) ?>"><?= h($hint) ?></p>
            <?= field_error($errors, $key) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endforeach; ?>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Save settings</button></div>
</form>
<?php require APP_SRC . '/partials/footer.php'; ?>
