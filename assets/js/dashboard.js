// Dashboard charts (Chart.js)
(function () {
    var d = window.PG_DASH;
    if (!d || typeof Chart === 'undefined') { return; }
    Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.color = '#5b6775';
    var palette = ['#0b7a3e', '#2f6fde', '#e08a00', '#c2410c', '#7c3aed', '#0891b2', '#be185d', '#65a30d', '#475569', '#ca8a04'];
    var kes = function (v) { return 'KES ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 }); };

    var el = document.getElementById('cashFlowChart');
    if (el) {
        new Chart(el, {
            type: 'bar',
            data: {
                labels: d.months,
                datasets: [
                    { label: 'Money in', data: d.in, backgroundColor: '#0b7a3e', borderRadius: 4 },
                    { label: 'Money out', data: d.out, backgroundColor: '#d1453b', borderRadius: 4 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + kes(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: kes } }, x: { grid: { display: false } } }
            }
        });
    }

    el = document.getElementById('categoryChart');
    if (el) {
        new Chart(el, {
            type: 'doughnut',
            data: { labels: d.catLabels, datasets: [{ data: d.catTotals, backgroundColor: palette, borderWidth: 2 }] },
            options: {
                maintainAspectRatio: false, cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } },
                    tooltip: { callbacks: { label: function (c) { return c.label + ': ' + kes(c.parsed); } } }
                }
            }
        });
    }

    el = document.getElementById('riskChart');
    if (el) {
        new Chart(el, {
            type: 'doughnut',
            data: { labels: ['Low', 'Medium', 'High'], datasets: [{ data: d.risk, backgroundColor: ['#16a34a', '#f59e0b', '#dc2626'], borderWidth: 2 }] },
            options: { maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom' } } }
        });
    }

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
                    backgroundColor: labels.map(function (_, i) { return i < 5 ? '#dc2626' : '#2f6fde'; })
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
