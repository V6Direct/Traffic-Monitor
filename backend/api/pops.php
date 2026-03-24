<?php
/**
 * backend/api/pops.php
 *
 * GET /api/pops
 * Returns the full PoP → Router tree.
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

$db = Database::getInstance();

$pops = $db->fetchAll(
    'SELECT id, name, location FROM pops WHERE active = 1 ORDER BY name'
);

$routers = $db->fetchAll(
    'SELECT id, pop_id, name FROM routers WHERE active = 1 ORDER BY name'
);

// Build nested structure: pops[].routers[].
$routersByPop = [];
foreach ($routers as $r) {
    $routersByPop[(int) $r['pop_id']][] = [
        'id'   => (int) $r['id'],
        'name' => $r['name'],
    ];
}

$result = [];
foreach ($pops as $pop) {
    $result[] = [
        'id'       => (int) $pop['id'],
        'name'     => $pop['name'],
        'location' => $pop['location'],
        'routers'  => $routersByPop[(int) $pop['id']] ?? [],
    ];
}

Response::json(['pops' => $result]);
