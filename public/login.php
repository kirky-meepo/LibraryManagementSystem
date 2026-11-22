<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require_once APP_SRC . '/partials/auth_page.php';

$me = current_user();
if ($me) {
    redirect($me['must_change_password'] ? '/change_password.php' : home_for($me));
}

/** Only follow ?next= to a page on this site. */
function safe_next(string $next): string
{
    return preg_match('#^/(librarian|member)/[a-z_]+\.php(\?[^\s]*)?$#', $next) ? $next : '';
}

$kind = get('kind') === 'staff' ? 'staff' : 'member';
$email = '';
$error = '';
$state = '';
$next = safe_next(get('next'));

if (is_post()) {
    $kind = post('kind') === 'staff' ? 'staff' : 'member';
    $email = mb_substr(post('email'), 0, 254);
    $password = (string) ($_POST['password'] ?? '');
    $next = safe_next(post('next'));

    if ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
        $state = 'wrong';
    } else {
        [$ok, $error, $state] = login($kind, $email, $password);
        if ($ok) {
            $user = current_user();
            if ($user['must_change_password']) {
                redirect('/change_password.php');
            }
            redirect($next !== '' ? $next : home_for($user));
        }
    }
}

auth_page_start('Sign in');
?>
    <form class="login-form" method="post" action="/login.php" novalidate>
      <h2>Sign in</h2>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">

      <fieldset class="segmented">
        <legend>I am signing in as</legend>
        <div class="segmented-options">
          <input type="radio" id="kind-member" name="kind" value="member" data-type-switch<?= $kind === 'member' ? ' checked' : '' ?>>
          <label for="kind-member">Member <span class="segmented-hint">Student or teacher</span></label>
          <input type="radio" id="kind-staff" name="kind" value="staff" data-type-switch<?= $kind === 'staff' ? ' checked' : '' ?>>
          <label for="kind-staff">Librarian/Admin <span class="segmented-hint">Library staff</span></label>
        </div>
      </fieldset>

      <?php if ($error !== ''): ?>
        <div class="alert <?= $state === 'locked' ? 'alert-locked' : 'alert-error' ?>" role="alert" id="login-error">
          <?= icon($state === 'locked' ? 'lock' : 'alert') ?>
          <p><?= h($error) ?></p>
        </div>
      <?php endif; ?>

      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required maxlength="254"
               value="<?= h($email) ?>"<?= $error !== '' ? ' aria-describedby="login-error"' : '' ?><?= $email === '' ? ' autofocus' : '' ?>>
      </div>
      <div class="field">
        <div class="field-label-row">
          <label for="password">Password</label>
          <a class="field-link" href="/forgot_password.php">Forgot your password?</a>
        </div>
        <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="200"<?= $email !== '' ? ' autofocus' : '' ?>>
      </div>

      <button class="btn btn-primary btn-block" type="submit"<?= $state === 'locked' ? ' disabled' : '' ?>>Sign in</button>
      <p class="help-text auth-switch" data-type-section="member"<?= $kind === 'staff' ? ' hidden' : '' ?>>
        New to the library? <a href="/signup.php">Create an account</a>
      </p>
    </form>
<?php
auth_page_end();
