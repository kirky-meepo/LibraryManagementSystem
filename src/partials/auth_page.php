<?php
declare(strict_types=1);

/*
 * The look shared by Sign in, Create an account, Forgot your password and Reset password:
 * the dark library panel on the left (on top on a phone) and the form on the right.
 * Usage: auth_page_start('Sign in'); ...the form... auth_page_end();
 */
require_once APP_SRC . '/partials/icons.php';

function auth_page_start(string $title): void
{
    $libraryName = setting('library_name', 'School Library');
    $libraryHours = setting('library_hours', 'Mon-Fri 8:00 AM-5:00 PM');
    $art = APP_ROOT . '/public/assets/img/books/stack.svg';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($libraryName) ?></title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/fonts.css">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body">
<?php require APP_SRC . '/partials/flash.php'; ?>
<main class="login" id="main">
  <section class="login-brand" aria-label="About the library">
    <div class="login-brand-inner">
      <?= logo_svg(56) ?>
      <p class="login-library"><?= h($libraryName) ?></p>
      <h1 class="login-title">Borrow, request and keep track of library books.</h1>
      <p class="login-hours"><?= icon('clock') ?> Open <?= h($libraryHours) ?></p>
    </div>
    <div class="login-art" aria-hidden="true"><?= is_file($art) ? file_get_contents($art) : '' ?></div>
  </section>

  <section class="login-panel">
<?php
}

function auth_page_end(): void
{
    ?>
  </section>
</main>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
<?php
}
