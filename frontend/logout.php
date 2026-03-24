<?php
/**
 * frontend/logout.php
 * Destroys session, redirects to login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once LIB_PATH . '/Auth.php';

Auth::startSession();
Auth::logout();
header('Location: /frontend/login.php');
exit;
