<?php
/**
 * Just in Time Group ERP - Carer Mobile App Incidents & Body Map
 * File Path: /careapp/incidents.php
 * Features: View assigned clients, log general incidents, and log interactive body map injuries with photographic proof.
 * Updates: Fixed header overlap, added native hardware back button support, and implemented form preloaders.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Auth;

// 1. MOBILE AUTH GUARD
if (!Auth::check()) {
    header("Location: " . CAREAPP_BASE_URL . "login");
    exit;
}

$db = db();
$userId = $_SESSION['user_id'];
$tenantId = $_SESSION['tenant_id'];

$clientId = $_GET['id'] ?? null;
$errorMsg = null;
$successMsg = null;

// =======================================================================================
// HANDLE POST ACTIONS
// =======================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION 1: LOG GENERAL INCIDENT
    if ($_POST['action'] === 'log_incident') {
        try {
            $incidentId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            
            $db->query("START TRANSACTION");

            $db->query("INSERT INTO healthcare_client_incidents (
                id, tenant_id, client_id, incident_date, incident_time, incident_type, 
                severity, description, action_taken, status, reported_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?)", [
                $incidentId, $tenantId, $clientId, 
                $_POST['incident_date'], $_POST['incident_time'], $_POST['incident_type'], 
                $_POST['severity'], trim($_POST['description']), trim($_POST['action_taken']), 
                $userId
            ]);

            audit('healthcare.incident_logged', 'healthcare_client_incidents', $incidentId, [], ['client_id' => $clientId, 'device' => 'mobile']);
            
            $db->query("COMMIT");
            $successMsg = "Incident logged successfully. The office has been notified.";
        } catch (Exception $e) {
            $db->query("ROLLBACK");
            $errorMsg = "Failed to log incident: " . $e->getMessage();
        }
    }

    // ACTION 2: LOG BODY MAP INJURY WITH PHOTO
    if ($_POST['action'] === 'log_body_map') {
        try {
            $pinId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $logId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            
            // Handle File Upload
            $imagePath = null;
            if (isset($_FILES['evidence_image']) && $_FILES['evidence_image']['error'] === UPLOAD_ERR_OK) {
                // Determine absolute path to the main ERP public uploads folder
                $uploadDir = realpath(__DIR__ . '/../public') . "/uploads/bodymaps/$tenantId/";
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                
                $ext = strtolower(pathinfo($_FILES['evidence_image']['name'], PATHINFO_EXTENSION));
                $fileName = "wound_" . $clientId . "_mob_" . time() . "." . $ext;
                
                if (move_uploaded_file($_FILES['evidence_image']['tmp_name'], $uploadDir . $fileName)) {
                    $imagePath = "uploads/bodymaps/$tenantId/$fileName";
                }
            }

            $db->query("START TRANSACTION");

            // Save Physical Pin Location
            $db->query("INSERT INTO healthcare_body_map_pins (id, tenant_id, client_id, view_side, pos_x, pos_y) 
                        VALUES (?, ?, ?, ?, ?, ?)", [
                $pinId, $tenantId, $clientId, $_POST['view_side'], $_POST['pos_x'], $_POST['pos_y']
            ]);

            // Save Initial Clinical Observation
            $db->query("INSERT INTO healthcare_body_map_logs (id, tenant_id, pin_id, mark_type, severity, size_info, observation_date, notes, image_path, recorded_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $logId, $tenantId, $pinId, $_POST['mark_type'], $_POST['severity'], 
                trim($_POST['size_info'] ?? ''), $_POST['observation_date'], trim($_POST['notes']), $imagePath, $userId
            ]);

            audit('healthcare.bodymap_pin_created', 'healthcare_body_map_pins', $pinId, [], ['type' => $_POST['mark_type'], 'device' => 'mobile']);
            
            $db->query("COMMIT");
            $successMsg = "Body map injury and proof uploaded securely.";
        } catch (Exception $e) {
            $db->query("ROLLBACK");
            $errorMsg = "Failed to add body mark: " . $e->getMessage();
        }
    }
}

// =======================================================================================
// FETCH DATA
// =======================================================================================

if ($clientId) {
    // SECURITY CHECK
    $isAssigned = $db->row("
        SELECT 1 FROM healthcare_roster_items ri
        JOIN healthcare_client_tasks t ON ri.task_id = t.id
        WHERE t.client_id = ? AND ri.carer_id = ? AND ri.tenant_id = ?
        LIMIT 1
    ", [$clientId, $userId, $tenantId]);

    if (!$isAssigned) {
        die("Security Alert: You are not authorized to log incidents for this service user.");
    }

    $client = $db->row("SELECT first_name, last_name, profile_photo FROM healthcare_clients WHERE id = ?", [$clientId]);
    
    // Fetch Recent Incidents
    $recentIncidents = $db->query("
        SELECT incident_date, incident_type, severity, status 
        FROM healthcare_client_incidents 
        WHERE client_id = ? AND tenant_id = ? 
        ORDER BY incident_date DESC, incident_time DESC LIMIT 5
    ", [$clientId, $tenantId]);

    // Fetch Recent Body Marks
    $recentMarks = $db->query("
        SELECT p.view_side, l.mark_type, l.severity, l.observation_date 
        FROM healthcare_body_map_pins p
        JOIN healthcare_body_map_logs l ON p.id = l.pin_id
        WHERE p.client_id = ? AND p.tenant_id = ?
        ORDER BY l.created_at DESC LIMIT 5
    ", [$clientId, $tenantId]);

} else {
    // FETCH ASSIGNED CLIENTS
    $myClients = $db->query("
        SELECT DISTINCT c.id, c.first_name, c.last_name, c.profile_photo, c.primary_address,
        TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) AS age
        FROM healthcare_clients c
        JOIN healthcare_client_tasks t ON c.id = t.client_id
        JOIN healthcare_roster_items ri ON t.id = ri.task_id
        WHERE ri.carer_id = ? AND c.tenant_id = ? AND c.status = 'active'
        ORDER BY c.first_name ASC
    ", [$userId, $tenantId]);
}

$incidentTypes = ['Fall / Slip / Trip', 'Medication Error / Missed Dose', 'Challenging Behavior / Aggression', 'Unexplained Injury / Bruising', 'Safeguarding Concern', 'Medical Emergency', 'Property Damage', 'Refusal of Care', 'Other'];
$markTypes = ['Pressure Sore / Ulcer', 'Bruise / Contusion', 'Cut / Laceration', 'Skin Tear', 'Rash / Redness', 'Burn', 'Surgical Wound', 'Other'];

$bodySvgPath = "M150,20 C136,20 125,31 125,45 C125,59 136,70 150,70 C164,70 175,59 175,45 C175,31 164,20 150,20 Z M150,80 C110,80 80,90 70,110 L40,200 C37,210 45,215 50,210 L75,130 L95,130 L95,230 L135,420 C138,430 148,430 150,420 L150,230 L150,230 L150,420 C152,430 162,430 165,420 L205,230 L205,130 L225,130 L250,210 C255,215 263,210 260,200 L230,110 C220,90 190,80 150,80 Z";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <title>Incidents & Marks | JIT Field Care</title>
    
    <script type="module" src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.esm.js"></script>
    <script nomodule src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.js"></script>
    <link rel="stylesheet" href="<?= CAREAPP_BASE_URL ?>assets/ionic/css/ionic.bundle.css" />
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        :root { --ion-color-primary: #15c3ba; --ion-color-danger: #EF4444; --ion-color-warning: #F59E0B; --ion-font-family: 'Urbanist', sans-serif; --card-radius: 20px; }
        body { overscroll-behavior-y: none; }
        ion-content { --background: #F8FAFC; }
        
        /* HEADER - Moved inside ion-header so sticky top is handled by Ionic naturally */
        .app-header { background: white; padding: calc(env(safe-area-inset-top, 20px) + 15px) 20px 15px; display: flex; align-items: center; justify-content: space-between; gap: 15px; border-bottom: 1px solid #E2E8F0; }
        .btn-back { background: #F1F5F9; border: none; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #475569; cursor: pointer; transition: 0.2s; }
        .btn-back:active { transform: scale(0.95); background: #E2E8F0; }
        .header-title { font-size: 18px; font-weight: 900; color: #1E293B; }

        .content-pad { padding: 25px 20px 100px 20px; }

        /* CLIENT LIST */
        .client-list-card { background: white; border-radius: var(--card-radius); padding: 20px; margin-bottom: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); border: 1.5px solid #E2E8F0; text-decoration: none; display: flex; align-items: center; gap: 15px; transition: all 0.2s ease; }
        .client-list-card:active { transform: scale(0.97); background: #FAFBFC; }
        .c-avatar { width: 50px; height: 50px; border-radius: 16px; background: #EEF2FF; color: var(--ion-color-primary); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 900; flex-shrink: 0; }
        .c-info { flex: 1; }
        .c-info h3 { font-size: 16px; font-weight: 900; color: #1E293B; margin: 0 0 4px 0; }
        .c-info p { font-size: 12px; font-weight: 600; color: #64748B; margin: 0; }

        /* CLIENT PROFILE COMPACT */
        .profile-hero { display: flex; align-items: center; gap: 15px; background: white; padding: 20px; border-radius: var(--card-radius); border: 1.5px solid #E2E8F0; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .profile-hero h1 { font-size: 20px; font-weight: 900; color: #1E293B; margin: 0; }
        
        /* ACTION BUTTONS */
        .action-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 30px; }
        .action-btn { background: white; border: 1.5px solid var(--border-color, #E2E8F0); border-radius: var(--card-radius); padding: 25px 15px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
        .action-btn:active { transform: scale(0.96); }
        .action-btn.incident { border-color: #FECACA; background: #FEF2F2; }
        .action-btn.incident i { color: #EF4444; }
        .action-btn.body { border-color: #BFDBFE; background: #EFF6FF; }
        .action-btn.body i { color: #3B82F6; }
        .action-btn span { font-size: 13px; font-weight: 900; color: #1E293B; text-align: center; }

        /* HISTORY LISTS */
        .history-section { background: white; border-radius: var(--card-radius); padding: 20px; border: 1.5px solid #E2E8F0; margin-bottom: 20px; }
        .section-header { font-size: 15px; font-weight: 900; color: #334155; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #F1F5F9; padding-bottom: 10px; }
        .history-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px dashed #F1F5F9; }
        .history-item:last-child { border-bottom: none; padding-bottom: 0; }
        .history-item h4 { font-size: 14px; font-weight: 800; color: #1E293B; margin: 0 0 4px 0; }
        .history-item p { font-size: 11px; font-weight: 700; color: #94A3B8; margin: 0; }
        .badge { padding: 4px 8px; border-radius: 6px; font-size: 9px; font-weight: 900; text-transform: uppercase; }
        .bg-red { background: #FEF2F2; color: #EF4444; }
        .bg-amber { background: #FFFBEB; color: #F59E0B; }
        .bg-green { background: #ECFDF5; color: #10B981; }
        .bg-gray { background: #F1F5F9; color: #64748B; }

        /* MODALS */
        .modal { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 2000; align-items: flex-end; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; width: 100%; border-radius: 24px 24px 0 0; padding: 25px 20px max(25px, env(safe-area-inset-bottom)); animation: slideUp 0.3s ease-out; max-height: 90vh; overflow-y: auto; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #F1F5F9; padding-bottom: 15px; }
        .modal-header h2 { font-size: 18px; font-weight: 900; margin: 0; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 12px; font-weight: 800; color: #64748B; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em; }
        .custom-input { width: 100%; padding: 14px; background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 12px; font-family: inherit; font-size: 14px; font-weight: 600; color: #1E293B; outline: none; appearance: none; }
        .custom-input:focus { border-color: var(--ion-color-primary); background: white; }
        textarea.custom-input { min-height: 80px; resize: vertical; }

        .btn-submit { background: var(--ion-color-primary); color: white; border: none; padding: 16px; border-radius: 14px; font-weight: 900; font-size: 15px; width: 100%; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 10px; box-shadow: 0 4px 15px rgba(21, 195, 186, 0.3); }

        /* BODY MAP SVG UI */
        .map-selector { display: flex; justify-content: center; gap: 10px; margin-bottom: 15px; }
        .map-btn { padding: 8px 20px; border-radius: 8px; font-weight: 800; font-size: 12px; color: #64748B; background: #F1F5F9; border: none; }
        .map-btn.active { background: #3B82F6; color: white; }
        
        .svg-container { position: relative; width: 250px; height: 350px; margin: 0 auto 20px; background: #F8FAFC; border: 1.5px dashed #CBD5E1; border-radius: 20px; }
        .body-svg { width: 100%; height: 100%; fill: #E2E8F0; stroke: #94A3B8; stroke-width: 2; }
        
        .temp-pin { position: absolute; width: 20px; height: 20px; background: #EF4444; border: 3px solid white; border-radius: 50%; transform: translate(-50%, -50%); box-shadow: 0 2px 8px rgba(0,0,0,0.3); pointer-events: none; display: none; }

        /* STATUS / ALERTS */
        .toast { position: fixed; top: env(safe-area-inset-top, 20px); left: 50%; transform: translateX(-50%); background: #10B981; color: white; padding: 14px 24px; border-radius: 14px; font-weight: 800; font-size: 14px; z-index: 3000; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); width: 90%; max-width: 400px; text-align: center; }
        .toast.error { background: #EF4444; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
        
        ion-tab-bar { --background: rgba(255,255,255,0.95); backdrop-filter: blur(15px); border-top: 1px solid #E2E8F0; padding-bottom: env(safe-area-inset-bottom); height: calc(65px + env(safe-area-bottom)); }
        ion-tab-button { --color: #94A3B8; --color-selected: var(--ion-color-primary); font-family: 'Urbanist', sans-serif; font-weight: 800; font-size: 11px; cursor: pointer; }
        ion-icon { font-size: 24px; margin-bottom: 4px; }
    </style>
</head>
<body>

    <ion-app>
        <!-- Use ion-header to ensure the header never overlaps with ion-content natively -->
        <ion-header class="ion-no-border">
            <div class="app-header">
                <?php if($clientId): ?>
                    <button class="btn-back" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>incidents'"><i data-lucide="arrow-left" style="width: 20px;"></i></button>
                    <div class="header-title">Log Incident / Mark</div>
                    <div style="width:40px;"></div>
                <?php else: ?>
                    <button class="btn-back" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'"><i data-lucide="arrow-left" style="width: 20px;"></i></button>
                    <div class="header-title">Incidents & Body Map</div>
                    <div style="width:40px;"></div>
                <?php endif; ?>
            </div>
        </ion-header>

        <ion-content scroll-y="true">
            <div class="content-pad">
                
                <?php if ($successMsg): ?>
                    <div class="toast" id="sysToast"><i data-lucide="check-circle" style="width:16px; display:inline; vertical-align:middle;"></i> <?= htmlspecialchars($successMsg) ?></div>
                    <script>setTimeout(() => document.getElementById('sysToast').style.display = 'none', 4000);</script>
                <?php endif; ?>
                
                <?php if ($errorMsg): ?>
                    <div class="toast error" id="errToast"><i data-lucide="alert-triangle" style="width:16px; display:inline; vertical-align:middle;"></i> <?= htmlspecialchars($errorMsg) ?></div>
                    <script>setTimeout(() => document.getElementById('errToast').style.display = 'none', 5000);</script>
                <?php endif; ?>

                <?php if (!$clientId): ?>
                    <!-- ========================================== -->
                    <!-- LIST VIEW: SELECT CLIENT                   -->
                    <!-- ========================================== -->
                    <p style="font-size:14px; font-weight:700; color:#64748B; margin-bottom:20px; text-align:center;">
                        Select a service user to log an incident or a body map injury.
                    </p>
                    
                    <?php if (empty($myClients)): ?>
                        <div style="text-align: center; padding: 60px 30px; background: white; border-radius: var(--card-radius); border: 2px dashed #E2E8F0;">
                            <i data-lucide="users" style="width:32px; height:32px; color:#94A3B8; margin-bottom:15px;"></i>
                            <h3 style="font-size: 18px; font-weight: 900; color: #334155; margin: 0 0 6px 0;">No Active Clients</h3>
                            <p style="font-size: 14px; font-weight: 600; color: #94A3B8; margin: 0;">You have no clients assigned today.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($myClients as $c): ?>
                            <a href="?id=<?= $c['id'] ?>" class="client-list-card">
                                <div class="c-avatar"><?= strtoupper(substr($c['first_name'], 0, 1)) ?></div>
                                <div class="c-info">
                                    <h3><?= strtoupper(htmlspecialchars($c['first_name'] . ' ' . $c['last_name'])) ?></h3>
                                    <p><i data-lucide="map-pin" style="width:12px;"></i> <?= htmlspecialchars($c['primary_address'] ?: 'No address') ?></p>
                                </div>
                                <i data-lucide="chevron-right" style="color:#CBD5E1;"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- ========================================== -->
                    <!-- ACTIONS & HISTORY FOR SPECIFIC CLIENT      -->
                    <!-- ========================================== -->
                    <div class="profile-hero">
                        <div class="c-avatar" style="width:60px; height:60px; font-size:24px;"><?= strtoupper(substr($client['first_name'], 0, 1)) ?></div>
                        <div>
                            <h1><?= strtoupper(htmlspecialchars($client['first_name'] . ' ' . $client['last_name'])) ?></h1>
                            <p style="font-size:12px; color:#64748B; font-weight:700; margin-top:4px;">Incident & Body Map Center</p>
                        </div>
                    </div>

                    <div class="action-grid">
                        <div class="action-btn incident" onclick="toggleModal('incidentModal', true)">
                            <div style="background:white; padding:12px; border-radius:50%; box-shadow:0 2px 8px rgba(239,68,68,0.2);">
                                <i data-lucide="alert-triangle" style="width:24px; height:24px;"></i>
                            </div>
                            <span>Log General<br>Incident</span>
                        </div>
                        <div class="action-btn body" onclick="toggleModal('bodyMapModal', true)">
                            <div style="background:white; padding:12px; border-radius:50%; box-shadow:0 2px 8px rgba(59,130,246,0.2);">
                                <i data-lucide="activity" style="width:24px; height:24px;"></i>
                            </div>
                            <span>Log Body Map<br>Injury/Mark</span>
                        </div>
                    </div>

                    <div class="history-section">
                        <div class="section-header"><i data-lucide="history"></i> Recent Incidents</div>
                        <?php if (empty($recentIncidents)): ?>
                            <p style="font-size:13px; color:#94A3B8; font-weight:600; font-style:italic; text-align:center; padding:10px 0;">No recent incidents.</p>
                        <?php else: foreach($recentIncidents as $inc): 
                            $bg = $inc['severity'] === 'Critical' ? 'bg-red' : ($inc['severity'] === 'High' ? 'bg-amber' : 'bg-gray');
                        ?>
                            <div class="history-item">
                                <div>
                                    <h4><?= htmlspecialchars($inc['incident_type']) ?></h4>
                                    <p><?= date('d M Y', strtotime($inc['incident_date'])) ?> • <?= htmlspecialchars($inc['status']) ?></p>
                                </div>
                                <span class="badge <?= $bg ?>"><?= htmlspecialchars($inc['severity']) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <div class="history-section">
                        <div class="section-header"><i data-lucide="user"></i> Recent Body Marks</div>
                        <?php if (empty($recentMarks)): ?>
                            <p style="font-size:13px; color:#94A3B8; font-weight:600; font-style:italic; text-align:center; padding:10px 0;">No recent body marks.</p>
                        <?php else: foreach($recentMarks as $mark): 
                            $bg = $mark['severity'] === 'Red' ? 'bg-red' : ($mark['severity'] === 'Yellow' ? 'bg-amber' : 'bg-green');
                        ?>
                            <div class="history-item">
                                <div>
                                    <h4><?= htmlspecialchars($mark['mark_type']) ?> (<?= htmlspecialchars($mark['view_side']) ?>)</h4>
                                    <p>Observed: <?= date('d M Y', strtotime($mark['observation_date'])) ?></p>
                                </div>
                                <span class="badge <?= $bg ?>"><?= htmlspecialchars($mark['severity']) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </ion-content>

        <!-- NATIVE TAB NAVIGATION -->
        <ion-tab-bar slot="bottom">
            <ion-tab-button tab="schedule" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'">
                <ion-icon name="calendar-outline"></ion-icon>
                <ion-label>Today</ion-label>
            </ion-tab-button>

            <ion-tab-button tab="clients" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>clients'" selected="true">
                <ion-icon name="people-outline"></ion-icon>
                <ion-label>Clients</ion-label>
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

    <!-- ============================================== -->
    <!-- MODALS -->
    <!-- ============================================== -->

    <!-- MODAL 1: GENERAL INCIDENT -->
    <div id="incidentModal" class="modal" onclick="closeBg(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 style="color:#EF4444;"><i data-lucide="alert-triangle" style="width:20px; display:inline; vertical-align:middle; margin-right:6px;"></i> Log Incident</h2>
                <button type="button" onclick="toggleModal('incidentModal', false)" style="background:none; border:none; cursor:pointer; color:#94A3B8;"><i data-lucide="x"></i></button>
            </div>
            
            <form action="" method="POST">
                <input type="hidden" name="action" value="log_incident">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="incident_date" class="custom-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Time</label>
                        <input type="time" name="incident_time" class="custom-input" value="<?= date('H:i') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Incident Category</label>
                    <select name="incident_type" class="custom-input" required>
                        <option value="">Select...</option>
                        <?php foreach($incidentTypes as $type): ?><option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Severity</label>
                    <select name="severity" class="custom-input" required>
                        <option value="Low">Low (No harm / Near miss)</option>
                        <option value="Medium" selected>Medium (Minor injury/issue)</option>
                        <option value="High">High (Medical attention needed)</option>
                        <option value="Critical">Critical (Emergency / 999)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>What Happened? (Factual details)</label>
                    <textarea name="description" class="custom-input" placeholder="Describe the event objectively..." required></textarea>
                </div>

                <div class="form-group">
                    <label>Immediate Actions Taken</label>
                    <textarea name="action_taken" class="custom-input" placeholder="e.g. Administered first aid, called ambulance..." required></textarea>
                </div>

                <button type="submit" class="btn-submit" style="background:#EF4444; box-shadow: 0 4px 15px rgba(239,68,68,0.3);">Submit Incident to Office</button>
            </form>
        </div>
    </div>

    <!-- MODAL 2: BODY MAP -->
    <div id="bodyMapModal" class="modal" onclick="closeBg(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 style="color:#3B82F6;"><i data-lucide="activity" style="width:20px; display:inline; vertical-align:middle; margin-right:6px;"></i> Locate Injury / Mark</h2>
                <button type="button" onclick="toggleModal('bodyMapModal', false)" style="background:none; border:none; cursor:pointer; color:#94A3B8;"><i data-lucide="x"></i></button>
            </div>
            
            <p style="font-size:12px; font-weight:700; color:#64748B; text-align:center; margin-bottom:15px;">Tap the body map below to pinpoint the location.</p>

            <div class="map-selector">
                <button class="map-btn active" id="btnFront" onclick="switchMap('Front')">Front</button>
                <button class="map-btn" id="btnBack" onclick="switchMap('Back')">Back</button>
            </div>

            <div class="svg-container" id="svgContainer" onclick="handleMapTap(event)">
                <svg viewBox="0 0 300 450" class="body-svg">
                    <path d="<?= $bodySvgPath ?>"></path>
                </svg>
                <div class="temp-pin" id="tempPin"></div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="bodyMapForm" style="display:none;">
                <input type="hidden" name="action" value="log_body_map">
                <input type="hidden" name="view_side" id="bm_view_side" value="Front">
                <input type="hidden" name="pos_x" id="bm_pos_x">
                <input type="hidden" name="pos_y" id="bm_pos_y">
                
                <div style="background:#F1F5F9; padding:10px; border-radius:10px; text-align:center; font-size:12px; font-weight:800; color:#1E293B; margin-bottom:15px;">
                    Coordinates Locked: <span id="lblCoords"></span>
                </div>

                <div class="form-group">
                    <label>Mark / Injury Type</label>
                    <select name="mark_type" class="custom-input" required>
                        <option value="">Select...</option>
                        <?php foreach($markTypes as $type): ?><option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Severity (Color)</label>
                    <select name="severity" class="custom-input" required>
                        <option value="Red">Red (Severe / New / Bleeding)</option>
                        <option value="Yellow" selected>Yellow (Standard Monitoring)</option>
                        <option value="Green">Green (Healing)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Approx Size (Optional)</label>
                    <input type="text" name="size_info" class="custom-input" placeholder="e.g. 2cm x 3cm">
                </div>

                <div class="form-group">
                    <label>Clinical Notes</label>
                    <textarea name="notes" class="custom-input" placeholder="Describe appearance, pain, exudate..." required></textarea>
                </div>

                <div class="form-group">
                    <label>Photographic Proof (Required for new marks)</label>
                    <input type="file" name="evidence_image" accept="image/*" capture="environment" class="custom-input" style="background:white; padding:10px;" required>
                </div>
                
                <input type="hidden" name="observation_date" value="<?= date('Y-m-d') ?>">

                <button type="submit" class="btn-submit" style="background:#3B82F6; box-shadow: 0 4px 15px rgba(59,130,246,0.3);">Save Body Mark Record</button>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Register Android Hardware Back Button listener
        if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) {
            window.Capacitor.Plugins.App.addListener('backButton', () => {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('id')) {
                    window.location.href = '<?= CAREAPP_BASE_URL ?>incidents';
                } else {
                    window.location.href = '<?= CAREAPP_BASE_URL ?>dashboard';
                }
            });
        }

        // Global Form Preloader to prevent duplicate submissions and provide visual feedback
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', async function(e) {
                if (this.checkValidity()) {
                    const loading = document.createElement('ion-loading');
                    loading.message = 'Processing securely...';
                    loading.spinner = 'crescent';
                    document.body.appendChild(loading);
                    await loading.present();
                }
            });
        });

        function toggleModal(id, show) {
            const modal = document.getElementById(id);
            if (modal) modal.style.display = show ? 'flex' : 'none';
            if (id === 'bodyMapModal' && !show) {
                // Reset map state
                document.getElementById('tempPin').style.display = 'none';
                document.getElementById('bodyMapForm').style.display = 'none';
            }
        }

        function closeBg(e) {
            if(e.target.classList.contains('modal')) e.target.style.display = 'none';
        }

        // BODY MAP LOGIC
        function switchMap(side) {
            document.getElementById('btnFront').className = 'map-btn' + (side === 'Front' ? ' active' : '');
            document.getElementById('btnBack').className = 'map-btn' + (side === 'Back' ? ' active' : '');
            document.getElementById('bm_view_side').value = side;
            
            // Hide form and pin when switching views
            document.getElementById('tempPin').style.display = 'none';
            document.getElementById('bodyMapForm').style.display = 'none';
        }

        function handleMapTap(e) {
            const container = document.getElementById('svgContainer');
            const rect = container.getBoundingClientRect();
            
            // Get coordinates relative to the container
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            const x = ((clientX - rect.left) / rect.width) * 100;
            const y = ((clientY - rect.top) / rect.height) * 100;

            // Set Pin visually
            const pin = document.getElementById('tempPin');
            pin.style.left = x + '%';
            pin.style.top = y + '%';
            pin.style.display = 'block';

            // Set Form Values
            document.getElementById('bm_pos_x').value = x.toFixed(2);
            document.getElementById('bm_pos_y').value = y.toFixed(2);
            const side = document.getElementById('bm_view_side').value;
            document.getElementById('lblCoords').innerText = `${side} View (X: ${x.toFixed(1)}%, Y: ${y.toFixed(1)}%)`;

            // Reveal Form and scroll to it smoothly
            const form = document.getElementById('bodyMapForm');
            form.style.display = 'block';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    </script>
</body>
</html>