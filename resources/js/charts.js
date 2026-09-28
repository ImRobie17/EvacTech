/* =========================================================================
   EvacTech -- Chart.js entry point (roadmap item 3: no CDNs, works offline).

   Chart.js used to load from cdnjs as a UMD <script>, which put a global
   `Chart` on window that inline page scripts could reach. A bundled library is
   NOT a global, so all chart construction lives here and receives its data from
   the page instead.

   THE CONTRACT WITH BLADE
   -----------------------
   A page renders a canvas plus a JSON island:

     <div class="chart-wrap">
       <canvas id="registrationsChart" data-chart="bar"
               data-chart-data="registrationsChartData"
               role="img" aria-label="..."></canvas>
     </div>
     <script type="application/json" id="registrationsChartData">...</script>

   and opts into this bundle with `$viteEntries = ['resources/js/charts.js']`
   at the top of the view.

   Data goes in a <script type="application/json"> island rather than through
   @json inside a JS expression. The island is inert -- the browser never parses
   it as code -- so a shelter or item name containing a quote or an angle bracket
   cannot break the page or inject script. It also keeps this module free of
   Blade, which is why it can be bundled at all.

   NO height ATTRIBUTE ON THE CANVAS. Chart.js sizes from the parent, so the
   parent is .chart-wrap (fixed height, in staff.css) and maintainAspectRatio is
   false. A canvas height attribute fights the wrapper and collapses the chart.

   Supported `data-chart` values: bar, line, pie, doughnut. pie/doughnut are
   here ready for the age-group and vulnerable-category charts in roadmap
   Phase 3 item 9 -- they cost nothing until a canvas asks for them.
   ========================================================================= */

import Chart from 'chart.js/auto';

/* Resolve a design token to its current computed value. Tokens are read at
   render time, not hard-coded, so a chart cannot drift away from the palette
   in app.css -- and so the dark-mode overrides in design-system.css apply. */
