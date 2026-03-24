<?php
/**
 * config/config.php
 * 
 * Cdntral PHP configuration. All secrets should be overrdien in 
 * config/config.php.local.php (git-ignored) or via enviroment variables.
 */

// -- Databse --
define('DB_HOST',   getenv('BM_DB_HOST')    ?: '127.0.0.1');
define('DB_PORT',   (int)(getenv('BM_DB_PORT')  ?: 3306));
define('DB_NAME',   getenv('BM_DB_NAME')    ?: 'bandwith_monitor');
define('DB_USER',    getenv('BM_DB_USER')    ?: 'bm_user');
define('DB_PASS',    getenv('BM_DB_PASS')    ?: 'change_me_now');
define('DB_CHARSET', 'utf8mb4');

// -- Security -- 
// Generate with: php -r "echo bin2hex(random_bytes(32));"
define('AUTH_PEPPER',   getenv('BM_AUTH_PEPPER') ?: '681d193b14cadf5978097619fa142330eaefa18686fa4e015de5e949c21336a3');
define('SESSION_LIFETIME', 14400); // 4 hours in seconds
define('CSRF_TOKEN_BYTES', 32);

// Argon2id tuning (NIST/OWNASP)
define('ARGON2_MEMORY', 65536); // 64MB
define('ARGON2_TIMECOST', 4);
define('ARGON2_THREADS', 1);

// -- Application --
define('APP_ENV',   getenv('BM_ENV') ?: 'production');
define('APP_DEBUG', APP_ENV === 'development');

// -- Paths --
define('BASE_PATH', dirname(__DIR__));
define('LIB_PATH',  BASE_PATH . '/lib');
define('CONFIG_PATH', BASE_PATH . '/config');

// -- Load local overrides -- 
$_localCfg = CONFIG_PATH . '/config.local.php';
if (file_exist($_localCfg)) {
    require_once $_localCfg;
}
unset($_localCfg);