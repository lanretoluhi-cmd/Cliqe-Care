<?php
/**
 * Just in Time Group ERP - Carer Mobile App Profile
 * File Path: /careapp/settings.php
 * Features: Staff Profile, App Versioning, and Secure Logout.
 * Fix: Replaced ionic router dependence with explicit window.location.href onclick events for tabs.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../app/core/bootstrap.php';

use App\Auth;

if (!Auth::check()) {
    header("Location: " . CAREAPP_BASE_URL . "login");
    exit;
}

$db = db();
$userId = $_SESSION['user_id'];

$user = $db->row("SELECT full_name, email, job_title, transport_mode FROM users WHERE id = ?", [$userId]);
$firstName = explode(' ', trim($user['full_name'] ?? 'Carer'))[0];
$initial = strtoupper(substr($firstName, 0, 1));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#F8FAFC">
    <title>Profile | JIT Field Care</title>
    
    <script type="module" src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.esm.js"></script>
    <script nomodule src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.js"></script>
    <link rel="stylesheet" href="<?= CAREAPP_BASE_URL ?>assets/ionic/css/ionic.bundle.css" />

    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        :root { 
            --ion-color-primary: #15c3ba;
            --ion-font-family: 'Urbanist', sans-serif;
            --ion-background-color: #F8FAFC;
        }

        body { overscroll-behavior-y: none; }
        
        .profile-header {
            padding: calc(env(safe-area-inset-top, 20px) + 30px) 25px 30px;
            background: white;
            text-align: center;
            border-bottom: 1px solid #E2E8F0;
        }

        .avatar-lg {
            width: 80px; height: 80px; margin: 0 auto 15px; border-radius: 24px;
            background: #EEF2FF; color: var(--ion-color-primary); font-size: 32px; font-weight: 900;
            display: flex; align-items: center; justify-content: center;
        }

        .profile-header h1 { font-size: 24px; font-weight: 900; color: #1E293B; margin: 0 0 5px 0; letter-spacing: -0.02em; }
        .profile-header p { font-size: 13px; font-weight: 700; color: #64748B; margin: 0; }
        
        .transport-badge {
            display: inline-flex; align-items: center; gap: 6px; margin-top: 15px;
            background: #F8FAFC; border: 1px solid #E2E8F0; padding: 6px 14px; border-radius: 100px;
            font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase;
        }

        .settings-list { padding: 20px; }
        
        ion-item.setting-item {
            --background: white; --border-radius: 16px; --padding-start: 16px; --inner-padding-end: 16px;
            margin-bottom: 12px; font-weight: 700; font-size: 14px; color: #334155;
            box-shadow: 0 4px 10px rgba(0,0,0,0.02);
        }
        
        .item-icon { margin-right: 15px; color: #94A3B8; width: 20px; height: 20px; }

        .btn-logout {
            margin: 30px 20px; background: #FEF2F2; color: #EF4444; border: 2px solid #FECACA;
            border-radius: 16px; padding: 18px; font-size: 15px; font-weight: 900;
            display: flex; justify-content: center; align-items: center; gap: 10px;
            cursor: pointer; transition: 0.2s; text-decoration: none;
        }
        .btn-logout:active { transform: scale(0.98); }

        .app-version { text-align: center; font-size: 11px; font-weight: 700; color: #CBD5E1; margin-top: 10px; }

        /* TAB BAR */
        ion-tab-bar { --background: rgba(255,255,255,0.95); backdrop-filter: blur(15px); border-top: 1px solid #E2E8F0; padding-bottom: env(safe-area-inset-bottom); height: calc(65px + env(safe-area-inset-bottom)); }
        ion-tab-button { --color: #94A3B8; --color-selected: var(--ion-color-primary); font-family: 'Urbanist', sans-serif; font-weight: 800; font-size: 11px; cursor: pointer; }
        ion-icon { font-size: 24px; margin-bottom: 4px; }
    </style>
</head>
<body>

    <ion-app>
        <ion-content>
            <div class="profile-header">
                <div class="avatar-lg"><?= $initial ?></div>
                <h1><?= htmlspecialchars($user['full_name']) ?></h1>
                <p><?= htmlspecialchars($user['email']) ?></p>
                <div class="transport-badge">
                    <i data-lucide="<?= ($user['transport_mode'] === 'car') ? 'car' : 'navigation' ?>" style="width:14px;"></i>
                    <?= htmlspecialchars($user['job_title'] ?: 'Support Worker') ?>
                </div>
            </div>

            <div class="settings-list">
                <ion-item lines="none" class="setting-item" detail="true" button>
                    <i data-lucide="user-cog" class="item-icon"></i>
                    <ion-label>Personal Details</ion-label>
                </ion-item>
                
                <ion-item lines="none" class="setting-item" detail="true" button>
                    <i data-lucide="shield-check" class="item-icon"></i>
                    <ion-label>Privacy & Security</ion-label>
                </ion-item>

                <ion-item lines="none" class="setting-item" detail="true" button>
                    <i data-lucide="help-circle" class="item-icon"></i>
                    <ion-label>App Support & Help</ion-label>
                </ion-item>
            </div>

            <!-- MANUAL SESSION DESTRUCTION LINK -->
            <a href="<?= CAREAPP_BASE_URL ?>logout" class="btn-logout">
                <i data-lucide="log-out" style="width:20px;"></i> Secure Log Out
            </a>
            
            <p class="app-version">JIT Field Care v1.1.0 • Connected</p>
            
            <div style="height: 40px;"></div>
        </ion-content>

        <!-- NATIVE TAB NAVIGATION WITH HARD-ROUTING -->
        <ion-tab-bar slot="bottom">
            <ion-tab-button tab="schedule" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'">
                <ion-icon name="calendar-outline"></ion-icon>
                <ion-label>Today</ion-label>
            </ion-tab-button>

            <ion-tab-button tab="notifications" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>notifications'">
                <ion-icon name="notifications-outline"></ion-icon>
                <ion-label>Alerts</ion-label>
            </ion-tab-button>

            <ion-tab-button tab="settings" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>settings'" selected="true">
                <ion-icon name="person-circle-outline"></ion-icon>
                <ion-label>Profile</ion-label>
            </ion-tab-button>
        </ion-tab-bar>
    </ion-app>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>