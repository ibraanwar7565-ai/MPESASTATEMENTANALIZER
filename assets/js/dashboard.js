/*
 * Dashboard charts (Chart.js).
 * Data: window.PG_DASH, set by dashboard.php
 *   months, in, out       monthly cash flow (bar chart)
 *   catLabels, catTotals  spending by category (doughnut)
 *   risk                  Low / Medium / High counts (doughnut)
 *   hourly                outgoing transactions per hour, 0 to 23 (bar chart)
 */
(function () {
    var d = window.PG_DASH;
    // No data or Chart.js missing
    if (!d || typeof Chart === 'undefined') { return; }
    // Colours are read from the CSS variables in style.css
    // (second argument = fallback).
    var css = getComputedStyle(document.documentElement);
    var color = function (name, fallback) { return css.getPropertyValue(name).trim() || fallback; };
    var C = {
        leaf: color('--leaf', '#0e8a47'),       // money in, Low risk, daytime hours
        ink: color('--ink', '#0c2a1d'),         // money out, tooltip background
        amber: color('--amber', '#db9200'),     // Medium risk
        red: color('--red', '#d23b2e'),         // High risk, night hours (rule R2)
        muted: color('--muted', '#5a6b61'),     // axis text and legends
        grid: color('--chart-grid', '#e6ece8')  // grid lines
    };

    // Global chart defaults
    Chart.defaults.font.family = 'Figtree, "Segoe UI", system-ui, sans-serif';
    Chart.defaults.font.size = 12.5;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
    Chart.defaults.plugins.tooltip.backgroundColor = C.ink;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.color = C.muted;
    Chart.defaults.borderColor = C.grid;
    // Doughnut palette: --chart-1 ... --chart-10
    var defaults = ['#0e8a47', '#0c2a1d', '#db9200', '#3f7fd6', '#b8d94a', '#d23b2e', '#7a5cc7', '#2aa6a1', '#8a968f', '#c26a2a'];
    var palette = defaults.map(function (hex, i) { return color('--chart-' + (i + 1), hex); });
    // 48000 -> "KES 48,000"
    var kes = function (v) { return 'KES ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 }); };

    // 1. Monthly cash flow
    var el = document.getElementById('cashFlowChart');
    if (el) {
        new Chart(el, {
            type: 'bar',
            data: {
                labels: d.months,
                datasets: [
                    { label: 'Money in', data: d.in, backgroundColor: C.leaf, borderRadius: 6, maxBarThickness: 72 },
                    { label: 'Money out', data: d.out, backgroundColor: C.ink, borderRadius: 6, maxBarThickness: 72 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + kes(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: kes } }, x: { grid: { display: false } } }
            }
        });
    }

    // 2. Spending by category.
    el = document.getElementById('categoryChart');
    if (el) {
        new Chart(el, {
            type: 'doughnut',
            data: { labels: d.catLabels, datasets: [{ data: d.catTotals, backgroundColor: palette, borderWidth: 2 }] },
            options: {
                maintainAspectRatio: false, cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } },
                    tooltip: { callbacks: { label: function (c) { return c.label + ': ' + kes(c.parsed); } } }
                }
            }
        });
    }

    // 3. Risk level of outgoing transactions.
    el = document.getElementById('riskChart');
    if (el) {
        new Chart(el, {
            type: 'doughnut',
            data: { labels: ['Low', 'Medium', 'High'], datasets: [{ data: d.risk, backgroundColor: [C.leaf, C.amber, C.red], borderWidth: 2 }] },
            options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom' } } }
        });
    }

    // 4. Outgoing transactions per hour; 00-04 highlighted (rule R2)
    el = document.getElementById('hourChart');
    if (el) {
        var labels = [];
        for (var h = 0; h < 24; h++) { labels.push((h < 10 ? '0' : '') + h + ':00'); }
        new Chart(el, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Outgoing transactions', data: d.hourly, borderRadius: 3,
                    backgroundColor: labels.map(function (_, i) { return i < 5 ? C.red : C.leaf; })
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { footer: function (items) { return items[0].dataIndex < 5 ? 'Odd hours (00:00-05:00)' : ''; } } } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } } }
            }
        });
    }
})();
