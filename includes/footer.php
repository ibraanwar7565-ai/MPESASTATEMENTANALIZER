</main>
<?php if (current_user()): ?>
<footer class="pg-footer text-center small text-muted py-3">
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; <?= e(APP_TAGLINE) ?> &middot; Mount Kenya University BBIT Project
</footer>
<?php endif; ?>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php if (!empty($useCharts)): ?>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?= $pageScript ?? '' ?>
</body>
</html>
