<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
require_once APP_SRC . '/users.php';
require_once APP_SRC . '/partials/pager.php';

$me = require_role(['librarian', 'admin']);
$isAdmin = is_admin($me);

$type = get('type');
$type = isset(USER_TYPES[$type]) ? $type : 'student';

// Deactivate, reactivate and delete go through the routines, which check every rule again.
if (is_post()) {
    $postType = post('user_type');
    $userId = int_param('user_id', 'post');
    $action = post('action');
    $back = '/librarian/users.php?' . http_build_query(['type' => $postType, 'q' => post('q'), 'status' => post('status')]);
    if (!isset(USER_TYPES[$postType]) || $userId === null || !in_array($action, ['deactivate', 'reactivate', 'delete'], true)) {
        flash('error', 'Choose an account and an action.');
        redirect('/librarian/users.php');
    }
    $person = q_val('SELECT full_name FROM v_user_directory WHERE user_type = :t AND user_id = :id', [':t' => $postType, ':id' => $userId]);
    try {
        tx(function (PDO $pdo) use ($action, $postType, $userId, $me) {
            if ($action === 'delete') {
                $st = $pdo->prepare('SELECT fn_delete_user(:t, :id, :staff)');
                $st->execute([':t' => $postType, ':id' => $userId, ':staff' => $me['id']]);
            } else {
                $st = $pdo->prepare('SELECT fn_set_user_active(:t, :id, :active, :staff)');
                $st->execute([':t' => $postType, ':id' => $userId, ':active' => $action === 'reactivate' ? 'true' : 'false', ':staff' => $me['id']]);
            }
        });
        flash('success', match ($action) {
            'delete' => "$person is deleted.",
            'deactivate' => "$person is deactivated and can no longer sign in. Pending requests were cancelled.",
            'reactivate' => "$person is active again and can sign in.",
        });
    } catch (PDOException $e) {
        flash('error', db_message($e));
    }
    redirect($back);
}

$q = mb_substr(get('q'), 0, 100);
$status = in_array(get('status'), ['active', 'inactive', 'all'], true) ? get('status') : 'active';
$perPage = 15;
[$page, $offset] = page_offset($perPage);

// fn_list_students, fn_list_teachers or fn_list_staff (04c_crud.sql); the function name comes from a fixed list.
$listFn = ['student' => 'fn_list_students', 'teacher' => 'fn_list_teachers', 'staff' => 'fn_list_staff'][$type];
[$people, $total] = list_rows(
    "SELECT * FROM $listFn(:q, CAST(:active AS boolean), :limit, :offset)",
    [':q' => $q, ':active' => $status === 'all' ? null : ($status === 'active' ? 'true' : 'false')],
    $perPage,
    (int) $offset
);
$counts = [];
foreach (q_all('SELECT user_type, count(*) AS n FROM v_user_directory GROUP BY user_type') as $row) {
    $counts[$row['user_type']] = (int) $row['n'];
}
$courses = q_all('SELECT course AS v FROM fn_list_courses()');
$departments = q_all('SELECT department AS v FROM fn_list_departments()');

$canManage = can_manage_user($me, $type);
$addTypes = $isAdmin ? ['student', 'teacher', 'staff'] : ['student', 'teacher'];

