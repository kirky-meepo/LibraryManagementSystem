<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$me = require_login();
$forced = $me['must_change_password'];
$error = '';

if (is_post()) {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($current === '' || !check_password($me['type'], $me['id'], $current)) {
        $error = 'Your current password is not right. Type the password you used to sign in.';
    } elseif ($problem = password_problem($new, $confirm, $current)) {
        $error = $problem;
    } else {
        set_password($me['type'], $me['id'], $new, false);
        session_regenerate_id(true);
        flash('success', 'Your password is changed.');
        redirect(home_for($me));
    }
}

$pageTitle = 'Change password';
$activeNav = 'profile';
$hideSidebar = $forced;
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <h1>Change password</h1>
  <?php if ($forced): ?>
    <p class="lead">Your password was reset. Choose a new one before you continue.</p>
  <?php endif; ?>
</div>

<form class="card form-card narrow" method="post" action="/change_password.php">
  <?= csrf_field() ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert" id="pw-error"><?= icon('alert') ?><p><?= h($error) ?></p></div>
  <?php endif; ?>
  <div class="field">
    <label for="current_password">Current password</label>
    <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
  </div>
  <div class="field">
    <label for="new_password">New password</label>
    <input id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="8" maxlength="200" aria-describedby="pw-rule">
    <p class="field-hint" id="pw-rule">At least 8 characters.</p>
  </div>
  <div class="field">
    <label for="confirm_password">Type the new password again</label>
    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required minlength="8" maxlength="200">
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Change password</button>
    <?php if (!$forced): ?><a class="btn btn-secondary" href="<?= h(home_for($me)) ?>">Cancel</a><?php endif; ?>
  </div>
</form>
<?php require APP_SRC . '/partials/footer.php'; ?>
