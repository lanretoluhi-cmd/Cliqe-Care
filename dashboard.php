<?php
/**
 * Just in Time Group ERP - Carer Mobile App Dashboard
 * File Path: /careapp/dashboard.php
 * Features: Daily Itinerary, Offline Status, Live Announcements, Live Visit Countdowns.
 * Update: Added standard footer navigation including Clients and Notice Board.
 */

declare(strict_types=1);

// Initialize Standalone Mobile App Engine
require_once __DIR__ . '/bootstrap.php';

use App\Auth;

$db = db();

// 1. MOBILE AUTH GUARD
if (!Auth::check()) {
    header("Location: " . CAREAPP_BASE_URL . "login");
    exit;
}

$userId = $_SESSION['user_id'];
$tenantId = $_SESSION['tenant_id'];
$deviceUuid = $_SESSION['device_uuid'] ?? null;
$today = date('Y-m-d');

// 2. SECURITY CHECK: SINGLE DEVICE ENFORCEMENT
if ($deviceUuid) {
    $deviceCheck = $db->row("SELECT status FROM carer_mobile_devices WHERE device_uuid = ? AND user_id = ?", [$deviceUuid, $userId]);
    if (!$deviceCheck || $deviceCheck['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        header("Location: " . CAREAPP_BASE_URL . "login?error=Session revoked. Accessed from another device.");
        exit;
    }
}

// 3. AJAX HANDLER: MARK ANNOUNCEMENT AS SEEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_announcement_seen') {
    try {
        $annId = $_POST['announcement_id'];
        $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
        $db->query("INSERT IGNORE INTO healthcare_announcement_seen (id, announcement_id, user_id) VALUES (?, ?, ?)", [$id, $annId, $userId]);
        echo json_encode(['success' => true]);
        exit;
    } catch(Exception $e) {
        echo json_encode(['success' => false]);
        exit;
    }
}

// 4. FETCH CARER INFO & UNREAD ALERTS (Inbox)
$carer = $db->row("SELECT full_name FROM users WHERE id = ?", [$userId]);
$firstName = explode(' ', trim($carer['full_name'] ?? 'Carer'))[0];
$unreadNotifs = (int)($db->row("SELECT COUNT(*) as count FROM healthcare_notifications WHERE user_id = ? AND is_read = 0", [$userId])['count'] ?? 0);

