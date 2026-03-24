<?php
/**
 * backend/api/interfaces.php
 *
 * GET /api/interfaces?router_id=N
 * Returns interfaces for the given router.
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

$routerId = filter_input(INPUT_GET, 'router_id', FILTER_VALIDATE_INT);
if (!$routerId || $routerId < 1) {
    Response::error('Valid router_id is required.', 400);
}

$db = Database::getInstance();

// Verify router exists and is active.
$router = $db->fetchOne(
    'SELECT id, name FROM routers WHERE id = ? AND active = 1',
    [$routerId]
);
if ($router === null) {
    Response::error('Router not found.', 404);
}

$ifaces = $db->fetchAll(
    'SELECT id, ifname, description FROM interfaces
     WHERE  router_id = ? AND active = 1
     ORDER BY ifname',
    [$routerId]
);

$result = array_map(fn($i) => [
    'id'          => (int) $i['id'],
    'ifname'      => $i['ifname'],
    'description' => $i['description'],
], $ifaces);

Response::json(['interfaces' => $result]);
