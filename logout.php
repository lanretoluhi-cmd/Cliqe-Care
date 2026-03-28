<?php
/**
 * Just in Time Group ERP - Carer Mobile App Logout
 * File Path: /careapp/logout.php
 * Fix: Replaced Auth::logout() with manual session destruction to prevent ERP web redirects.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';

$db = db();

// Start session if not already started by bootstrap
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$deviceUuid = $_SESSION['device_uuid'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

// 1. Securely revoke the device session in the database
if ($deviceUuid && $userId) {
    try {
        $db->query("UPDATE carer_mobile_devices SET status = 'revoked' WHERE device_uuid = ? AND user_id = ?", [$deviceUuid, $userId]);
    } catch (Exception $e) {
        // Silently continue if DB update fails during logout
    }
}

// 2. Manual Session Destruction (Bypasses core Auth::logout redirect)
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// 3. Clear Mobile LocalStorage (PINs) and Redirect
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <title>Logging Out | JIT Field Care</title>
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@600;800;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Urbanist', sans-serif;
            background: #F8FAFC;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            color: #64748B;
        }
        .loader {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        .spinner {
            width: 45px;
            height: 45px;
            border: 4px solid #E2E8F0;
            border-top-color: #15c3ba;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="loader">
        <div class="spinner"></div>
        <p style="font-weight: 900; font-size: 15px; color: #1E293B; letter-spacing: -0.02em;">Securing session...</p>
    </div>

    <script>
        // Purge the local PIN authentication flags
        localStorage.removeItem('jit_has_pin');
        localStorage.removeItem('jit_saved_name');
        
        // Redirect to login screen via JS explicitly using the App Base URL
        setTimeout(() => {
            window.location.replace('<?= CAREAPP_BASE_URL ?>login');
        }, 1200);
    </script>
</body>
</html>