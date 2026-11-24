/**
 * Chart.js for <canvas data-cv-chart="{...}"> (see components/chart.blade.php).
 *
 * Colours are tokens ("series-1", "domain-ota") read from CSS variables
 * (--cv-series-1, --cv-domain-ota) defined in Sass from _variables.scss.
 * Charts are created on load and whenever Livewire adds a new canvas;
 * buttons with data-cv-chart-target + data-cv-chart-group switch series groups.
 */
import {
    BarController, BarElement, CategoryScale, Chart, Legend, LinearScale,
    LineController, LineElement, PointElement, Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip);

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

function datasets(config, group) {
    return config.series
        .filter((s) => s.group === 'all' || s.group === group)
        .map((s) => {
            const color = css(`--cv-${s.color}`) || '#0088cc';
            const base = { label: s.label, data: s.data, backgroundColor: color, borderColor: color };

            if (config.type === 'bar') {
                // 2px surface gap between stacked segments, rounded data end.
                return { ...base, borderColor: css('--cv-chart-surface') || '#fff', borderWidth: { top: 2 }, borderSkipped: 'bottom', maxBarThickness: 36 };
            }

            return {
                ...base,
                borderWidth: s.highlight ? 3 : 2,
                pointRadius: s.highlight ? 4 : 3,
                pointHoverRadius: 6,
                pointBorderColor: css('--cv-chart-surface') || '#fff',
                pointBorderWidth: 2,
                tension: 0.25,
                spanGaps: false,
                // The comparison line is dashed; days without data stay blank, not zero.
                borderDash: s.dash ? [6, 4] : [],
                order: s.highlight ? 0 : 1,
            };
        });
}

function build(canvas) {
    Chart.getChart(canvas)?.destroy();
    Chart.defaults.font.family = css('--bs-body-font-family') || Chart.defaults.font.family;

    const config = JSON.parse(canvas.dataset.cvChart);
    const ink = css('--cv-chart-ink') || '#777';
    const grid = css('--cv-chart-grid') || '#eee';
    const unit = config.unit || '';

    const chart = new Chart(canvas, {
        type: config.type,
        data: { labels: config.labels, datasets: datasets(config, config.group) },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 250 },
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    stacked: config.stacked,
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: ink, maxRotation: 0, autoSkipPadding: 12 },
                },
                y: {
                    stacked: config.stacked,
                    beginAtZero: true,
                    suggestedMax: unit === '%' ? 100 : undefined,
                    max: unit === '%' ? 100 : undefined,
                    grid: { color: grid, drawTicks: false },
                    border: { display: false },
                    ticks: { color: ink, padding: 8, maxTicksLimit: 5, precision: 0, callback: (v) => `${v}${unit}` },
                },
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: ink, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 14 },
                },
                tooltip: {
                    backgroundColor: css('--cv-chart-tooltip-bg') || '#222',
                    padding: 10,
                    boxPadding: 4,
                    usePointStyle: true,
                    callbacks: {
                        title: (items) => config.titles[items[0]?.dataIndex] ?? '',
                        label: (item) => ` ${item.dataset.label}: ${item.raw === null ? '—' : item.raw + unit}`,
                    },
                },
            },
        },
    });

    chart.$cvConfig = config;
}

function buildAll(root = document) {
    root.querySelectorAll?.('canvas[data-cv-chart]').forEach(build);
}

export function initCharts() {
    buildAll();

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType !== 1) return;
                if (node.matches('canvas[data-cv-chart]')) build(node);
                else buildAll(node);
            });
        }
    }).observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-cv-chart-target][data-cv-chart-group]');
        const canvas = button && document.getElementById(button.dataset.cvChartTarget);
        const chart = canvas && Chart.getChart(canvas);

        if (!chart) return;

        chart.data.datasets = datasets(chart.$cvConfig, button.dataset.cvChartGroup);
        chart.update();

        button.parentElement.querySelectorAll('[data-cv-chart-group]').forEach((b) => {
            const active = b === button;
            b.classList.toggle('active', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    });
}