// 5. FETCH ANNOUNCEMENTS (Broadcasts)
$announcements = [];
try {
    $announcements = $db->query("
        SELECT a.*, 
               (SELECT COUNT(*) FROM healthcare_announcement_seen s WHERE s.announcement_id = a.id AND s.user_id = ?) as has_seen
        FROM healthcare_announcements a
        WHERE a.tenant_id = ? 
        AND a.status = 'published' 
        AND a.target_audience IN ('all', 'carers')
        ORDER BY a.created_at DESC 
        LIMIT 5
    ", [$userId, $tenantId]);
} catch (Exception $e) { /* Ignore if tables are missing */ }

// 6. FETCH TODAY'S SHIFTS (Added ri.roster_date for Live Countdowns)
$shifts = $db->query("
    SELECT ri.id as roster_id, ri.roster_date, ri.start_time, ri.end_time, ri.duration_minutes, ri.status as roster_status,
           t.task_type, t.description,
           c.id as client_id, c.first_name, c.last_name, c.primary_address, c.postcode,
           a.status as attendance_status, a.clock_in, a.clock_out
    FROM healthcare_roster_items ri
    JOIN healthcare_client_tasks t ON ri.task_id = t.id
    JOIN healthcare_clients c ON t.client_id = c.id
    LEFT JOIN attendance a ON ri.id = a.roster_item_id
    WHERE ri.carer_id = ? AND ri.roster_date = ? AND ri.tenant_id = ?
    ORDER BY ri.start_time ASC
", [$userId, $today, $tenantId]);

// Calculate progress metrics
$totalShifts = count($shifts);
$completedShifts = 0;
foreach ($shifts as $s) {
    if (($s['attendance_status'] ?? '') === 'completed' || $s['roster_status'] === 'completed') {
        $completedShifts++;
    }
}
$progressPct = $totalShifts > 0 ? ($completedShifts / $totalShifts) * 100 : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Dashboard | JIT Field Care</title>
    
    <!-- Ionic Framework -->
    <script type="module" src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.esm.js"></script>
    <script nomodule src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.js"></script>
    <link rel="stylesheet" href="<?= CAREAPP_BASE_URL ?>assets/ionic/css/ionic.bundle.css" />

    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        :root { 
            --ion-color-primary: #15c3ba;
            --ion-color-primary-rgb: 21,195,186;
            --ion-font-family: 'Urbanist', sans-serif;
            --card-radius: 24px;
        }

        body { overscroll-behavior-y: none; }
        ion-content { --background: #F8FAFC; }
        
        /* HEADER */
        .app-header {
            background: linear-gradient(135deg, #15c3ba, #24C6DC);
            padding: env(safe-area-inset-top, 20px) 25px 40px 25px;
            color: white; border-radius: 0 0 40px 40px;
            box-shadow: 0 10px 30px rgba(21, 195, 186, 0.2);
            margin-bottom: -30px; position: relative; z-index: 10;
        }
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .greeting h1 { font-size: 26px; font-weight: 900; letter-spacing: -0.03em; margin: 0; }
        .greeting p { font-size: 14px; font-weight: 600; opacity: 0.9; margin: 4px 0 0 0; }
        .avatar-circle { width: 48px; height: 48px; background: rgba(255,255,255,0.25); border: 2px solid rgba(255,255,255,0.6); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 900; backdrop-filter: blur(10px); }
        .network-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(0,0,0,0.15); padding: 6px 14px; border-radius: 100px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        .network-badge.offline { background: #EF4444; color: white; }

        /* PROGRESS WIDGET */
        .progress-card { background: white; border-radius: var(--card-radius); padding: 25px; box-shadow: 0 12px 30px rgba(0,0,0,0.04); margin: 0 20px 30px 20px; position: relative; z-index: 20; display: flex; justify-content: space-between; align-items: center; border: 1px solid #E2E8F0; }
        .progress-text h3 { font-size: 16px; font-weight: 900; color: #1E293B; margin: 0 0 4px 0; }
        .progress-text p { font-size: 12px; font-weight: 700; color: #94A3B8; margin: 0; text-transform: uppercase; letter-spacing: 0.05em; }
        .progress-circle { width: 60px; height: 60px; border-radius: 50%; background: conic-gradient(var(--ion-color-primary) <?= $progressPct ?>%, #F1F5F9 0); display: flex; align-items: center; justify-content: center; }
        .progress-inner { width: 48px; height: 48px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 900; color: var(--ion-color-primary); }

        /* ANNOUNCEMENTS FEED */
        .section-title { font-size: 18px; font-weight: 900; color: #334155; margin: 0 0 15px 0; padding: 0 25px; }
        .announcements-scroll { display: flex; gap: 15px; overflow-x: auto; padding: 0 20px 25px 20px; scrollbar-width: none; }
        .announcements-scroll::-webkit-scrollbar { display: none; }
        .ann-card { min-width: 260px; max-width: 280px; background: white; border-radius: 20px; padding: 15px; border: 1.5px solid #E2E8F0; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); position: relative; cursor: pointer; transition: 0.2s; }
        .ann-card:active { transform: scale(0.97); }
        .ann-card.unread { border-color: var(--ion-color-primary); background: #F0FDFA; }
        .ann-icon { width: 40px; height: 40px; border-radius: 12px; background: #F1F5F9; color: var(--ion-color-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ann-content h4 { font-size: 13px; font-weight: 800; color: #1E293B; margin: 0 0 4px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
        .ann-content p { font-size: 11px; font-weight: 700; color: #94A3B8; margin: 0; }
        .unread-dot { position: absolute; top: 15px; right: 15px; width: 8px; height: 8px; background: #EF4444; border-radius: 50%; box-shadow: 0 0 0 2px white; }

        /* ITINERARY FEED */
        .feed-container { padding: 0 20px 20px 20px; }
        .visit-card { background: white; border-radius: var(--card-radius); padding: 25px; margin-bottom: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); border: 1.5px solid #E2E8F0; text-decoration: none; display: block; transition: all 0.2s ease; }
        .visit-card:active { transform: scale(0.97); background: #FAFBFC; }
        
        .v-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
        .v-time { font-size: 20px; font-weight: 900; color: #1E293B; letter-spacing: -0.02em; }
        .v-time span { font-size: 12px; font-weight: 700; color: #94A3B8; margin-left: 6px; }
        
        .v-status { font-size: 10px; font-weight: 900; text-transform: uppercase; padding: 5px 12px; border-radius: 100px; letter-spacing: 0.04em; }
        .v-status.planned { background: #F1F5F9; color: #64748B; }
        .v-status.arrived { background: #FFF7ED; color: #EA580C; border: 1px solid #FDBA74; }
        .v-status.completed { background: #E9FBF0; color: #10B981; }

        /* LIVE COUNTDOWN STYLES */
        .v-countdown { font-size: 11px; font-weight: 800; padding: 4px 8px; border-radius: 6px; display: inline-block; margin-top: 6px; }
        .v-countdown.upcoming { background: #F1F5F9; color: #64748B; }
        .v-countdown.live { background: #EEF2FF; color: #3B82F6; animation: pulse-border 2s infinite; }
        .v-countdown.overdue { background: #FEF2F2; color: #EF4444; border: 1px solid #FECACA; }
        .v-countdown.finished { background: #F8FAFC; color: #CBD5E1; }
        
        @keyframes pulse-border { 
            0% { box-shadow: 0 0 0 0 rgba(59,130,246,0.3); } 
            70% { box-shadow: 0 0 0 5px rgba(59,130,246,0); } 
            100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); } 
        }

        .v-client { font-size: 18px; font-weight: 800; color: #1E293B; margin-bottom: 6px; }
        .v-address { font-size: 13px; font-weight: 600; color: #64748B; display: flex; align-items: flex-start; gap: 8px; line-height: 1.5; margin-bottom: 20px; }
        .v-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #E2E8F0; padding-top: 18px; }
        .v-type { font-size: 12px; font-weight: 800; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.02em; }
        .v-type.med { color: #DB2777; }
        .v-type.task { color: var(--ion-color-primary); }
        .v-action { background: #F1F5F9; color: var(--ion-color-primary); padding: 10px 20px; border-radius: 100px; font-size: 13px; font-weight: 800; }

        /* MODAL OVERLAY */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .modal-content { background: white; border-radius: 24px; padding: 30px; width: 100%; max-width: 400px; max-height: 80vh; overflow-y: auto; transform: translateY(20px); transition: transform 0.3s; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .modal-overlay.active .modal-content { transform: translateY(0); }
        .modal-title { font-size: 18px; font-weight: 900; color: #1E293B; margin: 0 0 5px 0; }
        .modal-date { font-size: 12px; font-weight: 700; color: #94A3B8; margin: 0 0 20px 0; }
        .modal-body { font-size: 14px; font-weight: 600; color: #475569; line-height: 1.6; white-space: pre-wrap; margin-bottom: 25px; }
        .btn-modal-close { width: 100%; background: #F1F5F9; color: #475569; padding: 16px; border-radius: 14px; border: none; font-size: 14px; font-weight: 800; cursor: pointer; }

        /* TAB BAR */
        ion-tab-bar { --background: rgba(255,255,255,0.95); backdrop-filter: blur(15px); border-top: 1px solid #E2E8F0; padding-bottom: env(safe-area-inset-bottom); height: calc(65px + env(safe-area-inset-bottom)); }
        ion-tab-button { --color: #94A3B8; --color-selected: var(--ion-color-primary); font-family: 'Urbanist', sans-serif; font-weight: 800; font-size: 11px; cursor: pointer; }
        ion-icon { font-size: 24px; margin-bottom: 4px; }
    </style>
</head>
<body>

    <!-- ANNOUNCEMENT READING MODAL -->
    <div id="announcementModal" class="modal-overlay" onclick="if(event.target === this) closeAnnouncementModal()">
        <div class="modal-content">
            <h2 class="modal-title" id="modalAnnTitle">Title</h2>
            <p class="modal-date" id="modalAnnDate">Date</p>
            <div class="modal-body" id="modalAnnBody">Content goes here...</div>
            <button class="btn-modal-close" onclick="closeAnnouncementModal()">Dismiss</button>
        </div>
    </div>

    <ion-app>
        <ion-content id="mainContent">
            <ion-refresher slot="fixed" id="refresher">
                <ion-refresher-content pulling-icon="chevron-down" refreshing-spinner="crescent"></ion-refresher-content>
            </ion-refresher>

            <!-- APP HEADER -->
            <div class="app-header">
                <div class="header-top">
                    <div class="greeting">
                        <p><?= date('l, d F') ?></p>
                        <h1>Hi, <?= htmlspecialchars($firstName) ?></h1>
                    </div>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <a href="<?= CAREAPP_BASE_URL ?>notifications" style="position:relative; display:flex; align-items:center; justify-content:center; width:44px; height:44px; background:rgba(255,255,255,0.2); border-radius:50%; border:2px solid rgba(255,255,255,0.5); backdrop-filter:blur(5px); color:white;">
                            <i data-lucide="bell" style="width:20px; height:20px;"></i>
                            <?php if ($unreadNotifs > 0): ?>
                                <span style="position:absolute; top:-2px; right:-2px; background:#EF4444; color:white; width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:900; border:2px solid #15c3ba; box-sizing:content-box;">
                                    <?= $unreadNotifs > 9 ? '9+' : $unreadNotifs ?>
                               </span>
                            <?php endif; ?>
                        </a>
                        <div class="avatar-circle">
                            <?= strtoupper(substr($firstName, 0, 1)) ?>
                        </div>
                    </div>
                </div>
                <div class="network-badge" id="networkStatus">
                    <i data-lucide="wifi" style="width:14px;"></i> Online & Synced
                </div>
            </div>

            <!-- PROGRESS TRACKER -->
            <div class="progress-card">
                <div class="progress-text">
                    <h3>Day Progress</h3>
                    <p><?= $completedShifts ?> of <?= $totalShifts ?> visits logged</p>
                </div>
                <div class="progress-circle">
                    <div class="progress-inner"><?= round($progressPct) ?>%</div>
                </div>
            </div>

            <!-- BROADCASTS & ANNOUNCEMENTS -->
            <?php if (!empty($announcements)): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; padding: 0 25px; margin: 0 0 15px 0;">
                    <h2 class="section-title" style="padding:0; margin:0;">Notice Board</h2>
                    <a href="<?= CAREAPP_BASE_URL ?>notice_board" style="font-size:12px; font-weight:800; color:var(--ion-color-primary); text-decoration:none;">View All</a>
                </div>
                <div class="announcements-scroll">
                    <?php foreach($announcements as $ann): ?>
                        <div class="ann-card <?= !$ann['has_seen'] ? 'unread' : '' ?>" id="card-<?= $ann['id'] ?>" onclick="openAnnouncement('<?= $ann['id'] ?>')">
                            <div class="ann-icon"><i data-lucide="megaphone" style="width:18px;"></i></div>
                            <div class="ann-content">
                                <h4><?= htmlspecialchars($ann['title']) ?></h4>
                                <p><?= date('D j M', strtotime($ann['created_at'])) ?></p>
                            </div>
                            <?php if(!$ann['has_seen']): ?>
                                <div class="unread-dot" id="unread-dot-<?= $ann['id'] ?>"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- ITINERARY FEED -->
            <div class="feed-container">
                <h2 class="section-title" style="padding:0;">Visit Schedule</h2>

                <?php if (empty($shifts)): ?>
                    <div style="text-align: center; padding: 60px 30px; background: white; border-radius: var(--card-radius); border: 2px dashed #E2E8F0;">
                        <div style="width:60px; height:60px; background:#F0FDF4; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; color:#10B981;">
                            <i data-lucide="check-circle-2" style="width:32px; height:32px;"></i>
                        </div>
                        <h3 style="font-size: 18px; font-weight: 900; color: #334155; margin: 0 0 6px 0;">All caught up!</h3>
                        <p style="font-size: 14px; font-weight: 600; color: #94A3B8; margin: 0; line-height:1.4;">There are no more visits scheduled for you today.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($shifts as $s): 
                        $status = $s['attendance_status'] ?? $s['roster_status'] ?? 'planned';
                        if ($status === 'in_progress') $status = 'arrived';
                        $isMed = $s['task_type'] === 'Medication';
                        
                        // Setup Data attributes for Live JS Countdown Engine
                        $startIso = $s['roster_date'] . 'T' . $s['start_time'];
                        $endIso = $s['roster_date'] . 'T' . $s['end_time'];
                    ?>
                        <a href="<?= CAREAPP_BASE_URL ?>visit?id=<?= $s['roster_id'] ?>" class="visit-card">
                            <div class="v-header">
                                <div>
                                    <div class="v-time">
                                        <?= substr($s['start_time'], 0, 5) ?> 
                                        <span><?= $s['duration_minutes'] ?>m</span>
                                    </div>
                                    <!-- LIVE COUNTDOWN TARGET -->
                                    <div class="v-countdown upcoming" 
                                         data-start="<?= $startIso ?>" 
                                         data-end="<?= $endIso ?>" 
                                         data-status="<?= $status ?>">
                                        <span class="cd-text">Evaluating time...</span>
                                    </div>
                                </div>
                                <div class="v-status <?= $status ?>"><?= $status ?></div>
                            </div>
                            
                            <div class="v-client">
                                <?= strtoupper(htmlspecialchars($s['first_name'] . ' ' . $s['last_name'])) ?>
                            </div>
                            
                            <div class="v-address">
                                <i data-lucide="map-pin" style="width:16px; flex-shrink:0; color:var(--ion-color-primary);"></i>
                                <span><?= htmlspecialchars(explode("\n", $s['primary_address'] ?? '')[0]) ?><br><strong><?= htmlspecialchars($s['postcode'] ?? '') ?></strong></span>
                            </div>

                            <div class="v-footer">
                                <div class="v-type <?= $isMed ? 'med' : 'task' ?>">
                                    <i data-lucide="<?= $isMed ? 'pill' : 'clipboard-list' ?>" style="width:16px;"></i>
                                    <?= $s['task_type'] ?> Call
                                </div>
                                <?php if ($status === 'planned'): ?>
                                    <div class="v-action">Start</div>
                                <?php elseif ($status === 'arrived'): ?>
                                    <div class="v-action" style="background:#FFF7ED; color:#EA580C;">Resume</div>
                                <?php else: ?>
                                    <div class="v-action" style="background:#E9FBF0; color:#10B981; padding: 10px 15px;"><i data-lucide="check" style="width:16px;"></i></div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div style="height: 40px;"></div>
        </ion-content>

        <!-- NATIVE TAB NAVIGATION WITH HARD-ROUTING -->
        <ion-tab-bar slot="bottom">
            <ion-tab-button tab="schedule" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'" selected="true">
                <ion-icon name="calendar-outline"></ion-icon>
                <ion-label>Today</ion-label>
            </ion-tab-button>
            
            <ion-tab-button tab="clients" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>clients'">
                <ion-icon name="people-outline"></ion-icon>
                <ion-label>Clients</ion-label>
            </ion-tab-button>

            <ion-tab-button tab="notice_board" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>notice_board'">
                <ion-icon name="megaphone-outline"></ion-icon>
                <ion-label>Notice Board</ion-label>
            </ion-tab-button>

            <ion-tab-button tab="notifications" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>notifications'">
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

        // ----------------------------------------------------
        // LIVE COUNTDOWN ENGINE
        // ----------------------------------------------------
        function updateCountdowns() {
            const now = new Date();
            
            document.querySelectorAll('.v-countdown').forEach(el => {
                const start = new Date(el.dataset.start);
                const end = new Date(el.dataset.end);
                const status = el.dataset.status;
                const textEl = el.querySelector('.cd-text');

                // If already marked as completed by the system, don't tick
                if (status === 'completed') {
                    textEl.innerText = 'Visit Complete';
                    el.className = 'v-countdown finished';
                    return;
                }

                // Calculate time differences in milliseconds
                const diffStart = start - now;
                const diffEnd = end - now;

                if (diffStart > 0) {
                    // FUTURE: Time remaining until shift starts
                    const totalMins = Math.ceil(diffStart / 60000);
                    const hrs = Math.floor(totalMins / 60);
                    const mins = totalMins % 60;
                    
                    textEl.innerText = hrs > 0 ? `Starts in ${hrs}h ${mins}m` : `Starts in ${mins}m`;
                    el.className = 'v-countdown upcoming';
                    
                } else if (diffStart <= 0 && diffEnd > 0) {
                    // LIVE: The current time is inside the scheduled shift window
                    const totalSecs = Math.floor(diffEnd / 1000);
                    const mins = Math.floor(totalSecs / 60);
                    const secs = totalSecs % 60;
                    
                    textEl.innerText = `Live: ${mins}m ${secs}s left`;
                    el.className = 'v-countdown live';
                    
                } else {
                    // PAST: Shift has ended, but task is not 'completed' yet
                    const overMins = Math.floor(Math.abs(diffEnd) / 60000);
                    
                    if (status === 'arrived') {
                        // They are clocked in but past the allocated time
                        textEl.innerText = `Overtime (${overMins}m)`;
                        el.className = 'v-countdown live'; 
                    } else {
                        // They haven't even clocked in yet
                        textEl.innerText = `Late / Overdue (${overMins}m)`;
                        el.className = 'v-countdown overdue';
                    }
                }
            });
        }

        // Initialize and tick every second
        updateCountdowns();
        setInterval(updateCountdowns, 1000);


        // ----------------------------------------------------
        // ANNOUNCEMENT MODAL LOGIC
        // ----------------------------------------------------
        const annData = <?= json_encode($announcements) ?>;

        function openAnnouncement(id) {
            const ann = annData.find(a => a.id === id);
            if (!ann) return;
            
            document.getElementById('modalAnnTitle').textContent = ann.title;
            const d = new Date(ann.created_at);
            document.getElementById('modalAnnDate').textContent = d.toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            document.getElementById('modalAnnBody').textContent = ann.content;
            
            document.getElementById('announcementModal').classList.add('active');

            if (parseInt(ann.has_seen) === 0) {
                const fd = new FormData();
                fd.append('action', 'mark_announcement_seen');
                fd.append('announcement_id', id);
                
                fetch('<?= CAREAPP_BASE_URL ?>dashboard', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const dot = document.getElementById('unread-dot-' + id);
                        if (dot) dot.remove();
                        document.getElementById('card-' + id).classList.remove('unread');
                        ann.has_seen = 1; 
                    }
                });
            }
        }

        function closeAnnouncementModal() {
            document.getElementById('announcementModal').classList.remove('active');
        }

        // Pull to Refresh Handler
        const refresher = document.getElementById('refresher');
        refresher.addEventListener('ionRefresh', () => {
            setTimeout(() => {
                window.location.reload();
                refresher.complete();
            }, 800);
        });

        // Online/Offline Status Management
        function updateNetworkStatus() {
            const badge = document.getElementById('networkStatus');
            if (navigator.onLine) {
                badge.className = 'network-badge';
                badge.innerHTML = '<i data-lucide="wifi" style="width:14px;"></i> Online & Synced';
            } else {
                badge.className = 'network-badge offline';
                badge.innerHTML = '<i data-lucide="wifi-off" style="width:14px;"></i> Working Offline';
            }
            lucide.createIcons();
        }

        window.addEventListener('online', updateNetworkStatus);
        window.addEventListener('offline', updateNetworkStatus);
        updateNetworkStatus();
    </script>
</body>
</html>