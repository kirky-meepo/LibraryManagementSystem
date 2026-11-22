<?php
declare(strict_types=1);

/*
 * Reset password from the emailed link (?token=...). The database only ever sees sha256(token).
 * fn_check_reset_token says whether the link is still good; fn_reset_password stores the new hash, uses up
 * this link and every other open link of the account, and returns who it was, so the owner gets a
 * "Your password was changed" notice.
 */
require __DIR__ . '/../src/bootstrap.php';
require_once APP_SRC . '/mail.php';
require_once APP_SRC . '/partials/auth_page.php';

// The token is in the address: never send it on to another site, and never cache this page.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$token = is_post() ? post('token') : get('token');
$token = preg_match('/^[0-9a-f]{64}$/', $token) ? $token : '';
$account = $token !== '' ? q_row('SELECT * FROM fn_check_reset_token(:h)', [':h' => hash('sha256', $token)]) : null;
$error = '';

if ($account && is_post()) {
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if ($problem = password_problem($new, $confirm)) {
        $error = $problem;
    } else {
        try {
            $done = q_row('SELECT * FROM fn_reset_password(:h, :p)', [
                ':h' => hash('sha256', $token), ':p' => password_hash($new, PASSWORD_DEFAULT),
            ]);
            $libraryName = setting('library_name', 'School Library');
            send_mail($done['email'], 'Your password was changed',
                "Hi {$done['first_name']},\n\n"
                . 'The password for your ' . $libraryName . ' account was changed on ' . date('M j, Y') . ' at ' . date('g:i A') . ".\n"
                . "If you did this, there is nothing else to do.\n"
                . "If you did not, use Forgot your password on the sign-in page right away, or tell the library desk.\n\n"
                . $libraryName);
            // Signed in somewhere with the old password? Start clean here too.
            if (isset($_SESSION['auth'])) {
                logout_session();
            }
            flash('success', 'Password changed. Sign in with your new password.');
            redirect('/login.php' . ($done['user_type'] === 'staff' ? '?kind=staff' : ''));
        } catch (PDOException $e) {
            if (db_rule_code($e) === null) {
                throw $e;
            }
            $error = db_message($e);
            $account = null;
        }
    }
}

auth_page_start('Reset password');
?>
    <div class="login-form">
      <h2>Choose a new password</h2>
      <?php if (!$account): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert') ?>
          <p><?= h($error !== '' ? $error : 'This reset link has expired or was already used. Ask for a new one.') ?></p>
        </div>
        <a class="btn btn-primary btn-block" href="/forgot_password.php">Ask for a new link</a>
        <p class="help-text auth-switch"><a href="/login.php">Back to sign in</a></p>
      <?php else: ?>
        <form method="post" action="/reset_password.php" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <p class="help-text auth-lead">Hi <?= h($account['first_name']) ?>. Choose a new password for <strong><?= h($account['email']) ?></strong>.</p>
          <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert" id="reset-error"><?= icon('alert') ?><p><?= h($error) ?></p></div>
          <?php endif; ?>
          <div class="field">
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password" autofocus
                   aria-describedby="pw-rule<?= $error !== '' ? ' reset-error' : '' ?>">
            <p class="field-hint" id="pw-rule">At least 8 characters.</p>
          </div>
          <div class="field">
            <label for="confirm_password">Type the new password again</label>
            <input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password">
          </div>
          <button class="btn btn-primary btn-block" type="submit">Save new password</button>
          <p class="help-text auth-switch"><a href="/login.php">Back to sign in</a></p>
        </form>
      <?php endif; ?>
    </div>
<?php
auth_page_end();
