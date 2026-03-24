<?php
/**
 * lib/Auth.php
 *
 * Session-based authentication with Argon2id + HMAC pepper.
 */

declare(strict_types=1);

class Auth
{
    /**
     * Start a secure session (call once per request, before output).
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_name('BM_SESSION');
            session_start();

            // Regenerate session ID periodically to prevent fixation.
            if (!isset($_SESSION['_created'])) {
                $_SESSION['_created'] = time();
            } elseif (time() - $_SESSION['_created'] > 1800) {
                session_regenerate_id(true);
                $_SESSION['_created'] = time();
            }
        }
    }

    /**
     * Attempt login. Returns user row on success, null on failure.
     */
    public static function login(string $username, string $password): ?array
    {
        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, password_hash, role FROM users WHERE username = ? AND active = 1',
            [$username]
        );

        if ($user === null) {
            // Dummy verify to prevent timing oracle on username existence.
            password_verify('dummy', '$argon2id$v=19$m=65536,t=4,p=1$dummysalt$dummyhash');
            return null;
        }

        $peppered = hash_hmac('sha256', $password, AUTH_PEPPER);

        if (!password_verify($peppered, $user['password_hash'])) {
            return null;
        }

        // Rehash if algo/params are outdated.
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID, [
            'memory_cost' => ARGON2_MEMORY,
            'time_cost'   => ARGON2_TIMECOST,
            'threads'     => ARGON2_THREADS,
        ])) {
            $newHash = self::hashPassword($password);
            $db->execute(
                'UPDATE users SET password_hash = ? WHERE id = ?',
                [$newHash, $user['id']]
            );
        }

        // Update last_login.
        $db->execute(
            'UPDATE users SET last_login = NOW() WHERE id = ?',
            [$user['id']]
        );

        // Establish session.
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['_created'] = time();

        return $user;
    }

    /**
     * Destroy the current session.
     */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * Returns true if the current session is authenticated.
     */
    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Require authentication; send 401 or redirect.
     *
     * @param bool $api  If true, return JSON 401 instead of redirect.
     */
    public static function requireAuth(bool $api = false): void
    {
        if (!self::check()) {
            if ($api) {
                Response::error('Unauthorized', 401);
            } else {
                header('Location: /frontend/login.php');
                exit;
            }
        }
    }

    /**
     * Require a specific role.
     */
    public static function requireRole(string $role, bool $api = false): void
    {
        self::requireAuth($api);
        if (($_SESSION['role'] ?? '') !== $role) {
            if ($api) {
                Response::error('Forbidden', 403);
            } else {
                http_response_code(403);
                exit('403 Forbidden');
            }
        }
    }

    /**
     * Hash a password with Argon2id + pepper.
     */
    public static function hashPassword(string $password): string
    {
        $peppered = hash_hmac('sha256', $password, AUTH_PEPPER);
        return password_hash($peppered, PASSWORD_ARGON2ID, [
            'memory_cost' => ARGON2_MEMORY,
            'time_cost'   => ARGON2_TIMECOST,
            'threads'     => ARGON2_THREADS,
        ]);
    }

    /**
     * Generate a CSRF token and store in session.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a submitted CSRF token (constant-time comparison).
     */
    public static function verifyCsrf(string $token): bool
    {
        $stored = $_SESSION['csrf_token'] ?? '';
        return hash_equals($stored, $token);
    }

    public static function currentUser(): array
    {
        return [
            'id'       => $_SESSION['user_id']  ?? null,
            'username' => $_SESSION['username'] ?? '',
            'role'     => $_SESSION['role']     ?? '',
        ];
    }
}
