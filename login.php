<?php
/**
 * Just in Time Group ERP - Carer Mobile App
 * File Path: /careapp/login.php
 * Features: Geospatial Login, Single-Device Enforcement, Modern Split-Card UI (Ionic), 4-Digit PIN.
 * Fix: Moved dispatchLoginAlerts to the top to resolve "Call to undefined function" fatal error.
 */

declare(strict_types=1);

// Initialize Standalone Mobile App Engine
require_once __DIR__ . '/bootstrap.php';

use App\Auth;
use App\SettingsService;

$db = db();
$errorMsg = null;
$pinSetupRequired = false;

// =========================================================================
// HELPER: Dispatch Notifications (Defined early to prevent Fatal Error)
// =========================================================================
if (!function_exists('dispatchLoginAlerts')) {
    function dispatchLoginAlerts($db, $tenantId, $user, $lat, $lng, $deviceUuid, $ipAddress) {
        try {
            $settingsSvc = new SettingsService($db);
            $settings = $settingsSvc->getSettings($tenantId);
            $appName = $settings['app_name'] ?? 'JIT Healthcare';
            $brandColor = $settings['primary_color'] ?? '#15c3ba';
            
            $loginTime = date('Y-m-d H:i:s');
            $locationStr = ($lat && $lng) ? "<a href='https://maps.google.com/?q=$lat,$lng' style='color:$brandColor; text-decoration:none; font-weight:bold;'>Lat: $lat, Lng: $lng (View on Map)</a>" : "Unavailable";

            $headers = "From: security@jit-erp.com\r\nReply-To: no-reply@jit-erp.com\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";

            // 1. Alert User
            $userBody = "<div style='font-family:sans-serif; max-width:600px; padding:20px; border:1px solid #e2e8f0; border-radius:12px;'><h2 style='color:$brandColor; margin-top:0;'>Security Alert</h2><p>A new login to your $appName account was recorded at $loginTime.</p><p>Device ID: $deviceUuid</p></div>";
            @mail($user['email'] ?? '', "New Login Detected - $appName", $userBody, $headers);

            // 2. Alert Admins
            $adminBody = "<div style='font-family:sans-serif; max-width:600px; padding:20px; border:1px solid #e2e8f0; border-radius:12px;'><h2 style='color:$brandColor; margin-top:0;'>Field Tracking Alert</h2><p>Staff member <strong>{$user['full_name']}</strong> has logged in.</p><p>Time: $loginTime<br>Location: $locationStr</p></div>";
            $admins = $db->query("SELECT u.email FROM users u JOIN user_assignments ua ON u.id = ua.user_id JOIN roles r ON ua.role_id = r.id WHERE r.hierarchy_level >= 80 AND u.tenant_id = ?", [$tenantId]);
            foreach ($admins as $admin) { @mail($admin['email'], "Staff App Login Activity: {$user['full_name']}", $adminBody, $headers); }
        } catch (Exception $e) { /* Silent */ }
    }
}

// If already logged in, push straight to the mobile dashboard using clean URL
if (Auth::check()) {
    header("Location: " . CAREAPP_BASE_URL . "dashboard");
    exit;
}