/** The fields of the Add/Edit user pop-up. */
function user_fields(string $prefix, array $addTypes, bool $isAdd): void
{
    ?>
    <div class="alert alert-error" data-form-error hidden role="alert"><?= icon('alert') ?><p></p></div>
    <?php if ($isAdd): ?>
      <div class="field">
        <label for="<?= $prefix ?>-type">Account type</label>
        <select id="<?= $prefix ?>-type" name="user_type" data-type-switch>
          <?php foreach ($addTypes as $t): ?><option value="<?= h($t) ?>"><?= h(ucfirst(USER_TYPES[$t]['one'])) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="user_type" data-type-switch>
    <?php endif; ?>
    <div class="form-row">
      <div class="field">
        <label for="<?= $prefix ?>-first">First name</label>
        <input id="<?= $prefix ?>-first" name="first_name" type="text" maxlength="60" required autocomplete="off">
        <p class="field-error" data-error-for="first_name" hidden></p>
      </div>
      <div class="field">
        <label for="<?= $prefix ?>-middle">Middle name <span class="muted">(optional)</span></label>
        <input id="<?= $prefix ?>-middle" name="middle_name" type="text" maxlength="60" autocomplete="off">
        <p class="field-error" data-error-for="middle_name" hidden></p>
      </div>
      <div class="field">
        <label for="<?= $prefix ?>-last">Last name</label>
        <input id="<?= $prefix ?>-last" name="last_name" type="text" maxlength="60" required autocomplete="off">
        <p class="field-error" data-error-for="last_name" hidden></p>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label for="<?= $prefix ?>-email">Email</label>
        <input id="<?= $prefix ?>-email" name="email" type="email" maxlength="254" required autocomplete="off">
        <p class="field-hint">Students and teachers sign in with this, so no two members can share it.</p>
        <p class="field-error" data-error-for="email" hidden></p>
      </div>
      <div class="field">
        <label for="<?= $prefix ?>-contact">Contact number <span class="muted">(optional)</span></label>
        <input id="<?= $prefix ?>-contact" name="contact_number" type="tel" maxlength="20" autocomplete="off">
        <p class="field-error" data-error-for="contact_number" hidden></p>
      </div>
    </div>
    <div class="form-row" data-type-section="student">
      <div class="field">
        <label for="<?= $prefix ?>-course">Course</label>
        <input id="<?= $prefix ?>-course" name="course" type="text" maxlength="80" list="course-list" required>
        <p class="field-error" data-error-for="course" hidden></p>
      </div>
      <div class="field">
        <label for="<?= $prefix ?>-year">Year level</label>
        <select id="<?= $prefix ?>-year" name="year_level" required>
          <?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
        </select>
        <p class="field-error" data-error-for="year_level" hidden></p>
      </div>
    </div>
    <div class="field" data-type-section="teacher">
      <label for="<?= $prefix ?>-dept">Department</label>
      <input id="<?= $prefix ?>-dept" name="department" type="text" maxlength="80" list="department-list" required>
      <p class="field-error" data-error-for="department" hidden></p>
    </div>
    <div class="form-row" data-type-section="staff">
      <div class="field">
        <label for="<?= $prefix ?>-position">Position</label>
        <select id="<?= $prefix ?>-position" name="position" required>
          <option value="librarian">Librarian</option>
          <option value="admin">Admin</option>
        </select>
        <p class="field-error" data-error-for="position" hidden></p>
      </div>
      <div class="field">
        <label for="<?= $prefix ?>-hired">Date hired <span class="muted">(optional)</span></label>
        <input id="<?= $prefix ?>-hired" name="date_hired" type="date" max="<?= h(today()) ?>">
        <p class="field-error" data-error-for="date_hired" hidden></p>
      </div>
    </div>
    <?php
}

$pageTitle = 'Manage Users';
$activeNav = 'users';
require APP_SRC . '/partials/header.php';
?>
<div class="page-head">
  <div>
    <h1>Manage Users</h1>
    <p class="lead">Students, teachers and staff in one place.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="button" data-modal-open="user-add" data-fill="<?= h(json_encode(['user_type' => in_array($type, $addTypes, true) ? $type : 'student', 'year_level' => '1', 'position' => 'librarian'])) ?>"><?= icon('plus') ?> Add user</button>
  </div>
</div>

<form class="toolbar" method="get" action="/librarian/users.php" role="search" data-live-search>
  <div class="field">
    <label for="type">Show</label>
    <select id="type" name="type" data-autosubmit>
      <?php foreach (USER_TYPES as $key => $meta): ?>
        <option value="<?= h($key) ?>"<?= $key === $type ? ' selected' : '' ?>><?= h($meta['label']) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field grow">
    <label for="q">Search</label>
    <div class="search-field"><?= icon('search') ?><input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder="Name or email" maxlength="100"></div>
  </div>
  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status" data-autosubmit>
      <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
      <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
      <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option>
    </select>
  </div>
  <noscript><button class="btn btn-secondary" type="submit">Show</button></noscript>
</form>

<?php if (!$canManage): ?>
  <div class="alert alert-info"><?= icon('info') ?><p>Only an admin can add or change staff accounts. You can see the list.</p></div>
<?php endif; ?>

