<?php
/**
 * lib/Response.php
 *
 * JSON response helpers. All API endpoints use these.
 */

declare(strict_types=1);

class Response
{
    /**
     * Send a JSON success response and exit.
     */
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Send a JSON error response and exit.
     */
    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(array_merge(['error' => $message], $extra), $status);
    }

    /**
     * Set common API headers (CORS disabled by default; enable if needed).
     */
    public static function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
    }

    /**
     * Only allow a specific HTTP method; return 405 otherwise.
     */
    public static function requireMethod(string ...$methods): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
            self::error('Method Not Allowed', 405, [
                'allowed' => implode(', ', $methods),
            ]);
        }
    }
}
