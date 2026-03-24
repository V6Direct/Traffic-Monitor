/**
 * frontend/js/app.js
 *
 * Bandwidth Monitor — Frontend Application
 * ─────────────────────────────────────────
 * Handles:
 *  - PoP / Router / Interface cascading dropdowns
 *  - Live polling every 5 seconds (paused on tab hide)
 *  - Real-time rolling Chart.js line chart
 *  - History range charts (5m / 1h / 24h / 7d)
 *  - History table with client-side sort + pagination
 *  - CSV export
 *  - Logout
 */

'use strict';

// ── Constants ─────────────────────────────────────────────────
const POLL_INTERVAL_MS   = 5_000;
const REALTIME_MAX_PTS   = 24;   // 24 × 5 s = 2 min rolling window
const HISTORY_PAGE_SIZE  = 50;

const CHART_COLORS = {
    in:        'rgba(52, 211, 153, 1)',    // green-400
    inFill:    'rgba(52, 211, 153, 0.12)',
    out:       'rgba(96, 165, 250, 1)',    // blue-400
    outFill:   'rgba(96, 165, 250, 0.12)',
    grid:      'rgba(255,255,255,0.06)',
    tick:      'rgba(255,255,255,0.35)',
};

// ── State ─────────────────────────────────────────────────────
const state = {
    pops:          [],
    selectedPop:   null,
    selectedRouter: null,
    selectedIface: null,

    liveTimer:     null,   // setInterval handle
    liveAbort:     null,   // AbortController for in-flight fetch

    realtimeChart: null,
    historyCharts: {},     // keyed by range string

    historyData:   [],     // full unfiltered rows for current table view
    historySortCol: 'ts',
    historySortAsc: false,
    historyPage:   1,
    historyRange:  '24h',

    activeTab:     'realtime',
};

// ── Bootstrap ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    setupChartDefaults();
    initTabs();
    initDropdownListeners();
    initHistoryTableListeners();
    loadPops();

    // Pause polling when tab is hidden to save bandwidth.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopLive();
        } else if (state.selectedIface) {
            startLive();
        }
    });
});

// ── Chart.js global defaults ──────────────────────────────────
function setupChartDefaults() {
    Chart.defaults.color            = CHART_COLORS.tick;
    Chart.defaults.font.family      = 'ui-monospace, monospace';
    Chart.defaults.font.size        = 11;
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
}

// ── Tab management ────────────────────────────────────────────
function initTabs() {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => activateTab(btn.dataset.tab));
    });
    activateTab('realtime');
}

function activateTab(tabName) {
    state.activeTab = tabName;

    // Update button styles.
    document.querySelectorAll('.tab-btn').forEach(btn => {
        const active = btn.dataset.tab === tabName;
        btn.classList.toggle('border-indigo-500', active);
        btn.classList.toggle('text-white',        active);
        btn.classList.toggle('border-transparent', !active);
        btn.classList.toggle('text-gray-400',     !active);
    });

    // Show/hide panels.
    document.querySelectorAll('.tab-panel').forEach(panel => {
        panel.classList.toggle('hidden', panel.id !== `panel-${tabName}`);
    });

    // Lazy-load history charts when switching to their tab.
    if (['5m', '1h', '24h', '7d'].includes(tabName) && state.selectedIface) {
        refreshHistoryChart(tabName);
    }
    if (tabName === 'history' && state.selectedIface) {
        loadHistoryTable();
    }
}

// ── Dropdown loaders ──────────────────────────────────────────
async function loadPops() {
    try {
        const res  = await apiFetch('/backend/api/pops.php');
        const data = await res.json();

        state.pops = data.pops || [];

        const popSel = document.getElementById('popSelect');
        popSel.innerHTML = '<option value="">— Select PoP —</option>';
        state.pops.forEach(pop => {
            popSel.insertAdjacentHTML('beforeend',
                `<option value="${pop.id}">${escHtml(pop.name)} — ${escHtml(pop.location)}</option>`
            );
        });
    } catch (err) {
        console.error('Failed to load PoPs:', err);
    }
}

function initDropdownListeners() {
    document.getElementById('popSelect').addEventListener('change', onPopChange);
    document.getElementById('routerSelect').addEventListener('change', onRouterChange);
    document.getElementById('ifaceSelect').addEventListener('change', onIfaceChange);
    document.getElementById('historyRange').addEventListener('change', (e) => {
        state.historyRange = e.target.value;
        if (state.selectedIface) loadHistoryTable();
    });
}

