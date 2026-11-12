<?php
/**
 * footer.php: closes every page and loads the JavaScript:
 *  - Bootstrap: menus, pop-ups and the mobile sidebar
 *  - Chart.js: only on pages that set $useCharts = true (the dashboard)
 *  - app.js: small helpers used on every page
 * $pageScript lets a page add its own script, e.g. the dashboard charts.
 */
?>
</main>
<?php if (current_user()): ?>
        <footer class="pg-footer">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; <?= e(APP_TAGLINE) ?> &middot; Mount Kenya University BBIT Project
        </footer>
    </div>
</div>
<?php endif; ?>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php if (!empty($useCharts)): ?>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(url('assets/js/app.js')) ?>?v=2"></script>
<?= $pageScript ?? '' ?>
</body>
</html>
