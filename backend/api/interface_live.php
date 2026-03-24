<?php
/**
 * backend/api/interface_live.php
 *
 * GET /api/interface/{id}/live
 * Returns the most recent bandwidth sample for the interface.
 *
 * Routing via .htaccess rewrites /api/interface/(\d+)/live
 * to this file with $_GET['id'] set.
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

$db = Database::getInstance();

// Verify interface exists.
$iface = $db->fetchOne(
    'SELECT i.id, i.ifname, i.description, r.name AS router_name, p.name AS pop_name
     FROM   interfaces i
     JOIN   routers r  ON r.id  = i.router_id
     JOIN   pops    p  ON p.id  = r.pop_id
     WHERE  i.id = ? AND i.active = 1',
    [$ifaceId]
);
if ($iface === null) {
    Response::error('Interface not found.', 404);
}

// Get the latest sample.
$sample = $db->fetchOne(
    'SELECT in_mbps, out_mbps, timestamp
     FROM   bandwidth_history
     WHERE  interface_id = ?
     ORDER BY timestamp DESC
     LIMIT 1',
    [$ifaceId]
);

Response::json([
    'interface' => [
        'id'          => (int) $iface['id'],
        'ifname'      => $iface['ifname'],
        'description' => $iface['description'],
        'router'      => $iface['router_name'],
        'pop'         => $iface['pop_name'],
    ],
    'live' => $sample ? [
        'in_mbps'   => (float) $sample['in_mbps'],
        'out_mbps'  => (float) $sample['out_mbps'],
        'timestamp' => $sample['timestamp'],
    ] : null,
]);
