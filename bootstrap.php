<?php
/**
 * JIT Group ERP - Core Bootstrap
 * File Path: /app/core/bootstrap.php
 * Summary: PHP 8.3 Stateless Entry Point. Handles subfolder pathing and external package autoloading.
 * Update: Hardened session configuration to resolve public login redirect loops.
 */

declare(strict_types=1);

// 1. Setup Constants
define('APP_ROOT', dirname(__DIR__, 2));
define('CONFIG_PATH', APP_ROOT . '/app/config');
define('LOG_PATH', APP_ROOT . '/storage/logs');

/**
 * 2. Manual URL Configuration
 */
define('BASE_URL', '/erp-jit/public/');

// 3. Environment & Error Handling
error_reporting(E_ALL);
ini_set('display_errors', '1'); 
ini_set('log_errors', '1');
if (!is_dir(LOG_PATH)) {
    mkdir(LOG_PATH, 0777, true);
}
ini_set('error_log', LOG_PATH . '/php_errors.log');

// 4. Autoloaders
$composerAutoload = APP_ROOT . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = APP_ROOT . '/app/services/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require_once $file;
});

// 5. Global Helper Functions
function db(): \App\Database {
    static $db = null;
    if ($db === null) {
        $configFile = CONFIG_PATH . '/database.php';
        if (!file_exists($configFile)) {
            throw new \Exception("Database configuration missing at: $configFile");
        }
        $config = require $configFile;
        $db = new \App\Database($config);
    }
    return $db;
}

/**
 * Redirect with forced session commit
 */
function redirect(string $path): void {
    $cleanPath = trim(str_replace('.php', '', $path), '/');
    session_write_close(); // Commit session before redirecting
    header("Location: " . BASE_URL . $cleanPath);
    exit;
}

function audit(string $action, ?string $resourceType = null, ?string $resourceId = null, array $old = [], array $new = []): void {
    $userId = $_SESSION['user_id'] ?? $_SESSION['lms_student_id'] ?? null;
    $tenantId = $_SESSION['tenant_id'] ?? null;
    if (!$tenantId) return;
    try {
        db()->query(
            "INSERT INTO audit_logs (tenant_id, user_id, action, resource_type, resource_id, old_values, new_values, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$tenantId, $userId, $action, $resourceType, $resourceId, json_encode($old), json_encode($new), $_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown']
        );
    } catch (\Exception $e) {
        error_log("Audit Logging Failed: " . $e->getMessage());
    }
}
// 6. Start Secure Session
/**
 * FIX: Set path to '/' to ensure session is accessible across erp-jit/public and erp-jit/lms.
 * Added explicit session_name to prevent conflict with other localhost apps.
 */
session_name('JIT_SECURE_NODE');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 6. Start Secure Session csrf thing just added by daniel
function getCsrfToken() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validates the CSRF token submitted via POST requests.
 * Halts execution immediately if the token is missing or invalid.
 */
function validateCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        // hash_equals prevents timing attacks during string comparison
        if (empty($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
            http_response_code(403);
            die('Security Error: CSRF token validation failed. Please return to the previous page, refresh, and try again.');
        }
    }
}