function onPopChange(e) {
    const popId = parseInt(e.target.value, 10);
    state.selectedPop    = popId || null;
    state.selectedRouter = null;
    state.selectedIface  = null;

    stopLive();
    resetInterface();

    const routerSel = document.getElementById('routerSelect');
    const ifaceSel  = document.getElementById('ifaceSelect');

    routerSel.innerHTML = '<option value="">— Select Router —</option>';
    ifaceSel.innerHTML  = '<option value="">— Select Interface —</option>';
    routerSel.disabled  = !popId;
    ifaceSel.disabled   = true;

    if (!popId) return;

    const pop = state.pops.find(p => p.id === popId);
    if (!pop) return;

    pop.routers.forEach(r => {
        routerSel.insertAdjacentHTML('beforeend',
            `<option value="${r.id}">${escHtml(r.name)}</option>`
        );
    });
}

async function onRouterChange(e) {
    const routerId = parseInt(e.target.value, 10);
    state.selectedRouter = routerId || null;
    state.selectedIface  = null;

    stopLive();
    resetInterface();

    const ifaceSel = document.getElementById('ifaceSelect');
    ifaceSel.innerHTML = '<option value="">Loading…</option>';
    ifaceSel.disabled  = true;

    if (!routerId) {
        ifaceSel.innerHTML = '<option value="">— Select Interface —</option>';
        return;
    }

    try {
        const res  = await apiFetch(`/backend/api/interfaces.php?router_id=${routerId}`);
        const data = await res.json();

        ifaceSel.innerHTML = '<option value="">— Select Interface —</option>';
        (data.interfaces || []).forEach(iface => {
            const label = iface.description
                ? `${iface.ifname} — ${iface.description}`
                : iface.ifname;
            ifaceSel.insertAdjacentHTML('beforeend',
                `<option value="${iface.id}">${escHtml(label)}</option>`
            );
        });
        ifaceSel.disabled = false;
    } catch (err) {
        console.error('Failed to load interfaces:', err);
        ifaceSel.innerHTML = '<option value="">Error loading interfaces</option>';
    }
}

function onIfaceChange(e) {
    const ifaceId = parseInt(e.target.value, 10);
    state.selectedIface = ifaceId || null;

    stopLive();
    resetInterface();

    if (!ifaceId) return;

    // Show data sections.
    document.getElementById('emptyState').classList.add('hidden');
    document.getElementById('liveSection').classList.remove('hidden');
    document.getElementById('dataSection').classList.remove('hidden');

    // Init / clear the real-time chart.
    initRealtimeChart();

    // Switch to realtime tab on new selection.
    activateTab('realtime');

    // Begin polling immediately.
    startLive();
}

// ── Live polling ──────────────────────────────────────────────
function startLive() {
    if (state.liveTimer) return;   // already running
    fetchLive();                   // immediate first fetch
    state.liveTimer = setInterval(fetchLive, POLL_INTERVAL_MS);
}

function stopLive() {
    if (state.liveTimer) {
        clearInterval(state.liveTimer);
        state.liveTimer = null;
    }
    if (state.liveAbort) {
        state.liveAbort.abort();
        state.liveAbort = null;
    }
}

async function fetchLive() {
    if (!state.selectedIface) return;

    // Abort any previous in-flight request.
    if (state.liveAbort) state.liveAbort.abort();
    state.liveAbort = new AbortController();

    try {
        const res = await apiFetch(
            `/backend/api/interface_live.php?id=${state.selectedIface}`,
            { signal: state.liveAbort.signal }
        );
        const data = await res.json();

        if (!res.ok) {
            if (res.status === 401) { redirectToLogin(); }
            return;
        }

        const live = data.live;
        if (!live) return;

        const inMbps  = parseFloat(live.in_mbps);
        const outMbps = parseFloat(live.out_mbps);

        // Update numeric display.
        document.getElementById('liveIn').textContent  = formatMbps(inMbps);
        document.getElementById('liveOut').textContent = formatMbps(outMbps);

        // Timestamp indicator.
        document.getElementById('lastUpdated').textContent =
            `Updated ${formatTime(new Date())}`;

        // Push to real-time chart.
        pushRealtimePoint(live.timestamp, inMbps, outMbps);

    } catch (err) {
        if (err.name === 'AbortError') return;
        console.warn('Live fetch error:', err);
    }
}

// ── Real-time Chart ───────────────────────────────────────────
function initRealtimeChart() {
    const canvas = document.getElementById('chartRealtime');

    if (state.realtimeChart) {
        state.realtimeChart.destroy();
    }

    state.realtimeChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels:   [],
            datasets: [
                makeDataset('▼ In (Mbps)',  CHART_COLORS.in,  CHART_COLORS.inFill),
                makeDataset('▲ Out (Mbps)', CHART_COLORS.out, CHART_COLORS.outFill),
            ],
        },
        options: realtimeChartOptions(),
    });
}

