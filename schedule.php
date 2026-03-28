<?php
/**
 * Just in Time Group ERP - Carer Mobile App Schedule
 * File Path: /careapp/schedule.php
 * Features: Interactive Calendar Strip, Multi-day Itinerary Viewing, Shift Details.
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

// 2. GET SELECTED DATE
// Defaults to today if no date is provided in the URL
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$displayDate = date('l, jS F Y', strtotime($selectedDate));

// 3. GENERATE CALENDAR STRIP (e.g., 3 days ago up to 14 days ahead)
$calendarDays = [];
$start = new DateTime('-3 days');
for ($i = 0; $i < 18; $i++) {
    $calendarDays[] = [
        'full_date' => $start->format('Y-m-d'),
        'day_name' => $start->format('D'),
        'day_num' => $start->format('d'),
        'is_today' => $start->format('Y-m-d') === date('Y-m-d'),
        'is_selected' => $start->format('Y-m-d') === $selectedDate
    ];
    $start->modify('+1 day');
}

// 4. FETCH SHIFTS FOR SELECTED DATE
$shifts = $db->query("
    SELECT ri.id as roster_id, ri.start_time, ri.end_time, ri.duration_minutes, ri.status as roster_status,
           t.task_type, t.description,
           c.id as client_id, c.first_name, c.last_name, c.primary_address, c.postcode,
           a.status as attendance_status, a.clock_in, a.clock_out
    FROM healthcare_roster_items ri
    JOIN healthcare_client_tasks t ON ri.task_id = t.id
    JOIN healthcare_clients c ON t.client_id = c.id
    LEFT JOIN attendance a ON ri.id = a.roster_item_id
    WHERE ri.carer_id = ? AND ri.roster_date = ? AND ri.tenant_id = ?
    ORDER BY ri.start_time ASC
", [$userId, $selectedDate, $tenantId]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <title>My Schedule | JIT Field Care</title>
    
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
            --card-radius: 20px;
        }

        body { overscroll-behavior-y: none; }
        ion-content { --background: #F8FAFC; }
        
        /* HEADER */
        .app-header {
            background: white;
            padding: calc(env(safe-area-inset-top, 20px) + 15px) 20px 20px;
            display: flex; align-items: center; gap: 15px;
            position: sticky; top: 0; z-index: 100;
        }
        .btn-back { background: #F1F5F9; border: none; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #475569; cursor: pointer; }
        .header-title { font-size: 18px; font-weight: 900; color: #1E293B; }

        /* CALENDAR STRIP */
        .calendar-strip-container {
            background: white;
            padding: 10px 0 25px 0;
            border-bottom: 1.5px solid #E2E8F0;
            margin-bottom: 25px;
        }
        .calendar-scroll {
            display: flex; gap: 12px; overflow-x: auto; padding: 0 20px; scrollbar-width: none;
            scroll-behavior: smooth;
        }
        .calendar-scroll::-webkit-scrollbar { display: none; }
        
        .cal-day {
            flex-shrink: 0; width: 65px; height: 85px; border-radius: 16px; border: 1.5px solid #E2E8F0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: white; text-decoration: none; transition: 0.2s;
            position: relative;
        }
        .cal-day.today { border-color: var(--ion-color-primary); }
        .cal-day.today::after { content: ''; position: absolute; bottom: 8px; width: 6px; height: 6px; border-radius: 50%; background: var(--ion-color-primary); }
        
        .cal-day.selected { background: var(--ion-color-primary); border-color: var(--ion-color-primary); color: white; box-shadow: 0 10px 20px rgba(21, 195, 186, 0.3); }
        .cal-day.selected.today::after { background: white; }

        .day-name { font-size: 12px; font-weight: 800; color: #94A3B8; margin-bottom: 4px; text-transform: uppercase; }
        .cal-day.selected .day-name { color: rgba(255,255,255,0.9); }
        
        .day-num { font-size: 22px; font-weight: 900; color: #1E293B; line-height: 1; }
        .cal-day.selected .day-num { color: white; }

        /* ITINERARY FEED */
        .content-pad { padding: 0 20px 100px 20px; }
        .date-heading { font-size: 16px; font-weight: 900; color: #334155; margin: 0 0 20px 0; display: flex; align-items: center; gap: 8px; }

        .visit-card {
            background: white; border-radius: var(--card-radius); padding: 25px; margin-bottom: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.02); border: 1.5px solid #E2E8F0; text-decoration: none; display: block;
            transition: all 0.2s ease;
        }
        .visit-card:active { transform: scale(0.97); background: #FAFBFC; }

        .v-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
        .v-time { font-size: 20px; font-weight: 900; color: #1E293B; letter-spacing: -0.02em; }
        .v-time span { font-size: 12px; font-weight: 700; color: #94A3B8; margin-left: 6px; }
        
        .v-status { font-size: 10px; font-weight: 900; text-transform: uppercase; padding: 5px 12px; border-radius: 100px; letter-spacing: 0.04em; }
        .v-status.planned { background: #F1F5F9; color: #64748B; }
        .v-status.arrived { background: #FFF7ED; color: #EA580C; border: 1px solid #FDBA74; }
        .v-status.completed { background: #E9FBF0; color: #10B981; }

        .v-client { font-size: 18px; font-weight: 800; color: #1E293B; margin-bottom: 6px; }
        .v-address { font-size: 13px; font-weight: 600; color: #64748B; display: flex; align-items: flex-start; gap: 8px; line-height: 1.5; margin-bottom: 20px; }
        
        .v-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #E2E8F0; padding-top: 18px; }
        .v-type { font-size: 12px; font-weight: 800; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.02em; }
        .v-type.med { color: #DB2777; }
        .v-type.task { color: var(--ion-color-primary); }

        .v-action { background: #F1F5F9; color: var(--ion-color-primary); padding: 10px 20px; border-radius: 100px; font-size: 13px; font-weight: 800; }
        
        /* TAB BAR */
        ion-tab-bar { --background: rgba(255,255,255,0.95); backdrop-filter: blur(15px); border-top: 1px solid #E2E8F0; padding-bottom: env(safe-area-inset-bottom); height: calc(65px + env(safe-area-bottom)); }
        ion-tab-button { --color: #94A3B8; --color-selected: var(--ion-color-primary); font-family: 'Urbanist', sans-serif; font-weight: 800; font-size: 11px; cursor: pointer; }
        ion-icon { font-size: 24px; margin-bottom: 4px; }
    </style>
</head>
<body>

    <div class="app-header">
        <button class="btn-back" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'">
            <i data-lucide="arrow-left" style="width: 20px;"></i>
        </button>
        <div class="header-title">Calendar & Roster</div>
    </div>

    <div class="calendar-strip-container">
        <div class="calendar-scroll" id="calendarStrip">
            <?php foreach($calendarDays as $day): ?>
                <a href="?date=<?= $day['full_date'] ?>" 
                   class="cal-day <?= $day['is_today'] ? 'today' : '' ?> <?= $day['is_selected'] ? 'selected' : '' ?>"
                   id="<?= $day['is_selected'] ? 'selectedDay' : '' ?>">
                    <span class="day-name"><?= $day['day_name'] ?></span>
                    <span class="day-num"><?= $day['day_num'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <ion-app>
        <ion-content scroll-y="true">
            <div class="content-pad">
                <h2 class="date-heading"><i data-lucide="calendar-check-2" style="color:var(--ion-color-primary);"></i> <?= $displayDate ?></h2>

                <?php if (empty($shifts)): ?>
                    <div style="text-align: center; padding: 60px 30px; background: white; border-radius: var(--card-radius); border: 2px dashed #E2E8F0;">
                        <div style="width:60px; height:60px; background:#F8FAFC; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; color:#94A3B8;">
                            <i data-lucide="calendar-off" style="width:32px; height:32px;"></i>
                        </div>
                        <h3 style="font-size: 18px; font-weight: 900; color: #334155; margin: 0 0 6px 0;">No shifts scheduled</h3>
                        <p style="font-size: 14px; font-weight: 600; color: #94A3B8; margin: 0; line-height:1.4;">You are not allocated to any visits on this day.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($shifts as $s): 
                        $status = $s['attendance_status'] ?? $s['roster_status'] ?? 'planned';
                        if ($status === 'in_progress') $status = 'arrived';
                        
                        $isMed = $s['task_type'] === 'Medication';
                    ?>
                        <a href="<?= CAREAPP_BASE_URL ?>visit?id=<?= $s['roster_id'] ?>" class="visit-card">
                            <div class="v-header">
                                <div class="v-time">
                                    <?= substr($s['start_time'], 0, 5) ?> 
                                    <span><?= $s['duration_minutes'] ?>m</span>
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
                                    <div class="v-action">View Details</div>
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
            
        </ion-content>
        
        <!-- NATIVE TAB NAVIGATION -->
        <ion-tab-bar slot="bottom">
            <ion-tab-button tab="schedule" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'" selected="true">
                <ion-icon name="calendar-outline"></ion-icon>
                <ion-label>Today</ion-label>
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

        // Auto-center the selected date in the horizontal scroll view
        window.addEventListener('DOMContentLoaded', () => {
            const selectedDay = document.getElementById('selectedDay');
            const strip = document.getElementById('calendarStrip');
            
            if (selectedDay && strip) {
                // Calculate scroll position to center the item
                const scrollPos = selectedDay.offsetLeft - (strip.offsetWidth / 2) + (selectedDay.offsetWidth / 2);
                strip.scrollTo({ left: scrollPos, behavior: 'smooth' });
            }
        });
    </script>
</body>
</html>