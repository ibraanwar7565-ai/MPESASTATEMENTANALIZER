<?php
/**
 * Page footer and scripts:
 *  - Bootstrap (dropdowns, offcanvas sidebar)
 *  - Chart.js, only when $useCharts is set
 *  - app.js
 * $pageScript: page-specific script (dashboard charts).
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