<?php if (!$people): ?>
  <div class="empty"><?= icon('users') ?>
    <strong>No <?= h(strtolower(USER_TYPES[$type]['label'])) ?> match</strong>
    <p><?= $q !== '' ? 'Try part of the name or email, or set Status to All.' : 'Set Status to All, or add someone with Add user.' ?></p>
  </div>
<?php else: ?>
  <div class="table-wrap" role="region" aria-label="<?= h(USER_TYPES[$type]['label']) ?>" tabindex="0">
    <table>
      <thead>
        <tr>
          <th scope="col">Name</th>
          <?php if ($type === 'student'): ?><th scope="col">Course</th><th scope="col">Year level</th>
          <?php elseif ($type === 'teacher'): ?><th scope="col">Department</th>
          <?php else: ?><th scope="col">Position</th><?php endif; ?>
          <th scope="col">Contact number</th>
          <th scope="col">Status</th>
          <th scope="col">Activity</th>
          <th scope="col"><?= $type === 'staff' ? 'Hired' : 'Joined' ?></th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($people as $p):
            $isSelf = $type === 'staff' && (int) $p['user_id'] === $me['id'];
            $fill = [
                'mode' => 'edit', 'user_type' => $type, 'user_id' => (int) $p['user_id'], 'name' => $p['full_name'],
                'first_name' => $p['first_name'], 'middle_name' => $p['middle_name'] ?? '', 'last_name' => $p['last_name'],
                'email' => $p['email'], 'contact_number' => $p['contact_number'] ?? '',
                'course' => $p['course'] ?? '', 'year_level' => $p['year_level'] ?? '1', 'department' => $p['department'] ?? '',
                'position' => $p['position'] ?? 'librarian', 'date_hired' => $type === 'staff' ? ($p['joined_on'] ?? '') : '',
            ];
            $keep = ['user_type' => $type, 'user_id' => (int) $p['user_id']]; ?>
          <tr<?= $p['is_active'] ? '' : ' class="is-inactive"' ?>>
            <td><strong><?= h(trim($p['first_name'] . ' ' . ($p['middle_name'] ? mb_substr($p['middle_name'], 0, 1) . '. ' : '') . $p['last_name'])) ?></strong>
              <span class="sub"><?= h($p['email']) ?></span></td>
            <?php if ($type === 'student'): ?><td><?= h($p['course']) ?></td><td>Year <?= (int) $p['year_level'] ?></td>
            <?php elseif ($type === 'teacher'): ?><td><?= h($p['department']) ?></td>
            <?php else: ?><td><?= h($p['detail']) ?><?= $isSelf ? ' <span class="chip chip-info chip-plain">You</span>' : '' ?></td><?php endif; ?>
            <td class="nowrap"><?= $p['contact_number'] ? h($p['contact_number']) : '<span class="muted">None</span>' ?></td>
            <td><?= $p['is_active'] ? '<span class="chip chip-ok">Active</span>' : '<span class="chip">Inactive</span>' ?>
              <?= $p['must_change_password'] ? '<span class="sub">Must change password</span>' : '' ?></td>
            <td><?php if ($p['is_signed_in']): ?><span class="chip chip-ok">Active</span><span class="sub">Signed in now</span>
              <?php else: ?><span class="chip">Inactive</span><span class="sub"><?= $p['last_seen'] ? 'Last seen ' . h(fmt_datetime($p['last_seen'])) : 'Never signed in' ?></span><?php endif; ?></td>
            <td class="nowrap"><?= $p['joined_on'] ? h(fmt_date($p['joined_on'])) : '<span class="muted">Not set</span>' ?></td>
            <td class="actions">
              <?php if (!$canManage): ?>
                <span class="small muted">Admin only</span>
              <?php else: ?>
                <details class="row-menu">
                  <summary class="btn btn-secondary btn-sm"><?= icon('more') ?> Actions<span class="sr-only"> for <?= h($p['full_name']) ?></span></summary>
                  <div class="menu-list">
                    <?php if ($isSelf): ?>
                      <a href="/librarian/profile.php"><?= icon('user') ?> Edit my profile</a>
                      <p class="menu-note">You cannot deactivate or delete your own account.</p>
                    <?php else: ?>
                      <button type="button" data-modal-open="user-edit" data-fill="<?= h(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><?= icon('edit') ?> Edit</button>
                      <form method="post" action="/api/user_password.php" data-ajax data-confirm="<?= h('Reset the password for ' . $p['full_name'] . '? Their current password stops working, and a temporary one is shown once.') ?>" data-confirm-label="Reset password">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_type" value="<?= h($type) ?>"><input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                        <button type="submit"><?= icon('key') ?> Reset password</button>
                      </form>
                      <form method="post" data-confirm="<?= h($p['is_active']
                          ? 'Deactivate ' . $p['full_name'] . '? They can no longer sign in, and their pending requests are cancelled.'
                          : 'Reactivate ' . $p['full_name'] . '? They can sign in again.') ?>" data-confirm-label="<?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_type" value="<?= h($type) ?>"><input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                        <input type="hidden" name="action" value="<?= $p['is_active'] ? 'deactivate' : 'reactivate' ?>">
                        <input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="status" value="<?= h($status) ?>">
                        <button type="submit"><?= icon($p['is_active'] ? 'lock' : 'restore') ?> <?= $p['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                      </form>
                      <?php if ($isAdmin && !$p['has_history']): ?>
                        <form method="post" data-confirm="<?= h('Delete ' . $p['full_name'] . ' for good? This cannot be undone.') ?>" data-confirm-label="Delete user" data-confirm-danger>
                          <?= csrf_field() ?>
                          <input type="hidden" name="user_type" value="<?= h($type) ?>"><input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="status" value="<?= h($status) ?>">
                          <button type="submit" class="is-danger"><?= icon('x') ?> Delete</button>
                        </form>
                      <?php elseif ($isAdmin): ?>
                        <p class="menu-note">Has borrowing history. Deactivate instead.</p>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?= render_pager($total, $page, $perPage, strtolower(USER_TYPES[$type]['label'])) ?>

<datalist id="course-list"><?php foreach ($courses as $c): ?><option value="<?= h($c['v']) ?>"></option><?php endforeach; ?></datalist>
<datalist id="department-list"><?php foreach ($departments as $d): ?><option value="<?= h($d['v']) ?>"></option><?php endforeach; ?></datalist>

<dialog class="modal modal-wide" id="user-add" aria-labelledby="user-add-title">
  <form class="modal-form" method="post" action="/api/user_save.php" data-ajax novalidate>
    <div class="modal-head">
      <div><h2 id="user-add-title">Add user</h2><p class="sub">A temporary password is made for them and shown once.</p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="add">
      <?php user_fields('add', $addTypes, true); ?>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit">Add user</button>
    </div>
  </form>
</dialog>

<dialog class="modal modal-wide" id="user-edit" aria-labelledby="user-edit-title">
  <form class="modal-form" method="post" action="/api/user_save.php" data-ajax novalidate>
    <div class="modal-head">
      <div><h2 id="user-edit-title">Edit user</h2><p class="sub" data-fill-text="name"></p></div>
      <button class="modal-close" type="button" data-modal-close><?= icon('x') ?><span class="sr-only">Close</span></button>
    </div>
    <div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="edit">
      <input type="hidden" name="user_id">
      <?php user_fields('edit', $addTypes, false); ?>
    </div>
    <div class="modal-foot">
      <button class="btn btn-secondary" type="button" data-modal-close>Cancel</button>
      <button class="btn btn-primary" type="submit">Save changes</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="secret-modal" aria-labelledby="secret-title" data-secret-modal>
  <div class="modal-form">
    <div class="modal-head"><div><h2 id="secret-title" data-secret-title>Temporary password</h2><p class="sub" data-secret-name></p></div></div>
    <div class="modal-body">
      <p>Give this password to <strong data-secret-name></strong> in person. It is shown only this once and is not saved anywhere in plain text. They choose a new password the first time they sign in.</p>
      <div class="secret-box"><code id="secret-value" data-secret-value></code>
        <button class="btn btn-secondary btn-sm" type="button" data-copy="secret-value"><?= icon('copy') ?> Copy</button></div>
    </div>
    <div class="modal-foot"><button class="btn btn-primary" type="button" data-modal-close>Done</button></div>
  </div>
</dialog>
<?php require APP_SRC . '/partials/footer.php'; ?>
