<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

require_login();

ini_set('display_errors', '0');
error_reporting(E_ALL);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function normalize_phone(string $raw): string {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === null) $digits = '';
    $digits = ltrim($digits, '0');
    return (strlen($digits) > 15) ? substr($digits, -15) : $digits;
}

function format_duration(int $seconds): string {
    if ($seconds <= 0) return '0m';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}

$uid      = current_user_id();
$action   = $_POST['action'] ?? ($_GET['action'] ?? '');
$view_cid = (int)($_GET['view'] ?? 0);
$flash    = '';
$error    = '';
$current_lead    = null;
$dial_session_id = 0;

// Close stale sessions older than 5 minutes with no heartbeat
try {
    $pdo->prepare("
        UPDATE public.dial_sessions
           SET status='closed', ended_at=NOW() AT TIME ZONE 'America/New_York',
               total_seconds=GREATEST(0,EXTRACT(EPOCH FROM (last_ping-started_at))::INT)
         WHERE status='active'
           AND last_ping < ((NOW() AT TIME ZONE 'America/New_York') - INTERVAL '5 minutes')
    ")->execute();
} catch (Throwable $e) {}

try {
    if ($action === 'save_outcome') {
        $lead_id = (int)$_POST['lead_id'];
        $cid     = (int)$_POST['campaign_id'];
        $outcome = $_POST['outcome'] ?? 'called';
        $paused  = (int)($_POST['paused'] ?? 0);
        $sess_id = (int)($_POST['dial_session_id'] ?? 0);
        $allowed = ['interested','not_interested','no_answer','called','callback'];
        if (!in_array($outcome, $allowed, true)) $outcome = 'called';

        // Map callback to 'called' for lead status (keeps it dialable)
        $lead_status = $outcome === 'callback' ? 'called' : $outcome;
        $pdo->prepare("UPDATE public.leads SET status=:st, last_result=:res, last_call_time=(NOW() AT TIME ZONE 'America/New_York'), attempts=attempts+1 WHERE id=:id")
            ->execute([':st'=>$lead_status,':res'=>$outcome,':id'=>$lead_id]);
        $pdo->prepare("INSERT INTO public.call_logs (lead_id,campaign_id,user_id,outcome) VALUES (:lid,:cid,:uid,:out)")
            ->execute([':lid'=>$lead_id,':cid'=>$cid,':uid'=>$uid,':out'=>$outcome]);
        // Insert into interested_notes when marked interested
        if ($outcome === 'interested') {
            $pdo->prepare("INSERT INTO public.interested_notes (lead_id,campaign_id,user_id,status)
                VALUES (:lid,:cid,:uid,'active')
                ON CONFLICT (lead_id) DO UPDATE SET status='active', updated_at=NOW()")
                ->execute([':lid'=>$lead_id,':cid'=>$cid,':uid'=>$uid]);
        }
        // Insert into callback_notes when marked callback
        if ($outcome === 'callback') {
            $pdo->prepare("INSERT INTO public.callback_notes (lead_id,campaign_id,user_id,status)
                VALUES (:lid,:cid,:uid,'active')
                ON CONFLICT (lead_id) DO NOTHING")
                ->execute([':lid'=>$lead_id,':cid'=>$cid,':uid'=>$uid]);
        }

        if ($sess_id > 0) {
            $pdo->prepare("UPDATE public.dial_sessions SET status='closed', ended_at=NOW() AT TIME ZONE 'America/New_York', total_seconds=GREATEST(0,EXTRACT(EPOCH FROM (last_ping-started_at))::INT) WHERE id=:sid AND user_id=:uid AND status='active'")
                ->execute([':sid'=>$sess_id,':uid'=>$uid]);
        }

        if ($paused) {
            header("Location: /leads/?flash=" . urlencode("Result saved. Campaign paused."));
        } else {
            header("Location: /leads/?action=dial_next&campaign_id=$cid");
        }
        exit;
    }

    if ($action === 'dial_next') {
        $cid = (int)($_REQUEST['campaign_id'] ?? 0);
        if (!is_admin()) {
            $chk = $pdo->prepare("SELECT 1 FROM public.campaign_users WHERE campaign_id=:c AND user_id=:u");
            $chk->execute([':c'=>$cid,':u'=>$uid]);
            if (!$chk->fetchColumn()) { $flash = "Campaign not assigned to you."; goto page_render; }
        }
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id, business_name, phone FROM public.leads WHERE campaign_id=:cid AND status='new' ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        $st->execute([':cid'=>$cid]);
        $current_lead = $st->fetch(PDO::FETCH_ASSOC);
        if ($current_lead) {
            $pdo->prepare("UPDATE public.leads SET status='calling' WHERE id=:id")->execute([':id'=>$current_lead['id']]);
            $ds = $pdo->prepare("INSERT INTO public.dial_sessions (user_id,campaign_id) VALUES (:uid,:cid) RETURNING id");
            $ds->execute([':uid'=>$uid,':cid'=>$cid]);
            $dial_session_id = (int)$ds->fetchColumn();
            $pdo->commit();
        } else {
            $pdo->commit();
            $flash = "Campaign complete! No remaining leads.";
        }
    }

    if ($action === 'upload_csv' && can_upload()) {
        $name = trim((string)($_POST['campaign_name'] ?? ''));
        if ($name === '') { $flash = 'Enter a campaign name.'; goto page_render; }
        $st = $pdo->prepare("INSERT INTO public.campaigns (name,created_by) VALUES (:n,:u) RETURNING id");
        $st->execute([':n'=>$name,':u'=>$uid]);
        $new_cid = (int)$st->fetchColumn();
        $pdo->prepare("INSERT INTO public.campaign_users (campaign_id,user_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$new_cid,$uid]);
        $imported = 0; $skipped = 0;
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error']===0) {
            $fh = fopen($_FILES['csv_file']['tmp_name'],'rb');
            $first = true;
            while (($row = fgetcsv($fh)) !== false) {
                if ($first) { $first=false; $raw=normalize_phone((string)($row[0]??'')); if(!ctype_digit($raw)||strlen($raw)<7) continue; }
                $biz   = trim((string)($row[0]??''));
                $phone = normalize_phone((string)($row[1]??''));
                if (strlen($phone)<7) { $skipped++; continue; }
                try { $pdo->prepare("INSERT INTO public.leads (campaign_id,business_name,phone) VALUES (?,?,?) ON CONFLICT DO NOTHING")->execute([$new_cid,$biz?:null,$phone]); $imported++; }
                catch (Throwable $e) { $skipped++; }
            }
            fclose($fh);
        }
        $st2 = $pdo->prepare("SELECT campaign_code FROM public.campaigns WHERE id=?");
        $st2->execute([$new_cid]);
        $code = (string)$st2->fetchColumn();
        $flash = "Campaign {$code} created — {$imported} leads imported, {$skipped} skipped.";
        goto page_render;
    }

    if ($action === 'update_lead_status') {
        $lead_id    = (int)$_POST['lead_id'];
        $new_status = $_POST['new_status'] ?? '';
        $allowed    = ['new','interested','not_interested','no_answer','called','callback'];
        $view_cid   = (int)($_POST['view_cid'] ?? 0);
        $cid_for_notes = (int)($_POST['cid_for_notes'] ?? 0);
        if (in_array($new_status, $allowed, true)) {
            $pdo->prepare("UPDATE public.leads SET status=:st, last_result=:st WHERE id=:id")
                ->execute([':st'=>$new_status, ':id'=>$lead_id]);
            if ($new_status === 'interested') {
                $pdo->prepare("INSERT INTO public.interested_notes (lead_id,campaign_id,user_id,status) VALUES (:lid,:cid,:uid,'active') ON CONFLICT (lead_id) DO UPDATE SET status='active', updated_at=NOW()")->execute([':lid'=>$lead_id,':cid'=>$cid_for_notes,':uid'=>$uid]);
                header('Location: /leads/interested.php?flash=' . urlencode('Lead marked interested.'));
            } elseif ($new_status === 'callback') {
                $pdo->prepare("INSERT INTO public.callback_notes (lead_id,campaign_id,user_id,status) VALUES (:lid,:cid,:uid,'active') ON CONFLICT (lead_id) DO UPDATE SET status='active', updated_at=NOW()")->execute([':lid'=>$lead_id,':cid'=>$cid_for_notes,':uid'=>$uid]);
                header('Location: /leads/callback.php?flash=' . urlencode('Lead marked call back.'));
            } else {
                header("Location: /leads/?view={$view_cid}&flash=" . urlencode('Status updated.') . '#lead-' . $lead_id);
            }
        } else {
            header("Location: /leads/?view={$view_cid}#lead-{$lead_id}");
        }
        exit;
    }

    if ($action === 'delete_campaign' && can_delete()) {
        $cid = (int)$_POST['campaign_id'];
        $pdo->prepare("UPDATE public.campaigns SET deleted=TRUE,deleted_by=:uid,deleted_at=NOW() AT TIME ZONE 'America/New_York' WHERE id=:id AND deleted=FALSE")
            ->execute([':uid'=>$uid,':id'=>$cid]);
        $flash = "Campaign moved to trash.";
    }
} catch (Throwable $e) { $error = "Error: " . $e->getMessage(); }

page_render:

// Today's stats for this user
$today_calls = 0; $today_seconds = 0;
$today_interested = 0; $today_not_interested = 0; $today_no_answer = 0;
try {
    $ts = $pdo->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN outcome='interested' THEN 1 ELSE 0 END) AS interested,
        SUM(CASE WHEN outcome='not_interested' THEN 1 ELSE 0 END) AS not_interested,
        SUM(CASE WHEN outcome='no_answer' THEN 1 ELSE 0 END) AS no_answer
        FROM public.call_logs
        WHERE user_id=:uid AND DATE(call_time AT TIME ZONE 'America/New_York')=CURRENT_DATE");
    $ts->execute([':uid'=>$uid]);
    $row = $ts->fetch(PDO::FETCH_ASSOC);
    $today_calls          = (int)($row['total'] ?? 0);
    $today_interested     = (int)($row['interested'] ?? 0);
    $today_not_interested = (int)($row['not_interested'] ?? 0);
    $today_no_answer      = (int)($row['no_answer'] ?? 0);

    $ts2 = $pdo->prepare("SELECT COALESCE(SUM(total_seconds),0) FROM public.dial_sessions WHERE user_id=:uid AND status='closed' AND DATE(started_at AT TIME ZONE 'America/New_York')=CURRENT_DATE");
    $ts2->execute([':uid'=>$uid]);
    $today_seconds = (int)$ts2->fetchColumn();
} catch (Throwable $e) {}

// Campaign list
$search = trim($_GET['search'] ?? '');
$page   = max(1,(int)($_GET['p'] ?? 1));
$limit  = 5; $offset = ($page-1)*$limit;
$flash  = $flash ?: (isset($_GET['flash']) ? (string)$_GET['flash'] : '');

$campaign_list = []; $total_pages = 1; $total_camps = 0;
try {
    $where_parts = ["c.deleted=FALSE"]; $params = [];
    if (!is_admin()) {
        $where_parts[] = "EXISTS (SELECT 1 FROM public.campaign_users cu WHERE cu.campaign_id=c.id AND cu.user_id=:uid)";
        $params[':uid'] = $uid;
    }
    if ($search !== '') {
        $where_parts[] = "(c.name ILIKE :search OR c.campaign_code ILIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    $where = 'WHERE '.implode(' AND ',$where_parts);

    $cnt_st = $pdo->prepare("SELECT COUNT(*) FROM public.campaigns c $where");
    $cnt_st->execute($params);
    $total_camps = (int)$cnt_st->fetchColumn();
    $total_pages = max(1,(int)ceil($total_camps/$limit));

    $list_st = $pdo->prepare("
        SELECT c.id, c.campaign_code, c.name,
            (SELECT COUNT(*) FROM public.leads l WHERE l.campaign_id=c.id AND l.status='new') AS rem,
            (SELECT COUNT(*) FROM public.leads l WHERE l.campaign_id=c.id) AS total,
            (SELECT COUNT(*) FROM public.leads l WHERE l.campaign_id=c.id AND l.status NOT IN ('new','calling')) AS dialed,
            (SELECT COUNT(*) FROM public.leads l WHERE l.campaign_id=c.id AND l.status='calling') AS calling
        FROM public.campaigns c $where ORDER BY c.id DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k=>$v) $list_st->bindValue($k,$v);
    $list_st->bindValue(':lim',$limit,PDO::PARAM_INT);
    $list_st->bindValue(':off',$offset,PDO::PARAM_INT);
    $list_st->execute();
    $campaign_list = $list_st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $error = "Error loading campaigns: ".$e->getMessage(); }

// View leads
$view_leads = [];
if ($view_cid > 0) {
    try {
        $view_leads = $pdo->query("SELECT * FROM public.leads WHERE campaign_id=$view_cid ORDER BY id ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

function svg_phone(): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.59a16 16 0 0 0 5.5 5.5l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
}
function svg_resume(): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.59a16 16 0 0 0 5.5 5.5l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/><line x1="15" y1="3" x2="15" y2="7"/><line x1="18" y1="3" x2="18" y2="7"/></svg>';
}
function svg_search(): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
}
function svg_trash(): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leads Lite</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --blue:    #2563eb;
            --blue-lt: #eff6ff;
            --red:     #ef4444;
            --red-lt:  #fef2f2;
            --green:   #10b981;
            --green-lt:#ecfdf5;
            --yellow:  #f59e0b;
            --yel-lt:  #fffbeb;
            --gray:    #64748b;
            --border:  #e2e8f0;
            --bg:      #f8fafc;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: #1e293b; display:flex; min-height:100vh; }
        .container { max-width: 1000px; }
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


        /* STATS BAR */
        .stats-bar { display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap; }
        .stat-pill { background:#fff; border:1px solid var(--border); border-radius:10px; padding:12px 18px; display:flex; flex-direction:column; gap:3px; min-width:110px; }
        .stat-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--gray); }
        .stat-val   { font-size:22px; font-weight:800; }
        .stat-val.blue   { color:var(--blue); }
        .stat-val.green  { color:var(--green); }
        .stat-val.red    { color:var(--red); }
        .stat-val.yellow { color:var(--yellow); }
        .stat-val.gray   { color:var(--gray); }

        /* FLASH */
        .flash { padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px; font-weight:500; }
        .flash.ok  { background:var(--green-lt); color:#065f46; border:1px solid #a7f3d0; }
        .flash.err { background:var(--red-lt);   color:#991b1b; border:1px solid #fecaca; }

        /* CARD */
        .card { background:#fff; border:1px solid var(--border); border-radius:12px; margin-bottom:24px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .card-head { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
        .card-title { font-size:14px; font-weight:700; color:#334155; }
        .card-body { padding:20px; }

        /* TABLE */
        table { width:100%; border-collapse:collapse; table-layout:fixed; }
        th { text-align:left; padding:11px 16px; background:#f8fafc; color:var(--gray); font-size:11px; text-transform:uppercase; letter-spacing:.06em; font-weight:700; border-bottom:1px solid var(--border); }
        td { padding:12px 16px; border-bottom:1px solid var(--border); font-size:13px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        tbody tr:hover td { background:#fafbfc; }
        .col-code   { width:100px; }
        .col-name   { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:600; }
        .col-status { width:120px; }
        .col-prog   { width:130px; white-space:nowrap; }
        .col-act    { width:130px; }

        /* CAMPAIGN STATUS BADGE */
        .c-status { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700; }
        .cs-new      { background:#f0f9ff; color:#0369a1; border:1px solid #bae6fd; }
        .cs-progress { background:var(--yel-lt); color:#92400e; border:1px solid #fde68a; }
        .cs-done     { background:var(--green-lt); color:#065f46; border:1px solid #a7f3d0; }

        /* PROGRESS BAR */
        .prog { display:flex; align-items:center; gap:8px; }
        .prog-track { width:60px; height:4px; background:#e2e8f0; border-radius:2px; overflow:hidden; }
        .prog-fill  { height:100%; background:var(--blue); border-radius:2px; }
        .prog-lbl   { font-size:11px; color:var(--gray); font-weight:600; }

        /* BUTTONS */
        .btn { padding:9px 16px; border:none; border-radius:8px; cursor:pointer; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; font-size:13px; gap:7px; transition:all .12s; white-space:nowrap; }
        .btn-blue    { background:var(--blue); color:#fff; }
        .btn-blue:hover { background:#1d4ed8; }
        .btn-outline { background:#fff; color:#334155; border:1px solid var(--border); }
        .btn-outline:hover { background:#f1f5f9; }
        .btn-ghost   { background:transparent; color:var(--gray); border:1px solid var(--border); }
        .btn-ghost:hover { background:#f1f5f9; color:#334155; }

        /* ICON BUTTONS */
        .icon-btn { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:7px; border:1px solid transparent; cursor:pointer; text-decoration:none; transition:all .12s; background:none; padding:0; flex-shrink:0; vertical-align:middle; }
        .ib-dial   { color:#16a34a; border-color:#bbf7d0; background:#f0fdf4; }
        .ib-dial:hover { background:#dcfce7; }
        .ib-resume { color:var(--blue); border-color:#bfdbfe; background:var(--blue-lt); }
        .ib-resume:hover { background:#dbeafe; }
        .ib-done   { color:#94a3b8; border-color:transparent; background:transparent; cursor:default; }
        .ib-view   { color:var(--gray); border-color:var(--border); background:#f8fafc; }
        .ib-view:hover { background:#f1f5f9; }
        .ib-delete { color:var(--red); border-color:#fecaca; background:var(--red-lt); }
        .ib-delete:hover { background:#fee2e2; }
        .act-row { display:flex; gap:5px; align-items:center; flex-wrap:nowrap; }
        .act-row form { display:contents; margin:0; }

        /* CODE BADGE */
        .code-badge { font-family:monospace; font-size:11px; font-weight:700; color:var(--blue); background:var(--blue-lt); padding:3px 7px; border-radius:4px; border:1px solid #bfdbfe; white-space:nowrap; }

        /* FORM */
        .inp { background:#fff; border:1px solid var(--border); color:#1e293b; padding:9px 12px; border-radius:7px; font-size:13px; font-family:inherit; outline:none; transition:border-color .12s; width:100%; }
        .inp:focus { border-color:var(--blue); }
        .inp-label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--gray); margin-bottom:5px; }
        .fgroup { margin-bottom:14px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .search-row { display:flex; gap:8px; align-items:center; }

        /* PAGINATION */
        .pages { display:flex; gap:4px; justify-content:center; padding:14px 0 2px; }
        .pg { padding:5px 11px; border-radius:6px; font-size:12px; color:var(--gray); text-decoration:none; border:1px solid var(--border); background:#fff; transition:all .12s; }
        .pg:hover { border-color:#cbd5e1; color:#1e293b; }
        .pg.on { background:var(--blue); color:#fff; border-color:var(--blue); }
        .pg.disabled { opacity:.4; cursor:default; pointer-events:none; }

        /* EMPTY */
        .empty { text-align:center; padding:40px; color:var(--gray); }

        /* DIALING SCREEN */
        .dial-wrap { max-width:520px; margin:0 auto; }
        .dial-card { background:#fff; border:1px solid var(--border); border-radius:16px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,.08); }
        .dial-header { padding:14px 22px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; }
        .dot-live { width:8px; height:8px; border-radius:50%; background:var(--red); animation:blink .9s infinite; }
        @keyframes blink { 0%,100%{opacity:1;}50%{opacity:.2;} }
        .dial-live-txt { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--red); }
        .dial-body { padding:32px 24px; text-align:center; }
        .dial-biz { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--gray); margin-bottom:6px; }
        .dial-num { font-family:monospace; font-size:40px; font-weight:800; color:#1e293b; letter-spacing:4px; margin-bottom:32px; line-height:1; }

        /* Hang Up button */
        .btn-hangup { width:100%; padding:18px; font-size:16px; font-weight:700; background:var(--red); color:#fff; border:none; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:background .12s; }
        .btn-hangup:hover { background:#dc2626; }

        /* Outcome area */
        .outcome-area { margin-top:24px; text-align:left; }
        .outcome-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--gray); margin-bottom:12px; text-align:center; }
        .outcome-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:16px; }
        .outcome-btn { padding:16px 8px; border-radius:10px; border:2px solid transparent; font-size:13px; font-weight:700; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:5px; transition:all .12s; background:#f8fafc; color:var(--gray); }
        .outcome-btn .ico { font-size:22px; }
        .outcome-btn:disabled { opacity:.35; cursor:not-allowed; }
        .outcome-btn.ob-green  { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
        .outcome-btn.ob-green:hover:not(:disabled)  { background:#d1fae5; }
        .outcome-btn.ob-yellow { background:#fffbeb; color:#92400e; border-color:#fde68a; }
        .outcome-btn.ob-yellow:hover:not(:disabled) { background:#fef3c7; }
        .outcome-btn.ob-red    { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
        .outcome-btn.ob-red:hover:not(:disabled)    { background:#fee2e2; }
        .outcome-btn.ob-gray   { background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
        .outcome-btn.ob-gray:hover:not(:disabled)   { background:#e2e8f0; }

        /* Pause toggle */
        .btn-pause { width:100%; padding:12px; font-size:13px; font-weight:700; background:#f5f3ff; color:#6d28d9; border:2px solid #ddd6fe; border-radius:8px; cursor:pointer; transition:all .12s; margin-top:4px; }
        .btn-pause:hover { background:#ede9fe; }
        .btn-pause.active { background:#6d28d9; color:#fff; border-color:#6d28d9; }
        .btn-pause.active:hover { background:#5b21b6; }

        .dial-foot { padding:12px 22px; border-top:1px solid var(--border); font-size:11px; color:var(--gray); text-align:center; }

        /* Status colors for leads table */
        .s-new{color:#0369a1;font-weight:700;} .s-calling{color:var(--yellow);font-weight:700;}
        .s-interested{color:var(--green);font-weight:700;} .s-not_interested{color:var(--red);font-weight:700;}
        .s-no_answer{color:var(--gray);font-weight:700;} .s-called{color:#94a3b8;font-weight:700;} .s-callback{color:var(--yellow);font-weight:700;}
    </style>
</head>
<body>
<?php include __DIR__ . '/nav.php'; ?>
<div class="main-content">
<div class="container">

    <!-- FLASH -->
    <?php if ($flash): ?><div class="flash ok">✓ <?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err">✕ <?= h($error) ?></div><?php endif; ?>

    <?php if ($current_lead): ?>
    <!-- ═══════════════════════════════
         DIALING SCREEN
    ═══════════════════════════════ -->
    <div class="dial-wrap">
        <div class="dial-card">
            <div class="dial-header">
                <div class="dot-live"></div>
                <span class="dial-live-txt">Live Call</span>
            </div>
            <div class="dial-body">
                <div class="dial-biz"><?= $current_lead['business_name'] ? h($current_lead['business_name']) : 'No name on file' ?></div>
                <div class="dial-num" id="active-phone"><?= h($current_lead['phone']) ?></div>

                <!-- STEP 1: Hang Up -->
                <div id="step-hangup">
                    <button class="btn-hangup" onclick="doHangup()">
                        🔴 &nbsp;Hang Up
                    </button>
                    <p style="margin-top:14px;font-size:12px;color:var(--gray);">
                        Call is in progress via MicroSIP. Click Hang Up once the call ends.
                    </p>
                </div>

                <!-- STEP 2: Outcome (hidden until hang up) -->
                <div id="step-outcome" class="outcome-area" style="display:none;">
                    <div class="outcome-label">Select call result to continue</div>

                    <form method="post" id="outcome-form">
                        <input type="hidden" name="lead_id"         value="<?= (int)$current_lead['id'] ?>">
                        <input type="hidden" name="campaign_id"     value="<?= (int)($_REQUEST['campaign_id'] ?? 0) ?>">
                        <input type="hidden" name="action"          value="save_outcome">
                        <input type="hidden" name="dial_session_id" value="<?= $dial_session_id ?>">
                        <input type="hidden" name="paused"          value="0" id="paused-flag">

                        <input type="hidden" name="outcome" id="outcome-value" value="">

                        <div class="outcome-grid">
                            <button type="button"
                                    class="outcome-btn ob-green" onclick="submitOutcome('interested')">
                                <span class="ico">✓</span>Interested
                            </button>
                            <button type="button"
                                    class="outcome-btn ob-gray" onclick="submitOutcome('no_answer')">
                                <span class="ico">〜</span>No Answer
                            </button>
                            <button type="button"
                                    class="outcome-btn ob-red" onclick="submitOutcome('not_interested')">
                                <span class="ico">✕</span>Not Interested
                            </button>
                            <button type="button"
                                    class="outcome-btn ob-yellow" onclick="submitOutcome('callback')">
                                <span class="ico">↩</span>Call Back
                            </button>
                        </div>

                        <button type="button" class="btn-pause" id="pause-btn" onclick="togglePause()">
                            ⏸ &nbsp;Pause after this call
                        </button>

                        <p style="margin-top:12px;font-size:11px;color:var(--gray);text-align:center;">
                            Selecting a result auto-dials next lead · Toggle Pause to stop after this call
                        </p>
                    </form>
                </div>
            </div>
            <div class="dial-foot">
                callto: auto-triggered via MicroSIP &nbsp;·&nbsp;
                <a href="/leads/" style="color:var(--gray);">← Back to campaigns</a>
            </div>
        </div>
    </div>

    <script>
    (function(){
        // Auto-trigger MicroSIP
        try {
            window.location.href = 'callto:' + document.getElementById('active-phone').innerText.replace(/\s/g,'');
        } catch(e){}

        // Heartbeat to keep dial session alive
        var sessId = <?= $dial_session_id ?>;
        if (sessId > 0) {
            setInterval(function(){
                var fd = new FormData();
                fd.append('session_id', sessId);
                fetch('/leads/heartbeat.php', {method:'POST', body:fd}).catch(function(){});
            }, 30000);
        }
    })();

    var paused = false;

    function doHangup() {
        document.getElementById('step-hangup').style.display = 'none';
        document.getElementById('step-outcome').style.display = 'block';
    }

    function togglePause() {
        paused = !paused;
        var btn  = document.getElementById('pause-btn');
        var flag = document.getElementById('paused-flag');
        if (paused) {
            btn.classList.add('active');
            btn.innerHTML = '⏸ &nbsp;Pausing after this call <small style="font-weight:400;">(click to cancel)</small>';
            flag.value = '1';
        } else {
            btn.classList.remove('active');
            btn.innerHTML = '⏸ &nbsp;Pause after this call';
            flag.value = '0';
        }
    }

    function submitOutcome(value) {
        document.getElementById('outcome-value').value = value;
        document.querySelectorAll('.outcome-btn').forEach(function(b){ b.disabled = true; });
        document.getElementById('outcome-form').submit();
    }
    </script>

    <?php else: ?>
    <!-- ═══════════════════════════════
         CAMPAIGN LIST
    ═══════════════════════════════ -->

    <!-- STATS BAR -->
    <div class="stats-bar">
        <div class="stat-pill">
            <span class="stat-label">Calls Today</span>
            <span class="stat-val blue"><?= $today_calls ?></span>
        </div>
        <div class="stat-pill">
            <span class="stat-label">Interested</span>
            <span class="stat-val green"><?= $today_interested ?></span>
        </div>
        <div class="stat-pill">
            <span class="stat-label">Not Interested</span>
            <span class="stat-val red"><?= $today_not_interested ?></span>
        </div>
        <div class="stat-pill">
            <span class="stat-label">No Answer</span>
            <span class="stat-val yellow"><?= $today_no_answer ?></span>
        </div>
        <div class="stat-pill">
            <span class="stat-label">Time Dialing</span>
            <span class="stat-val gray"><?= format_duration($today_seconds) ?></span>
        </div>
    </div>

    <!-- CAMPAIGNS -->
    <div class="card">
        <div class="card-head">
            <span class="card-title">My Campaigns <span style="font-weight:400;color:var(--gray);font-size:12px;">(<?= $total_camps ?> total)</span></span>
            <form method="get" class="search-row">
                <input type="text" name="search" value="<?= h($search) ?>" class="inp" placeholder="Search campaigns…" style="width:190px;">
                <button type="submit" class="btn btn-outline" style="padding:8px 14px;">Search</button>
                <?php if ($search): ?><a href="/leads/" class="btn btn-ghost" style="padding:8px 14px;">Clear</a><?php endif; ?>
            </form>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="col-code">Code</th>
                    <th class="col-name">Campaign</th>
                    <th class="col-status">Status</th>
                    <th class="col-prog">Progress</th>
                    <th class="col-act">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaign_list)): ?>
                <tr><td colspan="5"><div class="empty">No campaigns assigned to you.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($campaign_list as $c):
                    $total   = (int)$c['total'];
                    $rem     = (int)$c['rem'];
                    $dialed  = (int)$c['dialed'];
                    $pct     = $total > 0 ? round($dialed / $total * 100) : 0;
                    $is_done  = $rem === 0 && $total > 0;
                    $is_fresh = $total === 0 || $rem === $total;

                    if ($total === 0 || $rem === $total) {
                        $status_label = '<span class="c-status cs-new">● New</span>';
                    } elseif ($is_done) {
                        $status_label = '<span class="c-status cs-done">✓ Complete</span>';
                    } else {
                        $status_label = '<span class="c-status cs-progress">▶ In Progress</span>';
                    }
                ?>
                <tr>
                    <td><span class="code-badge"><?= h($c['campaign_code']) ?></span></td>
                    <td class="col-name" title="<?= h($c['name']) ?>"><?= h($c['name']) ?></td>
                    <td><?= $status_label ?></td>
                    <td>
                        <div class="prog">
                            <div class="prog-track">
                                <div class="prog-fill" style="width:<?= $pct ?>%;<?= $is_done?'background:var(--green)':'' ?>"></div>
                            </div>
                            <span class="prog-lbl"><?= $dialed ?>/<?= $total ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="act-row">
                            <?php if ($is_done): ?>
                                <span class="icon-btn ib-done" title="Complete">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                            <?php elseif ($is_fresh): ?>
                                <a href="/leads/?action=dial_next&campaign_id=<?= (int)$c['id'] ?>" class="icon-btn ib-dial" title="Start Dialing"><?= svg_phone() ?></a>
                            <?php else: ?>
                                <a href="/leads/?action=dial_next&campaign_id=<?= (int)$c['id'] ?>" class="icon-btn ib-resume" title="Resume"><?= svg_resume() ?></a>
                            <?php endif; ?>
                            <a href="/leads/?view=<?= (int)$c['id'] ?>" class="icon-btn ib-view" title="View Leads"><?= svg_search() ?></a>
                            <?php if (can_delete()): ?>
                            <form method="post" onsubmit="return confirm('Move <?= h(addslashes($c['campaign_code'])) ?> to trash?');">
                                <input type="hidden" name="action" value="delete_campaign">
                                <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="icon-btn ib-delete" title="Delete"><?= svg_trash() ?></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="pages">
            <?php if ($page > 1): ?>
                <a href="?p=<?= $page-1 ?>&search=<?= urlencode($search) ?>" class="pg">‹</a>
            <?php else: ?>
                <span class="pg disabled">‹</span>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?p=<?= $i ?>&search=<?= urlencode($search) ?>" class="pg <?= $i===$page?'on':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?p=<?= $page+1 ?>&search=<?= urlencode($search) ?>" class="pg">›</a>
            <?php else: ?>
                <span class="pg disabled">›</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- UPLOAD CAMPAIGN -->
    <?php if (can_upload()): ?>
    <div style="margin-bottom:16px;">
        <button onclick="toggleUpload()" id="upload-toggle-btn" class="btn btn-blue" style="padding:8px 16px;font-size:12px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Campaign
        </button>
        <div id="upload-accordion" style="display:none;margin-top:10px;">
            <div class="card">
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_csv">
                        <div class="form-grid" style="margin-bottom:12px;">
                            <div class="fgroup" style="margin-bottom:0;">
                                <label class="inp-label">Campaign Name</label>
                                <input type="text" name="campaign_name" required class="inp" placeholder="e.g. Q2 Charlotte Outreach">
                            </div>
                            <div class="fgroup" style="margin-bottom:0;">
                                <label class="inp-label">CSV File &nbsp;<span style="font-weight:400;opacity:.5;">col 1: business · col 2: phone</span></label>
                                <input type="file" name="csv_file" accept=".csv" required class="inp">
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-blue" style="flex:1;justify-content:center;">Upload &amp; Create Campaign</button>
                            <button type="button" onclick="toggleUpload()" class="btn btn-ghost">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- VIEW LEADS -->
    <?php if ($view_cid > 0): ?>
    <?php
        try {
            $vc_st = $pdo->prepare("SELECT name, campaign_code FROM public.campaigns WHERE id=?");
            $vc_st->execute([$view_cid]);
            $vc = $vc_st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $vc = ['name'=>'Campaign','campaign_code'=>'']; }
        $st_labels = ['new'=>'New','calling'=>'Calling…','called'=>'Called','interested'=>'Interested','not_interested'=>'Not Interested','no_answer'=>'No Answer','callback'=>'Call Back'];
    ?>
    <div class="card">
        <div class="card-head">
            <span class="card-title"><?= h((string)($vc['campaign_code']??'')) ?> — <?= h((string)($vc['name']??'')) ?></span>
            <a href="/leads/" class="btn btn-ghost" style="padding:6px 12px;font-size:12px;">← Back</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th style="width:120px;">Phone</th>
                    <th style="width:80px;">Business</th>
                    <th style="width:130px;">Status</th>
                    <th style="width:45px;">Tries</th>
                    <th style="width:120px;">Last Call</th>
                    <th style="width:100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($view_leads)): ?>
                <tr><td colspan="7"><div class="empty">No leads in this campaign.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($view_leads as $l): ?>
                <tr id="lead-<?= (int)$l['id'] ?>">
                    <td style="font-family:monospace;color:var(--gray);font-size:11px;"><?= (int)$l['id'] ?></td>
                    <td style="font-family:monospace;font-weight:700;font-size:12px;"><?= h((string)$l['phone']) ?></td>
                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--gray);font-size:12px;" title="<?= h((string)($l['business_name']??'')) ?>"><?= $l['business_name'] ? h((string)$l['business_name']) : '—' ?></td>
                    <td><span class="s-<?= h((string)$l['status']) ?>"><?= $st_labels[$l['status']] ?? h((string)$l['status']) ?></span></td>
                    <td style="font-family:monospace;font-size:12px;"><?= (int)$l['attempts'] ?></td>
                    <td style="font-size:12px;color:var(--gray);"><?= $l['last_call_time'] ? date("d M, H:i", strtotime((string)$l['last_call_time'])) : '—' ?></td>
                    <td>
                        <div class="act-row">
                            <button type="button" class="icon-btn ib-view" title="Edit Status"
                                onclick="openStatusEdit(<?= (int)$l['id'] ?>, '<?= h($l['status']) ?>', <?= (int)($l['campaign_id'] ?? 0) ?>)">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php endif; // end not current_lead ?>
</div>

<!-- EDIT STATUS MODAL -->
<div id="status-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border:1px solid var(--border);border-radius:14px;padding:28px;width:100%;max-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.12);">
        <div style="font-size:15px;font-weight:700;color:#1e293b;margin-bottom:18px;">Edit Call Status</div>
        <form method="post">
            <input type="hidden" name="action" value="update_lead_status">
            <input type="hidden" name="view_cid" value="<?= $view_cid ?>">
            <input type="hidden" name="lead_id" id="modal-lead-id">
            <input type="hidden" name="cid_for_notes" id="modal-cid">
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
                <?php foreach(['interested'=>'✓ Interested','callback'=>'↩ Call Back','not_interested'=>'✕ Not Interested','no_answer'=>'〜 No Answer','called'=>'Called','new'=>'New'] as $val=>$lbl): ?>
                <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;font-weight:500;">
                    <input type="radio" name="new_status" value="<?= $val ?>" id="rs-<?= $val ?>" style="accent-color:var(--blue);">
                    <?= $lbl ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-blue" style="flex:1;justify-content:center;">Save</button>
                <button type="button" class="btn btn-ghost" onclick="closeStatusEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>


<script>
var _sm  = document.getElementById('status-modal');
if (_sm)  _sm.addEventListener ('click', function(e){ if(e.target===this) closeStatusEdit(); });

function openStatusEdit(leadId, currentStatus, campaignId) {
    if (!_sm) return;
    document.getElementById('modal-lead-id').value = leadId;
    document.getElementById('modal-cid').value = campaignId || 0;
    var r = document.getElementById('rs-' + currentStatus);
    if (r) r.checked = true;
    _sm.style.display = 'flex';
}
function closeStatusEdit() { if (_sm) _sm.style.display = 'none'; }
function toggleUpload() {
    var acc = document.getElementById('upload-accordion');
    var btn = document.getElementById('upload-toggle-btn');
    if (!acc) return;
    var open = acc.style.display === 'block';
    acc.style.display = open ? 'none' : 'block';
    if (!open) {
        btn.textContent = '✕ Cancel';
        setTimeout(function(){ var inp = document.querySelector('#upload-accordion input[name="campaign_name"]'); if(inp) inp.focus(); }, 50);
    } else {
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> New Campaign';
    }
}
</script>

</body>
</html>
