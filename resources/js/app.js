import Chart from 'chart.js/auto';

const GRID = 'rgba(255, 255, 255, 0.05)';
const TICKS = '#71717a';

function renderChart(canvas) {
    if (canvas.__chart) {
        canvas.__chart.destroy();
    }

    const config = JSON.parse(canvas.dataset.chart);

    canvas.__chart = new Chart(canvas, {
        data: {
            labels: config.labels,
            datasets: config.datasets.map((dataset) => ({
                label: dataset.label,
                type: dataset.type,
                data: dataset.data,
                borderColor: dataset.color,
                backgroundColor: dataset.type === 'bar' ? dataset.color + 'b3' : dataset.color + '26',
                fill: dataset.fill,
                stack: dataset.stack ?? undefined,
                yAxisID: dataset.yAxisID || 'y',
                tension: 0.35,
                pointRadius: 0,
                pointHitRadius: 8,
                borderWidth: dataset.type === 'bar' ? 0 : 1.5,
                borderRadius: 2,
                spanGaps: false,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: config.datasets.length > 1,
                    labels: { color: TICKS, boxWidth: 10, boxHeight: 10, usePointStyle: true },
                },
                tooltip: {
                    backgroundColor: '#18181b',
                    borderColor: '#3f3f46',
                    borderWidth: 1,
                    titleColor: '#e4e4e7',
                    bodyColor: '#a1a1aa',
                },
            },
            scales: {
                x: {
                    stacked: config.stacked,
                    ticks: { color: TICKS, maxTicksLimit: 8, maxRotation: 0 },
                    grid: { color: GRID },
                },
                y: {
                    stacked: config.stacked,
                    beginAtZero: true,
                    ticks: { color: TICKS, maxTicksLimit: 6 },
                    grid: { color: GRID },
                },
                ...(config.dualAxis
                    ? {
                          y1: {
                              position: 'right',
                              beginAtZero: true,
                              ticks: { color: TICKS, maxTicksLimit: 6 },
                              grid: { drawOnChartArea: false },
                          },
                      }
                    : {}),
            },
        },
    });
}

function scan(root = document) {
    root.querySelectorAll('canvas[data-chart]').forEach(renderChart);
}

document.addEventListener('DOMContentLoaded', () => {
    scan();

    // Livewire morphs swap chart nodes (they carry a data-derived wire:key),
    // so initializing anything new that appears keeps charts in sync.
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (node.nodeType !== Node.ELEMENT_NODE) continue;
                if (node.matches?.('canvas[data-chart]')) renderChart(node);
                node.querySelectorAll?.('canvas[data-chart]').forEach(renderChart);
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
});
