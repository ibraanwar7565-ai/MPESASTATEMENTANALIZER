/*
 * dashboard.js: draws the four dashboard charts with Chart.js.
 * dashboard.php puts the data in window.PG_DASH as JSON:
 *   months, in, out       monthly cash flow (bar chart)
 *   catLabels, catTotals  spending by category (doughnut)
 *   risk                  Low / Medium / High counts (doughnut)
 *   hourly                outgoing transactions per hour, 0 to 23 (bar chart)
 */
(function () {
    var d = window.PG_DASH;
    // Nothing to draw (no data, or Chart.js did not load): stop quietly.
    if (!d || typeof Chart === 'undefined') { return; }
    // Shared look for every chart: font, colours and tooltips.
    Chart.defaults.font.family = 'Figtree, "Segoe UI", system-ui, sans-serif';
    Chart.defaults.font.size = 12.5;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
    Chart.defaults.plugins.tooltip.backgroundColor = '#0c2a1d';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.color = '#5a6b61';
    Chart.defaults.borderColor = '#e6ece8';
    var palette = ['#0e8a47', '#0c2a1d', '#db9200', '#3f7fd6', '#b8d94a', '#d23b2e', '#7a5cc7', '#2aa6a1', '#8a968f', '#c26a2a'];
    // Formats numbers as KES for the axes and tooltips, e.g. 48000 becomes "KES 48,000".
    var kes = function (v) { return 'KES ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 }); };

    // 1. Monthly cash flow: money in vs money out per month.
    var el = document.getElementById('cashFlowChart');
    if (el) {
        new Chart(el, {
            type: 'bar',
            data: {
                labels: d.months,
                datasets: [
                    { label: 'Money in', data: d.in, backgroundColor: '#0e8a47', borderRadius: 6, maxBarThickness: 72 },
                    { label: 'Money out', data: d.out, backgroundColor: '#0c2a1d', borderRadius: 6, maxBarThickness: 72 }
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
            data: { labels: ['Low', 'Medium', 'High'], datasets: [{ data: d.risk, backgroundColor: ['#0e8a47', '#db9200', '#d23b2e'], borderWidth: 2 }] },
            options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom' } } }
        });
    }

    // 4. Outgoing transactions by hour. Hours 00 to 04 are red: the odd-hour window of rule R2.
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
                    backgroundColor: labels.map(function (_, i) { return i < 5 ? '#d23b2e' : '#0e8a47'; })
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
