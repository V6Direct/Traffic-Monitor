<?php
/**
 * frontend/dashboard.php
 *
 * Main dashboard. Requires authentication.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once LIB_PATH . '/Database.php';
require_once LIB_PATH . '/Auth.php';

Auth::startSession();
Auth::requireAuth(api: false);

$user = Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BW Monitor – Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"
            integrity="sha512-..." crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/frontend/css/tailwind.css">
    <style>
        /* Dark scrollbar */
        ::-webkit-scrollbar { width:6px; height:6px; }
        ::-webkit-scrollbar-track { background:#1e293b; }
        ::-webkit-scrollbar-thumb { background:#475569; border-radius:3px; }
        .tab-active { @apply border-b-2 border-indigo-500 text-white; }
    </style>
</head>
<body class="h-full bg-gray-950 text-gray-200">

<!-- ── Layout ───────────────────────────────────────────────── -->
<div class="min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="bg-gray-900 border-b border-gray-700 px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="flex items-center justify-center w-8 h-8 bg-indigo-600 rounded-lg">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0
                             002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/>
                </svg>
            </div>
            <span class="font-semibold text-white text-lg">BW Monitor</span>
        </div>

        <!-- Live indicator -->
        <div class="flex items-center gap-2 text-sm text-gray-400" id="liveIndicator">
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full
                             bg-green-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
            </span>
            <span id="lastUpdated">Waiting…</span>
        </div>

        <!-- User menu -->
        <div class="flex items-center gap-4">
            <span class="text-sm text-gray-400">
                <?= htmlspecialchars($user['username'], ENT_QUOTES) ?>
                <span class="ml-1 text-xs text-indigo-400 font-medium uppercase">
                    <?= htmlspecialchars($user['role'], ENT_QUOTES) ?>
                </span>
            </span>
            <button onclick="logoutUser()"
                    class="text-sm text-gray-400 hover:text-white transition px-3 py-1
                           border border-gray-700 rounded-lg hover:border-gray-500">
                Sign out
            </button>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 p-6 max-w-screen-2xl mx-auto w-full">

        <!-- ── Selectors Row ──────────────────────────────────── -->
        <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1.5 uppercase tracking-wide">
                    PoP
                </label>
                <select id="popSelect"
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg
                               text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">— Select PoP —</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1.5 uppercase tracking-wide">
                    Router
                </label>
                <select id="routerSelect" disabled
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg
                               text-white focus:outline-none focus:ring-2 focus:ring-indigo-500
                               disabled:opacity-40">
                    <option value="">— Select Router —</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1.5 uppercase tracking-wide">
                    Interface
                </label>
                <select id="ifaceSelect" disabled
                        class="w-full px-3 py-2 bg-gray-800 border border-gray-600 rounded-lg
                               text-white focus:outline-none focus:ring-2 focus:ring-indigo-500
                               disabled:opacity-40">
                    <option value="">— Select Interface —</option>
                </select>
            </div>
        </section>

        <!-- ── Live Mbps Cards ────────────────────────────────── -->
        <section id="liveSection" class="hidden grid grid-cols-2 gap-4 mb-6">
            <!-- Inbound -->
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold uppercase tracking-widest text-green-400">
                        ▼ Inbound
                    </span>
                    <span class="text-xs text-gray-500">Mbps</span>
                </div>
                <div id="liveIn" class="text-5xl font-mono font-bold text-green-400">—</div>
                <div class="mt-1 text-xs text-gray-500">Receive throughput</div>
            </div>
            <!-- Outbound -->
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-semibold uppercase tracking-widest text-blue-400">
                        ▲ Outbound
                    </span>
                    <span class="text-xs text-gray-500">Mbps</span>
                </div>
                <div id="liveOut" class="text-5xl font-mono font-bold text-blue-400">—</div>
                <div class="mt-1 text-xs text-gray-500">Transmit throughput</div>
            </div>
        </section>

        <!-- ── Placeholder when no interface selected ────────── -->
        <div id="emptyState" class="flex flex-col items-center justify-center py-20 text-gray-600">
            <svg class="w-16 h-16 mb-4 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2
                         0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0
                         002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2
                         2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            <p class="text-lg font-medium">Select a PoP, Router and Interface to begin monitoring</p>
        </div>

        <!-- ── Charts & History (shown after selection) ──────── -->
        <div id="dataSection" class="hidden">

            <!-- Tab Navigation -->
            <nav class="flex gap-1 mb-6 border-b border-gray-700">
                <button data-tab="realtime"
                        class="tab-btn px-4 py-2.5 text-sm font-medium text-gray-400 hover:text-white
                               transition border-b-2 border-transparent -mb-px">
                    Real-time
                </button>
                <button data-tab="5m"
                        class="tab-btn px-4 py-2.5 text-sm font-medium text-gray-400 hover:text-white
                               transition border-b-2 border-transparent -mb-px">
                    5 Minutes
                </button>
                <button data-tab="1h"
                        class="tab-btn px-4 py-2.5 text-sm font-medium text-gray-400 hover:text-white
                               transition border-b-2 border-transparent -mb-px">
                    1 Hour
                </button>
                <button data-tab="24h"
                        class="tab-btn px-4 py-2.5 text-sm font-medium text-gray-400 hover:text-white
                               transition border-b-2 border-transparent -mb-px">
                    24 Hours
                </button>
                <button data-tab="7d"
                        class="tab-btn px-4 py-2.5 text-sm font-medium text-gray-400 hover:text-white
                               transition border-b-2 border-transparent -mb-px">
                    7 Days
                </button>
                <button data-tab="history"
                        class="tab-btn px-4 py-2.5 text-sm font-medium text-gray-400 hover:text-white
                               transition border-b-2 border-transparent -mb-px">
                    History Table
                </button>
            </nav>

            <!-- Real-time chart panel -->
            <div id="panel-realtime" class="tab-panel">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                    <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-4">
                        Live Throughput (rolling 2-minute window)
                    </h2>
                    <div class="h-72">
                        <canvas id="chartRealtime"></canvas>
                    </div>
                </div>
            </div>

            <!-- History range chart panels (5m / 1h / 24h / 7d) -->
            <?php foreach (['5m' => '5 Minutes', '1h' => '1 Hour', '24h' => '24 Hours', '7d' => '7 Days'] as $r => $label): ?>
            <div id="panel-<?= $r ?>" class="tab-panel hidden">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide">
                            Throughput — Last <?= $label ?>
                        </h2>
                        <button onclick="refreshHistoryChart('<?= $r ?>')"
                                class="text-xs text-gray-500 hover:text-indigo-400 transition
                                       flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11
                                         4v5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Refresh
                        </button>
                    </div>
                    <div class="h-72">
                        <canvas id="chart-<?= $r ?>"></canvas>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- History Table Panel -->
            <div id="panel-history" class="tab-panel hidden">
                <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-gray-400 uppercase tracking-wide">
                            History Table — Last 24 Hours
                        </h2>
                        <div class="flex items-center gap-3">
                            <button onclick="exportCSV()"
                                    class="text-xs text-gray-400 hover:text-green-400 transition
                                           flex items-center gap-1 px-3 py-1.5 border border-gray-700
                                           rounded-lg hover:border-green-700">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2
                                             0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0
                                             01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                Export CSV
                            </button>
                            <select id="historyRange"
                                    class="text-xs bg-gray-800 border border-gray-600 rounded-lg
                                           px-2 py-1.5 text-gray-300 focus:outline-none
                                           focus:ring-1 focus:ring-indigo-500">
                                <option value="5m">Last 5 min</option>
                                <option value="1h">Last 1 hour</option>
                                <option value="24h" selected>Last 24 hours</option>
                                <option value="7d">Last 7 days</option>
                            </select>
                        </div>
                    </div>

                    <!-- Sortable table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="historyTable">
                            <thead>
                                <tr class="border-b border-gray-700 text-xs text-gray-500
                                           uppercase tracking-wide">
                                    <th class="sortable text-left py-2 px-3 cursor-pointer
                                               hover:text-white select-none" data-col="ts">
                                        Timestamp
                                        <span class="sort-icon ml-1 opacity-40">⇅</span>
                                    </th>
                                    <th class="sortable text-right py-2 px-3 cursor-pointer
                                               hover:text-white select-none" data-col="in">
                                        ▼ In (Mbps)
                                        <span class="sort-icon ml-1 opacity-40">⇅</span>
                                    </th>
                                    <th class="sortable text-right py-2 px-3 cursor-pointer
                                               hover:text-white select-none" data-col="out">
                                        ▲ Out (Mbps)
                                        <span class="sort-icon ml-1 opacity-40">⇅</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="historyBody" class="divide-y divide-gray-800">
                                <tr>
                                    <td colspan="3" class="py-8 text-center text-gray-600">
                                        Select an interface to view history.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination controls -->
                    <div class="flex items-center justify-between mt-4 text-xs text-gray-500">
                        <span id="historyCount">0 records</span>
                        <div class="flex gap-2">
                            <button id="prevPage" onclick="changePage(-1)"
                                    class="px-3 py-1 border border-gray-700 rounded hover:border-gray-500
                                           disabled:opacity-30 disabled:cursor-not-allowed" disabled>
                                ← Prev
                            </button>
                            <span id="pageInfo" class="px-2 py-1">Page 1</span>
                            <button id="nextPage" onclick="changePage(1)"
                                    class="px-3 py-1 border border-gray-700 rounded hover:border-gray-500
                                           disabled:opacity-30 disabled:cursor-not-allowed" disabled>
                                Next →
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /#dataSection -->
    </main>
</div><!-- /.min-h-screen -->

<script src="/frontend/js/app.js"></script>
<script>
    // Expose PHP session user to JS (non-sensitive fields only).
    window.BM_USER = {
        username: <?= json_encode($user['username']) ?>,
        role:     <?= json_encode($user['role']) ?>,
    };
</script>
</body>
</html>
