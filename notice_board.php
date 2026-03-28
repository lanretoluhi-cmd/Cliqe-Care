<?php
/**
 * Just in Time Group ERP - Carer Mobile App Notice Board
 * File Path: /careapp/notice_board.php
 * Features: Full Archive of Broadcasts & Announcements.
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

// 2. AJAX HANDLER: MARK ANNOUNCEMENT AS SEEN
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

// 3. FETCH ALL ANNOUNCEMENTS (Full History)
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
    ", [$userId, $tenantId]);
} catch (Exception $e) { /* Ignore if tables are missing */ }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#15c3ba">
    <title>Notice Board | JIT Field Care</title>
    
    <!-- Ionic Framework -->
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
        
        .app-header {
            background: white;
            padding: calc(env(safe-area-inset-top, 20px) + 15px) 20px 20px;
            display: flex; align-items: center; gap: 15px;
            position: sticky; top: 0; z-index: 100; border-bottom: 1px solid #E2E8F0;
        }
        .btn-back { background: #F1F5F9; border: none; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #475569; cursor: pointer; }
        .header-title { font-size: 18px; font-weight: 900; color: #1E293B; }

        .content-pad { padding: 25px 20px 100px; }

        .ann-list { display: flex; flex-direction: column; gap: 15px; }
        .ann-card { background: white; border-radius: 20px; padding: 20px; border: 1.5px solid #E2E8F0; display: flex; align-items: flex-start; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); position: relative; cursor: pointer; transition: 0.2s; }
        .ann-card:active { transform: scale(0.97); background: #FAFBFC; }
        .ann-card.unread { border-color: var(--ion-color-primary); background: #F0FDFA; }
        
        .ann-icon { width: 45px; height: 45px; border-radius: 12px; background: #F1F5F9; color: var(--ion-color-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        
        .ann-content { flex: 1; }
        .ann-content h4 { font-size: 15px; font-weight: 900; color: #1E293B; margin: 0 0 6px 0; line-height: 1.3; }
        .ann-meta { font-size: 11px; font-weight: 700; color: #94A3B8; display: flex; align-items: center; gap: 8px; }
        .ann-badge { background: #E2E8F0; color: #475569; padding: 2px 8px; border-radius: 6px; font-size: 9px; font-weight: 800; text-transform: uppercase; }

        .unread-dot { position: absolute; top: 20px; right: 20px; width: 10px; height: 10px; background: #EF4444; border-radius: 50%; box-shadow: 0 0 0 3px white; }

        .empty-state { text-align: center; padding: 60px 20px; border: 2px dashed #E2E8F0; border-radius: 20px; }
        .empty-state h3 { font-size: 18px; font-weight: 900; color: #334155; margin-top: 15px; margin-bottom: 5px; }
        .empty-state p { font-size: 13px; font-weight: 600; color: #94A3B8; }

        /* MODAL OVERLAY */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 20px; opacity: 0; pointer-events: none; transition: opacity 0.3s; }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .modal-content { background: white; border-radius: 24px; padding: 30px; width: 100%; max-width: 400px; max-height: 80vh; overflow-y: auto; transform: translateY(20px); transition: transform 0.3s; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .modal-overlay.active .modal-content { transform: translateY(0); }
        .modal-title { font-size: 18px; font-weight: 900; color: #1E293B; margin: 0 0 8px 0; line-height: 1.4; }
        .modal-date { font-size: 12px; font-weight: 700; color: #94A3B8; margin: 0 0 20px 0; display: flex; align-items: center; gap: 6px; }
        .modal-body { font-size: 14px; font-weight: 600; color: #475569; line-height: 1.6; white-space: pre-wrap; margin-bottom: 25px; padding-top: 20px; border-top: 1px solid #F1F5F9; }
        .btn-modal-close { width: 100%; background: #F1F5F9; color: #475569; padding: 16px; border-radius: 14px; border: none; font-size: 14px; font-weight: 800; cursor: pointer; }
    </style>
</head>
<body>

    <!-- ANNOUNCEMENT READING MODAL -->
    <div id="announcementModal" class="modal-overlay" onclick="if(event.target === this) closeAnnouncementModal()">
        <div class="modal-content">
            <h2 class="modal-title" id="modalAnnTitle">Title</h2>
            <p class="modal-date" id="modalAnnDate"><i data-lucide="clock" style="width:12px;"></i> Date</p>
            <div class="modal-body" id="modalAnnBody">Content goes here...</div>
            <button class="btn-modal-close" onclick="closeAnnouncementModal()">Dismiss</button>
        </div>
    </div>

    <div class="app-header">
        <button class="btn-back" onclick="window.location.href='<?= CAREAPP_BASE_URL ?>dashboard'">
            <i data-lucide="arrow-left" style="width: 20px;"></i>
        </button>
        <div class="header-title">Notice Board</div>
    </div>

    <ion-content>
        <div class="content-pad">
            
            <?php if (empty($announcements)): ?>
                <div class="empty-state">
                    <i data-lucide="megaphone" style="width: 48px; height: 48px; color: #CBD5E1;"></i>
                    <h3>No Announcements</h3>
                    <p>There are no active broadcasts or notices at this time.</p>
                </div>
            <?php else: ?>
                <div class="ann-list">
                    <?php foreach($announcements as $ann): ?>
                        <div class="ann-card <?= !$ann['has_seen'] ? 'unread' : '' ?>" id="card-<?= $ann['id'] ?>" onclick="openAnnouncement('<?= $ann['id'] ?>')">
                            <div class="ann-icon"><i data-lucide="megaphone"></i></div>
                            <div class="ann-content">
                                <h4><?= htmlspecialchars($ann['title']) ?></h4>
                                <div class="ann-meta">
                                    <span class="ann-badge"><?= htmlspecialchars($ann['category']) ?></span>
                                    <span><?= date('D j M • H:i', strtotime($ann['created_at'])) ?></span>
                                </div>
                            </div>
                            <?php if(!$ann['has_seen']): ?>
                                <div class="unread-dot" id="unread-dot-<?= $ann['id'] ?>"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </ion-content>

    <script>
        lucide.createIcons();

        // Pass PHP Announcements to JS for the Modal content
        const annData = <?= json_encode($announcements) ?>;

        function openAnnouncement(id) {
            const ann = annData.find(a => a.id === id);
            if (!ann) return;
            
            document.getElementById('modalAnnTitle').textContent = ann.title;
            // Format Date
            const d = new Date(ann.created_at);
            document.getElementById('modalAnnDate').innerHTML = `<i data-lucide="clock" style="width:12px;"></i> ${d.toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })}`;
            document.getElementById('modalAnnBody').textContent = ann.content;
            
            lucide.createIcons();
            
            // Show Modal
            document.getElementById('announcementModal').classList.add('active');

            // Trigger Mark as Seen via Background AJAX
            if (parseInt(ann.has_seen) === 0) {
                const fd = new FormData();
                fd.append('action', 'mark_announcement_seen');
                fd.append('announcement_id', id);
                
                fetch('<?= CAREAPP_BASE_URL ?>notice_board', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const dot = document.getElementById('unread-dot-' + id);
                        if (dot) dot.remove();
                        document.getElementById('card-' + id).classList.remove('unread');
                        ann.has_seen = 1; // Update local state
                    }
                });
            }
        }

        function closeAnnouncementModal() {
            document.getElementById('announcementModal').classList.remove('active');
        }
    </script>
</body>
</html>