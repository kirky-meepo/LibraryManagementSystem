<?php
declare(strict_types=1);

/*
 * Forgot your password: works for students, teachers and staff.
 * PHP makes a random token (random_bytes(32)) and gives the database only its sha256; the token itself is only
 * in the emailed link. The answer is always the same sentence, so nobody can find out which emails have accounts.
 * fn_request_password_reset quietly refuses unknown or inactive emails, a 4th open link and a 6th link per IP per hour.
 */
require __DIR__ . '/../src/bootstrap.php';
require_once APP_SRC . '/mail.php';
require_once APP_SRC . '/partials/auth_page.php';

const RESET_SENT = 'If that email belongs to an account, we sent a reset link. It works for 30 minutes.';

$email = '';
$error = '';
$sent = false;
$demo = null;

if (is_post()) {
    $email = mb_substr(trim(post('email')), 0, 254);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Type the email you sign in with, for example juan.cruz@school.edu.';
    } else {
        $token = bin2hex(random_bytes(32));
        $account = q_row('SELECT * FROM fn_request_password_reset(:e, CAST(:ip AS inet), :h)', [
            ':e' => $email, ':ip' => client_ip(), ':h' => hash('sha256', $token),
        ]);
        if ($account) {
            $link = app_url() . '/reset_password.php?token=' . $token;
            $libraryName = setting('library_name', 'School Library');
            $text = "Hi {$account['first_name']},\n\n"
                . "Someone asked to reset the password for your $libraryName account.\n"
                . "To choose a new password, open this link within 30 minutes:\n\n$link\n\n"
                . "If you did not ask for this, ignore this email. Your password stays the same.\n\n"
                . "$libraryName";
            $mail = send_mail(mb_strtolower($email), 'Reset your library password', $text);
            // Demo mode: only on a developer's PC, only with the outbox, and only when a link was really made.
            if (is_dev() && mail_driver() === 'outbox' && $mail['file'] !== null) {
                $demo = ['link' => $link, 'file' => $mail['file']];
            }
        }
        $sent = true;
    }
}

auth_page_start('Forgot your password');
?>
    <div class="login-form">
      <h2>Forgot your password?</h2>
      <?php if ($sent): ?>
        <div class="alert alert-success" role="status"><?= icon('mail') ?><p><?= h(RESET_SENT) ?></p></div>
        <p class="help-text">Check your Junk or Spam folder too. No email after a few minutes? Ask for a new link, or visit the library desk.</p>
        <?php if ($demo): ?>
          <section class="demo-box" aria-labelledby="demo-title">
            <h3 id="demo-title">Demo mode</h3>
            <p>APP_ENV is dev and MAIL_DRIVER is outbox, so no email was sent. The message was saved as
               <code>storage/outbox/<?= h($demo['file']) ?></code>.</p>
            <p><a href="<?= h($demo['link']) ?>">Open the reset link</a></p>
          </section>
        <?php endif; ?>
        <p class="help-text auth-switch"><a href="/login.php">Back to sign in</a> · <a href="/forgot_password.php">Send another link</a></p>
      <?php else: ?>
        <form method="post" action="/forgot_password.php" novalidate>
          <?= csrf_field() ?>
          <p class="help-text auth-lead">Type the email you sign in with. We will send you a link to choose a new password.</p>
          <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert" id="forgot-error"><?= icon('alert') ?><p><?= h($error) ?></p></div>
          <?php endif; ?>
          <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="254" required autocomplete="username" autofocus
                   value="<?= h($email) ?>"<?= $error !== '' ? ' aria-invalid="true" aria-describedby="forgot-error"' : '' ?>>
          </div>
          <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
          <p class="help-text auth-switch"><a href="/login.php">Back to sign in</a></p>
        </form>
      <?php endif; ?>
    </div>
<?php
auth_page_end();
