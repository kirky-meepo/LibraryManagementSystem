<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

$me = require_role(['librarian', 'admin']);

$row = q_row('SELECT first_name, middle_name, last_name, email, contact_number, position, date_hired FROM fn_get_staff(:id)', [':id' => $me['id']]);
$values = ['first_name' => $row['first_name'], 'middle_name' => $row['middle_name'] ?? '', 'last_name' => $row['last_name'], 'contact_number' => $row['contact_number'] ?? ''];
$errors = [];
$pwErrors = [];

if (is_post() && post('form') === 'details') {
    foreach ($values as $k => $_) {
        $values[$k] = mb_substr(post($k), 0, 80);
    }
    foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $k => $label) {
        if ($values[$k] === '') {
            $errors[$k] = "Type your $label.";
        } elseif (mb_strlen($values[$k]) > 60) {
            $errors[$k] = 'Use at most 60 characters.';
        }
    }
    if (mb_strlen($values['middle_name']) > 60) {
        $errors['middle_name'] = 'Use at most 60 characters.';
    }
    if ($values['contact_number'] !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $values['contact_number'])) {
        $errors['contact_number'] = 'Use digits, spaces, + ( ) or -, up to 20 characters.';
    }
    if (!$errors) {
        // fn_update_my_profile: only your own account; staff emails are changed by an admin in Manage Users.
        tx(function (PDO $pdo) use ($values, $me) {
            $st = $pdo->prepare("SELECT fn_update_my_profile('staff', :id, :f, :m, :l, :c, NULL)");
            $st->execute([':f' => $values['first_name'], ':m' => $values['middle_name'] ?: null, ':l' => $values['last_name'],
                          ':c' => $values['contact_number'] ?: null, ':id' => $me['id']]);
        });
        flash('success', 'Your details are saved.');
        redirect('/librarian/profile.php');
    }
}

if (is_post() && post('form') === 'password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if ($current === '' || !check_password('staff', $me['id'], $current)) {
        $pwErrors['current_password'] = 'Your current password is not right.';
    } elseif ($problem = password_problem($new, $confirm, $current)) {
        $pwErrors['new_password'] = $problem;
    } else {
        set_password('staff', $me['id'], $new, false);
        session_regenerate_id(true);
        flash('success', 'Your password is changed.');
        redirect('/librarian/profile.php');
    }
}

$pageTitle = 'Profile';
$activeNav = 'profile';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Profile</h1>
    <p class="lead">Your name and contact number, and your password.</p>
  </div>
</div>

<div class="grid-2">
  <form class="card form-card" method="post" action="/librarian/profile.php" novalidate>
    <h2 class="card-title">Your details</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="details">
    <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><p>Please fix the highlighted fields.</p></div><?php endif; ?>
    <div class="form-row">
      <div class="field">
        <label for="first_name">First name</label>
        <input id="first_name" name="first_name" type="text" maxlength="60" required autocomplete="given-name" value="<?= h($values['first_name']) ?>"<?= field_attrs($errors, 'first_name') ?>>
        <?= field_error($errors, 'first_name') ?>
      </div>
      <div class="field">
        <label for="middle_name">Middle name <span class="muted">(optional)</span></label>
        <input id="middle_name" name="middle_name" type="text" maxlength="60" autocomplete="additional-name" value="<?= h($values['middle_name']) ?>"<?= field_attrs($errors, 'middle_name') ?>>
        <?= field_error($errors, 'middle_name') ?>
      </div>
    </div>
    <div class="field">
      <label for="last_name">Last name</label>
      <input id="last_name" name="last_name" type="text" maxlength="60" required autocomplete="family-name" value="<?= h($values['last_name']) ?>"<?= field_attrs($errors, 'last_name') ?>>
      <?= field_error($errors, 'last_name') ?>
    </div>
    <div class="field">
      <label for="contact_number">Contact number <span class="muted">(optional)</span></label>
      <input id="contact_number" name="contact_number" type="tel" maxlength="20" autocomplete="tel" value="<?= h($values['contact_number']) ?>"<?= field_attrs($errors, 'contact_number') ?>>
      <?= field_error($errors, 'contact_number') ?>
    </div>
    <dl class="dl panel">
      <dt>Email</dt><dd><?= h($row['email']) ?></dd>
      <dt>Role</dt><dd><?= h(role_label($row['position'])) ?></dd>
      <dt>Hired</dt><dd><?= $row['date_hired'] ? h(fmt_date($row['date_hired'])) : 'Not set' ?></dd>
    </dl>
    <p class="field-hint">Only an admin can change your email or role, on Manage Users.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save details</button></div>
  </form>

  <form class="card form-card" method="post" action="/librarian/profile.php" novalidate>
    <h2 class="card-title">Change password</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="password">
    <div class="field">
      <label for="current_password">Current password</label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password" required<?= field_attrs($pwErrors, 'current_password') ?>>
      <?= field_error($pwErrors, 'current_password') ?>
    </div>
    <div class="field">
      <label for="new_password">New password</label>
      <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="200" required<?= field_attrs($pwErrors, 'new_password', 'pw-hint') ?>>
      <p class="field-hint" id="pw-hint">At least 8 characters.</p>
      <?= field_error($pwErrors, 'new_password') ?>
    </div>
    <div class="field">
      <label for="confirm_password">Type the new password again</label>
      <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="200" required>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
  </form>
</div>
<?php require APP_SRC . '/partials/footer.php'; ?>