function pushRealtimePoint(rawTs, inMbps, outMbps) {
    if (!state.realtimeChart) return;

    const chart  = state.realtimeChart;
    const label  = formatTime(new Date(rawTs + 'Z'));

    chart.data.labels.push(label);
    chart.data.datasets[0].data.push(inMbps);
    chart.data.datasets[1].data.push(outMbps);

    // Keep rolling window.
    if (chart.data.labels.length > REALTIME_MAX_PTS) {
        chart.data.labels.shift();
        chart.data.datasets[0].data.shift();
        chart.data.datasets[1].data.shift();
    }

    chart.update('none');   // 'none' skips animation for smooth updates
}

// ── History Charts ────────────────────────────────────────────
async function refreshHistoryChart(range) {
    if (!state.selectedIface) return;

    const canvasId = `chart-${range}`;
    const canvas   = document.getElementById(canvasId);
    if (!canvas) return;

    try {
        const res  = await apiFetch(
            `/backend/api/interface_history.php?id=${state.selectedIface}&range=${range}`
        );
        const data = await res.json();

        if (!res.ok) return;

        const labels  = data.data.map(d => d.ts);
        const inData  = data.data.map(d => parseFloat(d.in_mbps));
        const outData = data.data.map(d => parseFloat(d.out_mbps));

        // Destroy existing chart if any.
        if (state.historyCharts[range]) {
            state.historyCharts[range].destroy();
        }

        state.historyCharts[range] = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    makeDataset('▼ In (Mbps)',  CHART_COLORS.in,  CHART_COLORS.inFill,  inData),
                    makeDataset('▲ Out (Mbps)', CHART_COLORS.out, CHART_COLORS.outFill, outData),
                ],
            },
            options: historyChartOptions(range),
        });

    } catch (err) {
        console.error(`Failed to load history chart [${range}]:`, err);
    }
}

// ── History Table ─────────────────────────────────────────────
async function loadHistoryTable() {
    if (!state.selectedIface) return;

    const range = state.historyRange;

    try {
        const res  = await apiFetch(
            `/backend/api/interface_history.php?id=${state.selectedIface}&range=${range}`
        );
        const data = await res.json();

        if (!res.ok) return;

        state.historyData   = data.data || [];
        state.historyPage   = 1;
        renderHistoryTable();

    } catch (err) {
        console.error('Failed to load history table:', err);
    }
}

function initHistoryTableListeners() {
    document.querySelectorAll('#historyTable .sortable').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.col;
            if (state.historySortCol === col) {
                state.historySortAsc = !state.historySortAsc;
            } else {
                state.historySortCol = col;
                state.historySortAsc = col !== 'ts';   // default: newest first
            }
            state.historyPage = 1;
            renderHistoryTable();
        });
    });
}

