<?php
/*
 * Page top: <head>, the dark-blue top bar, the sidebar and the opening <main>.
 * Set before including:  $pageTitle (string), $activeNav (sidebar key), optional $bodyClass, $hideSidebar.
 */
require_once __DIR__ . '/icons.php';

$me = current_user();
$libraryName = setting('library_name', 'School Library');
$pageTitle = $pageTitle ?? 'Library';
$activeNav = $activeNav ?? '';
$showSidebar = $me !== null && empty($hideSidebar);
$bodyClasses = trim(($showSidebar ? 'has-sidebar ' : '') . ($bodyClass ?? ''));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($pageTitle) ?> · <?= h($libraryName) ?></title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/fonts.css">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/cards.css">
<link rel="stylesheet" href="/assets/css/print.css" media="print">
</head>
<body class="<?= h($bodyClasses) ?>">
<a class="skip-link" href="#main">Skip to main content</a>
<header class="topbar">
  <?php if ($showSidebar): ?>
    <button class="nav-toggle" type="button" aria-controls="sidebar" aria-expanded="false" data-nav-toggle>
      <?= icon('menu') ?><span class="sr-only">Show menu</span>
    </button>
  <?php endif; ?>
  <a class="brand" href="<?= h($me ? home_for($me) : '/login.php') ?>">
    <?= logo_svg(32) ?><span class="brand-name"><?= h($libraryName) ?></span>
  </a>
  <?php if ($me): ?>
    <div class="topbar-user">
      <span class="topbar-person">
        <span class="topbar-name"><?= h($me['name']) ?></span>
        <span class="role-tag"><?= h(role_label($me['role'])) ?></span>
      </span>
      <form method="post" action="/logout.php" class="inline-form">
        <?= csrf_field() ?>
        <button class="btn btn-on-dark" type="submit"><?= icon('logout') ?><span>Sign out</span></button>
      </form>
    </div>
  <?php endif; ?>
</header>
<div class="shell">
<?php if ($showSidebar) { require __DIR__ . '/sidebar.php'; } ?>
<main id="main" class="main" tabindex="-1">
<?php require __DIR__ . '/flash.php'; ?>
