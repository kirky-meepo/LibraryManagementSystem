<?php
/*
 * Sidebar navigation, in the fixed order and with the names used everywhere in the app. The current page is marked with
 * aria-current="page". Members see a red number on Messages when they have unread messages.
 * Runs inside a closure so its variables never leak into the page that includes it.
 */
(static function (array $me, string $activeNav): void {
    $badges = [];
    if ($me['is_staff']) {
        $navItems = [
            ['dashboard', 'Dashboard', '/librarian/dashboard.php', 'dashboard'],
            ['profile', 'Profile', '/librarian/profile.php', 'user'],
            ['books', 'Manage Books', '/librarian/books.php', 'books'],
            ['users', 'Manage Users', '/librarian/users.php', 'users'],
            ['requests', 'Requests', '/librarian/requests.php', 'inbox'],
            ['issue', 'Issue Book', '/librarian/issue.php', 'issue'],
            ['issued', 'Issued Books', '/librarian/issued.php', 'list'],
            ['fines', 'Fines', '/librarian/fines.php', 'coins'],
            ['status', 'Status and Reports', '/librarian/status.php', 'chart'],
            ['messages', 'Messages', '/librarian/messages.php', 'mail'],
        ];
        if ($me['role'] === 'admin') {
            $navItems[] = ['settings', 'Settings', '/librarian/settings.php', 'settings'];
            $navItems[] = ['activity', 'Activity', '/librarian/activity.php', 'activity'];
        }
        $pending = (int) q_val("SELECT count(*) FROM book_request WHERE status = 'pending'");
        if ($pending > 0) {
            $badges['requests'] = [$pending, plural($pending, 'pending request')];
        }
    } else {
        $navItems = [
            ['home', 'Home', '/member/home.php', 'home'],
            ['catalog', 'Catalog', '/member/catalog.php', 'books'],
            ['requests', 'My Requests', '/member/requests.php', 'inbox'],
            ['messages', 'Messages', '/member/messages.php', 'mail'],
            ['borrowed', 'My Borrowed Books', '/member/borrowed.php', 'list'],
            ['profile', 'My Profile', '/member/profile.php', 'user'],
        ];
        $unread = (int) q_val(
            $me['type'] === 'student'
                ? 'SELECT count(*) FROM message WHERE recipient_student_id = :id AND read_at IS NULL'
                : 'SELECT count(*) FROM message WHERE recipient_teacher_id = :id AND read_at IS NULL',
            [':id' => $me['id']]
        );
        if ($unread > 0) {
            $badges['messages'] = [$unread, plural($unread, 'unread message')];
        }
    }
    ?>
<nav class="sidebar" id="sidebar" aria-label="Main" data-sidebar>
  <ul class="nav-list">
    <?php foreach ($navItems as [$key, $label, $href, $iconName]): ?>
      <li>
        <a class="nav-link" href="<?= h($href) ?>"<?= $key === $activeNav ? ' aria-current="page"' : '' ?>>
          <?= icon($iconName) ?>
          <span class="nav-label"><?= h($label) ?></span>
          <?php if (isset($badges[$key])): ?>
            <span class="nav-badge<?= $key === 'messages' ? ' nav-badge-alert' : '' ?>" data-nav-badge="<?= h($key) ?>"><span aria-hidden="true"><?= (int) $badges[$key][0] ?></span><span class="sr-only">, <?= h($badges[$key][1]) ?></span></span>
          <?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php
})($me, (string) $activeNav);