function renderHistoryTable() {
    const data = [...state.historyData];

    // Sort.
    data.sort((a, b) => {
        let va, vb;
        if (state.historySortCol === 'ts') {
            va = a.ts; vb = b.ts;
        } else if (state.historySortCol === 'in') {
            va = parseFloat(a.in_mbps); vb = parseFloat(b.in_mbps);
        } else {
            va = parseFloat(a.out_mbps); vb = parseFloat(b.out_mbps);
        }
        return state.historySortAsc
            ? (va < vb ? -1 : va > vb ? 1 : 0)
            : (va > vb ? -1 : va < vb ? 1 : 0);
    });

    // Update sort icons.
    document.querySelectorAll('#historyTable .sortable').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (th.dataset.col === state.historySortCol) {
            icon.textContent = state.historySortAsc ? '↑' : '↓';
            icon.classList.replace('opacity-40', 'opacity-100');
        } else {
            icon.textContent = '⇅';
            icon.classList.replace('opacity-100', 'opacity-40');
        }
    });

    // Paginate.
    const total  = data.length;
    const pages  = Math.ceil(total / HISTORY_PAGE_SIZE) || 1;
    state.historyPage = Math.min(state.historyPage, pages);

    const start  = (state.historyPage - 1) * HISTORY_PAGE_SIZE;
    const sliced = data.slice(start, start + HISTORY_PAGE_SIZE);

    // Render rows.
    const tbody = document.getElementById('historyBody');
    if (sliced.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="3" class="py-8 text-center text-gray-600">No data available.</td>
            </tr>`;
    } else {
        tbody.innerHTML = sliced.map(row => `
            <tr class="hover:bg-gray-800/50 transition">
                <td class="py-2 px-3 font-mono text-xs text-gray-400">${escHtml(row.ts)}</td>
                <td class="py-2 px-3 text-right font-mono text-green-400">
                    ${parseFloat(row.in_mbps).toFixed(3)}
                </td>
                <td class="py-2 px-3 text-right font-mono text-blue-400">
                    ${parseFloat(row.out_mbps).toFixed(3)}
                </td>
            </tr>
        `).join('');
    }

    // Update pagination controls.
    document.getElementById('historyCount').textContent =
        `${total.toLocaleString()} records`;
    document.getElementById('pageInfo').textContent =
        `Page ${state.historyPage} / ${pages}`;
    document.getElementById('prevPage').disabled = state.historyPage <= 1;
    document.getElementById('nextPage').disabled = state.historyPage >= pages;
}

function changePage(delta) {
    state.historyPage += delta;
    renderHistoryTable();
}

// ── CSV Export ────────────────────────────────────────────────
function exportCSV() {
    if (!state.historyData.length) return;

    const rows = [
        ['Timestamp', 'In (Mbps)', 'Out (Mbps)'],
        ...state.historyData.map(r => [r.ts, r.in_mbps, r.out_mbps]),
    ];
    const csv     = rows.map(r => r.join(',')).join('\n');
    const blob    = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url     = URL.createObjectURL(blob);
    const anchor  = document.createElement('a');
    const iface   = document.getElementById('ifaceSelect');
    const ifName  = iface.options[iface.selectedIndex]?.text || 'interface';

    anchor.href     = url;
    anchor.download = `bwmon_${slugify(ifName)}_${state.historyRange}_${dateStamp()}.csv`;
    anchor.click();
    URL.revokeObjectURL(url);
}

// ── Logout ────────────────────────────────────────────────────
async function logoutUser() {
    stopLive();
    try {
        await apiFetch('/backend/api/logout.php', { method: 'POST' });
    } catch (_) { /* ignore */ }
    redirectToLogin();
}

function redirectToLogin() {
    window.location.href = '/frontend/login.php';
}

// ── Reset UI ──────────────────────────────────────────────────
function resetInterface() {
    document.getElementById('liveIn').textContent  = '—';
    document.getElementById('liveOut').textContent = '—';
    document.getElementById('lastUpdated').textContent = 'Waiting…';

    if (state.realtimeChart) {
        state.realtimeChart.destroy();
        state.realtimeChart = null;
    }
    Object.values(state.historyCharts).forEach(c => c.destroy());
    state.historyCharts = {};
    state.historyData   = [];

    document.getElementById('historyBody').innerHTML = `
        <tr>
            <td colspan="3" class="py-8 text-center text-gray-600">
                Select an interface to view history.
            </td>
        </tr>`;

    document.getElementById('liveSection').classList.add('hidden');
    document.getElementById('dataSection').classList.add('hidden');
    document.getElementById('emptyState').classList.remove('hidden');
}

// ── Chart factory helpers ─────────────────────────────────────
function makeDataset(label, color, fillColor, data = []) {
    return {
        label,
        data,
        borderColor:     color,
        backgroundColor: fillColor,
        borderWidth:     2,
        pointRadius:     0,
        pointHoverRadius: 4,
        fill:            true,
        tension:         0.3,
    };
}

function baseChartOptions() {
    return {
        responsive:          true,
        maintainAspectRatio: false,
        interaction: {
            mode:      'index',
            intersect: false,
        },
        plugins: {
            legend: {
                position: 'top',
                labels: { color: CHART_COLORS.tick, padding: 16 },
            },
            tooltip: {
                backgroundColor: 'rgba(15,23,42,0.95)',
                borderColor:     'rgba(255,255,255,0.1)',
                borderWidth:     1,
                callbacks: {
                    label: ctx =>
                        ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(3)} Mbps`,
                },
            },
        },
        scales: {
            x: {
                grid:  { color: CHART_COLORS.grid },
                ticks: { color: CHART_COLORS.tick, maxTicksLimit: 8, maxRotation: 0 },
            },
            y: {
                min:  0,
                grid: { color: CHART_COLORS.grid },
                ticks: {
                    color:    CHART_COLORS.tick,
                    callback: v => `${v} Mbps`,
                },
            },
        },
        animation: { duration: 200 },
    };
}

function realtimeChartOptions() {
    const opts = baseChartOptions();
    opts.plugins.legend.display = true;
    return opts;
}

function historyChartOptions(range) {
    const opts = baseChartOptions();
    // Reduce x-tick density for longer ranges.
    const maxTicks = { '5m': 12, '1h': 12, '24h': 12, '7d': 14 };
    opts.scales.x.ticks.maxTicksLimit = maxTicks[range] || 10;
    return opts;
}

// ── API wrapper ───────────────────────────────────────────────
async function apiFetch(url, options = {}) {
    const defaults = {
        method:      options.method || 'GET',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    };
    return fetch(url, { ...defaults, ...options });
}

// ── Utilities ─────────────────────────────────────────────────
function formatMbps(v) {
    if (!isFinite(v)) return '—';
    return v >= 1000
        ? (v / 1000).toFixed(2) + ' Gbps'
        : v.toFixed(2);
}

function formatTime(date) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function slugify(str) {
    return str.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function dateStamp() {
    return new Date().toISOString().slice(0, 10);
}
