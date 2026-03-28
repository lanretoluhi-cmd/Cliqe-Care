<?php
/**
 * Just in Time Group ERP - Carer Mobile App Notifications
 * File Path: /careapp/notifications.php
 * Features: Mobile Alert Inbox, Admin Messages, Mark as Read.
 * Fix: Replaced ionic router dependence with explicit window.location.href onclick events for tabs.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Auth;

if (!Auth::check()) {
    header("Location: " . CAREAPP_BASE_URL . "login");
    exit;
}

$db = db();
$userId = $_SESSION['user_id'];
$tenantId = $_SESSION['tenant_id'];

// ACTION: MARK ALL AS READ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    $db->query("UPDATE healthcare_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", [$userId]);
    header("Location: " . CAREAPP_BASE_URL . "notifications");
    exit;
}

// FETCH ALERTS
$notifications = $db->query("SELECT * FROM healthcare_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50", [$userId]);
$unreadCount = 0;
foreach($notifications as $n) { if((int)$n['is_read'] === 0) $unreadCount++; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Alerts | JIT Field Care</title>
    
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
        ion-content { --background: #F8FAFC; }
        
        .page-header {
            background: linear-gradient(135deg, #24C6DC, #15c3ba);
            padding: calc(env(safe-area-inset-top, 20px) + 20px) 25px 30px;
            color: white;
            border-radius: 0 0 30px 30px;
            box-shadow: 0 10px 20px rgba(21, 195, 186, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 { font-size: 24px; font-weight: 900; margin: 0; letter-spacing: -0.02em; }
        
        .btn-clear { background: rgba(255,255,255,0.2); border: none; padding: 8px 14px; border-radius: 12px; color: white; font-weight: 800; font-size: 11px; cursor: pointer; backdrop-filter: blur(5px); }
        .btn-clear:active { background: rgba(255,255,255,0.3); }

        .notif-list { padding: 25px 20px; display: flex; flex-direction: column; gap: 15px; }

        .notif-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1.5px solid #E2E8F0;
            position: relative;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            transition: 0.2s;
        }
        .notif-card.unread { border-left: 5px solid var(--ion-color-primary); background: #F0FDFA; border-color: #CCFBF1; }
        
        .notif-header { display: flex; align-items: flex-start; gap: 15px; margin-bottom: 10px; }
        
        .n-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: white; }
        .n-icon.system { background: #64748B; }
        .n-icon.clinical_alert { background: #EF4444; }
        .n-icon.incident_alert { background: #F59E0B; }
        
        .n-title { flex: 1; }
        .n-title h3 { font-size: 14px; font-weight: 900; color: #1E293B; margin: 0 0 4px 0; line-height: 1.3; }
        .n-time { font-size: 10px; font-weight: 800; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.05em; }
        
        .n-body { font-size: 13px; font-weight: 600; color: #475569; line-height: 1.5; padding-left: 51px; }

        .empty-state { text-align: center; padding: 80px 30px; }
        .empty-state h3 { font-size: 18px; font-weight: 900; color: #334155; margin-top: 15px; }
        .empty-state p { font-size: 13px; font-weight: 600; color: #94A3B8; margin-top: 5px; }

        /* TAB BAR */
        ion-tab-bar { --background: rgba(255,255,255,0.95); backdrop-filter: blur(15px); border-top: 1px solid #E2E8F0; padding-bottom: env(safe-area-inset-bottom); height: calc(65px + env(safe-area-inset-bottom)); }
        ion-tab-button { --color: #94A3B8; --color-selected: var(--ion-color-primary); font-family: 'Urbanist', sans-serif; font-weight: 800; font-size: 11px; cursor: pointer; }
        ion-icon { font-size: 24px; margin-bottom: 4px; }
    </style>
</head>
<body>

    <ion-app>
        <ion-content>
            <ion-refresher slot="fixed" id="refresher">
                <ion-refresher-content pulling-icon="chevron-down" refreshing-spinner="crescent"></ion-refresher-content>
            </ion-refresher>

            <div class="page-header">
                <div>
                    <h1>Inbox</h1>
                    <p style="font-size:13px; font-weight:700; margin:4px 0 0 0; opacity:0.9;">
                        <?= $unreadCount ?> unread message<?= $unreadCount !== 1 ? 's' : '' ?>
                    </p>
                </div>
                <?php if($unreadCount > 0): ?>
                    <form method="POST" action="<?= CAREAPP_BASE_URL ?>notifications">
                        <input type="hidden" name="action" value="mark_read">
                        <button type="submit" class="btn-clear"><i data-lucide="check-check" style="width:14px; vertical-align:middle; margin-right:4px;"></i> Mark Read</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="notif-list">
                <?php if(empty($notifications)): ?>
                    <div class="empty-state">
                        <div style="width:70px; height:70px; background:white; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto; box-shadow:0 10px 25px rgba(0,0,0,0.05); color:#CBD5E1;">
                            <i data-lucide="bell-off" style="width:32px; height:32px;"></i>
                        </div>
                        <h3>All Caught Up!</h3>
                        <p>You have no new alerts or notifications at this time.</p>
                    </div>
                <?php else: foreach($notifications as $n): 
                    $iconClass = 'system';
                    $iconLucide = 'info';
                    
                    if($n['type'] === 'clinical_alert') { $iconClass = 'clinical_alert'; $iconLucide = 'activity'; }
                    if($n['type'] === 'incident_alert') { $iconClass = 'incident_alert'; $iconLucide = 'alert-triangle'; }
                ?>
                    <div class="notif-card <?= (int)$n['is_read'] === 0 ? 'unread' : '' ?>">
                        <div class="notif-header">
                            <div class="n-icon <?= $iconClass ?>"><i data-lucide="<?= $iconLucide ?>" style="width:20px;"></i></div>
                            <div class="n-title">
                                <h3><?= htmlspecialchars($n['title']) ?></h3>
                                <div class="n-time"><?= date('D j M • H:i', strtotime($n['created_at'])) ?></div>
                            </div>
                        </div>
                        <div class="n-body">
                            <?= nl2br(htmlspecialchars($n['message'])) ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            
            <div style="height: 40px;"></div>
        </ion-content>

        <!-- NATIVE TAB NAVIGATION WITH HARD-ROUTING -->
        <ion-tab-bar slot="bottom">
            <ion-tab-button tab="schedule" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'">
                <ion-icon name="calendar-outline"></ion-icon>
                <ion-label>Today</ion-label>
            </ion-tab-button>

            <ion-tab-button tab="notifications" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>notifications'" selected="true">
                <ion-icon name="notifications-outline"></ion-icon>
                <ion-label>Alerts</ion-label>
            </ion-tab-button>

            <ion-tab-button tab="settings" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>settings'">
                <ion-icon name="person-circle-outline"></ion-icon>
                <ion-label>Profile</ion-label>
            </ion-tab-button>
        </ion-tab-bar>
    </ion-app>

    <script>
        lucide.createIcons();

        // Pull to Refresh Handler
        const refresher = document.getElementById('refresher');
        if (refresher) {
            refresher.addEventListener('ionRefresh', () => {
                setTimeout(() => {
                    window.location.reload();
                    refresher.complete();
                }, 800);
            });
        }
    </script>
</body>
</html>