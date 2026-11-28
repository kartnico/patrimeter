/* Patrimeter — report picture: the report card drawn on a canvas (PNG, portrait 4:5), then shared through the phone's
   share sheet, saved, or posted on a social network. No library and nothing sent by Patrimeter: texts, colours and the
   pyramid's shapes are read from the page (theme included); the picture never leaves the browser unless the user
   shares it. */
(() => {
    'use strict';

    const WIDTH = 1080;
    const HEIGHT = 1350;
    const PADDING = 84;
    const INNER = WIDTH - 2 * PADDING;

    // Web addresses of each network. X, WhatsApp and Mastodon take a text; Facebook and Instagram take none from a
    // link: their site opens and the saved picture is added to the post there.
    const NETWORKS = {
        mastodon: (text, instance) => `https://${instance}/share?text=${encodeURIComponent(text)}`,
        x: (text) => `https://x.com/intent/post?text=${encodeURIComponent(text)}`,
        facebook: () => 'https://www.facebook.com/',
        whatsapp: (text) => `https://wa.me/?text=${encodeURIComponent(text)}`,
        instagram: () => 'https://www.instagram.com/',
    };
    const MASTODON_KEY = 'patrimeter.mastodon-instance';
    const HOST = /^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/;

    document.addEventListener('DOMContentLoaded', () => {
        const card = document.querySelector('[data-report-card]');
        const panel = document.querySelector('[data-report-share]');
        if (!card || !panel || !HTMLCanvasElement.prototype.toBlob) {
            return;
        }
        draw(card)
            .then((blob) => initPanel(panel, new File([blob], panel.dataset.shareFile, { type: 'image/png' })))
            .catch(() => {
                panel.hidden = false;
                panel.querySelectorAll('button').forEach((button) => {
                    button.disabled = true;
                });
                panel.querySelector('[data-share-status]').textContent = panel.dataset.shareFailed;
            });
    });

    function initPanel(panel, file) {
        const text = panel.dataset.shareText;
        const status = panel.querySelector('[data-share-status]');
        const nativeButton = panel.querySelector('[data-share-native]');
        const networks = panel.querySelector('[data-share-networks]');
        const mastodon = panel.querySelector('[data-share-mastodon]');
        const instance = mastodon.querySelector('input');
        const canShare = Boolean(navigator.canShare && navigator.canShare({ files: [file] }));
        // On a phone, the share sheet lists the installed apps (Instagram, WhatsApp, Mastodon…) and takes the picture:
        // one action, instead of network buttons that would all open that same sheet.
        const phone = canShare && window.matchMedia('(pointer: coarse)').matches;

        const shareNatively = () => {
            status.textContent = '';
            navigator.share({ files: [file], text }).catch((error) => {
                // Closed by the user: nothing to say. Refused by the system or the browser: how to share anyway.
                if (error?.name !== 'AbortError') {
                    status.textContent = panel.dataset.shareRefused;
                }
            });
        };
        const save = () => {
            const url = URL.createObjectURL(file);
            const link = document.createElement('a');
            link.href = url;
            link.download = file.name;
            link.hidden = true;
            document.body.append(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
        };
        // On a computer: the picture is saved, then the network opens in a new tab with the text, if it takes one.
        const publish = (network, host) => {
            save();
            window.open(NETWORKS[network](text, host), '_blank', 'noopener,noreferrer');
            status.textContent = panel.dataset.shareSaved;
        };

        nativeButton.hidden = !canShare;
        nativeButton.addEventListener('click', shareNatively);
        networks.hidden = phone;
        panel.querySelector('[data-share-download]').addEventListener('click', () => {
            save();
            status.textContent = panel.dataset.shareSaved;
        });
        // On a computer, each network opens its own site: the picture is saved first, to add to the post there.
        panel.querySelectorAll('[data-share-network]').forEach((button) => {
            if (button.dataset.shareNetwork === 'mastodon') {
                button.setAttribute('aria-expanded', 'false');
            }
            button.addEventListener('click', () => {
                const network = button.dataset.shareNetwork;
                if (network === 'mastodon') {
                    mastodon.hidden = !mastodon.hidden;
                    button.setAttribute('aria-expanded', String(!mastodon.hidden));
                    if (!mastodon.hidden) {
                        instance.value = instance.value || readInstance();
                        instance.focus();
                    }
                } else {
                    publish(network);
                }
            });
        });
        mastodon.addEventListener('submit', (event) => {
            event.preventDefault();
            // "https://mastodon.social/@me" or "@me@mastodon.social" are understood too.
            const host = instance.value
                .trim()
                .toLowerCase()
                .replace(/^[a-z]+:\/\//, '')
                .split(/[/?#]/, 1)[0]
                .replace(/^.*@/, '');
            if (!HOST.test(host)) {
                instance.setAttribute('aria-invalid', 'true');
                status.textContent = panel.dataset.shareInvalid;
                return;
            }
            instance.removeAttribute('aria-invalid');
            instance.value = host;
            writeInstance(host);
            publish('mastodon', host);
        });
        panel.hidden = false;
    }

    // The Mastodon server is remembered in this browser only (a convenience: it may be unavailable).
    function readInstance() {
        try {
            return window.localStorage.getItem(MASTODON_KEY) ?? '';
        } catch {
            return '';
        }
    }

    function writeInstance(host) {
        try {
            window.localStorage.setItem(MASTODON_KEY, host);
        } catch {
            // Storage refused (private browsing…): the server will be asked again next time.
        }
    }

    /** Draws the report card: brand, title, net worth and changes on top, pyramid in the middle, figures at the bottom. */
    async function draw(card) {
        if (document.fonts) {
            await document.fonts.ready;
        }
        const canvas = document.createElement('canvas');
        canvas.width = WIDTH;
        canvas.height = HEIGHT;
        const ctx = canvas.getContext('2d');
        const style = getComputedStyle(card);
        const family = style.fontFamily;
        const font = (weight, size) => `${weight} ${size}px ${family}`;
        const colour = (element) => (element ? getComputedStyle(element).color : style.color);
        const foot = card.querySelector('.report-foot');
        const muted = colour(foot);
        // Largest size, down to a minimum, at which the text fits on one line.
        const fit = (content, weight, size, minimum, width = INNER) => {
            let current = size;
            ctx.font = font(weight, current);
            while (current > minimum && ctx.measureText(content).width > width) {
                current -= 2;
                ctx.font = font(weight, current);
            }
            return current;
        };

        ctx.fillStyle = style.backgroundColor;
        ctx.fillRect(0, 0, WIDTH, HEIGHT);
        ctx.textBaseline = 'alphabetic';
        ctx.textAlign = 'left';

        // Brand: the logo, the favicon's two triangles (viewBox 32, one and a half times as big), and the name.
        let y = PADDING;
        const brand = card.querySelector('.report-brand');
        const root = getComputedStyle(document.documentElement);
        const triangle = (fill, points) => {
            ctx.fillStyle = fill;
            ctx.beginPath();
            points.forEach(([px, py], index) => {
                const [x, top] = [PADDING + px * 1.5, y + py * 1.5];
                if (index === 0) {
                    ctx.moveTo(x, top);
                } else {
                    ctx.lineTo(x, top);
                }
            });
            ctx.closePath();
            ctx.fill();
        };
        triangle(root.getPropertyValue('--brand-base').trim(), [
            [16, 3],
            [30, 28],
            [2, 28],
        ]);
        triangle(root.getPropertyValue('--brand-tip').trim(), [
            [16, 3],
            [21.6, 13],
            [10.4, 13],
        ]);
        ctx.fillStyle = colour(brand);
        ctx.font = font(700, 36);
        ctx.fillText(brand.textContent.trim(), PADDING + 60, y + 40);
        y += 112;

        // Title, the month in the muted colour: on one line if it fits, on two otherwise.
        const heading = card.querySelector('h1');
        const monthPart = heading.querySelector('span');
        const month = monthPart ? monthPart.textContent.trim() : '';
        const title = heading.textContent.replace(monthPart ? monthPart.textContent : '', '').trim();
        const titleSize = fit(`${title} ${month}`, 700, 56, 40);
        ctx.fillStyle = colour(heading);
        if (ctx.measureText(`${title} ${month}`).width <= INNER) {
            ctx.fillText(title, PADDING, y);
            const after = PADDING + ctx.measureText(`${title} `).width;
            ctx.font = font(400, titleSize);
            ctx.fillStyle = colour(monthPart);
            ctx.fillText(month, after, y);
        } else {
            ctx.font = font(700, fit(title, 700, 56, 30));
            ctx.fillText(title, PADDING, y);
            y += 64;
            ctx.font = font(400, fit(month, 400, 48, 30));
            ctx.fillStyle = colour(monthPart);
            ctx.fillText(month, PADDING, y);
        }
        y += 24;

        // Net worth, when amounts are shown.
        const total = card.querySelector('.report-total');
        if (total) {
            const size = fit(total.textContent.trim(), 700, 108, 64);
            y += Math.round(size * 0.95);
            ctx.fillStyle = colour(total);
            ctx.fillText(total.textContent.trim(), PADDING, y);
            y += 12;
        }

        // Changes over one month and one year: figures in their colour (sign), period in the muted colour.
        const changes = [...card.querySelectorAll('.report-variations li')];
        if (changes.length > 0) {
            y += 56;
            let x = PADDING;
            changes.forEach((change) => {
                const figures = [...change.querySelectorAll('.num')].map((part) => part.textContent.trim()).join(' ');
                const period = change.querySelector('.report-period').textContent.trim();
                ctx.font = font(600, 40);
                const figuresWidth = ctx.measureText(`${figures} `).width;
                ctx.font = font(400, 40);
                const width = figuresWidth + ctx.measureText(period).width;
                if (x > PADDING && x + width > WIDTH - PADDING) {
                    x = PADDING;
                    y += 56;
                }
                ctx.font = font(600, 40);
                ctx.fillStyle = colour(change);
                ctx.fillText(figures, x, y);
                ctx.font = font(400, 40);
                ctx.fillStyle = muted;
                ctx.fillText(period, x + figuresWidth, y);
                x += width + 52;
            });
        }

        // Bottom: footer line and text, then the figures (or the note) above it.
        ctx.textAlign = 'center';
        const footBaseline = HEIGHT - PADDING + 8;
        const rule = footBaseline - 52;
        ctx.fillStyle = getComputedStyle(foot).borderTopColor;
        ctx.fillRect(PADDING, rule, INNER, 2);
        ctx.font = font(400, fit(foot.textContent.trim(), 400, 30, 22));
        ctx.fillStyle = muted;
        ctx.fillText(foot.textContent.trim(), WIDTH / 2, footBaseline);
        let bottom = rule - 36;
        // Stars and level of the challenges, when asked for: just above the footer, as on the page.
        const stars = card.querySelector('.report-stars');
        if (stars) {
            ctx.font = font(600, fit(stars.textContent.trim(), 600, 34, 22));
            ctx.fillStyle = colour(stars);
            ctx.fillText(stars.textContent.trim(), WIDTH / 2, bottom);
            bottom -= 64;
        }
        const figures = [...card.querySelectorAll('.report-figures > div')];
        if (figures.length > 0) {
            figures.forEach((figure, index) => {
                const x = (WIDTH * (index + 0.5)) / figures.length;
                const label = figure.querySelector('dt');
                const value = figure.querySelector('dd');
                ctx.font = font(700, 48);
                ctx.fillStyle = colour(value);
                ctx.fillText(value.textContent.trim(), x, bottom);
                ctx.font = font(400, 30);
                ctx.fillStyle = colour(label);
                ctx.fillText(label.textContent.trim(), x, bottom - 58);
            });
            bottom -= 110;
        }
        const note = card.querySelector('.report-note');
        if (note) {
            ctx.font = font(400, fit(note.textContent.trim(), 400, 32, 22));
            ctx.fillStyle = colour(note);
            ctx.fillText(note.textContent.trim(), WIDTH / 2, bottom);
            bottom -= 56;
        }

        // Pyramid: its polygons and texts redrawn at the largest scale that fits between the top and the bottom.
        const pyramid = card.querySelector('svg.pyramid');
        const box = pyramid.viewBox.baseVal;
        const top = y + 40;
        const scale = Math.min(INNER / box.width, (bottom - top) / box.height);
        ctx.save();
        ctx.translate((WIDTH - box.width * scale) / 2, top + (bottom - top - box.height * scale) / 2);
        ctx.scale(scale, scale);
        pyramid.querySelectorAll('.pyr-tier').forEach((tier) => {
            const polygon = tier.querySelector('polygon');
            const shape = getComputedStyle(polygon);
            const points = polygon
                .getAttribute('points')
                .trim()
                .split(/\s+/)
                .map((point) => point.split(',').map(Number));
            ctx.beginPath();
            points.forEach(([px, py], index) => (index === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py)));
            ctx.closePath();
            if (shape.fill !== 'none') {
                ctx.fillStyle = shape.fill;
                ctx.fill();
            }
            if (shape.stroke !== 'none') {
                ctx.setLineDash((shape.strokeDasharray.match(/[\d.]+/g) ?? []).map(Number));
                ctx.lineWidth = Number.parseFloat(shape.strokeWidth) || 1;
                ctx.strokeStyle = shape.stroke;
                ctx.stroke();
                ctx.setLineDash([]);
            }
            tier.querySelectorAll('text').forEach((label) => {
                const look = getComputedStyle(label);
                ctx.font = `${look.fontWeight} ${look.fontSize} ${family}`;
                ctx.fillStyle = look.fill;
                ctx.fillText(label.textContent, Number(label.getAttribute('x')), Number(label.getAttribute('y')));
            });
        });
        ctx.restore();

        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('canvas'))), 'image/png');
        });
    }
})();
