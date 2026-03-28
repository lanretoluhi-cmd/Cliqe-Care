<?php
/**
 * Just in Time Group ERP - Carer Mobile App Assigned Clients
 * File Path: /careapp/clients.php
 * Features: Scoped Client List, CQC Critical Alerts (Top Priority), Core Information, and actionable Care Plan.
 */

declare(strict_types=1);

// Initialize Standalone Mobile App Engine
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

if ($clientId) {
    // =======================================================================================
    // DETAIL VIEW: SPECIFIC CLIENT PROFILE
    // =======================================================================================
    
    // SECURITY CHECK: Verify the carer is assigned to this client
    $isAssigned = $db->row("
        SELECT 1 FROM healthcare_roster_items ri
        JOIN healthcare_client_tasks t ON ri.task_id = t.id
        WHERE t.client_id = ? AND ri.carer_id = ? AND ri.tenant_id = ?
        LIMIT 1
    ", [$clientId, $userId, $tenantId]);

    if (!$isAssigned) {
        die("Security Alert: You are not authorized to view this service user's clinical file.");
    }

    // 1. Basic Profile
    $client = $db->row("SELECT *, TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) AS age FROM healthcare_clients WHERE id = ?", [$clientId]);
    
    // 2. Clinical Governance (CRITICAL CQC DATA)
    $govData = $db->row("SELECT * FROM healthcare_clinical_governance WHERE client_id = ? AND tenant_id = ?", [$clientId, $tenantId]);
    $riskFlagsArray = !empty($govData['risk_flags']) ? array_filter(array_map('trim', explode("\n", $govData['risk_flags']))) : [];
    
    // 3. Safeguarding Alerts (Active cases only)
    $safeguardingAlerts = [];
    try {
        $safeguardingAlerts = $db->query("SELECT incident_type, safeguarding_status FROM healthcare_client_incidents WHERE client_id = ? AND tenant_id = ? AND safeguarding_status IN ('Submitted', 'In Progress')", [$clientId, $tenantId]);
    } catch (Exception $e) { /* Ignore if table missing */ }
    
    // 4. Care Plan Elements
    $aboutMe = $db->row("SELECT * FROM cp_about_me WHERE client_id = ?", [$clientId]);
    $routines = $db->query("SELECT * FROM cp_routines WHERE client_id = ?", [$clientId]);
    $supportNeeds = $db->query("SELECT * FROM cp_support_needs WHERE client_id = ? ORDER BY created_at DESC", [$clientId]);
    $cpRisks = $db->query("SELECT * FROM cp_risks WHERE client_id = ? ORDER BY likelihood DESC", [$clientId]);
    
    // 5. Medical & System Alerts
    $medicalAlerts = $db->query("SELECT record_type, name, severity FROM healthcare_client_medical WHERE client_id = ? AND status = 'active'", [$clientId]);
    $clinicalMarkers = $db->query("SELECT label, marker_type as type, color_code FROM healthcare_client_markers WHERE client_id = ?", [$clientId]);
    
    // 6. Core Contacts (NOK & GP)
    $contacts = $db->query("SELECT * FROM healthcare_client_contacts WHERE client_id = ? ORDER BY created_at ASC", [$clientId]);
    $gp = [];
    try { $gp = $db->row("SELECT * FROM healthcare_client_gp WHERE client_id = ?", [$clientId]); } catch (Exception $e) {}
    
    // 7. Visit History (Specific to this Carer)
    $history = $db->query("
        SELECT ri.roster_date, ri.start_time, t.task_type, a.clock_in, a.clock_out 
        FROM healthcare_roster_items ri
        JOIN healthcare_client_tasks t ON ri.task_id = t.id
        LEFT JOIN attendance a ON ri.id = a.roster_item_id
        WHERE t.client_id = ? AND ri.carer_id = ? AND (a.status = 'completed' OR ri.roster_date < CURDATE())
        ORDER BY ri.roster_date DESC, ri.start_time DESC LIMIT 5
    ", [$clientId, $userId]);

} else {
    // =======================================================================================
    // LIST VIEW: MY ASSIGNED CLIENTS
    // =======================================================================================
    $myClients = $db->query("
        SELECT DISTINCT c.id, c.first_name, c.last_name, c.primary_address, c.postcode, c.gender, c.date_of_birth,
        TIMESTAMPDIFF(YEAR, c.date_of_birth, CURDATE()) AS age
        FROM healthcare_clients c
        JOIN healthcare_client_tasks t ON c.id = t.client_id
        JOIN healthcare_roster_items ri ON t.id = ri.task_id
        WHERE ri.carer_id = ? AND c.tenant_id = ? AND c.status = 'active'
        ORDER BY c.first_name ASC
    ", [$userId, $tenantId]);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <title><?= $clientId ? htmlspecialchars($client['first_name']) . ' | Profile' : 'My Clients' ?></title>
    
    <script type="module" src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.esm.js"></script>
    <script nomodule src="<?= CAREAPP_BASE_URL ?>assets/ionic/dist/ionic/ionic.js"></script>
    <link rel="stylesheet" href="<?= CAREAPP_BASE_URL ?>assets/ionic/css/ionic.bundle.css" />

    <link href="https://fonts.googleapis.com/css2?family=Urbanist:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        :root { 
            --ion-color-primary: #15c3ba;
            --ion-color-danger: #EF4444;
            --ion-color-warning: #F59E0B;
            --ion-font-family: 'Urbanist', sans-serif;
            --card-radius: 20px;
        }

        body { overscroll-behavior-y: none; }
        ion-content { --background: #F8FAFC; }
        
        /* HEADER */
        .app-header {
            background: white;
            padding: calc(env(safe-area-inset-top, 20px) + 15px) 20px 20px;
            display: flex; align-items: center; justify-content: space-between; gap: 15px;
            position: sticky; top: 0; z-index: 100;
            border-bottom: 1px solid #E2E8F0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }
        .btn-back { background: #F1F5F9; border: none; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #475569; cursor: pointer; }
        .header-title { font-size: 18px; font-weight: 900; color: #1E293B; }

        .content-pad { padding: 25px 20px 100px 20px; }

        /* LIST VIEW STYLES */
        .client-list-card {
            background: white; border-radius: var(--card-radius); padding: 20px; margin-bottom: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02); border: 1.5px solid #E2E8F0; text-decoration: none; display: flex; align-items: center; gap: 15px;
            transition: all 0.2s ease;
        }
        .client-list-card:active { transform: scale(0.97); background: #FAFBFC; }
        .c-avatar { width: 50px; height: 50px; border-radius: 16px; background: #EEF2FF; color: var(--ion-color-primary); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 900; flex-shrink: 0; }
        .c-info { flex: 1; }
        .c-info h3 { font-size: 16px; font-weight: 900; color: #1E293B; margin: 0 0 4px 0; }
        .c-info p { font-size: 12px; font-weight: 600; color: #64748B; margin: 0; display: flex; align-items: center; gap: 6px; }

        /* DETAIL VIEW STYLES */
        .profile-hero { text-align: center; margin-bottom: 25px; }
        .hero-avatar { width: 80px; height: 80px; margin: 0 auto 15px; border-radius: 24px; background: #EEF2FF; color: var(--ion-color-primary); font-size: 32px; font-weight: 900; display: flex; align-items: center; justify-content: center; border: 3px solid white; box-shadow: 0 10px 25px rgba(0,0,0,0.05); overflow: hidden; }
        .hero-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-hero h1 { font-size: 26px; font-weight: 900; color: #1E293B; margin: 0 0 5px 0; letter-spacing: -0.02em; }
        .profile-hero p { font-size: 14px; font-weight: 700; color: #64748B; margin: 0 0 15px 0; }

        /* SECTION BLOCKS */
        .section-block { background: white; border-radius: var(--card-radius); padding: 20px; border: 1.5px solid #E2E8F0; margin-bottom: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
        .section-header { font-size: 14px; font-weight: 900; color: #334155; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #F1F5F9; padding-bottom: 10px; }
        .section-header i { color: var(--ion-color-primary); width: 18px; height: 18px; }
        
        /* Critical Alerts Box */
        .critical-block { border-width: 2px; border-color: #EF4444; background: #FEF2F2; }
        .critical-block .section-header { color: #991B1B; border-bottom-color: #FECACA; }
        .critical-block .section-header i { color: #EF4444; }

        /* Key Value Pairs & Cards */
        .kv-row { display: flex; flex-direction: column; margin-bottom: 15px; padding-bottom:15px; border-bottom: 1px dashed #F1F5F9; }
        .kv-row:last-child { margin-bottom: 0; padding-bottom:0; border-bottom: none; }
        .kv-row label { font-size: 11px; font-weight: 900; color: #94A3B8; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.05em; display:flex; align-items:center; gap:6px;}
        .kv-row p { font-size: 15px; font-weight: 800; color: #1E293B; margin: 0; line-height: 1.5; }

        /* Nested Cards (For Risks & Needs) */
        .nested-card { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 15px; margin-bottom: 12px; }
        .nested-card:last-child { margin-bottom: 0; }
        .nc-title { font-size: 15px; font-weight: 900; color: #0F172A; margin: 0 0 10px 0; display:flex; align-items:center; gap:8px; }
        .nc-badge { padding: 4px 8px; border-radius: 6px; font-size: 9px; font-weight: 900; text-transform: uppercase; margin-left:auto; }
        .nc-badge.high { background:#FEF2F2; color:#EF4444; border:1px solid #FECACA;}
        .nc-badge.medium { background:#FFFBEB; color:#F59E0B; border:1px solid #FDE68A;}
        .nc-badge.low { background:#ECFDF5; color:#10B981; border:1px solid #A7F3D0;}
        .nc-text { font-size: 14px; font-weight: 600; color: #475569; line-height: 1.5; margin: 0 0 12px 0; }
        .nc-highlight { background: white; padding: 12px; border-radius: 8px; border: 1px solid #E2E8F0; font-size: 13px; font-weight: 700; color: #334155; margin-top:5px;}
        .nc-highlight.emergency { border-color: #FECACA; background: #FEF2F2; color: #991B1B; }

        /* Risk Alerts & Flags */
        .alert-pill { display: flex; align-items: flex-start; gap: 12px; padding: 14px; border-radius: 14px; margin-bottom: 12px; font-size: 14px; font-weight: 700; line-height:1.4; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .alert-pill.critical { background: #FEF2F2; color: #991B1B; border: 1.5px solid #FECACA; }
        .alert-pill.critical i { color: #EF4444; flex-shrink: 0;}
        .alert-pill.warning { background: #FFF7ED; color: #9A3412; border: 1.5px solid #FED7AA; }
        .alert-pill.warning i { color: #F59E0B; flex-shrink: 0;}

        /* Visit History */
        .history-item { display: flex; gap: 15px; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed #E2E8F0; }
        .history-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .h-time { width: 45px; font-size: 11px; font-weight: 800; color: #94A3B8; text-align: center; }
        .h-details h4 { font-size: 14px; font-weight: 900; color: #1E293B; margin: 0 0 4px 0; }
        .h-details p { font-size: 12px; font-weight: 600; color: #64748B; margin: 0; }

        /* TAB BAR */
        ion-tab-bar { --background: rgba(255,255,255,0.95); backdrop-filter: blur(15px); border-top: 1px solid #E2E8F0; padding-bottom: env(safe-area-inset-bottom); height: calc(65px + env(safe-area-bottom)); }
        ion-tab-button { --color: #94A3B8; --color-selected: var(--ion-color-primary); font-family: 'Urbanist', sans-serif; font-weight: 800; font-size: 11px; cursor: pointer; }
        ion-icon { font-size: 24px; margin-bottom: 4px; }
    </style>
</head>
<body>

    <div class="app-header">
        <?php if($clientId): ?>
            <button class="btn-back" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>clients'">
                <i data-lucide="arrow-left" style="width: 20px;"></i>
            </button>
            <div class="header-title">Clinical Profile</div>
            <div style="width:40px;"></div> <!-- Spacer for centering -->
        <?php else: ?>
            <div class="header-title" style="margin-left: 10px;">My Assigned Clients</div>
        <?php endif; ?>
    </div>

    <ion-app>
        <ion-content scroll-y="true">
            <div class="content-pad">
                
                <?php if (!$clientId): ?>
                    <!-- ========================================== -->
                    <!-- LIST VIEW: MY CLIENTS                      -->
                    <!-- ========================================== -->
                    <?php if (empty($myClients)): ?>
                        <div style="text-align: center; padding: 60px 30px; background: white; border-radius: var(--card-radius); border: 2px dashed #E2E8F0;">
                            <div style="width:60px; height:60px; background:#F8FAFC; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; color:#94A3B8;">
                                <i data-lucide="users" style="width:32px; height:32px;"></i>
                            </div>
                            <h3 style="font-size: 18px; font-weight: 900; color: #334155; margin: 0 0 6px 0;">No Assigned Clients</h3>
                            <p style="font-size: 14px; font-weight: 600; color: #94A3B8; margin: 0; line-height:1.4;">You currently do not have any service users allocated to your roster.</p>
                        </div>
                    <?php else: ?>
                        <div style="font-size:12px; font-weight:800; color:#94A3B8; text-transform:uppercase; margin-bottom:15px; letter-spacing:0.05em;">
                            <?= count($myClients) ?> Active Service Users
                        </div>
                        <?php foreach($myClients as $c): ?>
                            <a href="?id=<?= $c['id'] ?>" class="client-list-card">
                                <div class="c-avatar"><?= strtoupper(substr($c['first_name'], 0, 1)) ?></div>
                                <div class="c-info">
                                    <h3><?= strtoupper(htmlspecialchars($c['first_name'] . ' ' . $c['last_name'])) ?></h3>
                                    <p><?= $c['age'] ?> yrs • <?= htmlspecialchars($c['gender']) ?></p>
                                    <p style="margin-top:4px;"><i data-lucide="map-pin" style="width:12px;"></i> <?= htmlspecialchars($c['postcode'] ?: 'No postcode') ?></p>
                                </div>
                                <i data-lucide="chevron-right" style="color:#CBD5E1;"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- ========================================== -->
                    <!-- DETAIL VIEW: CLINICAL PROFILE              -->
                    <!-- ========================================== -->
                    
                    <div class="profile-hero">
                        <div class="hero-avatar">
                            <?php if(!empty($client['profile_photo'])): ?>
                                <img src="<?= BASE_URL . $client['profile_photo'] ?>" alt="Profile">
                            <?php else: ?>
                                <?= strtoupper(substr($client['first_name'], 0, 1)) ?>
                            <?php endif; ?>
                        </div>
                        <h1><?= strtoupper(htmlspecialchars($client['first_name'] . ' ' . $client['last_name'])) ?></h1>
                        <p>
                            <?= $client['age'] ?> years old • <?= htmlspecialchars($client['gender']) ?>
                            <?php if(!empty($client['nhs_number'])): ?><br>NHS: <strong><?= htmlspecialchars($client['nhs_number']) ?></strong><?php endif; ?>
                        </p>
                    </div>

                    <!-- 1. CRITICAL ALERTS (ALWAYS VISIBLE - TOP OF PROFILE) -->
                    <?php 
                    $hasGovAlerts = (!empty($govData) && ($govData['dnacpr_status'] === 'Active DNACPR' || $govData['emollient_risk_status'] === 'High' || !empty($riskFlagsArray)));
                    $hasMedicalAlerts = !empty($medicalAlerts) || !empty($clinicalMarkers);
                    $hasSafeguarding = !empty($safeguardingAlerts);
                    
                    if ($hasGovAlerts || $hasMedicalAlerts || $hasSafeguarding): 
                    ?>
                        <div class="section-block critical-block">
                            <div class="section-header"><i data-lucide="alert-triangle"></i> Critical Alerts (Read Immediately)</div>
                            
                            <!-- Safeguarding Alerts -->
                            <?php foreach($safeguardingAlerts as $sg): ?>
                                <div class="alert-pill critical">
                                    <i data-lucide="shield-alert"></i>
                                    <div>
                                        <strong>SAFEGUARDING ALERT</strong><br>
                                        Status: <?= htmlspecialchars($sg['safeguarding_status']) ?><br>
                                        <span style="font-size:12px; font-weight:600;">Related to: <?= htmlspecialchars($sg['incident_type']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- DNACPR & ReSPECT -->
                            <?php if(isset($govData['dnacpr_status']) && $govData['dnacpr_status'] === 'Active DNACPR'): ?>
                                <div class="alert-pill critical">
                                    <i data-lucide="heart-off"></i>
                                    <div>
                                        <strong>ACTIVE DNACPR / ReSPECT</strong><br>
                                        Do Not Resuscitate. 
                                        <?= !empty($govData['respect_form_location']) ? "Form loc: " . htmlspecialchars($govData['respect_form_location']) : "Check physical file." ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Medical / Allergies -->
                            <?php if (!empty($medicalAlerts)): foreach($medicalAlerts as $med): 
                                $sevClass = strtolower($med['severity']);
                                $icon = $med['record_type'] === 'Allergy' ? 'alert-octagon' : 'stethoscope';
                            ?>
                                <div class="alert-pill <?= $sevClass === 'critical' ? 'critical' : ($sevClass === 'high' ? 'warning' : 'critical') ?>">
                                    <i data-lucide="<?= $icon ?>"></i>
                                    <div><strong><?= htmlspecialchars($med['record_type']) ?>:</strong> <?= htmlspecialchars($med['name']) ?></div>
                                </div>
                            <?php endforeach; endif; ?>

                            <!-- Emollient Fire Risk -->
                            <?php if(isset($govData['emollient_risk_status']) && $govData['emollient_risk_status'] === 'High'): ?>
                                <div class="alert-pill warning">
                                    <i data-lucide="flame"></i>
                                    <div>
                                        <strong>HIGH FIRE RISK (Emollients)</strong><br>
                                        <?= htmlspecialchars($govData['emollient_details'] ?? 'Paraffin-based creams in use. No smoking/naked flames near bedding.') ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Custom Risk Flags -->
                            <?php foreach($riskFlagsArray as $flag): ?>
                                <div class="alert-pill critical">
                                    <i data-lucide="flag"></i>
                                    <div><strong>RISK FLAG:</strong> <?= htmlspecialchars($flag) ?></div>
                                </div>
                            <?php endforeach; ?>
                            
                        </div>
                    <?php endif; ?>

                    <!-- 2. CORE INFORMATION (AT A GLANCE) -->
                    <div class="section-block">
                        <div class="section-header"><i data-lucide="info"></i> Core Information</div>
                        
                        <div class="kv-row">
                            <label><i data-lucide="map-pin" style="width:14px;"></i> Primary Address</label>
                            <p><?= nl2br(htmlspecialchars($client['primary_address'] ?? 'Not provided')) ?><br><strong><?= htmlspecialchars($client['postcode']) ?></strong></p>
                        </div>
                        
                        <div class="kv-row">
                            <label><i data-lucide="stethoscope" style="width:14px;"></i> GP Details</label>
                            <?php if($gp): ?>
                                <p><strong><?= htmlspecialchars($gp['surgery_name']) ?></strong><br><?= htmlspecialchars($gp['gp_name']) ?></p>
                                <?php if(!empty($gp['phone'])): ?>
                                    <p style="margin-top:8px;"><a href="tel:<?= htmlspecialchars($gp['phone']) ?>" style="color:var(--ion-color-primary); text-decoration:none; display:flex; align-items:center; gap:6px;"><i data-lucide="phone" style="width:16px;"></i> <?= htmlspecialchars($gp['phone']) ?></a></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p style="color:#94A3B8; font-style:italic; font-size:13px;">No GP recorded.</p>
                            <?php endif; ?>
                        </div>

                        <div class="kv-row" style="border-bottom:none;">
                            <label><i data-lucide="users" style="width:14px;"></i> Key Contacts / Next of Kin</label>
                            <?php if($contacts): foreach($contacts as $c): ?>
                                <div style="background:#F8FAFC; padding:15px; border-radius:12px; border:1px solid #E2E8F0; margin-bottom:10px;">
                                    <p style="font-size:16px; font-weight:900; color:#1E293B; margin-bottom:4px;"><?= htmlspecialchars($c['contact_name']) ?></p>
                                    <p style="font-size:13px; font-weight:700; color:#64748B; margin-bottom:10px;">Relationship: <?= htmlspecialchars($c['relationship']) ?></p>
                                    <?php if(!empty($c['phone_mobile'])): ?>
                                        <a href="tel:<?= htmlspecialchars($c['phone_mobile']) ?>" style="display:inline-flex; align-items:center; justify-content:center; gap:8px; background:#10B981; color:white; padding:10px 16px; border-radius:10px; font-size:14px; font-weight:800; text-decoration:none; width:100%;">
                                            <i data-lucide="phone-call" style="width:18px;"></i> Call Contact
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; else: ?>
                                <p style="color:#94A3B8; font-style:italic; font-size:13px;">No emergency contacts recorded.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 3. CARE PLAN (HOW TO CARE) -->
                    <div class="section-block">
                        <div class="section-header"><i data-lucide="heart-handshake"></i> Care Plan (How to Care)</div>
                        
                        <?php if(!empty($aboutMe['communication_style'])): ?>
                            <div class="nested-card">
                                <h4 class="nc-title"><i data-lucide="message-circle" style="width:18px; color:#3B82F6;"></i> Communication Needs</h4>
                                <p class="nc-text"><?= nl2br(htmlspecialchars($aboutMe['communication_style'])) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if(empty($supportNeeds)): ?>
                            <p style="font-size:13px; color:#94A3B8; font-weight:600; font-style:italic;">No specific support needs documented yet.</p>
                        <?php else: ?>
                            <?php foreach($supportNeeds as $sn): 
                                $icon = 'check-circle';
                                $iconColor = 'var(--ion-color-primary)';
                                if ($sn['area'] === 'Personal Care') { $icon = 'droplet'; $iconColor = '#3B82F6'; }
                                if ($sn['area'] === 'Mobility') { $icon = 'activity'; $iconColor = '#F59E0B'; }
                                if ($sn['area'] === 'Nutrition') { $icon = 'utensils'; $iconColor = '#10B981'; }
                                if (strpos(strtolower($sn['area']), 'behav') !== false || strpos(strtolower($sn['area']), 'emotional') !== false) { $icon = 'brain'; $iconColor = '#8B5CF6'; }
                            ?>
                                <div class="nested-card">
                                    <h4 class="nc-title"><i data-lucide="<?= $icon ?>" style="width:18px; color:<?= $iconColor ?>;"></i> <?= htmlspecialchars($sn['area']) ?></h4>
                                    <p class="nc-text"><strong style="color:#EF4444; font-size:12px; text-transform:uppercase;">Guidelines / Support Required:</strong><br><span style="color:#1E293B;"><?= nl2br(htmlspecialchars($sn['support_needed'])) ?></span></p>
                                    <p class="nc-text" style="margin-bottom:0;"><strong style="color:#10B981; font-size:12px; text-transform:uppercase;">Client Can Do (Independence):</strong><br><span style="color:#1E293B;"><?= nl2br(htmlspecialchars($sn['client_can_do'])) ?></span></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- 4. POSITIVE RISKS & MITIGATIONS -->
                    <?php if(!empty($cpRisks)): ?>
                    <div class="section-block">
                        <div class="section-header"><i data-lucide="shield"></i> Risk Management Plan</div>
                        <?php foreach($cpRisks as $rk): 
                            $badgeClass = strtolower($rk['likelihood']) === 'high' ? 'high' : (strtolower($rk['likelihood']) === 'medium' ? 'medium' : 'low');
                        ?>
                            <div class="nested-card">
                                <h4 class="nc-title"><?= htmlspecialchars($rk['risk_name']) ?> <span class="nc-badge <?= $badgeClass ?>"><?= htmlspecialchars($rk['likelihood']) ?> Risk</span></h4>
                                <p class="nc-text"><strong style="color:#475569;">Support Strategy:</strong><br><span style="color:#1E293B;"><?= nl2br(htmlspecialchars($rk['support_plan'])) ?></span></p>
                                <?php if(!empty($rk['emergency_instruction'])): ?>
                                    <div class="nc-highlight emergency"><strong>EMERGENCY:</strong> <?= nl2br(htmlspecialchars($rk['emergency_instruction'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 5. DAILY ROUTINES -->
                    <?php if(!empty($routines)): ?>
                    <div class="section-block">
                        <div class="section-header"><i data-lucide="clock"></i> Daily Routines</div>
                        <?php foreach($routines as $r): ?>
                            <div class="kv-row" style="padding-bottom:10px; margin-bottom:10px;">
                                <label><?= htmlspecialchars($r['period']) ?> Routine</label>
                                <p style="font-weight:600; color:#334155;"><?= nl2br(htmlspecialchars($r['routine_details'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 6. VISIT HISTORY -->
                    <div class="section-block">
                        <div class="section-header"><i data-lucide="history"></i> My Recent Visits</div>
                        <?php if (empty($history)): ?>
                            <p style="font-size:13px; color:#94A3B8; font-weight:600; font-style:italic;">You have not completed any visits with this service user yet.</p>
                        <?php else: ?>
                            <?php foreach($history as $h): ?>
                                <div class="history-item">
                                    <div class="h-time">
                                        <div style="font-size:18px; font-weight:900; color:#1E293B; line-height:1;"><?= date('d', strtotime($h['roster_date'])) ?></div>
                                        <div style="font-size:10px; font-weight:800; text-transform:uppercase; margin-top:2px; color:#64748B;"><?= date('M', strtotime($h['roster_date'])) ?></div>
                                    </div>
                                    <div class="h-details">
                                        <h4 style="color:#1E293B; font-size:15px;"><?= htmlspecialchars($h['task_type']) ?></h4>
                                        <p style="color:#64748B;">Scheduled: <?= substr($h['start_time'], 0, 5) ?></p>
                                        <?php if($h['clock_in']): ?>
                                            <p style="color:var(--ion-color-primary); font-weight:800; margin-top:2px;">Clocked In: <?= date('H:i', strtotime($h['clock_in'])) ?></p>
                                        <?php else: ?>
                                            <p style="color:#EF4444; font-weight:800; margin-top:2px;">Missed / Not Logged</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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

            <!-- CLIENTS TAB -->
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

    <script>
        lucide.createIcons();
    </script>
</body>
</html>