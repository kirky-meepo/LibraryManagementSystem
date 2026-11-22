<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/member.php';

$me = require_role(['student', 'teacher']);
$isStudent = $me['type'] === 'student';
$row = q_row($isStudent ? 'SELECT * FROM fn_get_student(:id)' : 'SELECT * FROM fn_get_teacher(:id)', [':id' => $me['id']]);
$values = ['first_name' => $row['first_name'], 'middle_name' => $row['middle_name'] ?? '', 'last_name' => $row['last_name'],
           'email' => $row['email'], 'contact_number' => $row['contact_number'] ?? ''];
$errors = [];
$pwErrors = [];

if (is_post() && post('form') === 'details') {
    foreach ($values as $k => $_) {
        $values[$k] = mb_substr(post($k), 0, 260);
    }
    $values['email'] = mb_strtolower($values['email']);
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
    $emailChanged = $values['email'] !== $row['email'];
    if ($emailChanged) {
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 254) {
            $errors['email'] = 'Type a full email address, for example juan.cruz@school.edu.';
        } elseif (q_val('SELECT 1 FROM v_user_directory WHERE user_type = :t AND email = :e AND user_id <> :id',
                         [':t' => $me['type'], ':e' => $values['email'], ':id' => $me['id']])) {
            $errors['email'] = 'Someone else already uses this email.';
        }
        // A new email changes how you sign in, so it needs your current password.
        $current = (string) ($_POST['current_password'] ?? '');
        if ($current === '' || !check_password($me['type'], $me['id'], $current)) {
            $errors['current_password'] = 'To change your email, type your current password.';
        }
    }
    if (!$errors) {
        try {
            // fn_update_my_profile: only your own account (the password check above guards an email change).
            tx(function (PDO $pdo) use ($values, $me) {
                $st = $pdo->prepare('SELECT fn_update_my_profile(:t, :id, :f, :m, :l, :c, :e)');
                $st->execute([':t' => $me['type'], ':f' => $values['first_name'], ':m' => $values['middle_name'] ?: null, ':l' => $values['last_name'],
                              ':e' => $values['email'], ':c' => $values['contact_number'] ?: null, ':id' => $me['id']]);
            });
            flash('success', 'Your details are saved.' . ($emailChanged ? ' Sign in with your new email from now on.' : ''));
            redirect('/member/profile.php');
        } catch (PDOException $e) {
            $errors['email'] = member_words(db_message($e), $me);
        }
    }
}

if (is_post() && post('form') === 'password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if ($current === '' || !check_password($me['type'], $me['id'], $current)) {
        $pwErrors['pw_current'] = 'Your current password is not right.';
    } elseif ($problem = password_problem($new, $confirm, $current)) {
        $pwErrors['new_password'] = $problem;
    } else {
        set_password($me['type'], $me['id'], $new, false);
        session_regenerate_id(true);
        flash('success', 'Your password is changed.');
        redirect('/member/profile.php');
    }
}

$pageTitle = 'My Profile';
$activeNav = 'profile';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>My Profile</h1>
    <p class="lead">Your name, email and contact number. The library changes your <?= $isStudent ? 'course and year level' : 'department' ?>.</p>
  </div>
</div>

<div class="grid-2">
  <form class="card form-card" method="post" action="/member/profile.php" novalidate>
    <h2 class="card-title">Your details</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="details">
    <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= icon('alert') ?><p>Nothing was saved. Please fix the highlighted fields.</p></div><?php endif; ?>
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
      <label for="email">Email</label>
      <input id="email" name="email" type="email" maxlength="254" required autocomplete="email" value="<?= h($values['email']) ?>"<?= field_attrs($errors, 'email', 'email-hint') ?>>
      <p class="field-hint" id="email-hint">You sign in with this email. Changing it needs your current password.</p>
      <?= field_error($errors, 'email') ?>
    </div>
    <div class="field">
      <label for="current_password">Current password <span class="muted">(only to change your email)</span></label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password"<?= field_attrs($errors, 'current_password') ?>>
      <?= field_error($errors, 'current_password') ?>
    </div>
    <div class="field">
      <label for="contact_number">Contact number <span class="muted">(optional)</span></label>
      <input id="contact_number" name="contact_number" type="tel" maxlength="20" autocomplete="tel" value="<?= h($values['contact_number']) ?>"<?= field_attrs($errors, 'contact_number') ?>>
      <?= field_error($errors, 'contact_number') ?>
    </div>
    <dl class="dl panel">
      <dt>Role</dt><dd><?= h(role_label($me['role'])) ?></dd>
      <?php if ($isStudent): ?>
        <dt>Course</dt><dd><?= h($row['course']) ?></dd>
        <dt>Year level</dt><dd>Year <?= (int) $row['year_level'] ?></dd>
      <?php else: ?>
        <dt>Department</dt><dd><?= h($row['department']) ?></dd>
      <?php endif; ?>
      <dt>Member since</dt><dd><?= h(fmt_date($row['date_registered'])) ?></dd>
    </dl>
    <p class="field-hint">To change these, ask at the library desk.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save details</button></div>
  </form>

  <form class="card form-card" method="post" action="/member/profile.php" novalidate>
    <h2 class="card-title">Change password</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="password">
    <div class="field">
      <label for="pw_current">Current password</label>
      <input id="pw_current" name="current_password" type="password" autocomplete="current-password" required<?= field_attrs($pwErrors, 'pw_current') ?>>
      <?= field_error($pwErrors, 'pw_current') ?>
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
