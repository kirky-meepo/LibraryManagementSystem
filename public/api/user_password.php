<?php
declare(strict_types=1);

/*
 * Reset password. Makes a random temporary password and sends only its hash to fn_staff_reset_password,
 * which checks the rules (only an admin for staff, never yourself) and sets must_change_password.
 * The plain password is returned once in this answer. It is never stored, flashed or logged.
 */
require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/users.php';

$me = require_role(['librarian', 'admin']);
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Use the Reset password button.'], 405);
}

$type = post('user_type');
$id = int_param('user_id', 'post');
if (!isset(USER_TYPES[$type]) || $id === null) {
    json_out(['ok' => false, 'error' => 'Choose an account first.'], 422);
}
if (!can_manage_user($me, $type)) {
    json_out(['ok' => false, 'error' => 'Only an admin can reset staff passwords.'], 403);
}
if ($type === 'staff' && $id === $me['id']) {
    json_out(['ok' => false, 'error' => 'To change your own password, use Profile.'], 422);
}
$person = q_row('SELECT full_name, is_active FROM v_user_directory WHERE user_type = :t AND user_id = :id', [':t' => $type, ':id' => $id]);
if (!$person) {
    json_out(['ok' => false, 'error' => 'That account no longer exists. Reload the page.'], 404);
}

$temporary = temporary_password();
try {
    staff_reset_password($me, $type, $id, $temporary);
} catch (PDOException $e) {
    json_out(['ok' => false, 'error' => db_message($e)], 422);
}

flash('success', 'Password reset for ' . $person['full_name'] . '. They must choose a new one when they sign in.');
json_out(['ok' => true, 'secret' => ['title' => 'Temporary password', 'name' => $person['full_name'], 'password' => $temporary]]);
