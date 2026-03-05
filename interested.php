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
    // Save / update note
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
        // Also update followup_method and followup_days if provided
        $fw_method = in_array($_POST['followup_method'] ?? '', ['Call','Email']) ? $_POST['followup_method'] : '';
        $fw_days   = max(0, (int)($_POST['followup_days'] ?? 0));
        $fw_date   = $fw_days > 0 ? date('Y-m-d', strtotime("+{$fw_days} days")) : null;
        $pdo->prepare("UPDATE public.interested_notes
            SET followup_method=:fm, followup_days=:fd, followup_date=:fdate, updated_at=NOW()
            WHERE id=:id")
            ->execute([':fm'=>$fw_method,':fd'=>$fw_days,':fdate'=>$fw_date,':id'=>$note_id]);
        $flash = "Note added.";
    }

    // Pause / resume
    if ($action === 'set_status') {
        $note_id    = (int)$_POST['note_id'];
        $new_status = in_array($_POST['new_status']??'', ['active','paused','archived']) ? $_POST['new_status'] : 'active';
        // Users can set active/paused/archived; admins can do everything
        if (!is_admin() && $new_status === 'archived') {
            // users CAN archive
        }
        $pdo->prepare("UPDATE public.interested_notes SET status=:st, updated_at=NOW()
            WHERE id=:id AND (user_id=:uid OR :admin=TRUE)")
            ->execute([':st'=>$new_status,':id'=>$note_id,':uid'=>$uid,':admin'=>is_admin()?'TRUE':'FALSE']);
        $flash = $new_status === 'archived' ? "Lead archived." : ($new_status === 'active' ? "Lead restored." : "Lead paused.");
    }

    // Mark done
    if ($action === 'mark_done') {
        $note_id = (int)$_POST['note_id'];
        $pdo->prepare("UPDATE public.interested_notes SET status='done', done_at=NOW(), updated_at=NOW()
            WHERE id=:id AND (user_id=:uid OR :admin=TRUE)")
            ->execute([':id'=>$note_id,':uid'=>$uid,':admin'=>is_admin()?'TRUE':'FALSE']);
        $flash = "Marked as done.";
    }

    // Hard delete (admin only)
    if ($action === 'hard_delete' && is_admin()) {
        $note_id = (int)$_POST['note_id'];
        $pdo->prepare("DELETE FROM public.interested_notes WHERE id=:id")->execute([':id'=>$note_id]);
        $flash = "Lead permanently deleted.";
    }

} catch (Throwable $e) { $error = "Error: " . $e->getMessage(); }

$flash = $flash ?: ($_GET['flash'] ?? '');
$filter = $_GET['filter'] ?? 'active'; // active | archived | done

// ── Load leads ───────────────────────────────────────────────────────────────
$leads = [];
try {
    $admin_filter = is_admin() ? "" : "AND n.user_id = {$uid}";
    $status_filter = match($filter) {
        'archived' => "AND n.status = 'archived'",
        'done'     => "AND n.status = 'done'",
        default    => "AND n.status IN ('active','paused')",
    };
    $leads = $pdo->query("
        SELECT n.*, l.phone, l.business_name, l.campaign_id AS lead_camp,
               c.campaign_code, c.name AS camp_name,
               u.username AS agent
        FROM public.interested_notes n
        JOIN public.leads l ON l.id = n.lead_id
        LEFT JOIN public.campaigns c ON c.id = n.campaign_id
        LEFT JOIN public.users u ON u.id = n.user_id
        WHERE 1=1 {$admin_filter} {$status_filter}
        ORDER BY
            CASE WHEN n.followup_date IS NULL THEN 1 ELSE 0 END,
            n.followup_date ASC,
            n.updated_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $error = "Error loading leads: " . $e->getMessage(); }

// Load chat notes for all visible leads (keyed by lead_id)
$lead_ids = array_column($leads, 'lead_id');
$chat_notes = [];
if (!empty($lead_ids)) {
    $in = implode(',', array_map('intval', $lead_ids));
    try {
        $rows = $pdo->query("SELECT * FROM public.lead_notes WHERE lead_id IN ($in) ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) $chat_notes[(int)$r['lead_id']][] = $r;
    } catch (Throwable $e) {}
}

// Load last viewed times for current user
$viewed_map = [];
try {
    $admin_filter3 = is_admin() ? "" : "AND user_id = {$uid}";
    $vrows = $pdo->query("SELECT lead_id, MAX(viewed_at) AS viewed_at FROM public.lead_views WHERE user_id = {$uid} GROUP BY lead_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vrows as $vr) $viewed_map[(int)$vr['lead_id']] = $vr['viewed_at'];
} catch (Throwable $e) {}

// Build unread map: lead_id => true if has notes newer than last view, written by someone else
$unread_map = [];
foreach ($leads as $l) {
    $lid = (int)$l['lead_id'];
    $notes_for = $chat_notes[$lid] ?? [];
    if (empty($notes_for)) continue;
    // Only consider notes written by OTHER users
    $others_notes = array_filter($notes_for, function($n) use ($uid) {
        return (int)$n['user_id'] !== $uid;
    });
    if (empty($others_notes)) continue;
    $others_notes = array_values($others_notes);
    $latest_note = $others_notes[count($others_notes)-1]['created_at'];
    $last_viewed = $viewed_map[$lid] ?? null;
    if (!$last_viewed || $latest_note > $last_viewed) {
        $unread_map[$lid] = true;
    }
}

// Counts for tab badges
$counts = ['active'=>0,'archived'=>0,'done'=>0];
try {
    $admin_filter2 = is_admin() ? "" : "AND user_id = {$uid}";
    $rows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM public.interested_notes WHERE 1=1 {$admin_filter2} GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $s = $r['status'];
        if ($s === 'active' || $s === 'paused') $counts['active'] += (int)$r['cnt'];
        elseif ($s === 'archived') $counts['archived'] = (int)$r['cnt'];
        elseif ($s === 'done')     $counts['done']     = (int)$r['cnt'];
    }
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Interested Leads</title>
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
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:#1e293b;display:flex;min-height:100vh;}
.container{max-width:1000px;}
.sidebar{width:200px;flex-shrink:0;background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:50;box-shadow:2px 0 8px rgba(0,0,0,.04);}
.sidebar-logo{display:flex;align-items:center;gap:10px;padding:18px 16px 16px;border-bottom:1px solid var(--border);font-size:15px;font-weight:800;color:#1e293b;}
.sidebar-nav{flex:1;padding:10px 8px;display:flex;flex-direction:column;gap:2px;overflow-y:auto;}
.snav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:8px;font-size:13px;font-weight:500;color:var(--gray);text-decoration:none;transition:all .12s;}
.snav-item:hover{background:var(--bg);color:#1e293b;}
.snav-item.active{background:var(--blue-lt);color:var(--blue);font-weight:700;}
.snav-badge{margin-left:auto;background:var(--green-lt);color:#065f46;border:1px solid #a7f3d0;border-radius:20px;font-size:10px;font-weight:700;padding:1px 6px;}
.snav-badge-orange{background:#fff7ed;color:#9a3412;border-color:#fed7aa;}
.sidebar-footer{padding:12px 12px 16px;border-top:1px solid var(--border);}
.sidebar-user{display:flex;align-items:center;gap:9px;}
.sidebar-avatar{width:32px;height:32px;border-radius:50%;background:var(--blue);color:#fff;font-size:13px;font-weight:700;display:flex;align-items:center;justify-content:center;}
.sidebar-username{font-size:12px;font-weight:600;color:#1e293b;}
.sidebar-logout{font-size:11px;color:var(--red);text-decoration:none;font-weight:500;}
.main-content{margin-left:200px;flex:1;padding:28px;min-width:0;}

/* TOPBAR */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:10px;}
.topbar-title{font-size:20px;font-weight:700;text-decoration:none;color:inherit;}
.topbar-links{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--gray);}
.tl-btn{font-size:12px;font-weight:600;padding:5px 11px;border-radius:6px;text-decoration:none;border:1px solid;transition:all .12s;}
.tl-blue{color:var(--blue);border-color:#bfdbfe;background:var(--blue-lt);}
.tl-blue:hover{background:#dbeafe;}
.tl-green{color:#065f46;border-color:#a7f3d0;background:var(--green-lt);}
.tl-red{color:var(--red);border-color:#fecaca;background:var(--red-lt);}
.tl-red:hover{background:#fee2e2;}
.tl-gray{color:var(--gray);border-color:var(--border);background:#fff;}
.tl-gray:hover{background:#f1f5f9;}

/* FLASH */
.flash{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;font-weight:500;}
.flash.ok{background:var(--green-lt);color:#065f46;border:1px solid #a7f3d0;}
.flash.err{background:var(--red-lt);color:#991b1b;border:1px solid #fecaca;}

/* FILTER TABS */
.filter-tabs{display:flex;gap:4px;margin-bottom:18px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:4px;width:fit-content;}
.ftab{padding:7px 16px;border-radius:7px;font-size:12px;font-weight:700;text-decoration:none;color:var(--gray);transition:all .12s;display:flex;align-items:center;gap:6px;}
.ftab:hover{background:#f1f5f9;color:#334155;}
.ftab.on{background:var(--blue);color:#fff;}
.ftab-badge{background:rgba(255,255,255,.3);border-radius:10px;padding:1px 6px;font-size:10px;}
.ftab:not(.on) .ftab-badge{background:#f1f5f9;color:var(--gray);}

/* CARD */
.card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.card-title{font-size:13px;font-weight:700;color:#334155;}

/* TABLE */
table{width:100%;border-collapse:collapse;table-layout:fixed;}
th{text-align:left;padding:10px 14px;background:#f8fafc;color:var(--gray);font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:700;border-bottom:1px solid var(--border);}
td{padding:11px 14px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tbody tr{cursor:pointer;transition:background .08s;}
tbody tr:hover td{background:#f8fafc;}
.empty{text-align:center;padding:48px;color:var(--gray);font-size:13px;}

/* BADGES */
.badge{display:inline-flex;align-items:center;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.b-active{background:var(--green-lt);color:#065f46;border:1px solid #a7f3d0;}
.b-paused{background:#f1f5f9;color:var(--gray);border:1px solid #cbd5e1;}
.b-archived{background:var(--yel-lt);color:#92400e;border:1px solid #fde68a;}
.b-done{background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;}
.b-overdue{background:var(--red-lt);color:#991b1b;border:1px solid #fecaca;}

/* ICON BTNS */
.icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:all .12s;background:none;padding:0;flex-shrink:0;}
.ib-edit{color:var(--gray);border-color:var(--border);background:#f8fafc;}
.ib-edit:hover{background:#f1f5f9;color:#334155;}
.ib-pause{color:var(--blue);border-color:#bfdbfe;background:var(--blue-lt);}
.ib-pause:hover{background:#dbeafe;}
.ib-archive{color:#92400e;border-color:#fde68a;background:var(--yel-lt);}
.ib-archive:hover{background:#fef3c7;}
.ib-restore{color:#065f46;border-color:#a7f3d0;background:var(--green-lt);}
.ib-restore:hover{background:#d1fae5;}
.ib-done{color:#0369a1;border-color:#bae6fd;background:#f0f9ff;}
.ib-done:hover{background:#e0f2fe;}
.ib-del{color:var(--red);border-color:#fecaca;background:var(--red-lt);}
.ib-del:hover{background:#fee2e2;}
.act-row{display:flex;gap:4px;align-items:center;}
.act-row form{display:contents;}

/* SIDE PANEL */
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

/* FORM */
.inp{background:#fff;border:1px solid var(--border);color:#1e293b;padding:9px 12px;border-radius:7px;font-size:13px;font-family:inherit;outline:none;transition:border-color .12s;width:100%;}
.inp:focus{border-color:var(--blue);}
.inp-label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gray);margin-bottom:5px;}
.fgroup{margin-bottom:14px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* BUTTONS */
.btn{padding:9px 16px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px;transition:all .12s;text-decoration:none;white-space:nowrap;}
.btn-blue{background:var(--blue);color:#fff;}
.btn-blue:hover{background:#1d4ed8;}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{background:#059669;}
.btn-ghost{background:#fff;color:#334155;border:1px solid var(--border);}
.btn-ghost:hover{background:#f1f5f9;}

/* DUE DATE */
.due-ok{color:var(--gray);font-size:12px;}
.due-soon{color:var(--yellow);font-weight:700;font-size:12px;}
.due-today{color:var(--blue);font-weight:700;font-size:12px;}
.due-overdue{color:var(--red);font-weight:700;font-size:12px;}
</style>
</head>
<body>
<?php include __DIR__ . '/nav.php'; ?>
<div class="main-content">
<div class="container">

<!-- FLASH -->
<?php if ($flash): ?><div class="flash ok">✓ <?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash err">✕ <?= h($error) ?></div><?php endif; ?>

<!-- FILTER TABS -->
<div class="filter-tabs">
    <a href="?filter=active"   class="ftab <?= $filter==='active'  ?'on':'' ?>">Active <span class="ftab-badge"><?= $counts['active'] ?></span></a>
    <a href="?filter=done"     class="ftab <?= $filter==='done'    ?'on':'' ?>">Done <span class="ftab-badge"><?= $counts['done'] ?></span></a>
    <a href="?filter=archived" class="ftab <?= $filter==='archived'?'on':'' ?>">Archived <span class="ftab-badge"><?= $counts['archived'] ?></span></a>
</div>

<!-- LEADS TABLE -->
<div class="card">
    <div class="card-head">
        <span class="card-title">
            <?= match($filter) { 'archived'=>'Archived Leads', 'done'=>'Done Leads', default=>'Active Interested Leads' } ?>
            <span style="font-weight:400;color:var(--gray);font-size:11px;margin-left:6px;">(<?= count($leads) ?>)</span>
        </span>
    </div>
    <div style="overflow-x:auto;">
    <table style="min-width:600px;">
        <thead>
            <tr>
                <th style="width:140px;">Business</th>
                <?php if (is_admin()): ?><th style="width:75px;">Agent</th><?php endif; ?>
                <th style="width:65px;">Method</th>
                <th style="width:75px;">Due</th>
                <th style="width:65px;">Status</th>
                <th style="width:<?= is_admin()?'115':'95' ?>px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($leads)): ?>
        <tr><td colspan="<?= is_admin()?6:5 ?>"><div class="empty">No leads here yet.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($leads as $l):
            $is_paused   = $l['status'] === 'paused';
            $is_archived = $l['status'] === 'archived';
            $is_done     = $l['status'] === 'done';
            $today       = date('Y-m-d');
            $due         = $l['followup_date'] ?? null;
            $due_class   = '';
            $due_label   = '—';
            if ($due) {
                $diff = (int)((strtotime($due) - strtotime($today)) / 86400);
                $due_label = date('d M', strtotime($due));
                if ($diff < 0)      $due_class = 'due-overdue';
                elseif ($diff === 0) $due_class = 'due-today';
                elseif ($diff <= 3)  $due_class = 'due-soon';
                else                 $due_class = 'due-ok';
            }
        ?>
        <?php $has_unread = !empty($unread_map[(int)$l['lead_id']]); ?>
        <tr style="<?= $is_paused?'opacity:.6;':'' ?><?= $has_unread?'background:#fffbeb;':'' ?>" onclick="openPanel(<?= (int)$l['id'] ?>)" title="Click to edit notes">
            <td style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h((string)($l['business_name']??'')) ?>">
                <span class="unread-dot" style="display:inline-block;width:8px;height:8px;background:<?= $has_unread ? '#ef4444' : 'transparent' ?>;border-radius:50%;margin-right:5px;vertical-align:middle;flex-shrink:0;"></span><?= $l['business_name'] ? h((string)$l['business_name']) : '<span style="color:var(--gray)">—</span>' ?>
            </td>
            <?php if (is_admin()): ?><td style="font-size:12px;color:var(--gray);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h((string)($l['agent']??'—')) ?></td><?php endif; ?>
            <td style="font-size:12px;color:var(--gray);"><?= $l['followup_method'] ? h((string)$l['followup_method']) : '—' ?></td>
            <td><span class="<?= $due_class ?>"><?= $due ? ($due_class==='due-overdue'?'⚠ ':'').h($due_label) : '—' ?></span></td>
            <td>
                <?php if ($is_done): ?>
                    <span class="badge b-done">Done</span>
                <?php elseif ($is_archived): ?>
                    <span class="badge b-archived">Archived</span>
                <?php elseif ($is_paused): ?>
                    <span class="badge b-paused">Paused</span>
                <?php else: ?>
                    <span class="badge b-active"><?= $due && $due < $today ? '⚠ Overdue' : 'Active' ?></span>
                <?php endif; ?>
            </td>
            <td>
                <div class="act-row">
                    <!-- Edit -->
                    <button type="button" class="icon-btn ib-edit" title="Edit notes" onclick="event.stopPropagation();openPanel(<?= (int)$l['id'] ?>)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>

                    <?php if ($filter === 'active'): ?>
                        <!-- Mark Done -->
                        <form method="post" onsubmit="return confirm('Mark as done?');">
                            <input type="hidden" name="action" value="mark_done">
                            <input type="hidden" name="note_id" value="<?= (int)$l['id'] ?>">
                            <button type="submit" class="icon-btn ib-done" title="Mark done" onclick="event.stopPropagation()">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                        </form>
                        <!-- Pause / Resume -->
                        <form method="post">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="note_id" value="<?= (int)$l['id'] ?>">
                            <input type="hidden" name="new_status" value="<?= $is_paused?'active':'paused' ?>">
                            <button type="submit" class="icon-btn ib-pause" onclick="event.stopPropagation()" title="<?= $is_paused?'Resume':'Pause' ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><?= $is_paused ? '<polygon points="5 3 19 12 5 21 5 3"/>' : '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>' ?></svg>
                            </button>
                        </form>
                        <!-- Archive -->
                        <form method="post" onsubmit="return confirm('Archive this lead?');">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="note_id" value="<?= (int)$l['id'] ?>">
                            <input type="hidden" name="new_status" value="archived">
                            <button type="submit" class="icon-btn ib-archive" onclick="event.stopPropagation()" title="Archive">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                            </button>
                        </form>

                    <?php elseif ($filter === 'archived'): ?>
                        <!-- Unarchive (admin only) -->
                        <?php if (is_admin()): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="note_id" value="<?= (int)$l['id'] ?>">
                            <input type="hidden" name="new_status" value="active">
                            <button type="submit" class="icon-btn ib-restore" onclick="event.stopPropagation()" title="Restore to Active">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg>
                            </button>
                        </form>
                        <!-- Hard delete -->
                        <form method="post" onsubmit="return confirm('Permanently delete? Cannot be undone.');">
                            <input type="hidden" name="action" value="hard_delete">
                            <input type="hidden" name="note_id" value="<?= (int)$l['id'] ?>">
                            <button type="submit" class="icon-btn ib-del" onclick="event.stopPropagation()" title="Delete permanently">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </form>
                        <?php endif; ?>

                    <?php elseif ($filter === 'done'): ?>
                        <!-- Reopen -->
                        <form method="post">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="note_id" value="<?= (int)$l['id'] ?>">
                            <input type="hidden" name="new_status" value="active">
                            <button type="submit" class="icon-btn ib-restore" onclick="event.stopPropagation()" title="Reopen">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

</div><!-- /container -->

<!-- SLIDE-IN PANEL -->
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
                        <option value="Email">📧 Email</option>
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
            <button onclick="sendNote()" style="background:var(--green);color:#fff;border:none;border-radius:50%;width:40px;height:40px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .12s;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='var(--green)'">↑</button>
        </div>
        <div style="font-size:11px;color:var(--gray);margin-top:5px;padding:0 4px;">Enter to send · Shift+Enter for new line · <a href="#" onclick="saveFollowup();return false;" style="color:var(--green);">Save follow-up settings</a></div>
    </div>
</div>

<style>
.bubble{max-width:85%;padding:9px 13px;border-radius:16px;font-size:13px;line-height:1.5;word-break:break-word;position:relative;}
.bubble-me{background:var(--green);color:#fff;border-bottom-right-radius:4px;align-self:flex-end;}
.bubble-other{background:#fff;color:#1e293b;border:1px solid var(--border);border-bottom-left-radius:4px;align-self:flex-start;}
.bubble-meta{font-size:10px;margin-top:3px;opacity:.7;}
.bubble-me .bubble-meta{text-align:right;}
.chat-row{display:flex;flex-direction:column;}
.chat-row.me{align-items:flex-end;}
.chat-row.other{align-items:flex-start;}
</style>
<script>
// Build lead data map for panel
var leadData = {
<?php foreach ($leads as $l): ?>
    <?= (int)$l['id'] ?>: {
        name:   <?= json_encode($l['business_name'] ?? 'Unknown') ?>,
        phone:  <?= json_encode($l['phone'] ?? '') ?>,
        camp:   <?= json_encode($l['campaign_code'] ?? '') ?>,
        campname: <?= json_encode($l['camp_name'] ?? '') ?>,
        notes:  <?= json_encode($l['notes'] ?? '') ?>,
        method: <?= json_encode($l['followup_method'] ?? '') ?>,
        days:   <?= (int)$l['followup_days'] ?>,
        date:   <?= json_encode($l['followup_date'] ?? '') ?>,
        nby:    <?= json_encode($l['notes_updated_by'] ?? '') ?>,
        nat:    <?= json_encode($l['notes_updated_at'] ?? '') ?>,
        lead_id:<?= (int)$l['lead_id'] ?>,
        unread: <?= !empty($unread_map[(int)$l['lead_id']]) ? 'true' : 'false' ?>
    },
<?php endforeach; ?>
};

// Chat notes per lead_id
var chatData = {
<?php foreach ($leads as $l):
    $lid = (int)$l['lead_id'];
    $notes_for_lead = $chat_notes[$lid] ?? [];
?>
    <?= $lid ?>: <?= json_encode(array_map(function($n) {
        return [
            'username'   => $n['username'],
            'note'       => $n['note'],
            'created_at' => $n['created_at'],
            'user_id'    => (int)$n['user_id'],
        ];
    }, $notes_for_lead)) ?>,
<?php endforeach; ?>
};

var currentLeadId = null;
var currentNoteId = null;
var currentUsername = <?= json_encode(current_username()) ?>;

function openPanel(id) {
    var d = leadData[id];
    if (!d) return;
    currentNoteId = id;
    currentLeadId = d.lead_id;
    document.getElementById('panel-note-id').value = id;
    document.getElementById('panel-lead-id').value = d.lead_id;
    document.getElementById('panel-name').textContent = d.name || ('Lead #' + id);
    var campLabel = d.camp ? ('  ·  ' + d.camp + (d.campname ? ' — ' + d.campname : '')) : '';
    document.getElementById('panel-sub').textContent = d.phone + campLabel;
    document.getElementById('panel-days').value = d.days;
    var sel = document.getElementById('panel-method');
    for (var i = 0; i < sel.options.length; i++) sel.options[i].selected = sel.options[i].value === d.method;
    updateDue();
    renderChat(d.lead_id);
    document.getElementById('panel-overlay').classList.add('open');
    document.getElementById('side-panel').classList.add('open');
    setTimeout(function(){ document.getElementById('new-note-txt').focus(); scrollChat(); }, 250);
    // Mark as viewed
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=mark_viewed&lead_id='+d.lead_id});
    // Remove red dot from row immediately
    var rows = document.querySelectorAll('tbody tr');
    rows.forEach(function(row){
        if (row.getAttribute('onclick') && row.getAttribute('onclick').indexOf('openPanel('+id+')') !== -1) {
            var dot = row.querySelector('.unread-dot');
            if (dot) dot.style.background = 'transparent';
            row.style.background = '';
        }
    });
}

function renderChat(lead_id) {
    var thread = document.getElementById('chat-thread');
    var notes  = chatData[lead_id] || [];
    var noMsg  = document.getElementById('no-notes-msg');
    // Remove old bubbles
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
            row.innerHTML = '<div class="bubble '+(isMe?'bubble-me':'bubble-other')+'">'
                + escHtml(n.note)
                + '<div class="bubble-meta">'+(isMe?'You':'<strong>'+escHtml(n.username)+'</strong>')+' · '+fmt+'</div>'
                + '</div>';
            thread.appendChild(row);
        });
    }
    scrollChat();
}

function scrollChat() {
    var t = document.getElementById('chat-thread');
    t.scrollTop = t.scrollHeight;
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/\n/g,'<br>'); }

function sendNote() {
    var txt = document.getElementById('new-note-txt').value.trim();
    if (!txt) return;
    var noteId = document.getElementById('panel-note-id').value;
    var leadId = document.getElementById('panel-lead-id').value;
    var method = document.getElementById('panel-method').value;
    var days   = document.getElementById('panel-days').value;
    var thread = document.getElementById('chat-thread');
    document.getElementById('no-notes-msg').style.display = 'none';
    var now = new Date();
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var fmt = now.getDate()+' '+months[now.getMonth()]+', '+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
    var row = document.createElement('div');
    row.className = 'chat-row me';
    row.innerHTML = '<div class="bubble bubble-me">'+escHtml(txt)+'<div class="bubble-meta">You · '+fmt+'</div></div>';
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
    document.querySelector('#followup-form [name="note_txt"]').value = '';
    document.getElementById('followup-form').submit();
}

function closePanel() {
    document.getElementById('panel-overlay').classList.remove('open');
    document.getElementById('side-panel').classList.remove('open');
}

function updateDue() {
    var days = parseInt(document.getElementById('panel-days').value, 10);
    var prv  = document.getElementById('panel-due-preview');
    if (!prv) return;
    if (isNaN(days) || days <= 0) { prv.textContent = ''; return; }
    var d = new Date();
    d.setDate(d.getDate() + days);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    prv.textContent = '📅 Due: ' + d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
}

// Close on ESC
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePanel(); });
</script>
</body>
</div><!-- /main-content -->
</html>
