<?php
declare(strict_types=1);

/*
 * Add user / Edit user pop-up. Staff only; staff accounts only by an admin.
 * A new account gets a temporary password, returned once in this JSON answer and never stored or logged in plain text.
 */
require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/users.php';

$me = require_role(['librarian', 'admin']);
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Use the Save button to send this form.'], 405);
}

$mode = post('mode') === 'edit' ? 'edit' : 'add';
$type = post('user_type');
if (!isset(USER_TYPES[$type])) {
    json_out(['ok' => false, 'error' => 'Choose Student, Teacher or Staff.', 'errors' => ['user_type' => 'Choose Student, Teacher or Staff.']], 422);
}
if (!can_manage_user($me, $type)) {
    json_out(['ok' => false, 'error' => 'Only an admin can add or change staff accounts.'], 403);
}
$meta = USER_TYPES[$type];

$id = null;
$current = null;
if ($mode === 'edit') {
    $id = int_param('user_id', 'post');
    $current = $id !== null ? q_row('SELECT * FROM v_user_directory WHERE user_type = :t AND user_id = :id', [':t' => $type, ':id' => $id]) : null;
    if (!$current) {
        json_out(['ok' => false, 'error' => 'That account no longer exists. Reload the page.'], 404);
    }
}

[$user, $errors] = validate_user($type, $_POST, $id);
if ($errors) {
    json_out(['ok' => false, 'error' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);
}
$name = $user['first_name'] . ' ' . $user['last_name'];

/** The routine call for this account kind (04c_crud.sql). The SQL text is fixed; only values are bound. */
function user_routine(string $mode, string $type, array $u, ?int $id, array $me, ?string $hash): array
{
    $person = [':f' => $u['first_name'], ':m' => $u['middle_name'], ':l' => $u['last_name'], ':phone' => $u['contact_number'], ':email' => $u['email']];
    if ($mode === 'add') {
        return match ($type) {
            'student' => ['SELECT fn_create_student(:f, :m, :l, :course, :year, :phone, :email, :hash, :staff)',
                          $person + [':course' => $u['course'], ':year' => $u['year_level'], ':hash' => $hash, ':staff' => $me['id']]],
            'teacher' => ['SELECT fn_create_teacher(:f, :m, :l, :dept, :phone, :email, :hash, :staff)',
                          $person + [':dept' => $u['department'], ':hash' => $hash, ':staff' => $me['id']]],
            'staff' => ['SELECT fn_create_staff(:f, :m, :l, :email, :hash, :pos, :phone, :staff, CAST(:hired AS date))',
                        $person + [':hash' => $hash, ':pos' => $u['position'], ':staff' => $me['id'], ':hired' => $u['date_hired']]],
        };
    }
    return match ($type) {
        'student' => ['SELECT fn_update_student(:id, :f, :m, :l, :course, :year, :phone, :email, :staff)',
                      $person + [':id' => $id, ':course' => $u['course'], ':year' => $u['year_level'], ':staff' => $me['id']]],
        'teacher' => ['SELECT fn_update_teacher(:id, :f, :m, :l, :dept, :phone, :email, :staff)',
                      $person + [':id' => $id, ':dept' => $u['department'], ':staff' => $me['id']]],
        'staff' => ['SELECT fn_update_staff(:id, :f, :m, :l, :email, :pos, :phone, CAST(:hired AS date), :staff)',
                    $person + [':id' => $id, ':pos' => $u['position'], ':hired' => $u['date_hired'], ':staff' => $me['id']]],
    };
}

try {
    if ($mode === 'add') {
        $temporary = temporary_password();
        [$sql, $params] = user_routine('add', $type, $user, null, $me, password_hash($temporary, PASSWORD_DEFAULT));
        tx(fn(PDO $pdo) => $pdo->prepare($sql)->execute($params));
        flash('success', "$name is added as a " . $meta['one'] . '. They choose their own password at first sign-in.');
        json_out(['ok' => true, 'secret' => ['title' => 'Account created', 'name' => $name, 'password' => $temporary]]);
    }

    // fn_update_staff also keeps the last active admin from becoming a librarian.
    [$sql, $params] = user_routine('edit', $type, $user, $id, $me, null);
    tx(fn(PDO $pdo) => $pdo->prepare($sql)->execute($params));
} catch (PDOException $e) {
    $message = db_message($e);
    $field = match (true) {
        str_contains($message, 'last active admin') => 'position',
        str_contains($message, 'email') => 'email',
        default => null,
    };
    json_out(['ok' => false, 'error' => $message, 'errors' => $field ? [$field => $message] : []], 422);
}

flash('success', possessive($name) . ' details are saved.');
json_out(['ok' => true]);