// HANDLE MOBILE AUTHENTICATION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: INITIAL LOGIN (Verify Email/Pass and Request PIN Setup)
    if (isset($_POST['action']) && $_POST['action'] === 'mobile_login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $forcePinReset = ($_POST['force_pin_reset'] ?? '0') === '1';
        
        // Captured Device Data
        $lat = $_POST['latitude'] ?? null;
        $lng = $_POST['longitude'] ?? null;
        $deviceUuid = $_POST['device_uuid'] ?? 'unknown_device';
        $osType = $_POST['os_type'] ?? 'web'; 
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            if (empty($email) || empty($password)) {
                throw new Exception("Please provide both email and password.");
            }

            // Fetch User
            $user = $db->row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                audit('carer_app.failed_login', 'users', null, [], ['email' => $email, 'ip' => $ipAddress]);
                throw new Exception("Invalid credentials or inactive account.");
            }

            $userId = $user['id'];
            $tenantId = $user['tenant_id'];

            // VERIFY ROLE (Must be Carer/Frontline level)
            $roleData = $db->row("
                SELECT r.hierarchy_level 
                FROM roles r 
                JOIN user_assignments ua ON r.id = ua.role_id 
                WHERE ua.user_id = ? AND r.tenant_id = ? LIMIT 1", 
            [$userId, $tenantId]);

            if (!$roleData || (int)$roleData['hierarchy_level'] > 50) {
                throw new Exception("Access restricted. This app is for Frontline Care Professionals only.");
            }

            // =========================================================================
            // CROSS-DEVICE PIN DETECTION & AUTO-RESTORE
            // =========================================================================
            $existingPin = null;
            if (!$forcePinReset) {
                // Look for the most recently established PIN for this user across any device
                $existingPin = $db->row("SELECT pin_hash FROM carer_mobile_devices WHERE user_id = ? AND pin_hash IS NOT NULL ORDER BY created_at DESC LIMIT 1", [$userId]);
            }

            if ($existingPin && !empty($existingPin['pin_hash'])) {
                // AUTO-RESTORE LOGIC: Link the new device to the existing PIN and bypass setup
                $db->beginTransaction();

                // Revoke other active devices to maintain Single-Device Security
                $db->query("UPDATE carer_mobile_devices SET status = 'revoked' WHERE user_id = ?", [$userId]);

                $deviceId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $db->query("INSERT INTO carer_mobile_devices 
                            (id, tenant_id, user_id, device_uuid, os_type, app_version, status, last_sync_at, pin_hash) 
                            VALUES (?, ?, ?, ?, ?, '1.0.0', 'active', NOW(), ?)
                            ON DUPLICATE KEY UPDATE status = 'active', last_sync_at = NOW(), pin_hash = ?", 
                [$deviceId, $tenantId, $userId, $deviceUuid, $osType, $existingPin['pin_hash'], $existingPin['pin_hash']]);

                $db->query("UPDATE users SET last_login_at = NOW(), failed_login_attempts = 0 WHERE id = ?", [$userId]);

                audit('carer_app.secure_login', 'users', $userId, [], [
                    'ip_address' => $ipAddress,
                    'lat' => $lat,
                    'lng' => $lng,
                    'device' => $deviceUuid,
                    'auth_method' => 'password_auto_restore_pin'
                ]);

                $db->commit();

                // Session Setup
                $_SESSION['user_id'] = $userId;
                $_SESSION['tenant_id'] = $tenantId;
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['device_uuid'] = $deviceUuid;

                // Dispatch Email Notification
                dispatchLoginAlerts($db, $tenantId, $user, $lat, $lng, $deviceUuid, $ipAddress);

                // Output JS to update LocalStorage flags and execute routing
                echo "<script>
                    localStorage.setItem('jit_has_pin', 'true');
                    localStorage.setItem('jit_saved_name', '" . addslashes($user['full_name']) . "');
                    window.location.href = '" . CAREAPP_BASE_URL . "dashboard';
                </script>";
                exit;
            } else {
                // NO PIN EXISTS OR RESET WAS FORCED: Transition to PIN setup
                $pinSetupRequired = true;
                $_SESSION['pending_login'] = [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'phone' => $user['phone'] ?? '',
                    'device_uuid' => $deviceUuid,
                    'os_type' => $osType,
                    'lat' => $lat,
                    'lng' => $lng,
                    'ip' => $ipAddress
                ];
            }

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
        }
    }

    // ACTION: SAVE PIN AND FINALIZE LOGIN
    if (isset($_POST['action']) && $_POST['action'] === 'setup_pin') {
        $pin = $_POST['pin'] ?? '';
        $confirmPin = $_POST['confirm_pin'] ?? '';
        $pendingData = $_SESSION['pending_login'] ?? null;

        try {
            if (!$pendingData) throw new Exception("Session expired. Please log in again.");
            if (strlen($pin) !== 4 || !is_numeric($pin)) { $pinSetupRequired = true; throw new Exception("PIN must be 4 digits."); }
            if ($pin !== $confirmPin) { $pinSetupRequired = true; throw new Exception("PINs do not match."); }

            $userId = $pendingData['user_id'];
            $tenantId = $pendingData['tenant_id'];
            $hashedPin = password_hash($pin, PASSWORD_DEFAULT);

            $db->beginTransaction();

            $db->query("UPDATE carer_mobile_devices SET status = 'revoked' WHERE user_id = ?", [$userId]);

            $deviceId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $db->query("INSERT INTO carer_mobile_devices 
                        (id, tenant_id, user_id, device_uuid, os_type, app_version, status, last_sync_at, pin_hash) 
                        VALUES (?, ?, ?, ?, ?, '1.0.0', 'active', NOW(), ?)
                        ON DUPLICATE KEY UPDATE status = 'active', last_sync_at = NOW(), pin_hash = ?", 
            [$deviceId, $tenantId, $userId, $pendingData['device_uuid'], $pendingData['os_type'], $hashedPin, $hashedPin]);

            $db->query("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$userId]);
            
            audit('carer_app.secure_login', 'users', $userId, [], ['device' => $pendingData['device_uuid']]);
            $db->commit();

            // Setup PHP session
            $_SESSION['user_id'] = $userId;
            $_SESSION['tenant_id'] = $tenantId;
            $_SESSION['user_name'] = $pendingData['full_name'];
            $_SESSION['device_uuid'] = $pendingData['device_uuid'];
            
            // Dispatch Alerts
            dispatchLoginAlerts($db, $tenantId, ['email' => $pendingData['email'], 'full_name' => $pendingData['full_name'], 'phone' => $pendingData['phone']], 
                $pendingData['lat'], $pendingData['lng'], $pendingData['device_uuid'], $pendingData['ip']);

            unset($_SESSION['pending_login']);
            
            header("Location: " . CAREAPP_BASE_URL . "dashboard");
            exit;
        } catch (Exception $e) { 
            if($db->inTransaction()) {
                $db->rollBack(); 
            }
            $errorMsg = $e->getMessage(); 
        }
    }

    // ACTION: PIN LOGIN (Quick Auth)
    if (isset($_POST['action']) && $_POST['action'] === 'pin_login') {
        $pin = $_POST['pin'] ?? '';
        $deviceUuid = $_POST['device_uuid'] ?? '';

        try {
            $device = $db->row("SELECT * FROM carer_mobile_devices WHERE device_uuid = ? AND status = 'active'", [$deviceUuid]);
            if (!$device || !password_verify($pin, $device['pin_hash'])) throw new Exception("Incorrect PIN.");

            $user = $db->row("SELECT id, tenant_id, full_name, status FROM users WHERE id = ?", [$device['user_id']]);
            if (!$user || $user['status'] !== 'active') throw new Exception("Account inactive.");

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['device_uuid'] = $deviceUuid;
            
            header("Location: " . CAREAPP_BASE_URL . "dashboard");
            exit;
        } catch (Exception $e) { $errorMsg = $e->getMessage(); }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <title>Carer Login | JIT Field Care</title>
    
    <script type="module" src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.esm.js"></script>
    <script nomodule src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.js"></script>
    <link rel="stylesheet" href="<?= CAREAPP_BASE_URL ?>assets/ionic/css/ionic.bundle.css" />
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        :root { 
            --ion-color-primary: #15c3ba;
            --ion-font-family: 'Urbanist', sans-serif;
            --bg-gradient: linear-gradient(135deg, #15c3ba, #24C6DC);
            --input-bg: #F8FAFC;
        }
        
        body { overscroll-behavior-y: none; background: var(--bg-gradient); margin: 0; padding: 0; }
        ion-content { --background: transparent; }
        
        .page-container { display: flex; flex-direction: column; min-height: 100vh; }
        
        /* TOP HERO SECTION */
        .hero-section { 
            flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; 
            padding: 40px 20px 60px; color: white; text-align: center; position: relative;
        }
        
        .hero-bg-circle { position: absolute; border-radius: 50%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); pointer-events: none; }
        .circle-1 { width: 300px; height: 300px; top: -50px; left: -100px; }
        .circle-2 { width: 200px; height: 200px; bottom: 10px; right: -50px; }

        .hero-section h1 { font-size: 34px; font-weight: 900; letter-spacing: -0.02em; margin: 15px 0 10px 0; }
        .hero-section p { font-size: 15px; font-weight: 600; opacity: 0.9; max-width: 250px; line-height: 1.4; margin: 0; }

        /* OVERLAPPING BOTTOM SHEET */
        .bottom-sheet { 
            background: white; border-radius: 40px 40px 0 0; padding: 40px 30px; 
            box-shadow: 0 -10px 40px rgba(0,0,0,0.1); position: relative; z-index: 10;
            padding-bottom: env(safe-area-inset-bottom, 40px);
        }
        
        .sheet-header { text-align: center; margin-bottom: 30px; }
        .sheet-header h2 { font-size: 28px; font-weight: 900; margin: 0; color: #1E293B; }
        .sheet-header p { font-size: 13px; font-weight: 700; color: #94A3B8; margin-top: 8px; }

        /* MODERN PILL-SHAPED INPUTS */
        ion-item.custom-input {
            --background: var(--input-bg); --border-radius: 100px; --padding-start: 20px; --inner-padding-end: 20px;
            margin-bottom: 20px; border: 2px solid transparent; box-shadow: none; transition: all 0.3s ease;
        }
        ion-item.custom-input.item-has-focus { border-color: var(--ion-color-primary); --background: white; box-shadow: 0 0 0 4px rgba(21, 195, 186, 0.15); }
        
        .input-icon { margin-right: 15px; color: #94A3B8; width: 20px; height: 20px; }
        ion-input { font-weight: 600; font-size: 15px; --padding-top: 18px; --padding-bottom: 18px; color: #1E293B; }

        /* SOLID PILL BUTTON */
        ion-button.btn-auth { 
            --border-radius: 100px; --padding-top: 20px; --padding-bottom: 20px; font-weight: 800; font-size: 16px;
            margin: 10px 0 0 0; --box-shadow: 0 10px 25px rgba(21, 195, 186, 0.3); letter-spacing: 0.5px;
        }

        /* PIN INPUT STYLING */
        .pin-container { display: flex; justify-content: center; gap: 12px; margin: 20px 0; }
        .pin-box { width: 55px; height: 65px; border-radius: 16px; background: var(--input-bg); border: 2px solid transparent; font-size: 28px; font-weight: 900; text-align: center; color: #1E293B; outline: none; transition: all 0.2s; }
        .pin-box:focus { border-color: var(--ion-color-primary); background: white; box-shadow: 0 0 0 4px rgba(21, 195, 186, 0.15); }

        /* AUXILIARY LINKS */
        .login-options { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding: 0 5px; }
        .remember-me { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #64748B; }
        .remember-me input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--ion-color-primary); border-radius: 4px; cursor: pointer; }
        .forgot-link { font-size: 13px; font-weight: 800; color: var(--ion-color-primary); text-decoration: none; }

        .error-banner { background: #FEF2F2; color: #EF4444; padding: 16px 20px; border-radius: 16px; font-size: 13px; font-weight: 800; margin-bottom: 25px; border: 1.5px solid #FECACA; display: flex; align-items: center; gap: 12px; }

        .footer-compliance { text-align: center; font-size: 11px; font-weight: 700; color: #CBD5E1; text-transform: uppercase; display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 25px; }
        .switch-account { display: block; text-align: center; margin-top: 25px; font-size: 14px; font-weight: 800; color: #64748B; cursor: pointer; }

        /* Screen toggles */
        #emailScreen, #pinSetupScreen, #pinLoginScreen { display: none; }
        .active-screen { display: block !important; animation: fadeIn 0.4s ease forwards; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <ion-app>
        <ion-content scroll-y="false">
            <div class="page-container">
                
                <div class="hero-section">
                    <div class="hero-bg-circle circle-1"></div>
                    <div class="hero-bg-circle circle-2"></div>
                    <div style="background: rgba(255,255,255,0.2); padding: 15px; border-radius: 20px; backdrop-filter: blur(10px); margin-bottom: 10px;">
                        <i data-lucide="shield-check" style="width: 40px; height: 40px; color: white;"></i>
                    </div>
                    <h1>JIT Field Care</h1>
                    <p>Log in to stay on top of your clinical tasks and visits.</p>
                </div>

                <div class="bottom-sheet">
                    <?php if ($errorMsg): ?>
                        <div class="error-banner">
                            <i data-lucide="alert-octagon" style="flex-shrink:0;"></i> 
                            <?= htmlspecialchars($errorMsg) ?>
                        </div>
                    <?php endif; ?>

                    <!-- SCREEN 1: EMAIL & PASSWORD LOGIN -->
                    <div id="emailScreen" class="<?= !$pinSetupRequired ? 'active-screen' : '' ?>">
                        <div class="sheet-header">
                            <h2>Login</h2>
                            <p>Enter your professional credentials.</p>
                        </div>
                        
                        <form id="loginForm" method="POST" action="<?= CAREAPP_BASE_URL ?>login">
                            <input type="hidden" name="action" value="mobile_login">
                            <input type="hidden" name="latitude" id="lat1">
                            <input type="hidden" name="longitude" id="lng1">
                            <input type="hidden" name="device_uuid" id="deviceUuid1">
                            <input type="hidden" name="os_type" id="osType1" value="web">
                            
                            <!-- Trigger for explicit PIN Reset -->
                            <input type="hidden" name="force_pin_reset" id="forcePinReset" value="0">

                            <ion-item lines="none" class="custom-input">
                                <i data-lucide="user" class="input-icon"></i>
                                <ion-input type="email" name="email" placeholder="Enter your email address" required></ion-input>
                            </ion-item>

                            <ion-item lines="none" class="custom-input">
                                <i data-lucide="lock" class="input-icon"></i>
                                <ion-input type="password" name="password" placeholder="Enter your password" required></ion-input>
                            </ion-item>

                            <div class="login-options">
                                <label class="remember-me">
                                    <input type="checkbox" id="rememberMe">
                                    Remember Me
                                </label>
                                <a href="<?= CAREAPP_BASE_URL ?>forgot_password" class="forgot-link">Forgot Password?</a>
                            </div>

                            <ion-button expand="block" type="submit" class="btn-auth" id="btnSubmitEmail">
                                Login
                            </ion-button>
                        </form>
                    </div>

                    <!-- SCREEN 2: PIN SETUP -->
                    <div id="pinSetupScreen" class="<?= $pinSetupRequired ? 'active-screen' : '' ?>">
                        <div class="sheet-header">
                            <h2>Secure PIN</h2>
                            <p>Set a 4-digit PIN for quick access later.</p>
                        </div>
                        <form id="setupPinForm" method="POST" action="<?= CAREAPP_BASE_URL ?>login">
                            <input type="hidden" name="action" value="setup_pin">
                            
                            <div class="pin-container">
                                <input type="password" maxlength="1" class="pin-box s-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box s-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box s-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box s-pin" required autocomplete="off">
                            </div>
                            <input type="hidden" name="pin" id="hiddenPin">
                            
                            <p style="text-align: center; font-size: 13px; font-weight: 700; color: #94A3B8; margin-top: 25px;">Confirm PIN</p>
                            <div class="pin-container">
                                <input type="password" maxlength="1" class="pin-box c-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box c-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box c-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box c-pin" required autocomplete="off">
                            </div>
                            <input type="hidden" name="confirm_pin" id="hiddenConfirm">
                            
                            <ion-button expand="block" type="submit" class="btn-auth">
                                Complete Setup
                            </ion-button>
                        </form>
                    </div>

                    <!-- SCREEN 3: QUICK PIN LOGIN -->
                    <div id="pinLoginScreen">
                        <div class="sheet-header">
                            <h2>Welcome Back</h2>
                            <p id="savedName" style="font-size:16px; font-weight:800; color:var(--ion-color-primary);">User</p>
                            <p>Enter your 4-digit PIN to unlock.</p>
                        </div>
                        <form id="quickPinForm" method="POST" action="<?= CAREAPP_BASE_URL ?>login">
                            <input type="hidden" name="action" value="pin_login">
                            <input type="hidden" name="device_uuid" id="deviceUuid2">
                            
                            <div class="pin-container">
                                <input type="password" maxlength="1" class="pin-box l-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box l-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box l-pin" required autocomplete="off">
                                <input type="password" maxlength="1" class="pin-box l-pin" required autocomplete="off">
                            </div>
                            <input type="hidden" name="pin" id="hiddenLoginPin">
                            
                            <ion-button expand="block" type="submit" class="btn-auth">
                                Unlock
                            </ion-button>
                            
                            <div class="switch-account" onclick="switchAccountAndReset()">
                                Forgot PIN or Switch Account?
                            </div>
                        </form>
                    </div>

                    <div class="footer-compliance">
                        <i data-lucide="lock" style="width:12px;"></i> 
                        Protected by JIT Clinical Engine • V1.2
                    </div>
                </div>
                
            </div>
        </ion-content>
    </ion-app>

    <script>
        lucide.createIcons();

        // Device ID Management
        const uuid = localStorage.getItem('jit_device_uuid') || crypto.randomUUID();
        localStorage.setItem('jit_device_uuid', uuid);
        document.getElementById('deviceUuid1').value = uuid;
        document.getElementById('deviceUuid2').value = uuid;

        // View Routing based on PIN state
        if (localStorage.getItem('jit_has_pin') === 'true' && !<?= $pinSetupRequired ? 'true' : 'false' ?>) {
            document.getElementById('pinLoginScreen').classList.add('active-screen');
            document.getElementById('savedName').textContent = localStorage.getItem('jit_saved_name') || 'Care Professional';
        } else if (!<?= $pinSetupRequired ? 'true' : 'false' ?>) {
            document.getElementById('emailScreen').classList.add('active-screen');
        }

        // Logic to Switch Account / Reset PIN
        function switchAccountAndReset() {
            // Remove local flag so it doesn't show PIN screen
            localStorage.removeItem('jit_has_pin'); 
            
            // Set hidden field so PHP knows to bypass auto-restore and force a new PIN setup
            document.getElementById('forcePinReset').value = '1';
            
            document.getElementById('pinLoginScreen').classList.remove('active-screen');
            document.getElementById('emailScreen').classList.add('active-screen');
            
            // Optional: clear email input to fully "switch"
            document.querySelector('ion-input[name="email"]').value = '';
        }

        // Auto-advance PIN inputs
        function setupPin(selector, hiddenId) {
            const inputs = document.querySelectorAll(selector);
            inputs.forEach((input, i) => {
                input.addEventListener('input', (e) => {
                    input.value = input.value.replace(/[^0-9]/g, '');
                    if (input.value && i < 3) inputs[i+1].focus();
                    let pin = ''; inputs.forEach(inp => pin += inp.value);
                    document.getElementById(hiddenId).value = pin;
                });
                input.addEventListener('keydown', (e) => { 
                    if(e.key === 'Backspace' && !input.value && i > 0) {
                        inputs[i-1].focus(); 
                        inputs[i-1].value = '';
                    }
                });
            });
        }
        
        setupPin('.s-pin', 'hiddenPin'); 
        setupPin('.c-pin', 'hiddenConfirm'); 
        setupPin('.l-pin', 'hiddenLoginPin');

        // Form Submit interception for Geolocation
        document.getElementById('loginForm').onsubmit = async (e) => {
            e.preventDefault();
            const loading = document.createElement('ion-loading'); 
            loading.message = 'Verifying Location...'; 
            loading.spinner = 'crescent';
            document.body.appendChild(loading); 
            await loading.present();
            
            if ("geolocation" in navigator) {
                navigator.geolocation.getCurrentPosition((p) => {
                    document.getElementById('lat1').value = p.coords.latitude; 
                    document.getElementById('lng1').value = p.coords.longitude;
                    loading.dismiss(); 
                    e.target.submit();
                }, () => { 
                    loading.dismiss(); 
                    alert("GPS Required for Clinical Compliance."); 
                }, { enableHighAccuracy: true, timeout: 10000 });
            } else {
                loading.dismiss();
                alert("Geolocation is not supported by your device.");
            }
        };

        // Track successful PIN setup to trigger view change next load
        document.getElementById('setupPinForm').onsubmit = () => { 
            localStorage.setItem('jit_has_pin', 'true'); 
        };
        
        // Remember me prepopulate
        window.addEventListener('DOMContentLoaded', () => {
            const savedEmail = localStorage.getItem('jit_saved_email');
            if (savedEmail) {
                document.querySelector('ion-input[name="email"]').value = savedEmail;
                const rememberCb = document.getElementById('rememberMe');
                if (rememberCb) rememberCb.checked = true;
            }
        });

        // Remember me change listener
        const rememberEl = document.getElementById('rememberMe');
        if (rememberEl) {
            rememberEl.addEventListener('change', function(e) {
                if(e.target.checked) {
                    localStorage.setItem('jit_saved_email', document.querySelector('ion-input[name="email"]').value);
                } else {
                    localStorage.removeItem('jit_saved_email');
                }
            });
        }
    </script>
</body>
</html>