function token(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

/* Categorical fills for pie and doughnut charts. Ordered so neighbouring
   segments stay distinguishable, and paired with white separators so the
   boundaries do not rely on colour contrast alone. */
function categoricalPalette() {
    return [
        token('--color-primary-600', '#0891b2'),
        token('--color-primary-900', '#164e63'),
        token('--color-success', '#15803d'),
        token('--color-warning', '#b45309'),
        token('--color-danger', '#b91c1c'),
        token('--color-primary-400', '#22d3ee'),
        token('--color-ink-soft', '#475569'),
    ];
}

function buildConfig(type, payload) {
    const ink = token('--color-ink-soft', '#475569');
    const grid = token('--color-border', '#cbd5e1');
    const surface = token('--color-bg', '#ffffff');
    const isCircular = type === 'pie' || type === 'doughnut';

    const datasets = (payload.datasets || []).map((set) => {
        if (isCircular) {
            return {
                label: set.label || '',
                data: set.data || [],
                backgroundColor: categoricalPalette(),
                borderColor: surface,
                borderWidth: 2,
            };
        }
        return {
            label: set.label || '',
            data: set.data || [],
            backgroundColor: token(set.colorToken || '--color-primary-400', '#22d3ee'),
            borderColor: token(set.colorToken || '--color-primary-600', '#0891b2'),
            borderWidth: type === 'line' ? 2 : 0,
            borderRadius: type === 'bar' ? 6 : 0,
            fill: false,
            tension: 0.25,
        };
    });

    return {
        type,
        data: { labels: payload.labels || [], datasets },
        options: {
            responsive: true,
            /* Required: the wrapper owns the height. */
            maintainAspectRatio: false,
            plugins: {
                /* A single-series bar chart has nothing to disambiguate, so its
                   legend is noise. Circular charts need one to name segments. */
                legend: {
                    display: isCircular || datasets.length > 1,
                    position: isCircular ? 'bottom' : 'top',
                    labels: { color: ink, boxWidth: 14, padding: 12 },
                },
                tooltip: { bodyColor: token('--color-bg', '#ffffff'), titleColor: token('--color-bg', '#ffffff') },
            },
            scales: isCircular ? {} : {
                x: { ticks: { color: ink }, grid: { color: grid, drawOnChartArea: false } },
                y: {
                    beginAtZero: true,
                    /* Headcounts are whole people. */
                    ticks: { color: ink, precision: 0 },
                    grid: { color: grid },
                },
            },
        },
    };
}

function readPayload(id) {
    const island = document.getElementById(id);
    if (!island) return null;
    try {
        return JSON.parse(island.textContent);
    } catch (err) {
        /* A malformed island must not take the rest of the page down with it --
           the dashboard KPIs above the chart still have to render. */
        console.error(`[EvacTech] chart data island #${id} is not valid JSON.`, err);
        return null;
    }
}

function initCharts() {
    const canvases = document.querySelectorAll('canvas[data-chart]');
    if (canvases.length === 0) return;

    const live = [];

    canvases.forEach((canvas) => {
        const type = canvas.dataset.chart;
        const payload = readPayload(canvas.dataset.chartData);
        if (!payload) return;

        const chart = new Chart(canvas, buildConfig(type, payload));
        live.push({ chart, type, payload });
    });

    if (live.length === 0) return;

    /* Dark mode is a data-theme attribute flipped by staff.js, and tokens were
       resolved to concrete values above. Without this the axes, ticks and
       legend keep their light-mode colours on a dark card and become
       unreadable. Rebuilding options is cheaper and less fragile than reaching
       into Chart.js internals. */
    const observer = new MutationObserver(() => {
        live.forEach((entry) => {
            const next = buildConfig(entry.type, entry.payload);
            entry.chart.options = next.options;
            entry.chart.data.datasets.forEach((set, i) => {
                const fresh = next.data.datasets[i];
                if (!fresh) return;
                set.backgroundColor = fresh.backgroundColor;
                set.borderColor = fresh.borderColor;
            });
            entry.chart.update('none');
        });
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
    });
}

document.addEventListener('DOMContentLoaded', initCharts);

/* =========================================================================
   PHASE 8 ITEM 5 -- download a chart as a PNG.

   A button carries data-chart-download="<canvas id>" and, optionally,
   data-chart-label="<words for the filename>". Delegated on `document` per the
   project convention, so it does not care whether the button was in the
   server-rendered markup or appeared later, and every failure path says why in
   the console instead of doing nothing.

   WHY NOT canvas.toDataURL() ON ITS OWN
   -------------------------------------
   A Chart.js canvas has a TRANSPARENT background -- the card behind it supplies
   the colour on screen. Export it directly and the PNG has an alpha channel with
   nothing in it, which most viewers, Word and Google Docs composite onto black.
   The result is dark-grey axis text on black: technically a correct export of
   the pixels, and completely unreadable. So the bitmap is redrawn onto an opaque
   rectangle first.

   WHY THE THEME COLOUR AND NOT ALWAYS WHITE
   -----------------------------------------
   The obvious fix is to fill white every time. That is wrong in dark mode: the
   axis labels, ticks and legend were rendered in the LIGHT-on-dark palette that
   buildConfig() resolved from the tokens, so white behind them gives pale text
   on a white field -- invisible in a different way. Filling with --color-bg,
   read at export time, means the PNG always matches the chart the operator was
   looking at when they pressed the button. A dark dashboard exports a dark
   image; a light one exports a light image.

   canvas.width / canvas.height are the BACKING STORE dimensions, which Chart.js
   has already multiplied by devicePixelRatio, so the export comes out at the
   screen's real resolution rather than CSS pixels.
   ========================================================================= */
function chartFilename(label) {
    const slug = String(label || 'chart')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'chart';

    /* Local date, not toISOString(): that converts to UTC first, so a download
       taken after 8am in Manila (UTC+8) would be stamped with the previous day
       for anyone exporting late in the evening. */
    const now = new Date();
    const stamp = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');

    return `evactech-${slug}-${stamp}.png`;
}

function downloadChart(button) {
    const canvasId = button.dataset.chartDownload;
    const canvas = document.getElementById(canvasId);

    if (!canvas) {
        console.error(`[EvacTech] download button points at #${canvasId}, but no such canvas is on this page.`);
        return;
    }
    if (!canvas.width || !canvas.height) {
        console.error(`[EvacTech] #${canvasId} has no rendered size yet, so there is nothing to export. Is the chart data island valid?`);
        return;
    }

    const flat = document.createElement('canvas');
    flat.width = canvas.width;
    flat.height = canvas.height;

    const ctx = flat.getContext('2d');
    if (!ctx) {
        console.error('[EvacTech] could not obtain a 2d context for the export canvas.');
        return;
    }

    ctx.fillStyle = token('--color-bg', '#ffffff');
    ctx.fillRect(0, 0, flat.width, flat.height);
    ctx.drawImage(canvas, 0, 0);

    const link = document.createElement('a');
    link.download = chartFilename(button.dataset.chartLabel || canvasId);
    link.href = flat.toDataURL('image/png');
    link.click();
}

document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-chart-download]');
    if (!button) return;
    e.preventDefault();
    downloadChart(button);
});
