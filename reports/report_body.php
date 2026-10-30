<?php
/** Renders the report sections as HTML tables. Expects $type, $rs (ReportService). */
require_once __DIR__ . '/report_data.php';

foreach (build_report($type, $rs, db()) as [$heading, $headers, $rows, $numeric]): ?>
    <h3 class="h6 fw-bold mt-2"><?= e($heading) ?></h3>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-striped report-table">
            <thead><tr>
                <?php foreach ($headers as $i => $h): ?><th class="<?= in_array($i, $numeric, true) ? 'text-end' : '' ?>"><?= e($h) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach (array_values($row) as $i => $cell): ?><td class="<?= in_array($i, $numeric, true) ? 'text-end text-nowrap' : '' ?>"><?= e((string) $cell) ?></td><?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= count($headers) ?>" class="text-center text-muted">No data for this report.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endforeach;
