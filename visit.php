<?php
/**
 * Just in Time Group ERP - Carer Mobile App
 * File Path: /careapp/visit.php
 * Features: Native Capacitor Geolocation & STRICT Physical QR Code Scanning.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
use App\Auth;

if (!Auth::check()) { header("Location: " . CAREAPP_BASE_URL . "login"); exit; }

$db = db();
$userId = $_SESSION['user_id'];
$tenantId = $_SESSION['tenant_id'];
$rosterId = $_GET['id'] ?? null;

if (!$rosterId) { header("Location: " . CAREAPP_BASE_URL . "dashboard"); exit; }

$errorMsg = null;
$successMsg = null;

$visit = $db->row("
    SELECT ri.*, t.id as task_id, t.task_type, t.description, t.importance,
           c.id as client_id, c.first_name, c.last_name, c.primary_address, c.postcode, c.date_of_birth, c.qr_reference_code,
           a.id as attendance_id, a.clock_in, a.clock_out, a.status as attendance_status,
           hti.id as interaction_id, hti.status as interaction_status
    FROM healthcare_roster_items ri
    JOIN healthcare_client_tasks t ON ri.task_id = t.id
    JOIN healthcare_clients c ON t.client_id = c.id
    LEFT JOIN attendance a ON ri.id = a.roster_item_id
    LEFT JOIN healthcare_task_interactions hti ON t.id = hti.task_id AND DATE(hti.created_at) = ri.roster_date
    WHERE ri.id = ? AND ri.tenant_id = ? AND ri.carer_id = ?
", [$rosterId, $tenantId, $userId]);

if (!$visit) die("Visit record not found.");

$visitState = 'planned';
if ($visit['attendance_status'] === 'completed' || $visit['status'] === 'completed') {
    $visitState = 'completed';
} elseif ($visit['clock_in']) {
    $visitState = 'in_progress';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // CLOCK IN
    if ($_POST['action'] === 'clock_in') {
        $lat = $_POST['latitude'] ?? null;
        $lng = $_POST['longitude'] ?? null;
        $scannedQr = $_POST['scanned_qr'] ?? null;
        $checkInMethod = 'MANUAL';

        try {
            // STRICT QR ENFORCEMENT ON BACKEND
            if (!empty($visit['qr_reference_code'])) {
                if (empty($scannedQr) || $scannedQr !== $visit['qr_reference_code']) {
                    throw new Exception("Security Alert: You MUST physically scan the correct QR code located at the property.");
                }
                $checkInMethod = 'QR_VERIFIED';
            } elseif ($lat && $lng) {
                // Fallback only if no QR code is assigned to this client yet
                $checkInMethod = 'GPS_ONLY';
            } else {
                throw new Exception("Location verification failed. Could not verify visit securely.");
            }

            $db->query("START TRANSACTION");
            $attId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $db->query("INSERT INTO attendance (id, tenant_id, roster_item_id, user_id, clock_in, gps_lat_in, gps_lng_in, check_in_method, status) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 'in_progress')", [$attId, $tenantId, $rosterId, $userId, $lat, $lng, $checkInMethod]);
            $intId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $db->query("INSERT INTO healthcare_task_interactions (id, tenant_id, task_id, carer_id, status, check_in_time) VALUES (?, ?, ?, ?, 'in_progress', NOW())", [$intId, $tenantId, $visit['task_id'], $userId]);
            $db->query("COMMIT");
            header("Location: ?id=$rosterId");
            exit;
        } catch (Exception $e) { try { $db->query("ROLLBACK"); } catch(Exception $ex) {} $errorMsg = "Clock-in failed: " . $e->getMessage(); }
    }

    // CLOCK OUT
    if ($_POST['action'] === 'clock_out') {
        $lat = $_POST['latitude'] ?? null;
        $lng = $_POST['longitude'] ?? null;
        $scannedQr = $_POST['scanned_qr'] ?? null;
        $checkOutMethod = 'MANUAL';
        
        $mood = $_POST['mood_indicator'] ?? 'Neutral';
        $isIncident = isset($_POST['is_incident']) ? 1 : 0;
        $isSafeguarding = isset($_POST['is_safeguarding']) ? 1 : 0;

        $tasksPerformed = $_POST['tasks'] ?? [];
        $taskNotes = $_POST['task_notes'] ?? [];
        $generalSummary = trim($_POST['general_summary'] ?? '');
        $hydration = trim($_POST['hydration_ml'] ?? '');
        $bp = trim($_POST['blood_pressure'] ?? '');
        $temp = trim($_POST['temperature'] ?? '');
        
        $assembledNote = "";

        // ADDED: Medication Outcome Logic
        if (strpos($visit['task_type'], 'Medication') !== false && !empty($_POST['med_outcome_code'])) {
            $medCode = $_POST['med_outcome_code'];
            $medNotes = trim($_POST['med_outcome_notes'] ?? '');
            $medMap = [
                'C' => 'Completed', 'P' => 'Prompted', 'R' => 'Refused', 'S' => 'Sleeping',
                'N' => 'Nausea', 'L' => 'Left Out', 'F' => 'Family Completed', 
                'NS' => 'No Stock', 'AT' => 'Already Taken', 'X' => 'Other'
            ];
            $fullCode = $medMap[$medCode] ?? $medCode;
            
            $assembledNote .= "MEDICATION OUTCOME: [ {$medCode} ] - {$fullCode}\n";
            if ($medNotes) {
                $assembledNote .= "Reason/Action: {$medNotes}\n";
            }
            $assembledNote .= "\n------------------------\n\n";
        }

        if (!empty($tasksPerformed)) {
            $assembledNote .= "TASKS COMPLETED:\n";
            foreach ($tasksPerformed as $task) {
                $detail = trim($taskNotes[$task] ?? 'Completed without issues.');
                $assembledNote .= "• {$task}: {$detail}\n";
            }
            $assembledNote .= "\n";
        }
        if ($hydration || $bp || $temp) {
            $assembledNote .= "CLINICAL OBSERVATIONS:\n";
            if ($hydration) $assembledNote .= "• Hydration: {$hydration}ml\n";
            if ($bp) $assembledNote .= "• Blood Pressure: {$bp}\n";
            if ($temp) $assembledNote .= "• Temp: {$temp}°C\n";
            $assembledNote .= "\n";
        }
        $assembledNote .= "GENERAL SUMMARY:\n" . ($generalSummary ?: "Routine visit.");

        try {
            // STRICT QR ENFORCEMENT ON BACKEND
            if (!empty($visit['qr_reference_code'])) {
                if (empty($scannedQr) || $scannedQr !== $visit['qr_reference_code']) {
                    throw new Exception("Security Alert: You MUST physically scan the correct QR code located at the property to depart.");
                }
                $checkOutMethod = 'QR_VERIFIED';
            } elseif ($lat && $lng) {
                $checkOutMethod = 'GPS_ONLY';
            } else {
                throw new Exception("Location verification failed. Could not verify visit securely.");
            }

            $db->query("START TRANSACTION");

            // QA ENGINE FLAGS
            $auditFlags = [];
            
            // Medication Specific Flags
            if (strpos($visit['task_type'], 'Medication') !== false && !empty($_POST['med_outcome_code'])) {
                if (in_array($_POST['med_outcome_code'], ['R', 'N', 'NS', 'X'])) {
                    $auditFlags[] = ['type' => 'medication_exception', 'msg' => "Medication Exception [Code: {$_POST['med_outcome_code']}]. Requires coordinator review."];
                }
            }

            if (strlen($assembledNote) < 40 && empty($tasksPerformed) && empty($_POST['med_outcome_code'])) $auditFlags[] = ['type' => 'poor_documentation', 'msg' => 'Care note flagged for insufficient detail.'];
            $riskRegex = '/\b(deteriorat|unwell|pain|fell|fall|injur|refus|blood|breath|chok|agress|bruis|wound)\b/i';
            if (preg_match($riskRegex, $assembledNote) && !$isIncident && !$isSafeguarding) $auditFlags[] = ['type' => 'unescalated_risk', 'msg' => 'Note contained clinical risk keywords but was not escalated.'];

            foreach ($auditFlags as $flag) {
                $logId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $db->query("INSERT INTO healthcare_compliance_logs (id, tenant_id, roster_date, roster_item_id, client_id, severity, event_type, message, created_by) VALUES (?, ?, ?, ?, ?, 'warning', ?, ?, ?)", [$logId, $tenantId, $visit['roster_date'], $rosterId, $visit['client_id'], $flag['type'], $flag['msg'], $userId]);
            }

            $db->query("UPDATE attendance SET clock_out = NOW(), gps_lat_out = ?, gps_lng_out = ?, check_out_method = ?, status = 'completed', notes = ? WHERE roster_item_id = ?", [$lat, $lng, $checkOutMethod, $assembledNote, $rosterId]);
            $db->query("UPDATE healthcare_task_interactions SET check_out_time = NOW(), status = 'completed', carer_notes = ? WHERE task_id = ? AND carer_id = ? AND DATE(created_at) = ?", [$assembledNote, $visit['task_id'], $userId, $visit['roster_date']]);

            $noteId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $db->query("INSERT INTO healthcare_visit_notes (id, tenant_id, roster_item_id, carer_id, client_id, note_content, mood_indicator, is_incident_flagged, is_safeguarding_flagged, device_timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", [$noteId, $tenantId, $rosterId, $userId, $visit['client_id'], $assembledNote, $mood, $isIncident, $isSafeguarding]);

            $db->query("UPDATE healthcare_roster_items SET status = 'completed' WHERE id = ?", [$rosterId]);

            if ($isIncident || $isSafeguarding) {
                $incidentNote = trim($_POST['incident_note'] ?? '');
                $incId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                $category = $isSafeguarding ? 'Safeguarding Concern' : 'Clinical Change';
                $severity = $isSafeguarding ? 'Critical' : 'Medium';
                $incDesc = "SPECIFIC INCIDENT DETAILS:\n" . ($incidentNote ?: 'No specific details provided.') . "\n\n--- AUTO-FLAGGED FROM FULL VISIT NOTE ---\n" . $assembledNote;
                
                $db->query("INSERT INTO healthcare_client_incidents (id, tenant_id, client_id, incident_date, incident_time, incident_type, severity, description, action_taken, status, reported_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Coordinator Review', 'Open', ?)", [$incId, $tenantId, $visit['client_id'], date('Y-m-d'), date('H:i'), $category, $severity, $incDesc, $userId]);
            }

            $db->query("COMMIT");
            header("Location: ?id=$rosterId&success=completed");
            exit;
        } catch (Exception $e) { try { $db->query("ROLLBACK"); } catch(Exception $ex) {} $errorMsg = "Completion failed: " . $e->getMessage(); }
    }
}

$age = date_diff(date_create($visit['date_of_birth']), date_create('today'))->y;
$isMed = $visit['task_type'] === 'Medication';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <title>Visit Execution | JIT Field Care</title>
    <script type="module" src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.esm.js"></script>
    <script nomodule src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.js"></script>
    <link rel="stylesheet" href="<?= CAREAPP_BASE_URL ?>assets/ionic/css/ionic.bundle.css" />
    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    
    <style>
        :root { --ion-color-primary: #15c3ba; --ion-font-family: 'Urbanist', sans-serif; --ion-background-color: #F8FAFC; }
        body { overscroll-behavior-y: none; }
        .app-header { background: white; padding: calc(env(safe-area-inset-top, 20px) + 15px) 20px 20px; display: flex; align-items: center; gap: 15px; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid #E2E8F0; }
        .btn-back { background: #F1F5F9; border: none; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #475569; cursor: pointer; }
        .header-title { font-size: 18px; font-weight: 900; color: #1E293B; }
        .content-pad { padding: 25px 20px 140px; }
        .client-card { background: white; border-radius: 20px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); border: 1.5px solid #E2E8F0; margin-bottom: 20px; }
        .c-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .c-avatar { width: 50px; height: 50px; border-radius: 16px; background: #EEF2FF; color: var(--ion-color-primary); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 900; flex-shrink: 0; }
        .c-info h2 { font-size: 20px; font-weight: 900; color: #1E293B; margin: 0 0 4px 0; line-height: 1.2; }
        .c-info p { font-size: 13px; font-weight: 700; color: #64748B; margin: 0; }
        .task-card { background: <?= $isMed ? '#FDF2F8' : '#F0FDF4' ?>; border: 1.5px solid <?= $isMed ? '#FBCFE8' : '#BBF7D0' ?>; border-radius: 20px; padding: 25px; margin-bottom: 25px; }
        .task-type { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; color: <?= $isMed ? '#BE185D' : '#16A34A' ?>; margin-bottom: 12px; }
        .task-desc { font-size: 16px; font-weight: 800; color: #1E293B; line-height: 1.4; }
        .form-section { background: white; border-radius: 20px; padding: 25px; border: 1.5px solid #E2E8F0; margin-bottom: 20px; }
        .form-section h3 { font-size: 15px; font-weight: 900; color: #334155; margin: 0 0 10px 0; display: flex; align-items: center; gap: 8px; }
        .form-section p.subtext { font-size: 11px; font-weight: 600; color: #94A3B8; margin-bottom: 15px; }
        .tasks-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px; }
        .task-pill { display: inline-block; cursor: pointer; }
        .task-pill input { display: none; }
        .task-pill span { display: inline-block; padding: 10px 16px; background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 12px; font-size: 12px; font-weight: 800; color: #64748B; transition: 0.2s; }
        .task-pill input:checked + span { background: #EEF2FF; border-color: var(--ion-color-primary); color: var(--ion-color-primary); }
        .dynamic-note { display: none; margin-top: 10px; animation: fadeIn 0.2s ease-in; }
        .dynamic-note label { font-size: 10px; font-weight: 800; color: var(--ion-color-primary); text-transform: uppercase; margin-bottom: 6px; display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
        .custom-input { width: 100%; border: 1.5px solid #E2E8F0; border-radius: 16px; padding: 15px; font-family: 'Urbanist', sans-serif; font-size: 14px; font-weight: 600; color: #1E293B; outline: none; transition: 0.2s; background: #F8FAFC; box-sizing: border-box; margin-bottom: 10px; }
        .custom-input:focus { border-color: var(--ion-color-primary); background: white; }
        textarea.custom-input { resize: none; height: 100px; }
        .vitals-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .vital-input label { display: block; font-size: 10px; font-weight: 800; color: #94A3B8; text-transform: uppercase; margin-bottom: 6px; }
        .vital-input input { height: 45px; padding: 10px; }
        .mood-selector { display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px; scrollbar-width: none; }
        .mood-btn { flex: 1; min-width: 70px; display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; border: 1.5px solid #E2E8F0; border-radius: 14px; background: white; cursor: pointer; transition: 0.2s; }
        .mood-btn.selected { border-color: var(--ion-color-primary); background: #F0FDFA; color: var(--ion-color-primary); }
        .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 15px 0; border-top: 1px solid #F1F5F9; }
        .toggle-row label { font-size: 14px; font-weight: 800; color: #334155; }
        .toggle-row p { font-size: 11px; font-weight: 600; color: #94A3B8; margin: 4px 0 0 0; }
        .action-bar { position: fixed; bottom: 0; left: 0; width: 100%; background: white; padding: 15px 20px calc(env(safe-area-inset-bottom, 20px) + 15px); border-top: 1px solid #E2E8F0; box-shadow: 0 -10px 30px rgba(0,0,0,0.05); z-index: 100; display: flex; gap: 10px; }
        .btn-massive { padding: 18px; border-radius: 16px; border: none; font-size: 15px; font-weight: 900; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; transition: transform 0.1s; }
        .btn-massive:active { transform: scale(0.98); }
        .btn-primary { background: var(--ion-color-primary); color: white; flex: 2; box-shadow: 0 10px 25px rgba(21, 195, 186, 0.3); }
        .btn-secondary { background: #1E293B; color: white; flex: 1; }
        .btn-complete { background: #10B981; color: white; flex: 2; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3); }
        .status-banner { background: #ECFDF5; border: 1.5px solid #A7F3D0; color: #065F46; padding: 15px 20px; border-radius: 16px; font-weight: 800; font-size: 14px; display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .error-banner { background: #FEF2F2; border-color: #FECACA; color: #991B1B; padding: 15px 20px; border-radius: 16px; font-weight: 800; font-size: 14px; display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
        .scanner-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 2000; flex-direction: column; align-items: center; padding-top: calc(env(safe-area-inset-top, 20px) + 20px); }
        .scanner-container { width: 100%; max-width: 400px; padding: 20px; display: flex; flex-direction: column; align-items: center; }
        #qr-reader { width: 100%; border-radius: 20px; overflow: hidden; border: 2px solid var(--ion-color-primary); background:black; }
        .scanner-cancel { margin-top: 30px; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 15px 30px; border-radius: 100px; font-weight: 900; font-size: 14px; backdrop-filter: blur(10px); }
    </style>
</head>
<body>

    <div class="app-header">
        <button class="btn-back" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'"><i data-lucide="arrow-left" style="width: 20px;"></i></button>
        <div class="header-title">Visit Execution</div>
    </div>

    <!-- QR SCANNER OVERLAY -->
    <div id="qrScannerModal" class="scanner-modal">
        <div class="scanner-container">
            <h2 style="color:white; font-weight:900; margin-bottom:10px;">Scan Property Code</h2>
            <p style="color:#CBD5E1; font-size:12px; font-weight:600; text-align:center; margin-bottom:20px;">Physical verification is mandatory.</p>
            <div id="qr-reader"></div>
            <button class="scanner-cancel" onclick="closeQRScanner()"><i data-lucide="x" style="width:16px; display:inline; vertical-align:middle;"></i> Cancel Scan</button>
        </div>
    </div>

    <ion-content>
        <div class="content-pad">
            
            <?php if (isset($_GET['success'])): ?>
                <div class="status-banner"><i data-lucide="check-circle-2"></i> Visit Completed & QA Passed!</div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="error-banner"><i data-lucide="alert-triangle"></i> <?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <div class="client-card">
                <div class="c-header">
                    <div class="c-avatar"><?= strtoupper(substr($visit['first_name'], 0, 1)) ?></div>
                    <div class="c-info">
                        <h2><?= strtoupper(htmlspecialchars($visit['first_name'] . ' ' . $visit['last_name'])) ?></h2>
                        <p><?= $age ?> years old</p>
                    </div>
                </div>
            </div>

            <div class="task-card">
                <div class="task-type">
                    <i data-lucide="<?= $isMed ? 'pill' : 'clipboard-list' ?>" style="width:14px;"></i> 
                    <?= htmlspecialchars($visit['task_type']) ?> Requirements
                </div>
                <div class="task-desc"><?= nl2br(htmlspecialchars($visit['description'])) ?></div>
            </div>

            <?php if ($visitState === 'planned'): ?>
                <div style="text-align: center; padding: 20px; color: #94A3B8; font-weight: 700; font-size: 13px;">
                    <i data-lucide="scan-line" style="width:32px; height:32px; margin-bottom:10px; opacity:0.5;"></i><br>
                    Location verification required.<br>Ensure you are at the property.
                </div>
                
                <div class="action-bar">
                    <form id="clockInForm" method="POST" style="display:none;">
                        <input type="hidden" name="action" value="clock_in">
                        <input type="hidden" name="latitude" id="latIn">
                        <input type="hidden" name="longitude" id="lngIn">
                        <input type="hidden" name="scanned_qr" id="qrIn">
                    </form>
                    <button type="button" class="btn-massive btn-primary" style="width:100%; flex:none;" onclick="openQRScanner('in')"><i data-lucide="camera"></i> Verify via Scan</button>
                </div>

            <?php elseif ($visitState === 'in_progress'): ?>
                <form id="clockOutForm" method="POST">
                    <input type="hidden" name="action" value="clock_out">
                    <input type="hidden" name="latitude" id="latOut">
                    <input type="hidden" name="longitude" id="lngOut">
                    <input type="hidden" name="scanned_qr" id="qrOut">
                    <input type="hidden" name="mood_indicator" id="moodInput" value="Neutral">

                    <?php if (strpos($visit['task_type'], 'Medication') !== false): ?>
                    <div class="form-section" style="background:#FFF1F2; border-color:#FECDD3;">
                        <h3 style="color:#E11D48;"><i data-lucide="pill" style="width:16px;"></i> eMAR Administration</h3>
                        <p class="subtext" style="color:#BE185D;">Select the exact medication outcome code for this visit.</p>
                        
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="color:#9F1239;">Medication Outcome Code *</label>
                            <select name="med_outcome_code" required class="custom-input" onchange="handleMedCodeChange(this)" style="border-color:#FDA4AF; background:white; font-weight:800; color:#E11D48;">
                                <option value="">-- Select Code --</option>
                                <option value="C">C - Completed (Taken in presence)</option>
                                <option value="P">P - Prompted (Passively encouraged)</option>
                                <option value="R">R - Refused (Requires explanation)</option>
                                <option value="S">S - Sleeping</option>
                                <option value="N">N - Nausea (Requires symptoms/actions)</option>
                                <option value="L">L - Left Out (As per risk assessment)</option>
                                <option value="F">F - Family Completed</option>
                                <option value="NS">NS - No Stock (Requires reporting)</option>
                                <option value="AT">AT - Already Taken</option>
                                <option value="X">X - Other (Explanation mandatory)</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="medNotesBox" style="display:none; margin-top:15px; animation: fadeIn 0.2s ease-in;">
                            <label style="color:#9F1239;">Explanation / Action Taken *</label>
                            <textarea name="med_outcome_notes" id="medNotesInput" class="custom-input" style="height:80px; border-color:#FDA4AF; background:white;" placeholder="Provide specific clinical details (e.g. why refused, nausea symptoms, or other reasons)..."></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-section" style="background:#EEF2FF; border-color:#C7D2FE;">
                        <h3 style="color:var(--ion-color-primary);"><i data-lucide="check-square" style="width:16px;"></i> Tasks Completed</h3>
                        <p class="subtext" style="color:#6366F1;">Select all care types provided during this visit.</p>
                        
                        <div class="tasks-grid">
                            <?php $careTypes = ['Personal Care', 'Meal Prep & Support', 'Medication Support', 'Mobility & Transfer', 'Domestic / Cleaning', 'Companionship', 'General Discussion']; 
                            foreach($careTypes as $idx => $ct): $safeId = "ct_$idx"; ?>
                                <label class="task-pill">
                                    <input type="checkbox" name="tasks[]" value="<?= $ct ?>" onchange="toggleTaskNote('<?= $safeId ?>', this.checked)">
                                    <span><?= $ct ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach($careTypes as $idx => $ct): $safeId = "ct_$idx"; ?>
                            <div id="<?= $safeId ?>" class="dynamic-note">
                                <label><?= $ct ?> Details</label>
                                <textarea name="task_notes[<?= $ct ?>]" class="custom-input" style="height:60px;" placeholder="Detail specifically what was done..."></textarea>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-section">
                        <h3><i data-lucide="activity" style="width:16px;"></i> Clinical Vitals (Optional)</h3>
                        <div class="vitals-grid">
                            <div class="vital-input"><label>Hydration Given</label><input type="number" name="hydration_ml" class="custom-input" placeholder="e.g. 250 (ml)"></div>
                            <div class="vital-input"><label>Temperature</label><input type="number" step="0.1" name="temperature" class="custom-input" placeholder="e.g. 37.2 (°C)"></div>
                            <div class="vital-input" style="grid-column: 1 / -1;"><label>Blood Pressure</label><input type="text" name="blood_pressure" class="custom-input" placeholder="e.g. 120/80"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i data-lucide="message-square" style="width:16px;"></i> Overall Summary</h3>
                        <p class="subtext">Provide a general overview of the visit and the client's disposition. <strong style="color:var(--ion-color-primary);">Notes are actively QA audited.</strong></p>
                        <div class="mood-selector">
                            <div class="mood-btn" data-mood="Happy"><i data-lucide="smile"></i><span style="font-size:9px;">Good</span></div>
                            <div class="mood-btn selected" data-mood="Neutral"><i data-lucide="meh"></i><span style="font-size:9px;">Neutral</span></div>
                            <div class="mood-btn" data-mood="Agitated"><i data-lucide="frown"></i><span style="font-size:9px;">Agitated</span></div>
                            <div class="mood-btn" data-mood="Unwell"><i data-lucide="thermometer"></i><span style="font-size:9px;">Unwell</span></div>
                        </div>
                        <textarea name="general_summary" id="generalSummaryInput" class="custom-input" placeholder="Document the visit summary here..." required></textarea>
                    </div>

                    <div class="form-section">
                        <div class="toggle-row" style="border:none; padding-top:0;">
                            <div><label>Log Clinical Incident</label><p>Accidents, med errors.</p></div>
                            <ion-toggle name="is_incident" id="toggleIncident" color="warning"></ion-toggle>
                        </div>
                        <div class="toggle-row">
                            <div><label style="color:#EF4444;">Safeguarding Alert</label><p>Escalate immediate risk.</p></div>
                            <ion-toggle name="is_safeguarding" id="toggleSafeguarding" color="danger"></ion-toggle>
                        </div>
                        <textarea name="incident_note" id="incidentNoteInput" class="custom-input" placeholder="Provide specific details regarding the incident or safeguarding concern..." style="display:none; margin-top: 15px; height: 80px;"></textarea>
                    </div>

                    <div class="action-bar">
                        <button type="button" class="btn-massive btn-complete" style="width:100%; flex:none;" onclick="openQRScanner('out')"><i data-lucide="camera"></i> Submit & Scan Out</button>
                    </div>
                </form>

            <?php else: ?>
                <div class="form-section" style="text-align: center; padding: 40px 20px;">
                    <div style="width: 60px; height: 60px; background: #E9FBF0; color: #10B981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;"><i data-lucide="check" style="width: 30px; height: 30px;"></i></div>
                    <h3 style="margin-bottom: 5px;">Visit Concluded</h3>
                    <p style="font-size: 13px; color: #64748B; font-weight: 600; margin: 0;">QA Audit Complete. Notes verified.</p>
                </div>
                <div class="action-bar">
                    <button type="button" class="btn-massive btn-secondary" style="width:100%; flex:none;" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'">Return to Schedule</button>
                </div>
            <?php endif; ?>

        </div>
    </ion-content>

    <script>
        lucide.createIcons();

        function handleMedCodeChange(select) {
            const reqNotes = ['R', 'N', 'NS', 'X'];
            const notesBox = document.getElementById('medNotesBox');
            const notesInput = document.getElementById('medNotesInput');
            if (reqNotes.includes(select.value)) {
                notesBox.style.display = 'block';
                notesInput.setAttribute('required', 'required');
            } else {
                notesBox.style.display = 'none';
                notesInput.removeAttribute('required');
                notesInput.value = '';
            }
        }

        function toggleTaskNote(id, isChecked) {
            const el = document.getElementById(id);
            el.style.display = isChecked ? 'block' : 'none';
            const textarea = el.querySelector('textarea');
            if (isChecked) textarea.setAttribute('required', 'required');
            else { textarea.removeAttribute('required'); textarea.value = ''; }
        }

        const moodBtns = document.querySelectorAll('.mood-btn');
        const moodInput = document.getElementById('moodInput');
        moodBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                moodBtns.forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                if(moodInput) moodInput.value = btn.getAttribute('data-mood');
            });
        });

        const toggleIncident = document.getElementById('toggleIncident');
        const toggleSafeguarding = document.getElementById('toggleSafeguarding');
        const incidentNoteInput = document.getElementById('incidentNoteInput');

        function handleEscalationToggle() {
            if (toggleIncident.checked || toggleSafeguarding.checked) {
                incidentNoteInput.style.display = 'block'; incidentNoteInput.setAttribute('required', 'required');
            } else {
                incidentNoteInput.style.display = 'none'; incidentNoteInput.removeAttribute('required'); incidentNoteInput.value = '';
            }
        }

        if (toggleIncident && toggleSafeguarding) {
            toggleIncident.addEventListener('ionChange', handleEscalationToggle);
            toggleSafeguarding.addEventListener('ionChange', handleEscalationToggle);
        }

        // ==========================================
        // CAPACITOR NATIVE BRIDGE & STRICT SCANNER
        // ==========================================
        let html5QrCode; 
        // PASS PHP DATA TO JS
        const clientHasQr = <?= !empty($visit['qr_reference_code']) ? 'true' : 'false' ?>;

        function validateForm() {
            const form = document.getElementById('clockOutForm');
            if (!form.checkValidity()) { form.reportValidity(); return false; }
            
            const summary = document.getElementById('generalSummaryInput').value.trim();
            const checkedTasks = document.querySelectorAll('input[name="tasks[]"]:checked').length;
            const medCodeInput = document.querySelector('select[name="med_outcome_code"]');
            const hasMedCode = medCodeInput ? medCodeInput.value !== '' : false;
            
            if (summary.length < 20 && checkedTasks === 0 && !hasMedCode) {
                alert("Documentation Audit: Notes lack sufficient detail. Please provide a longer summary or detail specific tasks performed.");
                return false;
            }
            return true;
        }

        // 1. NATIVE GEOLOCATION ENGINE
        async function getNativeLocation() {
            if (window.Capacitor && window.Capacitor.isNative && window.Capacitor.Plugins.Geolocation) {
                try {
                    const perm = await window.Capacitor.Plugins.Geolocation.requestPermissions();
                    if (perm.location !== 'granted') return null;

                    let bestPos = null;

                    for (let i = 0; i < 4; i++) {
                        try {
                            const pos = await window.Capacitor.Plugins.Geolocation.getCurrentPosition({
                                enableHighAccuracy: true,
                                timeout: 10000,
                                maximumAge: 0
                            });
                            
                            bestPos = pos;

                            if (pos.coords.accuracy <= 50) {
                                return { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy };
                            }
                            
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        } catch (err) {
                            console.warn("GPS Attempt " + (i + 1) + " failed: ", err);
                            await new Promise(resolve => setTimeout(resolve, 1500));
                        }
                    }
                    
                    if (bestPos) {
                        return { lat: bestPos.coords.latitude, lng: bestPos.coords.longitude, accuracy: bestPos.coords.accuracy, error: 'accuracy_too_low' };
                    }
                    return { error: 'accuracy_too_low', accuracy: 999 };
                    
                } catch(e) {
                    console.error("Native GPS Error:", e);
                    return null; 
                }
            } else if ("geolocation" in navigator) {
                return new Promise((resolve) => {
                    navigator.geolocation.getCurrentPosition(
                        (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
                        (e) => resolve(null),
                        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                    );
                });
            }
            return null;
        }

        async function openQRScanner(actionType) {
            if (actionType === 'out' && !validateForm()) return;
            pendingActionType = actionType;

            const loading = document.createElement('ion-loading');
            loading.message = 'Acquiring GPS Meta Data...';
            loading.spinner = 'crescent';
            document.body.appendChild(loading);
            await loading.present();

            let loc = await getNativeLocation();
            loading.dismiss();

            const latField = actionType === 'in' ? 'latIn' : 'latOut';
            const lngField = actionType === 'in' ? 'lngIn' : 'lngOut';
            
            // Log GPS silently as metadata
            if (loc && !loc.error) {
                document.getElementById(latField).value = loc.lat;
                document.getElementById(lngField).value = loc.lng;
            }

            // If the client has no QR code in the system, we bypass and rely on GPS (or fail if GPS is bad)
            if (!clientHasQr) {
                if (!loc || loc.error === 'accuracy_too_low') {
                    alert("GPS lock failed and no QR code is assigned to this client profile. Cannot verify visit securely.");
                    return;
                }
                // Only auto-submit if NO QR code exists for this client AND GPS is perfect
                if (actionType === 'in') document.getElementById('clockInForm').submit();
                else document.getElementById('clockOutForm').submit();
                return;
            }

            // ==========================================
            // STRICT CAMERA FORCING (NO FILE UPLOAD)
            // ==========================================
            // ALWAYS open the scanner if a QR code is required, regardless of GPS lock
            if (loc && loc.error === 'accuracy_too_low') {
                 alert("Notice: GPS accuracy is low (" + Math.round(loc.accuracy) + "m). Please scan the QR code to verify this visit.");
            } else if (!loc) {
                 alert("Notice: GPS could not be acquired. Please scan the QR code to verify this visit.");
            }

            document.getElementById('qrScannerModal').style.display = 'flex';
            
            html5QrCode = new Html5Qrcode("qr-reader");
            
            html5QrCode.start(
                { facingMode: "environment" }, 
                {
                    fps: 10,
                    qrbox: { width: 250, height: 250 },
                    aspectRatio: 1.0
                },
                onScanSuccess,
                onScanFailure
            ).catch((err) => {
                console.error("Camera start failed:", err);
                alert("Cannot access the camera. Please ensure camera permissions are granted in your device settings.");
                closeQRScanner();
            });
        }

        function onScanSuccess(decodedText, decodedResult) {
            // Stop camera securely
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                }).catch((e) => console.log("Failed to stop scanner", e));
            }
            
            document.getElementById('qrScannerModal').style.display = 'none';
            
            if (pendingActionType === 'in') {
                document.getElementById('qrIn').value = decodedText;
                document.getElementById('clockInForm').submit();
            } else {
                document.getElementById('qrOut').value = decodedText;
                document.getElementById('clockOutForm').submit();
            }
        }

        function onScanFailure(error) { /* Ignore background scanning errors (happens every frame) */ }

        function closeQRScanner() {
            // Stop camera securely
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                }).catch((e) => console.log("Failed to stop scanner", e));
            }
            document.getElementById('qrScannerModal').style.display = 'none';
        }
    </script>
</body>
</html>