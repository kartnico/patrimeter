/* Patrimeter — history chart (Chart.js 4, copied into the repository). */
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const dataNode = document.getElementById('history-data');
    const canvas = document.getElementById('history-chart');
    if (!dataNode || !canvas || typeof window.Chart === 'undefined') {
        return;
    }

    const data = JSON.parse(dataNode.textContent);
    const toEuros = (cents) => cents / 100;
    // Interface language ("fr" or "en"): amount formats and labels given by the server.
    const locale = data.locale === 'en' ? 'en-GB' : 'fr-FR';
    const currency = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
    const compact = new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 });
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const withAlpha = (color, alpha) => {
        // #rrggbb colours only (defined in app.css).
        const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color);
        return m
            ? `rgba(${Number.parseInt(m[1], 16)}, ${Number.parseInt(m[2], 16)}, ${Number.parseInt(m[3], 16)}, ${alpha})`
            : color;
    };

    // Pattern specific to each level, over its colour: the stacked areas stay distinct without seeing the colours
    // (colour blindness, black and white printing). Same order as the pyramid levels.
    const PATTERNS = [
        null,
        (ctx, s) => {
            ctx.moveTo(0, s);
            ctx.lineTo(s, 0);
        },
        (ctx, s) => {
            ctx.moveTo(s / 2, s / 2);
            ctx.arc(s / 2, s / 2, 1.3, 0, 2 * Math.PI);
        },
        (ctx, s) => {
            ctx.moveTo(0, s / 2);
            ctx.lineTo(s, s / 2);
        },
        (ctx, s) => {
            ctx.moveTo(0, 0);
            ctx.lineTo(s, s);
            ctx.moveTo(0, s);
            ctx.lineTo(s, 0);
        },
        (ctx, s) => {
            ctx.moveTo(s / 2, 0);
            ctx.lineTo(s / 2, s);
        },
    ];
    const patternFill = (color, index, ink) => {
        const draw = PATTERNS[index % PATTERNS.length];
        const size = 8;
        const tile = document.createElement('canvas');
        tile.width = size;
        tile.height = size;
        const ctx = tile.getContext('2d');
        if (!ctx || !draw) {
            return color;
        }
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, size, size);
        ctx.strokeStyle = withAlpha(ink, 0.55);
        ctx.fillStyle = withAlpha(ink, 0.55);
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        draw(ctx, size);
        ctx.stroke();
        ctx.fill();
        return ctx.createPattern(tile, 'repeat') || color;
    };

    // The colours come from the CSS variables of the theme shown: the chart is redrawn when the user switches
    // theme (event sent by app.js).
    const render = () => {
        const css = getComputedStyle(document.documentElement);
        const cssVar = (name) => css.getPropertyValue(name).trim();
        const textColor = cssVar('--muted');
        const primary = cssVar('--primary');

        const totalDataset = {
            label: data.total_label,
            data: data.total.map(toEuros),
            borderColor: primary,
            backgroundColor: withAlpha(primary, 0.15),
            borderWidth: 2.5,
            pointRadius: data.total.length > 36 ? 0 : 3,
            pointHoverRadius: 5,
            tension: 0.25,
            fill: data.mode === 'total',
            stack: 'net',
            order: 0,
        };

        let datasets = [totalDataset];
        if (data.mode === 'niveaux') {
            totalDataset.borderColor = cssVar('--text');
            totalDataset.borderDash = [6, 4];
            totalDataset.pointRadius = 0;
            const ink = cssVar('--lvl-text');
            datasets = data.levels
                .map((level, index) => {
                    const color = cssVar(`--lvl-${level.code}`);
                    return {
                        label: level.label,
                        data: level.data.map(toEuros),
                        borderColor: color,
                        backgroundColor: patternFill(color, index, ink),
                        borderWidth: 1,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        tension: 0.2,
                        fill: true,
                        stack: 'levels',
                        order: 1,
                    };
                })
                .concat([totalDataset]);
        }

        // A single value: show a clearly visible point.
        if (data.total.length === 1) {
            datasets.forEach((d) => {
                d.pointRadius = 5;
            });
        }

        return new window.Chart(canvas, {
            type: 'line',
            data: { labels: data.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reducedMotion ? false : { duration: 400 },
                locale,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        ticks: { color: textColor, maxRotation: 0, autoSkip: true, autoSkipPadding: 12 },
                        grid: { display: false },
                    },
                    y: {
                        stacked: data.mode === 'niveaux',
                        // Stacked areas: the axis must start from zero so as not to distort the proportions.
                        beginAtZero: data.mode === 'niveaux',
                        ticks: {
                            color: textColor,
                            callback: (value) =>
                                data.locale === 'en' ? `€${compact.format(value)}` : `${compact.format(value)} €`,
                        },
                        grid: { color: cssVar('--border') },
                    },
                },
                plugins: {
                    legend: {
                        display: data.mode === 'niveaux',
                        position: 'bottom',
                        labels: { color: textColor, boxWidth: 16, boxHeight: 12 },
                    },
                    tooltip: {
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

    let chart = render();
    document.addEventListener('patrimeter:themechange', () => {
        chart.destroy();
        chart = render();
    });
});
