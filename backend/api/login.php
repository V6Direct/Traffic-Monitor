<?php
/**
 * backend/api/login.php
 *
 * POST /api/login
 * Body: {"username":"…","password":"…"}
 * Returns: {"user":{"id":…,"username":…,"role":…}}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once LIB_PATH . '/Database.php';
require_once LIB_PATH . '/Auth.php';
require_once LIB_PATH . '/Response.php';

Response::headers();
Response::requireMethod('POST');
Auth::startSession();

// Already authenticated → return current user.
if (Auth::check()) {
    Response::json(['user' => Auth::currentUser()]);
}

$body = (string) file_get_contents('php://input');
$data = json_decode($body, true);

$username = trim((string) ($data['username'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($username === '' || $password === '') {
    Response::error('Username and password are required.', 400);
}

// Basic rate limiting via session attempts counter (simple, no Redis needed).
$_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
if ($_SESSION['login_attempts'] > 10) {
    Response::error('Too many attempts. Please wait.', 429);
}

$user = Auth::login($username, $password);

if ($user === null) {
    Response::error('Invalid credentials.', 401);
}

// Reset attempts on success.
$_SESSION['login_attempts'] = 0;

Response::json([
    'user' => [
        'id'       => $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
    ],
]);
