<?php
declare(strict_types=1);

/*
 * Create an account: students and teachers only. Staff accounts are made by an admin in Manage Users.
 * PHP hashes the password; fn_register_member checks every rule (one email per person across students,
 * teachers and staff, never staff, at most 5 sign-ups from one IP address an hour) and writes the audit row.
 */
require __DIR__ . '/../src/bootstrap.php';
require_once APP_SRC . '/users.php';
require_once APP_SRC . '/partials/auth_page.php';

$me = current_user();
if ($me) {
    redirect(home_for($me));
}

$type = post('user_type') === 'teacher' ? 'teacher' : 'student';
$values = ['first_name' => '', 'middle_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => '',
           'course' => '', 'year_level' => '1', 'department' => ''];
$errors = [];
$formError = '';

if (is_post()) {
    foreach (array_keys($values) as $key) {
        $values[$key] = mb_substr(post($key), 0, 260);
    }
    [$clean, $errors] = validate_user($type, $_POST);
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if ($problem = password_problem($password, $confirm)) {
        $errors[$password === '' || mb_strlen($password) < 8 || mb_strlen($password) > 200 ? 'password' : 'confirm_password'] = $problem;
    }
    if (isset($errors['email']) && str_starts_with($errors['email'], 'Another')) {
        $errors['email'] = 'That email already has an account. Sign in, or use Forgot your password.';
    }

    if (!$errors) {
        try {
            q_val('SELECT fn_register_member(:type, :first, :middle, :last, :email, :hash, :course, :year, :dept, :phone, CAST(:ip AS inet))', [
                ':type' => $type,
                ':first' => $clean['first_name'],
                ':middle' => $clean['middle_name'],
                ':last' => $clean['last_name'],
                ':email' => $clean['email'],
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':course' => $type === 'student' ? $clean['course'] : null,
                ':year' => $type === 'student' ? $clean['year_level'] : null,
                ':dept' => $type === 'teacher' ? $clean['department'] : null,
                ':phone' => $clean['contact_number'],
                ':ip' => client_ip(),
            ]);
            flash('success', 'Account created. Sign in to continue.');
            redirect('/login.php');
        } catch (PDOException $e) {
            $message = db_message($e);
            if (str_contains($message, 'email')) {
                $errors['email'] = $message;
            } else {
                $formError = $message;
            }
        }
    }
    if ($errors && $formError === '') {
        $formError = 'Please fix the highlighted fields.';
    }
}

auth_page_start('Create an account');
?>
    <form class="login-form" method="post" action="/signup.php" novalidate>
      <h2>Create an account</h2>
      <?= csrf_field() ?>
      <p class="help-text auth-lead">For students and teachers. Library staff accounts are made by an admin.</p>

      <fieldset class="segmented">
        <legend>I am a</legend>
        <div class="segmented-options">
          <input type="radio" id="type-student" name="user_type" value="student" data-type-switch<?= $type === 'student' ? ' checked' : '' ?>>
          <label for="type-student">Student</label>
          <input type="radio" id="type-teacher" name="user_type" value="teacher" data-type-switch<?= $type === 'teacher' ? ' checked' : '' ?>>
          <label for="type-teacher">Teacher</label>
        </div>
      </fieldset>

      <?php if ($formError !== ''): ?>
        <div class="alert alert-error" role="alert"><?= icon('alert') ?><p><?= h($formError) ?></p></div>
      <?php endif; ?>

      <div class="form-row">
        <div class="field">
          <label for="first_name">First name</label>
          <input id="first_name" name="first_name" type="text" maxlength="60" required autocomplete="given-name"
                 value="<?= h($values['first_name']) ?>"<?= field_attrs($errors, 'first_name') ?><?= is_post() ? '' : ' autofocus' ?>>
          <?= field_error($errors, 'first_name') ?>
        </div>
        <div class="field">
          <label for="last_name">Last name</label>
          <input id="last_name" name="last_name" type="text" maxlength="60" required autocomplete="family-name"
                 value="<?= h($values['last_name']) ?>"<?= field_attrs($errors, 'last_name') ?>>
          <?= field_error($errors, 'last_name') ?>
        </div>
      </div>
      <div class="field">
        <label for="middle_name">Middle name <span class="muted">(optional)</span></label>
        <input id="middle_name" name="middle_name" type="text" maxlength="60" autocomplete="additional-name"
               value="<?= h($values['middle_name']) ?>"<?= field_attrs($errors, 'middle_name') ?>>
        <?= field_error($errors, 'middle_name') ?>
      </div>

      <div class="form-row" data-type-section="student"<?= $type === 'student' ? '' : ' hidden' ?>>
        <div class="field">
          <label for="course">Course</label>
          <input id="course" name="course" type="text" maxlength="80" required
                 value="<?= h($values['course']) ?>"<?= field_attrs($errors, 'course', 'course-hint') ?><?= $type === 'student' ? '' : ' disabled' ?>>
          <p class="field-hint" id="course-hint">For example BS Information Technology.</p>
          <?= field_error($errors, 'course') ?>
        </div>
        <div class="field">
          <label for="year_level">Year level</label>
          <select id="year_level" name="year_level" required<?= field_attrs($errors, 'year_level') ?><?= $type === 'student' ? '' : ' disabled' ?>>
            <?php for ($y = 1; $y <= 6; $y++): ?>
              <option value="<?= $y ?>"<?= (string) $y === $values['year_level'] ? ' selected' : '' ?>>Year <?= $y ?></option>
            <?php endfor; ?>
          </select>
          <?= field_error($errors, 'year_level') ?>
        </div>
      </div>
      <div class="field" data-type-section="teacher"<?= $type === 'teacher' ? '' : ' hidden' ?>>
        <label for="department">Department</label>
        <input id="department" name="department" type="text" maxlength="80" required
               value="<?= h($values['department']) ?>"<?= field_attrs($errors, 'department', 'dept-hint') ?><?= $type === 'teacher' ? '' : ' disabled' ?>>
        <p class="field-hint" id="dept-hint">For example College of Engineering.</p>
        <?= field_error($errors, 'department') ?>
      </div>

      <div class="field">
        <label for="contact_number">Contact number <span class="muted">(optional)</span></label>
        <input id="contact_number" name="contact_number" type="tel" maxlength="20" autocomplete="tel"
               value="<?= h($values['contact_number']) ?>"<?= field_attrs($errors, 'contact_number') ?>>
        <?= field_error($errors, 'contact_number') ?>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" maxlength="254" required autocomplete="email"
               value="<?= h($values['email']) ?>"<?= field_attrs($errors, 'email', 'email-hint') ?>>
        <p class="field-hint" id="email-hint">You sign in with this. Use your school email if you have one.</p>
        <?= field_error($errors, 'email') ?>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" minlength="8" maxlength="200" required autocomplete="new-password"
               <?= field_attrs($errors, 'password', 'pw-rule') ?>>
        <p class="field-hint" id="pw-rule">At least 8 characters.</p>
        <?= field_error($errors, 'password') ?>
      </div>
      <div class="field">
        <label for="confirm_password">Type the password again</label>
        <input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="200" required autocomplete="new-password"
               <?= field_attrs($errors, 'confirm_password') ?>>
        <?= field_error($errors, 'confirm_password') ?>
      </div>

      <button class="btn btn-primary btn-block" type="submit">Create account</button>
      <p class="help-text auth-switch">Already have an account? <a href="/login.php">Sign in</a></p>
    </form>
<?php
auth_page_end();
