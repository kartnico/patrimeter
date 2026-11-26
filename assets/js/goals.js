/* Patrimeter — projection charts of the savings goals (Chart.js 4, copied into the repository). */
'use strict';

/**
 * Datasets of one goal chart, amounts in euros. The lines differ by their style, not only by their colour: solid line
 * over a shaded area (reached), dashes (planned trajectory), dots (current pace), thin line (target). The data comes
 * from the server (GoalVisuals::chart), in cents, null where a line has no point.
 */
const goalDatasets = (data, colors) => {
    const toEuros = (cents) => (cents === null ? null : cents / 100);
    const lastActual = data.actual.reduce((last, value, index) => (value === null ? last : index), -1);
    const lastProjection = data.projection.reduce((last, value, index) => (value === null ? last : index), -1);

    return [
        {
            label: data.names.actual,
            data: data.actual.map(toEuros),
            borderColor: colors.primary,
            backgroundColor: colors.area,
            fill: 'start',
            borderWidth: 3,
            tension: 0.3,
            cubicInterpolationMode: 'monotone',
            // Only the point of the current month, larger, with a ring of the card's colour.
            pointRadius: data.actual.map((_, index) => (index === lastActual ? 5 : 0)),
            pointHoverRadius: 6,
            pointBackgroundColor: colors.primary,
            pointBorderColor: colors.surface,
            pointBorderWidth: 2,
            order: 0,
        },
        {
            label: data.names.plan,
            data: data.plan.map(toEuros),
            borderColor: colors.muted,
            borderDash: [6, 5],
            borderWidth: 1.75,
            spanGaps: true,
            pointRadius: 0,
            pointHoverRadius: 4,
            fill: false,
            order: 2,
        },
        {
            label: data.names.projection,
            data: data.projection.map(toEuros),
            borderColor: colors.primary,
            borderDash: [2, 5],
            borderCapStyle: 'round',
            borderWidth: 2.5,
            spanGaps: true,
            pointRadius: data.projection.map((_, index) => (index === lastProjection ? 4 : 0)),
            pointStyle: 'circle',
            pointBackgroundColor: colors.surface,
            pointBorderColor: colors.primary,
            pointBorderWidth: 2,
            pointHoverRadius: 5,
            fill: false,
            order: 1,
        },
        {
            label: data.names.target,
            data: data.target.map(toEuros),
            borderColor: colors.text,
            borderWidth: 1,
            pointRadius: 0,
            pointHoverRadius: 0,
            fill: false,
            order: 3,
        },
    ];
};

const renderGoalChart = (canvas, data) => {
    const css = getComputedStyle(document.documentElement);
    const cssVar = (name) => css.getPropertyValue(name).trim();
    const locale = data.locale === 'en' ? 'en-GB' : 'fr-FR';
    const currency = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
    const compact = new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 });
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const primary = cssVar('--primary');
    const muted = cssVar('--muted');

    // Shaded area under the reached line: the theme's colour, fading towards the bottom of the chart.
    const area = (context) => {
        const { chart } = context;
        if (!chart.chartArea) {
            return 'transparent';
        }
        const gradient = chart.ctx.createLinearGradient(0, chart.chartArea.top, 0, chart.chartArea.bottom);
        gradient.addColorStop(0, withAlpha(primary, 0.28));
        gradient.addColorStop(1, withAlpha(primary, 0.02));
        return gradient;
    };

    return new window.Chart(canvas, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: goalDatasets(data, {
                primary,
                muted,
                text: cssVar('--text'),
                surface: cssVar('--surface'),
                area,
            }),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion ? false : { duration: 500 },
            locale,
            layout: { padding: { top: 8, right: 8 } },
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: {
                    ticks: { color: muted, maxRotation: 0, autoSkip: true, autoSkipPadding: 16 },
                    grid: { display: false },
                    border: { color: cssVar('--border') },
                },
                y: {
                    ticks: {
                        color: muted,
                        maxTicksLimit: 5,
                        callback: (value) =>
                            data.locale === 'en' ? `€${compact.format(value)}` : `${compact.format(value)} €`,
                    },
                    grid: { color: withAlpha(cssVar('--border'), 0.7) },
                    border: { display: false },
                },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: cssVar('--surface'),
                    titleColor: cssVar('--text'),
                    bodyColor: cssVar('--text'),
                    borderColor: cssVar('--border'),
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 8,
                    usePointStyle: true,
                    // Lines without a point this month (straight lines between two ends) are left out.
                    filter: (item) =>
                        item.raw !== null && item.raw !== undefined && item.dataset.pointHoverRadius !== 0,
                    callbacks: {
                        title: (items) => (items.length ? data.months[items[0].dataIndex] : ''),
                        label: (item) =>
                            ` ${item.dataset.label}${data.locale === 'en' ? ':' : ' :'} ${currency.format(item.parsed.y)}`,
                    },
                },
            },
        },
    });
};

// #rrggbb colours only (defined in app.css).
const withAlpha = (color, alpha) => {
    const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color);
    return m
        ? `rgba(${Number.parseInt(m[1], 16)}, ${Number.parseInt(m[2], 16)}, ${Number.parseInt(m[3], 16)}, ${alpha})`
        : color;
};

{
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof window.Chart === 'undefined') {
            return;
        }
        const charts = [...document.querySelectorAll('canvas[data-goal-chart]')].map((canvas) => {
            const data = JSON.parse(canvas.dataset.goalChart);
            return { canvas, data, chart: renderGoalChart(canvas, data) };
        });
        // The colours come from the theme shown: redrawn when the user switches theme (event sent by app.js).
        document.addEventListener('patrimeter:themechange', () => {
            charts.forEach((entry) => {
                entry.chart.destroy();
                entry.chart = renderGoalChart(entry.canvas, entry.data);
            });
        });
    });
}

// Pure part, exposed for the unit tests (tests/js/goals.test.mjs).
window.PatrimeterGoals = { goalDatasets, withAlpha };
