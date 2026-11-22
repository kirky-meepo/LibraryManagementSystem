<?php
/*
 * A standalone page with one message: 403, expired form, errors.
 * It uses no database call, so it still works when PostgreSQL is down.
 * Variables: $title, $text, $action (href, label), $code, $detail (dev only).
 */
require_once __DIR__ . '/icons.php';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Library</title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/fonts.css">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="message-body">
<main class="message-page" id="main">
  <div class="message-card">
    <?= logo_svg(44) ?>
    <?php if (!empty($code)): ?><p class="message-code">Error <?= h($code) ?></p><?php endif; ?>
    <h1><?= h($title) ?></h1>
    <p><?= h($text) ?></p>
    <?php if (!empty($action)): ?>
      <p><a class="btn btn-primary" href="<?= h($action['href']) ?>"><?= h($action['label']) ?></a></p>
    <?php endif; ?>
    <?php if (!empty($detail)): ?>
      <details class="message-detail" open>
        <summary>Details (shown because APP_ENV is dev)</summary>
        <pre><?= h($detail) ?></pre>
      </details>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
