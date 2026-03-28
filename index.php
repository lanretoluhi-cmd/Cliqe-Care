<?php
/**
 * Just in Time Group ERP - Carer Mobile App Router
 * File Path: /careapp/index.php
 * Features: Front Controller with foolproof path extraction.
 */

declare(strict_types=1);

// Initialize Standalone Mobile App Engine
require_once __DIR__ . '/bootstrap.php';

// Foolproof Route Extraction (Prevents the router from defaulting to login and looping)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('careapp/', $requestUri);
$route = isset($parts[1]) ? trim($parts[1], '/') : '';

// Strip .php extension if the user manually typed it in the URL
$route = str_replace('.php', '', $route);

// Default to the login screen if hitting the root folder
if (empty($route) || $route === 'index') {
    $route = 'login';
}

// Security: Prevent directory traversal
$route = str_replace(['.', '/'], '', $route);

// Resolve physical file path
$physicalFile = __DIR__ . '/' . $route . '.php';

// Dispatch the request
if (file_exists($physicalFile) && is_file($physicalFile) && $route !== 'config' && $route !== 'bootstrap') {
    require_once $physicalFile;
} else {
    // High-Fidelity 404 Fallback
    http_response_code(404);
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Not Found | JIT Care</title>
        <link href='https://fonts.googleapis.com/css2?family=Urbanist:wght@800&display=swap' rel='stylesheet'>
        <style>
            body { font-family: 'Urbanist', sans-serif; background: #F8FAFC; color: #1E293B; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; padding: 20px; }
            h1 { font-size: 80px; margin: 0; color: #15c3ba; }
            h2 { font-size: 20px; font-weight: 900; margin: 10px 0; text-transform: uppercase; }
            p { font-size: 15px; color: #64748B; font-weight: 600; line-height: 1.5; margin-bottom: 30px; }
            .btn-home { padding: 16px 32px; background: #15c3ba; color: white; text-decoration: none; border-radius: 50px; font-weight: 800; }
        </style>
    </head>
    <body>
        <h1>404</h1>
        <h2>Screen Not Found</h2>
        <p>The page you requested does not exist or has been moved.</p>
        <a href='" . CAREAPP_BASE_URL . "' class='btn-home'>Return to App</a>
    </body>
    </html>";
}