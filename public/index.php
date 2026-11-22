<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Send people to their home page by role, or to the sign-in page.
$me = current_user();
if (!$me) {
    redirect('/login.php');
}
redirect($me['must_change_password'] ? '/change_password.php' : home_for($me));
