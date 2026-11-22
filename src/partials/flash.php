<?php
/*
 * One-time messages from the last action, shown as toasts in the top right.
 * Success and info toasts close themselves after a few seconds; errors stay until closed.
 * Without JavaScript they simply stay on the page.
 * Runs inside a closure so its variables never leak into the page that includes it.
 */
(static function (): void {
    $flashes = flash_take();
    ?>
<div class="toast-region" data-toast-region aria-live="polite">
  <?php foreach ($flashes as $f):
      $kind = in_array($f['type'], ['success', 'error', 'info', 'warning'], true) ? $f['type'] : 'info'; ?>
    <div class="toast toast-<?= h($kind) ?>" role="<?= $kind === 'error' ? 'alert' : 'status' ?>" data-toast>
      <span class="toast-icon"><?= icon($kind === 'success' ? 'check' : ($kind === 'error' || $kind === 'warning' ? 'alert' : 'info')) ?></span>
      <p class="toast-text"><?= h($f['message']) ?></p>
      <?php if (!empty($f['action']['href']) && !empty($f['action']['label'])): ?>
        <a class="toast-action" href="<?= h($f['action']['href']) ?>"><?= h($f['action']['label']) ?></a>
      <?php endif; ?>
      <button class="toast-close" type="button" data-toast-close><?= icon('x') ?><span class="sr-only">Close message</span></button>
    </div>
  <?php endforeach; ?>
</div>
<?php
})();
