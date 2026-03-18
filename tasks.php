<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require_login();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$uid    = current_user_id();
$action = $_POST['action'] ?? '';
$flash  = '';
$error  = '';

// ── Actions ──────────────────────────────────────────────────────────────────
try {
    // Mark done
    if ($action === 'mark_done') {
        $note_id = (int)$_POST['note_id'];
        $pdo->prepare("UPDATE public.interested_notes SET status='done', done_at=NOW(), updated_at=NOW()
            WHERE id=:id AND (user_id=:uid OR :admin=TRUE)")
            ->execute([':id'=>$note_id,':uid'=>$uid,':admin'=>is_admin()?'TRUE':'FALSE']);
        $flash = "Marked as done.";
    }

    // Reschedule
    if ($action === 'reschedule') {
        $note_id = (int)$_POST['note_id'];
        $add_days = max(1, (int)($_POST['add_days'] ?? 1));
        $pdo->prepare("UPDATE public.interested_notes
            SET followup_date = COALESCE(followup_date, CURRENT_DATE) + :days::INT,
                followup_days = :days,
                updated_at = NOW()
            WHERE id=:id AND (user_id=:uid OR :admin=TRUE)")
            ->execute([':days'=>$add_days,':id'=>$note_id,':uid'=>$uid,':admin'=>is_admin()?'TRUE':'FALSE']);
        $flash = "Rescheduled by {$add_days} day(s).";
    }

    // Save note from panel
    if ($action === 'save_note') {
        $note_id   = (int)$_POST['note_id'];
        $notes     = trim((string)($_POST['notes'] ?? ''));
        $fw_method = in_array($_POST['followup_method'] ?? '', ['Call','Email']) ? $_POST['followup_method'] : '';
        $fw_days   = max(0, (int)($_POST['followup_days'] ?? 0));
        $fw_date   = $fw_days > 0 ? date('Y-m-d', strtotime("+{$fw_days} days")) : null;
        $pdo->prepare("UPDATE public.interested_notes
            SET notes=:notes, followup_method=:fm, followup_days=:fd, followup_date=:fdate,
                notes_updated_by=:nby, notes_updated_at=NOW(), updated_at=NOW()
            WHERE id=:id AND (user_id=:uid OR :admin=TRUE)")
            ->execute([':notes'=>$notes,':fm'=>$fw_method,':fd'=>$fw_days,':fdate'=>$fw_date,
                       ':nby'=>current_username(),
                       ':id'=>$note_id,':uid'=>$uid,':admin'=>is_admin()?'TRUE':'FALSE']);
        $flash = "Notes saved.";
    }

    // Mark lead as viewed
    if ($action === 'mark_viewed') {
        $lead_id = (int)$_POST['lead_id'];
        $pdo->prepare("INSERT INTO public.lead_views (user_id, lead_id, viewed_at)
            VALUES (:uid, :lid, NOW())
            ON CONFLICT (user_id, lead_id) DO UPDATE SET viewed_at = NOW()")
            ->execute([':uid'=>$uid, ':lid'=>$lead_id]);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Post a new chat note
    if ($action === 'post_note') {
        $note_id  = (int)$_POST['note_id'];
        $lead_id  = (int)$_POST['lead_id'];
        $note_txt = trim((string)($_POST['note_txt'] ?? ''));
        if ($note_txt !== '') {
            $pdo->prepare("INSERT INTO public.lead_notes (lead_id,user_id,username,note)
                VALUES (:lid,:uid,:uname,:note)")
                ->execute([':lid'=>$lead_id,':uid'=>$uid,':uname'=>current_username(),':note'=>$note_txt]);
        }
        $fw_method = in_array($_POST['followup_method'] ?? '', ['Call','Email']) ? $_POST['followup_method'] : '';
        $fw_days   = max(0, (int)($_POST['followup_days'] ?? 0));
        $fw_date   = $fw_days > 0 ? date('Y-m-d', strtotime("+{$fw_days} days")) : null;
        $pdo->prepare("UPDATE public.interested_notes
            SET followup_method=:fm, followup_days=:fd, followup_date=:fdate, updated_at=NOW()
            WHERE id=:id")
            ->execute([':fm'=>$fw_method,':fd'=>$fw_days,':fdate'=>$fw_date,':id'=>$note_id]);
        $flash = "Note added.";
    }

} catch (Throwable $e) { $error = "Error: " . $e->getMessage(); }

$flash = $flash ?: ($_GET['flash'] ?? '');

// ── Load all active tasks with due dates ─────────────────────────────────────
$admin_filter = is_admin() ? "" : "AND n.user_id = {$uid}";
$today = date('Y-m-d');
$in7   = date('Y-m-d', strtotime('+7 days'));

$all_tasks = [];
try {
    $all_tasks = $pdo->query("
        SELECT n.id, n.lead_id, n.campaign_id, n.user_id, n.notes, n.followup_method, n.followup_days, n.followup_date, n.status, n.created_at, n.updated_at, n.notes_updated_by, n.notes_updated_at, l.phone, l.business_name,
               c.campaign_code, c.name AS camp_name,
               u.username AS agent, 'interested' AS source
        FROM public.interested_notes n
        JOIN public.leads l ON l.id = n.lead_id
        LEFT JOIN public.campaigns c ON c.id = n.campaign_id
        LEFT JOIN public.users u ON u.id = n.user_id
        WHERE n.status IN ('active','paused')
          AND n.followup_date IS NOT NULL
          {$admin_filter}
        UNION ALL
        SELECT n.id, n.lead_id, n.campaign_id, n.user_id, n.notes, n.followup_method, n.followup_days, n.followup_date, n.status, n.created_at, n.updated_at, n.notes_updated_by, n.notes_updated_at, l.phone, l.business_name,
               c.campaign_code, c.name AS camp_name,
               u.username AS agent, 'callback' AS source
        FROM public.callback_notes n
        JOIN public.leads l ON l.id = n.lead_id
        LEFT JOIN public.campaigns c ON c.id = n.campaign_id
        LEFT JOIN public.users u ON u.id = n.user_id
        WHERE n.status IN ('active','paused')
          AND n.followup_date IS NOT NULL
          {$admin_filter}
        ORDER BY followup_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $error = "Error loading tasks: " . $e->getMessage(); }

// ── Also load all upcoming (no date limit) separate ──────────────────────────
$no_date = [];
try {
    $no_date = $pdo->query("
        SELECT n.id, n.lead_id, n.campaign_id, n.user_id, n.notes, n.followup_method, n.followup_days, n.followup_date, n.status, n.created_at, n.updated_at, n.notes_updated_by, n.notes_updated_at, l.phone, l.business_name,
               c.campaign_code, c.name AS camp_name,
               u.username AS agent, 'interested' AS source
        FROM public.interested_notes n
        JOIN public.leads l ON l.id = n.lead_id
        LEFT JOIN public.campaigns c ON c.id = n.campaign_id
        LEFT JOIN public.users u ON u.id = n.user_id
        WHERE n.status IN ('active','paused')
          AND n.followup_date IS NULL
          {$admin_filter}
        UNION ALL
        SELECT n.id, n.lead_id, n.campaign_id, n.user_id, n.notes, n.followup_method, n.followup_days, n.followup_date, n.status, n.created_at, n.updated_at, n.notes_updated_by, n.notes_updated_at, l.phone, l.business_name,
               c.campaign_code, c.name AS camp_name,
               u.username AS agent, 'callback' AS source
        FROM public.callback_notes n
        JOIN public.leads l ON l.id = n.lead_id
        LEFT JOIN public.campaigns c ON c.id = n.campaign_id
        LEFT JOIN public.users u ON u.id = n.user_id
        WHERE n.status IN ('active','paused')
          AND n.followup_date IS NULL
          {$admin_filter}
        ORDER BY updated_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Load chat notes ─────────────────────────────────────────────────────────
$all_lead_ids = array_merge(
    array_column($all_tasks, 'id') ? array_column($all_tasks, 'lead_id') : [],
    array_column($no_date,   'lead_id')
);
// collect all lead_ids from tasks
$all_lead_ids = [];
foreach (array_merge($all_tasks, $no_date) as $t) $all_lead_ids[] = (int)$t['lead_id'];
$chat_notes = [];
if (!empty($all_lead_ids)) {
    $in = implode(',', array_unique($all_lead_ids));
    try {
        $rows = $pdo->query("SELECT * FROM public.lead_notes WHERE lead_id IN ($in) ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $chat_notes[(int)$r['lead_id']][] = $r;
    } catch (Throwable $e) {}
}

// ── Load unread status ───────────────────────────────────────────────────────
$viewed_map = [];
try {
    $vrows = $pdo->query("SELECT lead_id, MAX(viewed_at) AS viewed_at FROM public.lead_views WHERE user_id = {$uid} GROUP BY lead_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vrows as $vr) $viewed_map[(int)$vr['lead_id']] = $vr['viewed_at'];
} catch (Throwable $e) {}

$unread_map = [];
foreach (array_merge($all_tasks, $no_date) as $t) {
    $lid = (int)$t['lead_id'];
    $notes_for = $chat_notes[$lid] ?? [];
    if (empty($notes_for)) continue;
    $others_notes = array_values(array_filter($notes_for, function($n) use ($uid) {
        return (int)$n['user_id'] !== $uid;
    }));
    if (empty($others_notes)) continue;
    $latest_note = $others_notes[count($others_notes)-1]['created_at'];
    $last_viewed = $viewed_map[$lid] ?? null;
    if (!$last_viewed || $latest_note > $last_viewed) $unread_map[$lid] = true;
}

// ── Bucket into sections ──────────────────────────────────────────────────────
$overdue  = [];
$due_today= [];
$due_7    = [];
$upcoming = [];

foreach ($all_tasks as $t) {
    $d = $t['followup_date'];
    if ($d < $today)       $overdue[]   = $t;
    elseif ($d === $today) $due_today[] = $t;
    elseif ($d <= $in7)    $due_7[]     = $t;
    else                   $upcoming[]  = $t;
}

function render_task_row(array $t, string $section, bool $is_admin_view): string {
    global $unread_map;
    $id      = (int)$t['id'];
    $lid     = (int)$t['lead_id'];
    $has_unread = !empty($unread_map[$lid]);
    $phone   = h((string)$t['phone']);
    $biz     = $t['business_name'] ? h((string)$t['business_name']) : '<span style="color:#94a3b8">—</span>';
    $code    = h((string)($t['campaign_code'] ?? '—'));
    $agent   = h((string)($t['agent'] ?? '—'));
    $method  = $t['followup_method'] ? h((string)$t['followup_method']) : '—';
    $due     = $t['followup_date'] ? date('d M Y', strtotime($t['followup_date'])) : '—';
    $notes   = $t['notes'] ? h(mb_strimwidth((string)$t['notes'], 0, 25, '…')) : '<span style="color:#94a3b8;font-style:italic;">No notes yet</span>';
    $paused  = $t['status'] === 'paused';
    $agent_col = $is_admin_view ? "<td style='font-size:12px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>{$agent}</td>" : '';
    $colspan   = $is_admin_view ? 8 : 7;

    ob_start(); ?>
    <tr style="<?= $paused?'opacity:.55;':'' ?><?= $has_unread?'background:#fffbeb;':'' ?>" onclick="openPanel(<?= $id ?>)" title="Click to edit">
        <td style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <span class="unread-dot" style="display:inline-block;width:8px;height:8px;background:<?= $has_unread ? '#ef4444' : 'transparent' ?>;border-radius:50%;margin-right:5px;vertical-align:middle;flex-shrink:0;"></span><?= $biz ?>
        </td>
        <?= $agent_col ?>
        <td style="font-size:12px;color:#64748b;"><?= $method ?></td>
        <td style="font-size:12px;font-weight:700;"><?= $due ?></td>
        <td style="font-size:12px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;"><?= $notes ?></td>
        <td onclick="event.stopPropagation()" style="white-space:nowrap;">
            <div style="display:flex;gap:3px;align-items:center;flex-wrap:nowrap;">
                <!-- Edit -->
                <button type="button" class="icon-btn ib-edit" title="Edit" onclick="event.stopPropagation();openPanel(<?= $id ?>)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <!-- Mark done -->
                <form method="post" style="display:contents;" onsubmit="return confirm('Mark as done?');">
                    <input type="hidden" name="action" value="mark_done">
                    <input type="hidden" name="note_id" value="<?= $id ?>">
                    <button type="submit" class="icon-btn ib-done" title="Mark done">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                </form>
                <!-- Reschedule dropdown -->
                <div style="position:relative;display:inline-block;" class="resched-wrap">
                    <button type="button" class="icon-btn ib-reschedule" title="Reschedule" onclick="toggleResched(this)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </button>
                    <div class="resched-menu" style="display:none;position:absolute;right:0;top:36px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:50;min-width:120px;overflow:hidden;">
                        <?php foreach ([1,3,7,14] as $days): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="reschedule">
                            <input type="hidden" name="note_id" value="<?= $id ?>">
                            <input type="hidden" name="add_days" value="<?= $days ?>">
                            <button type="submit" style="width:100%;padding:9px 14px;background:none;border:none;cursor:pointer;font-size:12px;font-weight:600;color:#334155;text-align:left;transition:background .1s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">+<?= $days ?> day<?= $days>1?'s':'' ?></button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </td>
    </tr>
    <?php return ob_get_clean();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Due Tasks</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root {
    --blue:#2563eb; --blue-lt:#eff6ff;
    --green:#10b981; --green-lt:#ecfdf5;
    --red:#ef4444;   --red-lt:#fef2f2;
    --yellow:#f59e0b;--yel-lt:#fffbeb;
    --gray:#64748b;  --border:#e2e8f0;
    --bg:#f8fafc;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:#1e293b;padding:28px 20px;}
.container{max-width:1100px;margin:auto;}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:10px;}
.topbar-title{font-size:20px;font-weight:700;text-decoration:none;color:inherit;}
.topbar-links{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--gray);}
.tl-btn{font-size:12px;font-weight:600;padding:5px 11px;border-radius:6px;text-decoration:none;border:1px solid;transition:all .12s;}
.tl-blue{color:var(--blue);border-color:#bfdbfe;background:var(--blue-lt);}
.tl-blue:hover{background:#dbeafe;}
.tl-green{color:#065f46;border-color:#a7f3d0;background:var(--green-lt);}
.tl-green:hover{background:#d1fae5;}
.tl-orange{color:#9a3412;border-color:#fed7aa;background:var(--orange-lt);}
.tl-orange:hover{background:#fed7aa;}
.tl-red{color:var(--red);border-color:#fecaca;background:var(--red-lt);}
.tl-red:hover{background:#fee2e2;}
.flash{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;font-weight:500;}
.flash.ok{background:var(--green-lt);color:#065f46;border:1px solid #a7f3d0;}
.flash.err{background:var(--red-lt);color:#991b1b;border:1px solid #fecaca;}

/* SECTION */
.section{margin-bottom:24px;}
.section-hd{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.section-title{font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;}
.section-count{font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;}
.s-overdue  .section-title{color:#991b1b;}
.s-overdue  .section-count{background:var(--red-lt);color:#991b1b;border:1px solid #fecaca;}
.s-today    .section-title{color:#1d4ed8;}
.s-today    .section-count{background:var(--blue-lt);color:#1d4ed8;border:1px solid #bfdbfe;}
.s-week     .section-title{color:#92400e;}
.s-week     .section-count{background:var(--yel-lt);color:#92400e;border:1px solid #fde68a;}
.s-upcoming .section-title{color:var(--gray);}
.s-upcoming .section-count{background:#f1f5f9;color:var(--gray);border:1px solid var(--border);}
.s-nodate   .section-title{color:var(--gray);}
.s-nodate   .section-count{background:#f1f5f9;color:var(--gray);border:1px solid var(--border);}

/* CARD / TABLE */
.card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.s-overdue  .card{border-color:#fecaca;}
.s-today    .card{border-color:#bfdbfe;}
table{width:100%;border-collapse:collapse;table-layout:fixed;}
th{text-align:left;padding:9px 13px;background:#f8fafc;color:var(--gray);font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:700;border-bottom:1px solid var(--border);}
td{padding:11px 13px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tbody tr{cursor:pointer;transition:background .08s;}
tbody tr:hover td{background:#fafbfc;}
.empty{text-align:center;padding:32px;color:var(--gray);font-size:12px;}

/* ICON BTNS */
.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid transparent;cursor:pointer;transition:all .12s;background:none;padding:0;flex-shrink:0;}
.ib-edit{color:var(--gray);border-color:var(--border);background:#f8fafc;}
.ib-edit:hover{background:#f1f5f9;}
.ib-done{color:#0369a1;border-color:#bae6fd;background:#f0f9ff;}
.ib-done:hover{background:#e0f2fe;}
.ib-reschedule{color:#92400e;border-color:#fde68a;background:var(--yel-lt);}
.ib-reschedule:hover{background:#fef3c7;}

/* PANEL */
.panel-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.35);z-index:200;backdrop-filter:blur(2px);}
.panel-overlay.open{display:block;}
.panel{position:fixed;top:0;right:0;bottom:0;width:420px;max-width:100vw;background:#fff;border-left:1px solid var(--border);box-shadow:-8px 0 32px rgba(0,0,0,.1);z-index:201;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .22s cubic-bezier(.4,0,.2,1);}
.panel.open{transform:translateX(0);}
.panel-head{padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;}
.panel-name{font-size:15px;font-weight:700;color:#1e293b;line-height:1.3;}
.panel-sub{font-size:11px;color:var(--gray);margin-top:3px;}
.panel-close{background:none;border:none;cursor:pointer;font-size:20px;color:var(--gray);line-height:1;padding:2px;}
.panel-close:hover{color:#334155;}
.panel-body{padding:20px;overflow-y:auto;flex:1;}
.panel-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;flex-shrink:0;}
.inp{background:#fff;border:1px solid var(--border);color:#1e293b;padding:9px 12px;border-radius:7px;font-size:13px;font-family:inherit;outline:none;transition:border-color .12s;width:100%;}
.inp:focus{border-color:var(--blue);}
.inp-label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gray);margin-bottom:5px;}
.fgroup{margin-bottom:14px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn{padding:9px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px;transition:all .12s;text-decoration:none;white-space:nowrap;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:#059669;}
.btn-ghost{background:#fff;color:#334155;border:1px solid var(--border);}
.btn-ghost:hover{background:#f1f5f9;}
</style>
</head>
<body>
<div class="container">

<div class="topbar">
    <a href="/leads/" class="topbar-title">📞 Leads Lite</a>
    <div class="topbar-links">
        Signed in as <strong><?= h(current_username()) ?></strong>
        <a href="https://sip.domain.com/leads/" class="tl-btn" style="color:#6d28d9;border-color:#ddd6fe;background:#f5f3ff;">▦ Campaigns</a>
        <a href="/leads/interested.php" class="tl-btn tl-green">✓ Interested</a>
<a href="/leads/callback.php" class="tl-btn tl-orange">↩ Call Back</a>
        <a href="/leads/tasks.php" class="tl-btn tl-blue">📅 Tasks</a>
        <?php if (is_admin()): ?>
        <a href="/leads/admin.php" class="tl-btn tl-blue">⚙ Admin</a>
        <?php endif; ?>
        <a href="/leads/logout.php" class="tl-btn tl-red">Sign Out</a>
    </div>
</div>

<?php if ($flash): ?><div class="flash ok">✓ <?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash err">✕ <?= h($error) ?></div><?php endif; ?>

<?php
$admin_view = is_admin();
$col_count  = $admin_view ? 6 : 5;

function section_table(array $rows, string $section_class, string $title, string $icon, int $col_count, bool $admin_view): void {
    $count = count($rows);
    $badge_count = $count > 0 ? $count : 0;
    ?>
    <div class="section <?= $section_class ?>">
        <div class="section-hd">
            <span style="font-size:18px;"><?= $icon ?></span>
            <span class="section-title"><?= $title ?></span>
            <span class="section-count"><?= $badge_count ?></span>
        </div>
        <?php if ($count === 0): ?>
        <div class="card"><div class="empty">Nothing here.</div></div>
        <?php else: ?>
        <div class="card">
        <div style="overflow-x:auto;">
        <table style="min-width:560px;table-layout:fixed;width:100%;">
            <thead><tr>
                <th style="width:130px;">Business</th>
                <?php if($admin_view): ?><th style="width:70px;">Agent</th><?php endif; ?>
                <th style="width:60px;">Method</th>
                <th style="width:90px;">Due</th>
                <th style="width:160px;">Notes</th>
                <th style="width:95px;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $t): echo render_task_row($t, $section_class, $admin_view); endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

section_table($overdue,   's-overdue',  'Overdue',         '🔴', $col_count, $admin_view);
section_table($due_today, 's-today',    'Due Today',       '🟡', $col_count, $admin_view);
section_table($due_7,     's-week',     'Next 7 Days',     '🟢', $col_count, $admin_view);
section_table($upcoming,  's-upcoming', 'All Upcoming',    '📋', $col_count, $admin_view);

if (!empty($no_date)): ?>
<div class="section s-nodate">
    <div class="section-hd">
        <span style="font-size:18px;">📌</span>
        <span class="section-title">No Date Set</span>
        <span class="section-count"><?= count($no_date) ?></span>
    </div>
    <div class="card">
    <div style="overflow-x:auto;">
    <table style="min-width:560px;table-layout:fixed;width:100%;">
        <thead><tr>
            <th style="width:130px;">Business</th>
            <?php if($admin_view): ?><th style="width:70px;">Agent</th><?php endif; ?>
            <th style="width:60px;">Method</th>
            <th style="width:90px;">Due</th>
            <th style="width:160px;">Notes</th>
            <th style="width:95px;">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($no_date as $t): echo render_task_row($t, 's-nodate', $admin_view); endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- SIDE PANEL -->
<div class="panel-overlay" id="panel-overlay" onclick="closePanel()"></div>
<div class="panel" id="side-panel">
    <div class="panel-head">
        <div>
            <div class="panel-name" id="panel-name">—</div>
            <div class="panel-sub" id="panel-sub"></div>
        </div>
        <button class="panel-close" onclick="closePanel()">✕</button>
    </div>
    <!-- FOLLOW-UP BAR -->
    <div style="padding:12px 20px;border-bottom:1px solid var(--border);background:#f8fafc;flex-shrink:0;">
        <form method="post" id="followup-form">
            <input type="hidden" name="action" value="post_note">
            <input type="hidden" name="note_id" id="panel-note-id">
            <input type="hidden" name="lead_id" id="panel-lead-id">
            <input type="hidden" name="note_txt" value="">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label class="inp-label">Follow-up Method</label>
                    <select name="followup_method" id="panel-method" class="inp">
                        <option value="">— None —</option>
                        <option value="Call">📞 Call</option>
                        <option value="Email">✉ Email</option>
                    </select>
                </div>
                <div>
                    <label class="inp-label">Follow-up in (days)</label>
                    <input type="number" name="followup_days" id="panel-days" class="inp" min="0" max="365" value="0" oninput="updateDue()">
                    <div id="panel-due-preview" style="font-size:11px;color:var(--blue);margin-top:3px;min-height:14px;"></div>
                </div>
            </div>
        </form>
    </div>

    <!-- CHAT THREAD -->
    <div class="panel-body" id="chat-thread" style="display:flex;flex-direction:column;gap:10px;padding:16px 20px;background:#f0f4f8;">
        <div id="no-notes-msg" style="text-align:center;color:var(--gray);font-size:12px;padding:20px 0;">No notes yet. Add the first one below.</div>
    </div>

    <!-- MESSAGE INPUT -->
    <div style="padding:12px 16px;border-top:1px solid var(--border);background:#fff;flex-shrink:0;">
        <div style="display:flex;gap:8px;align-items:flex-end;">
            <textarea id="new-note-txt" class="inp" rows="2" placeholder="Type a note…" style="resize:none;flex:1;border-radius:20px;padding:10px 16px;font-size:13px;" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendNote();}"></textarea>
            <button type="button" onclick="sendNote()" style="background:var(--green);color:#fff;border:none;border-radius:50%;width:40px;height:40px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .12s;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='var(--green)'">↑</button>
        </div>
        <div style="font-size:11px;color:var(--gray);margin-top:5px;padding:0 4px;">Enter to send · Shift+Enter for new line · <a href="#" onclick="saveFollowup();return false;" style="color:var(--blue);">Save follow-up settings</a></div>
    </div>
</div>

<style>
.bubble{max-width:85%;padding:9px 13px;border-radius:16px;font-size:13px;line-height:1.5;word-break:break-word;}
.bubble-me{background:var(--green);color:#fff;border-bottom-right-radius:4px;align-self:flex-end;}
.bubble-me.cb{background:#f97316;}
.bubble-other{background:#fff;color:#1e293b;border:1px solid var(--border);border-bottom-left-radius:4px;align-self:flex-start;}
.bubble-meta{font-size:10px;margin-top:3px;opacity:.7;}
.bubble-me .bubble-meta{text-align:right;}
.chat-row{display:flex;flex-direction:column;}
.chat-row.me{align-items:flex-end;}
.chat-row.other{align-items:flex-start;}
</style>
<script>
var allTasks = {};
<?php
$all_for_js = array_merge($all_tasks, $no_date);
foreach ($all_for_js as $t): ?>
allTasks[<?= (int)$t['id'] ?>] = {
    name:   <?= json_encode($t['business_name'] ?? 'Unknown') ?>,
    phone:  <?= json_encode($t['phone'] ?? '') ?>,
    camp:   <?= json_encode($t['campaign_code'] ?? '') ?>,
    notes:  <?= json_encode($t['notes'] ?? '') ?>,
    method: <?= json_encode($t['followup_method'] ?? '') ?>,
    days:   <?= (int)$t['followup_days'] ?>,
    nby:    <?= json_encode($t['notes_updated_by'] ?? '') ?>,
    nat:    <?= json_encode($t['notes_updated_at'] ?? '') ?>,
    lead_id:<?= (int)$t['lead_id'] ?>,
    note_id:<?= (int)$t['id'] ?>,
    unread: <?= !empty($unread_map[(int)$t['lead_id']]) ? 'true' : 'false' ?>,
    source: <?= json_encode($t['source'] ?? 'interested') ?>
};
<?php endforeach; ?>

// Chat notes per lead_id
var chatData = {
<?php
$all_for_js2 = array_merge($all_tasks, $no_date);
foreach ($all_for_js2 as $t):
    $lid = (int)$t['lead_id'];
    $notes_for_lead = $chat_notes[$lid] ?? [];
?>
    <?= $lid ?>: <?= json_encode(array_map(function($n) {
        return ['username'=>$n['username'],'note'=>$n['note'],'created_at'=>$n['created_at'],'user_id'=>(int)$n['user_id']];
    }, $notes_for_lead)) ?>,
<?php endforeach; ?>
};

var currentUsername = <?= json_encode(current_username()) ?>;

function openPanel(id) {
    var d = allTasks[id];
    if (!d) return;
    document.getElementById('panel-note-id').value = d.note_id;
    document.getElementById('panel-lead-id').value = d.lead_id;
    document.getElementById('panel-name').textContent = d.name || ('Lead #' + id);
    document.getElementById('panel-sub').textContent  = d.phone + (d.camp ? '  ·  ' + d.camp : '');
    document.getElementById('panel-days').value = d.days;
    var sel = document.getElementById('panel-method');
    for (var i = 0; i < sel.options.length; i++) sel.options[i].selected = sel.options[i].value === d.method;
    updateDue();
    renderChat(d.lead_id, d.source);
    document.getElementById('panel-overlay').classList.add('open');
    document.getElementById('side-panel').classList.add('open');
    setTimeout(function(){ document.getElementById('new-note-txt').focus(); scrollChat(); }, 250);
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=mark_viewed&lead_id='+d.lead_id});
    var rows = document.querySelectorAll('tbody tr');
    rows.forEach(function(row){
        if (row.getAttribute('onclick') && row.getAttribute('onclick').indexOf('openPanel('+id+')') !== -1) {
            var dot = row.querySelector('.unread-dot');
            if (dot) dot.style.background = 'transparent';
            row.style.background = '';
        }
    });
}

function renderChat(lead_id, source) {
    var thread = document.getElementById('chat-thread');
    var notes  = chatData[lead_id] || [];
    var noMsg  = document.getElementById('no-notes-msg');
    thread.querySelectorAll('.chat-row').forEach(function(el){ el.remove(); });
    if (notes.length === 0) {
        noMsg.style.display = 'block';
    } else {
        noMsg.style.display = 'none';
        notes.forEach(function(n){
            var isMe = n.username === currentUsername;
            var dt   = new Date(n.created_at);
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var fmt  = dt.getDate()+' '+months[dt.getMonth()]+', '+String(dt.getHours()).padStart(2,'0')+':'+String(dt.getMinutes()).padStart(2,'0');
            var row  = document.createElement('div');
            row.className = 'chat-row ' + (isMe ? 'me' : 'other');
            var cbClass = (isMe && source === 'callback') ? ' cb' : '';
            row.innerHTML = '<div class="bubble '+(isMe?'bubble-me'+cbClass:'bubble-other')+'">'
                + escHtml(n.note)
                + '<div class="bubble-meta">'+(isMe?'You':'<strong>'+escHtml(n.username)+'</strong>')+' · '+fmt+'</div>'
                + '</div>';
            thread.appendChild(row);
        });
    }
    scrollChat();
}

function scrollChat() { var t=document.getElementById('chat-thread'); t.scrollTop=t.scrollHeight; }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/\n/g,'<br>'); }
function sendNote() {
    var txt = document.getElementById('new-note-txt').value.trim();
    if (!txt) return;
    var noteId = document.getElementById('panel-note-id').value;
    var leadId = document.getElementById('panel-lead-id').value;
    var method = document.getElementById('panel-method').value;
    var days   = document.getElementById('panel-days').value;
    var d      = allTasks[noteId] || {};
    var cbClass = (d.source === 'callback') ? ' cb' : '';
    var thread = document.getElementById('chat-thread');
    document.getElementById('no-notes-msg').style.display = 'none';
    var now = new Date();
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var fmt = now.getDate()+' '+months[now.getMonth()]+', '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
    var row = document.createElement('div');
    row.className = 'chat-row me';
    row.innerHTML = '<div class="bubble bubble-me'+cbClass+'">'+escHtml(txt)+'<div class="bubble-meta">You · '+fmt+'</div></div>';
    thread.appendChild(row);
    scrollChat();
    document.getElementById('new-note-txt').value = '';
    fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=post_note&note_id='+encodeURIComponent(noteId)+'&lead_id='+encodeURIComponent(leadId)+'&note_txt='+encodeURIComponent(txt)+'&followup_method='+encodeURIComponent(method)+'&followup_days='+encodeURIComponent(days)
    });
}
function saveFollowup() {
    var noteId = document.getElementById('panel-note-id').value;
    var leadId = document.getElementById('panel-lead-id').value;
    var method = document.getElementById('panel-method').value;
    var days   = document.getElementById('panel-days').value;
    fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=post_note&note_id='+encodeURIComponent(noteId)+'&lead_id='+encodeURIComponent(leadId)+'&note_txt=&followup_method='+encodeURIComponent(method)+'&followup_days='+encodeURIComponent(days)
    }).then(function(){ 
        var prv = document.getElementById('panel-due-preview');
        if (prv) prv.textContent = '✓ Saved';
        setTimeout(function(){ if(prv) prv.textContent=''; }, 2000);
    });
}
function closePanel() { document.getElementById('panel-overlay').classList.remove('open'); document.getElementById('side-panel').classList.remove('open'); }
function updateDue() {
    var days = parseInt(document.getElementById('panel-days').value, 10);
    var prv  = document.getElementById('panel-due-preview');
    if (!prv) return;
    if (isNaN(days) || days <= 0) { prv.textContent = ''; return; }
    var d = new Date();
    d.setDate(d.getDate() + days);
    var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    prv.textContent = '📅 Due: ' + d.getDate() + ' ' + m[d.getMonth()] + ' ' + d.getFullYear();
}
function toggleResched(btn) {
    var menu = btn.nextElementSibling;
    var isOpen = menu.style.display === 'block';
    // Close all open menus first
    document.querySelectorAll('.resched-menu').forEach(function(m){ m.style.display='none'; });
    if (!isOpen) menu.style.display = 'block';
}
// Close reschedule menus on outside click
document.addEventListener('click', function(e){
    if (!e.target.closest('.resched-wrap')) {
        document.querySelectorAll('.resched-menu').forEach(function(m){ m.style.display='none'; });
    }
});
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePanel(); });
</script>
</body>
</html>
