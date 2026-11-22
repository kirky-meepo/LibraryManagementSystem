<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Signing out changes state, so it only happens on POST (with the CSRF token checked by bootstrap).
// fn_logout writes the audit row first, then the session is destroyed.
if (!is_post()) {
    redirect('/');
}
logout();
flash('success', 'You are signed out.');
redirect('/login.php');
