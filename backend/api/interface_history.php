<?php
/**
 * backend/api/interface_history.php
 *
 * GET /api/interface/{id}/history?range=5m|1h|24h|7d
 *
 * Returns time-bucketed averages:
 *   5m  → raw (every 5s, last 5 minutes)
 *   1h  → 1-minute buckets
 *   24h → 5-minute buckets
 *   7d  → 1-hour buckets
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once LIB_PATH . '/Database.php';
require_once LIB_PATH . '/Auth.php';
require_once LIB_PATH . '/Response.php';

Response::headers();
Response::requireMethod('GET');
Auth::startSession();
Auth::requireAuth(api: true);

$ifaceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$ifaceId || $ifaceId < 1) {
    Response::error('Valid interface id required.', 400);
}

$range = $_GET['range'] ?? '5m';
$allowedRanges = ['5m', '1h', '24h', '7d'];
if (!in_array($range, $allowedRanges, true)) {
    Response::error('Invalid range. Use: 5m, 1h, 24h, 7d', 400);
}

$db = Database::getInstance();

// Verify interface exists.
$iface = $db->fetchOne(
    'SELECT id FROM interfaces WHERE id = ? AND active = 1',
    [$ifaceId]
);
if ($iface === null) {
    Response::error('Interface not found.', 404);
}

// Map range → (interval expression, time format for bucketing)
$rangeMap = [
    '5m'  => ['5 MINUTE',  null,              '%Y-%m-%d %H:%i:%s'],
    '1h'  => ['1 HOUR',    '60 SECOND',        '%Y-%m-%d %H:%i:00'],
    '24h' => ['24 HOUR',   '300 SECOND',       '%Y-%m-%d %H:%i:00'],
    '7d'  => ['7 DAY',     '3600 SECOND',      '%Y-%m-%d %H:00:00'],
];

[$since, $bucketSeconds, $tsFmt] = $rangeMap[$range];

if ($bucketSeconds === null) {
    // Raw data for 5m.
    $rows = $db->fetchAll(
        "SELECT DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:%s') AS ts,
                CAST(in_mbps  AS DECIMAL(16,4)) AS in_mbps,
                CAST(out_mbps AS DECIMAL(16,4)) AS out_mbps
         FROM   bandwidth_history
         WHERE  interface_id = ?
           AND  timestamp   >= DATE_SUB(NOW(3), INTERVAL {$since})
         ORDER BY timestamp ASC
         LIMIT 3600",
        [$ifaceId]
    );
} else {
    // Bucket + average.
    $rows = $db->fetchAll(
        "SELECT DATE_FORMAT(
                    FROM_UNIXTIME(
                        FLOOR(UNIX_TIMESTAMP(timestamp) / {$bucketSeconds}) * {$bucketSeconds}
                    ), '{$tsFmt}'
                ) AS ts,
                ROUND(AVG(in_mbps),  4) AS in_mbps,
                ROUND(AVG(out_mbps), 4) AS out_mbps
         FROM   bandwidth_history
         WHERE  interface_id = ?
           AND  timestamp   >= DATE_SUB(NOW(3), INTERVAL {$since})
         GROUP BY ts
         ORDER BY ts ASC
         LIMIT 10080",
        [$ifaceId]
    );
}

$data = array_map(fn($r) => [
    'ts'       => $r['ts'],
    'in_mbps'  => (float) $r['in_mbps'],
    'out_mbps' => (float) $r['out_mbps'],
], $rows);

Response::json([
    'interface_id' => (int) $ifaceId,
    'range'        => $range,
    'points'       => count($data),
    'data'         => $data,
]);
