<?php
/**
 * backend/api/logout.php
 *
 * POST /api/logout
 * Destroys the session and returns {ok: true}.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once LIB_PATH . '/Auth.php';
require_once LIB_PATH . '/Response.php';

Response::headers();
Response::requireMethod('POST');
Auth::startSession();
Auth::requireAuth(api: true);

Auth::logout();
Response::json(['ok' => true]);
