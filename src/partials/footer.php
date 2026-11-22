<?php
/* Closes the page opened by header.php. Pages may set $pageScripts to load extra script files from /assets/js. */
?>
</main>
</div>
<footer class="site-footer">
  <p><?= h(setting('library_name', 'School Library')) ?> · LibraryInventorySystem · PC318 Group 7, BSIT 3-1, Cebu Technological University</p>
</footer>
<script src="/assets/js/app.js" defer></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
  <?php if (preg_match('#^/assets/js/[a-z_]+\.js$#', $script)): ?><script src="<?= h($script) ?>" defer></script><?php endif; ?>
<?php endforeach; ?>
</body>
</html>
