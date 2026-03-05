<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

require_login(true);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function format_duration(int $seconds): string {
    if ($seconds <= 0) return '0m';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}

function normalize_phone(string $raw): string {
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $digits = ltrim($digits, '0');
    return strlen($digits) > 15 ? substr($digits, -15) : $digits;
}

$tab   = $_GET['tab'] ?? 'campaigns';
$flash = '';
$error = '';

try {
    $pdo->prepare("UPDATE public.dial_sessions SET status='closed',ended_at=NOW() AT TIME ZONE 'America/New_York',total_seconds=GREATEST(0,EXTRACT(EPOCH FROM (last_ping-started_at))::INT) WHERE status='active' AND last_ping<(NOW() AT TIME ZONE 'America/New_York')-INTERVAL '5 minutes'")->execute();
} catch (Throwable $e) {}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

try {
    if ($action==='create_user') {
        $uname=trim((string)($_POST['username']??'')); $pass=(string)($_POST['password']??'');
        $role=$_POST['role']==='admin'?'admin':'user';
        $cup=isset($_POST['can_upload']); $cdel=isset($_POST['can_delete']);
        if($uname===''||strlen($pass)<6){ $flash='Username required and password must be 6+ chars.'; }
        else { $hash=password_hash($pass,PASSWORD_BCRYPT); $pdo->prepare("INSERT INTO public.users (username,password_hash,role,can_upload,can_delete) VALUES (?,?,?,?,?)")->execute([$uname,$hash,$role,$cup,$cdel]); $flash="User '{$uname}' created."; }
        $tab='users';
    }

    if ($action==='update_user') {
        $uid2=(int)$_POST['user_id']; $role=$_POST['role']==='admin'?'admin':'user';
        $cup=isset($_POST['can_upload']); $cdel=isset($_POST['can_delete']); $active=isset($_POST['active']);
        $pdo->prepare("UPDATE public.users SET role=?,can_upload=?,can_delete=?,active=? WHERE id=?")->execute([$role,$cup,$cdel,$active,$uid2]);
        if(!empty($_POST['new_password'])&&strlen((string)$_POST['new_password'])>=6)
            $pdo->prepare("UPDATE public.users SET password_hash=? WHERE id=?")->execute([password_hash((string)$_POST['new_password'],PASSWORD_BCRYPT),$uid2]);
        $flash="User updated."; $tab='users';
    }

    if ($action==='delete_user') {
        $uid2=(int)$_POST['user_id'];
        if($uid2===current_user_id()){ $flash="Cannot delete yourself."; }
        else { $pdo->prepare("DELETE FROM public.users WHERE id=?")->execute([$uid2]); $flash="User deleted."; }
        $tab='users';
    }

    if ($action==='upload_csv') {
        $name=trim((string)($_POST['campaign_name']??''));
        if($name===''){ $flash='Enter a campaign name.'; $tab='campaigns'; goto done; }
        $dup=$pdo->prepare("SELECT id FROM public.campaigns WHERE name=? AND deleted=FALSE");
        $dup->execute([$name]);
        if($dup->fetchColumn()){ $flash="A campaign named \"$name\" already exists."; $tab='campaigns'; goto done; }
        $st=$pdo->prepare("INSERT INTO public.campaigns (name,created_by) VALUES (?,?) RETURNING id");
        $st->execute([$name,current_user_id()]); $new_cid=(int)$st->fetchColumn();
        foreach((array)($_POST['assign_users']??[]) as $auid)
            $pdo->prepare("INSERT INTO public.campaign_users (campaign_id,user_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$new_cid,(int)$auid]);
        $pdo->prepare("INSERT INTO public.campaign_users (campaign_id,user_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$new_cid,current_user_id()]);
        $imported=0; $skipped=0;
        if(isset($_FILES['csv_file'])&&$_FILES['csv_file']['error']===0){
            $fh=fopen($_FILES['csv_file']['tmp_name'],'rb'); $first=true;
            while(($row=fgetcsv($fh))!==false){
                if($first){ $first=false; continue; } // always skip header row
                $biz=trim((string)($row[0]??'')); $phone=normalize_phone((string)($row[1]??''));
                if(strlen($phone)<7){ $skipped++; continue; }
                try{ $pdo->prepare("INSERT INTO public.leads (campaign_id,business_name,phone) VALUES (?,?,?) ON CONFLICT DO NOTHING")->execute([$new_cid,$biz?:null,$phone]); $imported++; }
                catch(Throwable $e){ $skipped++; }
            }
            fclose($fh);
        }
        $st2=$pdo->prepare("SELECT campaign_code FROM public.campaigns WHERE id=?"); $st2->execute([$new_cid]);
        $code=(string)$st2->fetchColumn();
        $flash="Campaign {$code} created — {$imported} leads, {$skipped} skipped."; $tab='campaigns';
    }

    if ($action==='assign_users') {
        $cid=(int)$_POST['campaign_id'];
        $pdo->prepare("DELETE FROM public.campaign_users WHERE campaign_id=?")->execute([$cid]);
        foreach((array)($_POST['assign_users']??[]) as $auid)
            $pdo->prepare("INSERT INTO public.campaign_users (campaign_id,user_id) VALUES (?,?) ON CONFLICT DO NOTHING")->execute([$cid,(int)$auid]);
        $flash="Assignments updated."; $tab='campaigns';
    }

    if ($action==='soft_delete') {
        $cid=(int)$_POST['campaign_id'];
        $pdo->prepare("UPDATE public.campaigns SET deleted=TRUE,deleted_by=:u,deleted_at=NOW() AT TIME ZONE 'America/New_York' WHERE id=:id AND deleted=FALSE")->execute([':u'=>current_user_id(),':id'=>$cid]);
        $flash="Campaign moved to trash."; $tab='campaigns';
    }

    if ($action==='restore_campaign') {
        $cid=(int)$_POST['campaign_id'];
        $pdo->prepare("UPDATE public.campaigns SET deleted=FALSE,deleted_by=NULL,deleted_at=NULL WHERE id=?")->execute([$cid]);
        $flash="Campaign restored."; $tab='trash';
    }

    if ($action==='hard_delete') {
        $cid=(int)$_POST['campaign_id'];
        $pdo->prepare("DELETE FROM public.campaigns WHERE id=? AND deleted=TRUE")->execute([$cid]);
        $flash="Campaign permanently deleted."; $tab='trash';
    }

    if ($action==='set_int_status') {
        $note_id=(int)$_POST['note_id'];
        $new_status=in_array($_POST['new_status']??'',['active','paused','deleted'])?$_POST['new_status']:'active';
        $pdo->prepare("UPDATE public.interested_notes SET status=:st, updated_at=NOW() WHERE id=:id")->execute([':st'=>$new_status,':id'=>$note_id]);
        $flash=$new_status==='deleted'?"Removed from interested.":"Status updated."; $tab='interested';
    }

    if ($action==='update_int_note') {
        $note_id=(int)$_POST['note_id'];
        $notes=trim((string)($_POST['notes']??''));
        $fw_method=trim((string)($_POST['followup_method']??''));
        $fw_days=max(0,(int)($_POST['followup_days']??0));
        $fw_date=$fw_days>0?date('Y-m-d',strtotime("+{$fw_days} days")):null;
        $pdo->prepare("UPDATE public.interested_notes SET notes=:notes,followup_method=:fm,followup_days=:fd,followup_date=:fdate,updated_at=NOW() WHERE id=:id")->execute([':notes'=>$notes,':fm'=>$fw_method,':fd'=>$fw_days,':fdate'=>$fw_date,':id'=>$note_id]);
        $flash="Note updated."; $tab='interested';
    }

    if ($action==='reset_call_logs') {
        $uid2=(int)$_POST['user_id'];
        $date=(string)($_POST['log_date']??date('Y-m-d'));
        $pdo->prepare("DELETE FROM public.call_logs WHERE user_id=? AND DATE(call_time AT TIME ZONE 'America/New_York')=?")->execute([$uid2,$date]);
        $pdo->prepare("UPDATE public.dial_sessions SET total_seconds=0 WHERE user_id=? AND DATE(started_at AT TIME ZONE 'America/New_York')=?")->execute([$uid2,$date]);
        $flash="Call logs reset for that user on {$date}."; $tab='users';
    }
} catch (Throwable $e) { $error="Error: ".$e->getMessage(); }

done:

$all_users=$pdo->query("SELECT id,username,role,can_upload,can_delete,active,created_at FROM public.users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$camp_page=max(1,(int)($_GET['cp']??1)); $camp_limit=8; $camp_off=($camp_page-1)*$camp_limit;
$camp_search=trim($_GET['cs']??'');
$cwhere="WHERE c.deleted=FALSE"; $cparams=[];
if($camp_search!==''){ $cwhere.=" AND (c.name ILIKE :cs OR c.campaign_code ILIKE :cs)"; $cparams[':cs']="%{$camp_search}%"; }
$cnt=$pdo->prepare("SELECT COUNT(*) FROM public.campaigns c $cwhere"); $cnt->execute($cparams);
$camp_total=(int)$cnt->fetchColumn(); $camp_pages=max(1,(int)ceil($camp_total/$camp_limit));
$cst=$pdo->prepare("SELECT c.id,c.campaign_code,c.name,c.created_at,u.username AS created_by_name,(SELECT COUNT(*) FROM public.leads l WHERE l.campaign_id=c.id AND l.status='new') AS rem,(SELECT COUNT(*) FROM public.leads l WHERE l.campaign_id=c.id) AS total FROM public.campaigns c LEFT JOIN public.users u ON u.id=c.created_by $cwhere ORDER BY c.id DESC LIMIT :lim OFFSET :off");
foreach($cparams as $k=>$v) $cst->bindValue($k,$v);
$cst->bindValue(':lim',$camp_limit,PDO::PARAM_INT); $cst->bindValue(':off',$camp_off,PDO::PARAM_INT);
$cst->execute(); $campaigns=$cst->fetchAll(PDO::FETCH_ASSOC);

$trash=$pdo->query("SELECT c.id,c.campaign_code,c.name,c.deleted_at,u.username AS deleted_by_name,(SELECT COUNT(*) FROM public.leads l WHERE l.campaign_id=c.id) AS total FROM public.campaigns c LEFT JOIN public.users u ON u.id=c.deleted_by WHERE c.deleted=TRUE ORDER BY c.deleted_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$perf_page=max(1,(int)($_GET['pp']??1)); $perf_limit=10; $perf_off=($perf_page-1)*$perf_limit;
$today_date=date('Y-m-d'); $p_start=$_GET['ps']??$today_date; $p_end=$_GET['pe']??$today_date; $p_user=(int)($_GET['pu']??0); $p_camp=(int)($_GET['pc']??0);
$pwhere="WHERE 1=1"; $pparams=[];
if($p_start&&$p_end){ $pwhere.=" AND DATE(cl.call_time AT TIME ZONE 'America/New_York') BETWEEN :ps AND :pe"; $pparams[':ps']=$p_start; $pparams[':pe']=$p_end; }
if($p_user>0){ $pwhere.=" AND cl.user_id=:pu"; $pparams[':pu']=$p_user; }
if($p_camp>0){ $pwhere.=" AND cl.campaign_id=:pc"; $pparams[':pc']=$p_camp; }

$p_cnt=$pdo->prepare("SELECT COUNT(*) FROM (SELECT DATE(cl.call_time AT TIME ZONE 'America/New_York') AS cdate, cl.user_id, cl.campaign_id FROM public.call_logs cl $pwhere GROUP BY DATE(cl.call_time AT TIME ZONE 'America/New_York'), cl.user_id, cl.campaign_id) x");
$p_cnt->execute($pparams); $perf_total=(int)$p_cnt->fetchColumn(); $perf_pages=max(1,(int)ceil($perf_total/$perf_limit));

$pst=$pdo->prepare("
    SELECT
        DATE(cl.call_time AT TIME ZONE 'America/New_York') AS cdate,
        u.username,
        COALESCE(camp.campaign_code, '[Deleted #'||cl.campaign_id||']') AS campaign_code,
        COALESCE(camp.name, '[Deleted Campaign]') AS camp_name,
        SUM(CASE WHEN cl.outcome='interested' THEN 1 ELSE 0 END) AS interested,
        SUM(CASE WHEN cl.outcome='not_interested' THEN 1 ELSE 0 END) AS not_interested,
        SUM(CASE WHEN cl.outcome='no_answer' THEN 1 ELSE 0 END) AS no_answer,
        SUM(CASE WHEN cl.outcome IN ('interested','not_interested','no_answer','called') THEN 1 ELSE 0 END) AS total,
        COALESCE(MAX(ds_agg.dial_seconds), 0) AS dial_seconds
    FROM public.call_logs cl
    LEFT JOIN public.users u ON u.id = cl.user_id
    LEFT JOIN public.campaigns camp ON camp.id = cl.campaign_id
    LEFT JOIN (
        SELECT user_id,
               DATE(started_at AT TIME ZONE 'America/New_York') AS ds_date,
               SUM(total_seconds) AS dial_seconds
        FROM public.dial_sessions
        WHERE status = 'closed'
        GROUP BY user_id, DATE(started_at AT TIME ZONE 'America/New_York')
    ) ds_agg ON ds_agg.user_id = cl.user_id
           AND ds_agg.ds_date = DATE(cl.call_time AT TIME ZONE 'America/New_York')
    $pwhere
    GROUP BY
        DATE(cl.call_time AT TIME ZONE 'America/New_York'),
        cl.user_id,
        cl.campaign_id,
        u.username,
        camp.campaign_code,
        camp.name
    ORDER BY cdate DESC, u.username ASC
    LIMIT :lim OFFSET :off
");
foreach($pparams as $k=>$v) $pst->bindValue($k,$v);
$pst->bindValue(':lim',$perf_limit,PDO::PARAM_INT); $pst->bindValue(':off',$perf_off,PDO::PARAM_INT);
$pst->execute(); $perf_rows=$pst->fetchAll(PDO::FETCH_ASSOC);

$all_campaigns=$pdo->query("SELECT id,campaign_code,name FROM public.campaigns WHERE deleted=FALSE ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Interested leads
$admin_interested = [];
try {
    $admin_interested = $pdo->query("
        SELECT n.*, l.phone, l.business_name, c.campaign_code, c.name AS camp_name, u.username AS agent
        FROM public.interested_notes n
        JOIN public.leads l ON l.id = n.lead_id
        LEFT JOIN public.campaigns c ON c.id = n.campaign_id
        LEFT JOIN public.users u ON u.id = n.user_id
        WHERE n.status != 'deleted'
        ORDER BY n.followup_date ASC NULLS LAST, n.updated_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $admin_interested = []; }

// Live today stats — uses its own dropdown param 'lu', independent of performance filters
$live_user = (int)($_GET['lu'] ?? 0);
$live_stats = null;
if ($live_user > 0) {
    try {
        $ls = $pdo->prepare("SELECT
            SUM(CASE WHEN outcome IN ('interested','not_interested','no_answer','called') THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN outcome='interested' THEN 1 ELSE 0 END) AS interested,
            SUM(CASE WHEN outcome='not_interested' THEN 1 ELSE 0 END) AS not_interested,
            SUM(CASE WHEN outcome='no_answer' THEN 1 ELSE 0 END) AS no_answer
            FROM public.call_logs
            WHERE user_id=:uid AND DATE(call_time AT TIME ZONE 'America/New_York')=CURRENT_DATE");
        $ls->execute([':uid'=>$live_user]);
        $live_stats = $ls->fetch(PDO::FETCH_ASSOC);

        $ls2 = $pdo->prepare("SELECT COALESCE(SUM(total_seconds),0) FROM public.dial_sessions WHERE user_id=:uid AND status='closed' AND DATE(started_at AT TIME ZONE 'America/New_York')=CURRENT_DATE");
        $ls2->execute([':uid'=>$live_user]);
        $live_stats['dial_seconds'] = (int)$ls2->fetchColumn();

        $ls3 = $pdo->prepare("SELECT username FROM public.users WHERE id=?");
        $ls3->execute([$live_user]);
        $live_stats['username'] = (string)$ls3->fetchColumn();
    } catch (Throwable $e) { $live_stats = null; }
}

function pagination_links(int $current,int $total,string $extra='',string $param='p'): string {
    if($total<=1) return '';
    $html='<div class="pages">';
    $html.=$current>1?'<a href="?'.$param.'='.($current-1).$extra.'" class="pg">‹</a>':'<span class="pg disabled">‹</span>';
    $show=[];
    for($i=1;$i<=$total;$i++) if($i===1||$i===$total||abs($i-$current)<=2) $show[]=$i;
    $prev=null;
    foreach($show as $i){ if($prev!==null&&$i-$prev>1) $html.='<span class="pg disabled">…</span>'; $html.='<a href="?'.$param.'='.$i.$extra.'" class="pg'.($i===$current?' on':'').'">'.$i.'</a>'; $prev=$i; }
    $html.=$current<$total?'<a href="?'.$param.'='.($current+1).$extra.'" class="pg">›</a>':'<span class="pg disabled">›</span>';
    return $html.'</div>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leads Lite — Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{
            --blue:#2563eb;--blue-lt:#eff6ff;
            --red:#ef4444;--red-lt:#fef2f2;
            --green:#10b981;--green-lt:#ecfdf5;
            --yellow:#f59e0b;--yel-lt:#fffbeb;
            --gray:#64748b;--border:#e2e8f0;--bg:#f8fafc;
            --surf:#fff;--surf2:#f1f5f9;
            --text:#1e293b;--muted:#64748b;
            --accent:#2563eb;--accent2:#1d4ed8;
            --mono:'Segoe UI',system-ui,sans-serif;--sans:'Segoe UI',system-ui,sans-serif;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html,body{height:100%;}
        body{font-family:var(--sans);background:var(--bg);color:var(--text);font-size:14px;display:flex;}
        .sidebar{width:220px;flex-shrink:0;background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;height:100vh;position:sticky;top:0;box-shadow:1px 0 3px rgba(0,0,0,.04);}
        .logo{padding:22px 18px 18px;border-bottom:1px solid var(--border);}
        .logo-eye{font-size:9px;color:var(--accent);letter-spacing:2px;text-transform:uppercase;margin-bottom:3px;font-weight:700;}
        .logo-name{font-size:17px;font-weight:800;color:var(--text);}
        .logo-role{font-size:10px;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-top:3px;}
        .nav{padding:14px 10px;display:flex;flex-direction:column;gap:2px;flex:1;}
        .nav-sep{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:#cbd5e1;padding:10px 10px 4px;font-weight:700;}
        .nav-item{display:flex;align-items:center;gap:9px;padding:9px 10px;border-radius:7px;color:var(--muted);text-decoration:none;font-size:13px;font-weight:500;transition:all 0.12s;border:1px solid transparent;}
        .nav-item:hover{color:var(--text);background:var(--surf2);}
        .nav-item.active{color:var(--accent);background:var(--blue-lt);border-color:#bfdbfe;}
        .nav-badge{margin-left:auto;background:var(--red-lt);color:var(--red);border:1px solid #fecaca;font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;}
        .sidebar-foot{padding:14px 18px;border-top:1px solid var(--border);}
        .user-chip{font-size:11px;color:var(--muted);margin-bottom:10px;}
        .user-chip span{color:var(--text);font-weight:600;}
        .logout-btn{display:flex;align-items:center;gap:7px;padding:8px 10px;border-radius:7px;color:var(--red);text-decoration:none;font-size:12px;font-weight:600;border:1px solid #fecaca;background:var(--red-lt);transition:background 0.12s;}
        .logout-btn:hover{background:#fee2e2;}
        .main{flex:1;padding:28px 34px;min-width:0;background:var(--bg);}
        .page-hd{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:22px;padding-bottom:16px;border-bottom:1px solid var(--border);}
        .page-title{font-size:20px;font-weight:800;color:var(--text);}
        .page-sub{font-size:12px;color:var(--muted);margin-top:2px;}
        .card{background:#fff;border:1px solid var(--border);border-radius:12px;margin-bottom:20px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);}
        .card-hd{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;background:#f8fafc;}
        .card-label{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);}
        .card-body{padding:18px;}
        .flash{padding:11px 15px;border-radius:8px;margin-bottom:18px;font-size:13px;font-weight:500;}
        .flash.ok{background:var(--green-lt);color:#065f46;border:1px solid #a7f3d0;}
        .flash.err{background:var(--red-lt);color:#991b1b;border:1px solid #fecaca;}
        table{width:100%;border-collapse:collapse;table-layout:fixed;}
        th{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);padding:10px 14px;text-align:left;border-bottom:1px solid var(--border);background:#f8fafc;}
        td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
        tr:last-child td{border-bottom:none;}
        tbody tr:hover td{background:#fafbfc;}
        .prog{display:flex;align-items:center;gap:8px;}
        .prog-track{width:55px;height:3px;background:var(--surf2);border-radius:2px;overflow:hidden;}
        .prog-fill{height:100%;background:var(--accent);border-radius:2px;}
        .prog-lbl{font-size:11px;color:var(--muted);font-weight:600;}
        .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:.5px;}
        .badge-admin{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}
        .badge-user{background:#f1f5f9;color:var(--muted);border:1px solid var(--border);}
        .badge-on{background:var(--green-lt);color:#065f46;border:1px solid #a7f3d0;}
        .badge-off{background:var(--red-lt);color:#991b1b;border:1px solid #fecaca;}
        .code-badge{font-family:monospace;font-size:11px;font-weight:700;color:var(--blue,#2563eb);background:#eff6ff;padding:3px 7px;border-radius:4px;border:1px solid #bfdbfe;white-space:nowrap;}
        .btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:7px;font-size:12px;font-weight:600;font-family:var(--sans);cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .12s;white-space:nowrap;}
        .btn-primary{background:var(--accent);color:#fff;border-color:var(--accent2);}
        .btn-primary:hover{background:var(--accent2);}
        .btn-ghost{background:transparent;color:var(--muted);border-color:var(--border);}
        .btn-ghost:hover{color:var(--text);background:var(--surf2,#f1f5f9);}
        .btn-danger{background:transparent;color:var(--red);border-color:#fecaca;}
        .btn-danger:hover{background:var(--red-lt);}
        .btn-green{background:var(--green-lt);color:#065f46;border-color:#a7f3d0;}
        .btn-green:hover{background:#d1fae5;}
        .btn-sm{padding:5px 9px;font-size:11px;}
        .icon-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:all .12s;background:none;padding:0;flex-shrink:0;vertical-align:middle;}
        .ib-edit{color:var(--accent);border-color:#bfdbfe;background:#eff6ff;}
        .ib-edit:hover{background:#dbeafe;}
        .ib-del{color:var(--red);border-color:#fecaca;background:var(--red-lt);}
        .ib-del:hover{background:#fee2e2;}
        .ib-restore{color:#065f46;border-color:#a7f3d0;background:var(--green-lt);}
        .ib-reset{color:var(--yellow,#f59e0b);border-color:#fde68a;background:#fffbeb;}
        .live-stats-bar{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.06);}
        .live-stats-label{font-size:12px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:7px;margin-bottom:12px;}
        .live-dot{width:8px;height:8px;border-radius:50%;background:#10b981;animation:blink 1.2s infinite;}
        @keyframes blink{0%,100%{opacity:1;}50%{opacity:.3;}}
        .live-stats-pills{display:flex;gap:10px;flex-wrap:wrap;}
        .live-pill{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 16px;display:flex;flex-direction:column;gap:2px;min-width:90px;}
        .live-pill-val{font-size:22px;font-weight:800;}
        .live-pill-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);}
        .live-pill-val.blue{color:#2563eb;}.live-pill-val.green{color:#10b981;}.live-pill-val.red{color:#ef4444;}.live-pill-val.yellow{color:#f59e0b;}.live-pill-val.gray{color:var(--muted);}
        .ib-reset:hover{background:#fef3c7;}
        .ib-restore:hover{background:#d1fae5;}
        .act-row{display:flex;gap:5px;align-items:center;}
        .act-row form{display:contents;margin:0;}
        .inp{background:#fff;border:1px solid var(--border);color:var(--text);padding:9px 12px;border-radius:7px;font-size:13px;font-family:var(--sans);outline:none;transition:border-color .12s;width:100%;}
        .inp:focus{border-color:var(--accent);}
        .inp::placeholder{color:var(--muted);}
        .inp-label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
        .fgroup{margin-bottom:14px;}
        select.inp{cursor:pointer;}
        .checkbox-row{display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;}
        .checkbox-row input{width:15px;height:15px;accent-color:var(--accent);}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
        .filter-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px;}
        .filter-row .fgroup{margin-bottom:0;}
        .pn{font-family:var(--mono);font-weight:700;font-size:14px;}
        .pc-green{color:var(--green);}.pc-red{color:var(--red);}.pc-yellow{color:var(--yellow);}.pc-blue{color:var(--accent);}
        .pages{display:flex;gap:3px;justify-content:center;padding:14px 0 2px;}
        .pg{padding:5px 11px;border-radius:6px;font-size:12px;color:var(--muted);text-decoration:none;border:1px solid var(--border);background:#fff;transition:all .12s;}
        .pg:hover{color:var(--text);border-color:#cbd5e1;}
        .pg.on{background:var(--accent);color:#fff;border-color:var(--accent);}
        .pg.disabled{opacity:.35;cursor:default;pointer-events:none;}
        .search-row{display:flex;gap:8px;align-items:center;}
        .empty{text-align:center;padding:40px 20px;color:var(--muted);}
        .empty-ico{font-size:28px;margin-bottom:8px;opacity:.4;}
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);}
        .modal-overlay.open{display:flex;}
        .modal{background:#fff;border:1px solid var(--border);border-radius:14px;padding:28px;width:100%;max-width:440px;box-shadow:0 8px 32px rgba(0,0,0,.12);}
        .modal-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:20px;}
        .trash-warn{background:var(--yel-lt,#fffbeb);border:1px solid #fde68a;color:#92400e;padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;}
    </style>
</head>
<body>
<aside class="sidebar">
    <div class="logo">
        <div class="logo-eye">SIP Dialer</div>
        <div class="logo-name">Leads Lite</div>
        <div class="logo-role">Admin Panel</div>
    </div>
    <nav class="nav">
        <div class="nav-sep">Manage</div>
        <a href="?tab=campaigns"   class="nav-item <?= $tab==='campaigns'  ?'active':'' ?>">▦ Campaigns</a>
        <a href="?tab=users"       class="nav-item <?= $tab==='users'      ?'active':'' ?>">👤 Users</a>
        <a href="?tab=performance" class="nav-item <?= $tab==='performance'?'active':'' ?>">↗ Performance</a>
        <a href="/leads/interested.php" class="nav-item">✓ Interested</a>
        <a href="/leads/callback.php" class="nav-item">↩ Call Back</a>
        <a href="/leads/tasks.php"      class="nav-item">📅 Due Tasks</a>
        <a href="?tab=trash"       class="nav-item <?= $tab==='trash'      ?'active':'' ?>">
            🗑 Trash <?php if(count($trash)>0): ?><span class="nav-badge"><?= count($trash) ?></span><?php endif; ?>
        </a>
        <div class="nav-sep">Navigate</div>
        <a href="/leads/" class="nav-item">◀ User View</a>
    </nav>
    <div class="sidebar-foot">
        <div class="user-chip">Admin<br><span><?= h(current_username()) ?></span></div>
        <a href="/leads/logout.php" class="logout-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </div>
</aside>
<main class="main">
    <?php if($flash): ?><div class="flash ok">✓ <?= h($flash) ?></div><?php endif; ?>
    <?php if($error): ?><div class="flash err">✕ <?= h($error) ?></div><?php endif; ?>

    <?php if($tab==='campaigns'): ?>
    <div class="page-hd">
        <div><div class="page-title">Campaigns</div><div class="page-sub"><?= $camp_total ?> active</div></div>
        <form method="get" class="search-row">
            <input type="hidden" name="tab" value="campaigns">
            <input type="text" name="cs" value="<?= h($camp_search) ?>" class="inp" placeholder="Search…" style="width:180px;">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if($camp_search): ?><a href="?tab=campaigns" class="btn btn-ghost">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="card">
        <table>
            <thead><tr><th style="width:90px;">Code</th><th>Name</th><th style="width:100px;">Created</th><th style="width:100px;">By</th><th style="width:120px;">Progress</th><th style="width:150px;">Actions</th></tr></thead>
            <tbody>
                <?php if(empty($campaigns)): ?><tr><td colspan="6"><div class="empty"><div class="empty-ico">▦</div><div>No campaigns yet.</div></div></td></tr><?php endif; ?>
                <?php foreach($campaigns as $c):
                    $total=(int)$c['total']; $rem=(int)$c['rem']; $dialed=$total-$rem;
                    $pct=$total>0?round($dialed/$total*100):0; $is_done=$rem===0&&$total>0;
                ?>
                <tr>
                    <td><span class="code-badge"><?= h($c['campaign_code']) ?></span></td>
                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;" title="<?= h($c['name']) ?>"><?= h($c['name']) ?></td>
                    <td style="font-family:var(--mono);font-size:11px;color:var(--muted);"><?= date('d M Y',strtotime((string)$c['created_at'])) ?></td>
                    <td style="font-size:12px;color:var(--muted);"><?= h((string)($c['created_by_name']??'—')) ?></td>
                    <td><div class="prog"><div class="prog-track"><div class="prog-fill" style="width:<?= $pct ?>%;<?= $is_done?'background:var(--green)':'' ?>"></div></div><span class="prog-lbl"><?= $dialed ?>/<?= $total ?></span></div></td>
                    <td>
                        <div class="act-row">
                            <button type="button" class="btn btn-ghost btn-sm" onclick="openAssign(<?= (int)$c['id'] ?>,'<?= h(addslashes($c['campaign_code'])) ?>')">Assign</button>
                            <form method="post" onsubmit="return confirm('Move to trash?');">
                                <input type="hidden" name="action" value="soft_delete">
                                <input type="hidden" name="tab" value="campaigns">
                                <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="icon-btn ib-del" title="Trash">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= pagination_links($camp_page,$camp_pages,'&tab=campaigns'.($camp_search?'&cs='.urlencode($camp_search):''),'cp') ?>
    </div>
    <div class="card">
        <div class="card-hd"><span class="card-label">New Campaign</span></div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_csv">
                <input type="hidden" name="tab" value="campaigns">
                <div class="form-grid">
                    <div class="fgroup"><label class="inp-label">Campaign Name</label><input type="text" name="campaign_name" required class="inp" style="width:100%;" placeholder="e.g. Q2 Outreach"></div>
                    <div class="fgroup"><label class="inp-label">CSV File</label><input type="file" name="csv_file" accept=".csv" class="inp" style="width:100%;"></div>
                </div>
                <div class="fgroup">
                    <label class="inp-label">Assign Users</label>
                    <div style="display:flex;flex-wrap:wrap;gap:12px;">
                        <?php foreach($all_users as $u): if($u['role']==='admin') continue; ?>
                        <label class="checkbox-row"><input type="checkbox" name="assign_users[]" value="<?= (int)$u['id'] ?>"> <?= h((string)$u['username']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;">Upload &amp; Create</button>
            </form>
        </div>
    </div>

    <?php elseif($tab==='users'): ?>
    <div class="page-hd"><div><div class="page-title">Users</div><div class="page-sub"><?= count($all_users) ?> accounts</div></div></div>
    <div class="card">
        <table>
            <thead><tr><th style="width:45px;">ID</th><th>Username</th><th style="width:75px;">Role</th><th style="width:75px;">Upload</th><th style="width:75px;">Delete</th><th style="width:75px;">Status</th><th style="width:130px;">Actions</th></tr></thead>
            <tbody>
                <?php foreach($all_users as $u): ?>
                <tr>
                    <td style="font-family:var(--mono);color:var(--muted);font-size:11px;"><?= (int)$u['id'] ?></td>
                    <td style="font-weight:600;"><?= h((string)$u['username']) ?><?= (int)$u['id']===current_user_id()?'<span style="font-size:10px;color:var(--muted);font-family:var(--mono);margin-left:6px;">(you)</span>':'' ?></td>
                    <td><span class="badge badge-<?= h((string)$u['role']) ?>"><?= strtoupper(h((string)$u['role'])) ?></span></td>
                    <td><span class="badge <?= $u['can_upload']?'badge-on':'badge-off' ?>"><?= $u['can_upload']?'YES':'NO' ?></span></td>
                    <td><span class="badge <?= $u['can_delete']?'badge-on':'badge-off' ?>"><?= $u['can_delete']?'YES':'NO' ?></span></td>
                    <td><span class="badge <?= $u['active']?'badge-on':'badge-off' ?>"><?= $u['active']?'ON':'OFF' ?></span></td>
                    <td>
                        <div class="act-row">
                            <button type="button" class="icon-btn ib-edit" title="Edit" onclick="openEdit(<?= (int)$u['id'] ?>,'<?= h(addslashes($u['username'])) ?>','<?= h($u['role']) ?>',<?= $u['can_upload']?'true':'false' ?>,<?= $u['can_delete']?'true':'false' ?>,<?= $u['active']?'true':'false' ?>)">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <?php if((int)$u['id']!==current_user_id()): ?>
                            <form method="post" style="display:contents;" onsubmit="return confirm('Delete <?= h(addslashes($u['username'])) ?>?');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="tab" value="users">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="icon-btn ib-del" title="Delete User"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
                            </form>
                            <form method="post" style="display:contents;" onsubmit="return confirm('Reset today\'s call logs for <?= h(addslashes($u['username'])) ?>? This cannot be undone.');">
                                <input type="hidden" name="action" value="reset_call_logs">
                                <input type="hidden" name="tab" value="users">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <input type="hidden" name="log_date" value="<?= date('Y-m-d') ?>">
                                <button type="submit" class="icon-btn ib-reset" title="Reset Today's Logs">
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
    <div class="card">
        <div class="card-hd"><span class="card-label">Create User</span></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="create_user">
                <input type="hidden" name="tab" value="users">
                <div class="form-grid">
                    <div class="fgroup"><label class="inp-label">Username</label><input type="text" name="username" required class="inp" style="width:100%;" placeholder="john_agent"></div>
                    <div class="fgroup"><label class="inp-label">Password (min 6)</label><input type="password" name="password" required class="inp" style="width:100%;" placeholder="••••••••"></div>
                    <div class="fgroup"><label class="inp-label">Role</label><select name="role" class="inp" style="width:100%;"><option value="user">User</option><option value="admin">Admin</option></select></div>
                    <div class="fgroup" style="display:flex;flex-direction:column;gap:12px;justify-content:flex-end;">
                        <label class="checkbox-row"><input type="checkbox" name="can_upload"> Can Upload</label>
                        <label class="checkbox-row"><input type="checkbox" name="can_delete"> Can Delete</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;">Create User</button>
            </form>
        </div>
    </div>

    <?php elseif($tab==='performance'): ?>
    <div class="page-hd"><div><div class="page-title">Performance</div><div class="page-sub">All agents · All campaigns</div></div></div>

    <!-- LIVE FEED — completely separate from performance filters -->
    <div class="card">
        <div class="card-hd"><span class="card-label">Live Feed — Today</span></div>
        <div class="card-body" style="padding:14px 18px;">
            <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <input type="hidden" name="tab" value="performance">
                <input type="hidden" name="ps" value="<?= h($p_start) ?>">
                <input type="hidden" name="pe" value="<?= h($p_end) ?>">
                <input type="hidden" name="pu" value="<?= $p_user ?>">
                <input type="hidden" name="pc" value="<?= $p_camp ?>">
                <select name="lu" class="inp" style="width:180px;" onchange="this.form.submit()">
                    <option value="0">— Select agent —</option>
                    <?php foreach($all_users as $u): if($u['role']==='admin') continue; ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $live_user===(int)$u['id']?'selected':'' ?>><?= h((string)$u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if($live_user > 0): ?>
                <a href="?tab=performance&ps=<?= h($p_start) ?>&pe=<?= h($p_end) ?>&pu=<?= $p_user ?>&pc=<?= $p_camp ?>" class="btn btn-ghost btn-sm">✕ Clear</a>
                <?php endif; ?>
            </form>
            <?php if($live_stats !== null): ?>
            <div class="live-stats-pills" style="margin-top:14px;">
                <div class="live-pill"><span class="live-pill-val blue"><?= (int)($live_stats['total']??0) ?></span><span class="live-pill-lbl">Calls</span></div>
                <div class="live-pill"><span class="live-pill-val green"><?= (int)($live_stats['interested']??0) ?></span><span class="live-pill-lbl">Interested</span></div>
                <div class="live-pill"><span class="live-pill-val red"><?= (int)($live_stats['not_interested']??0) ?></span><span class="live-pill-lbl">Not Interested</span></div>
                <div class="live-pill"><span class="live-pill-val yellow"><?= (int)($live_stats['no_answer']??0) ?></span><span class="live-pill-lbl">No Answer</span></div>
                <div class="live-pill"><span class="live-pill-val gray"><?= format_duration((int)($live_stats['dial_seconds']??0)) ?></span><span class="live-pill-lbl">Time Dialing</span></div>
            </div>
            <?php elseif($live_user === 0): ?>
            <p style="margin-top:12px;font-size:12px;color:var(--muted);">Select an agent above to see their live stats for today.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- PERFORMANCE TABLE FILTERS — independent -->
    <div class="card">
        <div class="card-hd"><span class="card-label">Performance Filters</span></div>
        <div class="card-body">
            <form method="get" class="filter-row">
                <input type="hidden" name="tab" value="performance">
                <input type="hidden" name="lu" value="<?= $live_user ?>">
                <div class="fgroup"><label class="inp-label">From</label><input type="date" name="ps" value="<?= h($p_start) ?>" class="inp"></div>
                <div class="fgroup"><label class="inp-label">To</label><input type="date" name="pe" value="<?= h($p_end) ?>" class="inp"></div>
                <div class="fgroup"><label class="inp-label">Agent</label><select name="pu" class="inp"><option value="0">All agents</option><?php foreach($all_users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $p_user===(int)$u['id']?'selected':'' ?>><?= h((string)$u['username']) ?></option><?php endforeach; ?></select></div>
                <div class="fgroup"><label class="inp-label">Campaign</label><select name="pc" class="inp"><option value="0">All</option><?php foreach($all_campaigns as $ac): ?><option value="<?= (int)$ac['id'] ?>" <?= $p_camp===(int)$ac['id']?'selected':'' ?>><?= h($ac['campaign_code'].' — '.$ac['name']) ?></option><?php endforeach; ?></select></div>
                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="?tab=performance&lu=<?= $live_user ?>" class="btn btn-ghost">Reset</a>
            </form>
        </div>
    </div>
    <div class="card">
        <table>
            <thead><tr><th style="width:95px;">Date</th><th style="width:100px;">Agent</th><th style="width:100px;">Campaign</th><th style="width:65px;">✓ Int.</th><th style="width:65px;">✕ No</th><th style="width:65px;">~ N/A</th><th style="width:60px;">Total</th><th style="width:80px;">Time</th><th style="width:80px;">Rate</th></tr></thead>
            <tbody>
                <?php if(empty($perf_rows)): ?><tr><td colspan="9"><div class="empty"><div class="empty-ico">↗</div><div>No data for this period.</div></div></td></tr><?php endif; ?>
                <?php
                $sum_int=0;$sum_no=0;$sum_na=0;$sum_tot=0;$sum_secs=0;
                foreach($perf_rows as $p):
                    $rate=(int)$p['total']>0?round((int)$p['interested']/(int)$p['total']*100):0;
                    $sum_int+=(int)$p['interested'];
                    $sum_no+=(int)$p['not_interested'];
                    $sum_na+=(int)$p['no_answer'];
                    $sum_tot+=(int)$p['total'];
                    $sum_secs+=0; // time shown in live bar
                ?>
                <tr>
                    <td style="font-family:var(--mono);font-size:11px;font-weight:700;"><?= date('d M Y',strtotime((string)$p['cdate'])) ?></td>
                    <td style="font-size:12px;color:var(--muted);"><?= h((string)($p['username']??'—')) ?></td>
                    <td><?php
                        $code = (string)($p['campaign_code'] ?? '');
                        if(str_starts_with($code,'[Deleted')):
                    ?><span style="font-size:11px;color:var(--muted);font-style:italic;"><?= h($code) ?></span><?php
                        elseif($code !== ''):
                    ?><span class="code-badge" style="font-size:9px;"><?= h($code) ?></span><?php
                        else: ?>—<?php endif; ?></td>
                    <td><span class="pn pc-green"><?= (int)$p['interested'] ?></span></td>
                    <td><span class="pn pc-red"><?= (int)$p['not_interested'] ?></span></td>
                    <td><span class="pn pc-yellow"><?= (int)$p['no_answer'] ?></span></td>
                    <td><span class="pn"><?= (int)$p['total'] ?></span></td>
                    <td style="font-family:var(--mono);font-size:12px;color:var(--accent);"><?= format_duration((int)$p['dial_seconds']) ?></td>
                    <td><span class="pn" style="color:<?= $rate>=50?'var(--green)':($rate>=20?'var(--yellow)'  :'var(--red)') ?>;font-weight:800;"><?= $rate ?>%</span></td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($perf_rows)>1): $sum_rate=$sum_tot>0?round($sum_int/$sum_tot*100):0; ?>
                <tr style="background:#f8fafc;border-top:2px solid var(--border);">
                    <td colspan="3" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);">Total (this page)</td>
                    <td><span class="pn pc-green"><?= $sum_int ?></span></td>
                    <td><span class="pn pc-red"><?= $sum_no ?></span></td>
                    <td><span class="pn pc-yellow"><?= $sum_na ?></span></td>
                    <td><span class="pn" style="font-weight:800;"><?= $sum_tot ?></span></td>
                    <td style="color:var(--muted);font-size:12px;">—</td>
                    <td><span class="pn" style="color:<?= $sum_rate>=50?'var(--green)':($sum_rate>=20?'var(--yellow)'  :'var(--red)') ?>;font-weight:800;"><?= $sum_rate ?>%</span></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php $pe=('&tab=performance'.($p_start?'&ps='.urlencode($p_start):'').($p_end?'&pe='.urlencode($p_end):'').($p_user?'&pu='.$p_user:'').($p_camp?'&pc='.$p_camp:'')); echo pagination_links($perf_page,$perf_pages,$pe,'pp'); ?>
    </div>

    <?php elseif($tab==='interested'): ?>
    <div class="page-hd"><div><div class="page-title">Interested Leads</div><div class="page-sub"><?= count($admin_interested) ?> active</div></div></div>
    <div class="card">
        <div style="overflow-x:auto;">
        <table style="table-layout:fixed;width:100%;min-width:620px;">
            <thead>
                <tr>
                    <th style="width:115px;">Phone</th>
                    <th style="width:120px;">Business</th>
                    <th style="width:80px;">Campaign</th>
                    <th style="width:75px;">Agent</th>
                    <th style="width:80px;">Follow-up</th>
                    <th style="width:72px;">Due</th>
                    <th style="width:65px;">Status</th>
                    <th style="width:108px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($admin_interested)): ?>
                <tr><td colspan="8"><div class="empty"><div class="empty-ico">✓</div><div>No interested leads yet.</div></div></td></tr>
                <?php endif; ?>
                <?php foreach($admin_interested as $il):
                    $is_due = $il['followup_date'] && $il['followup_date'] <= date('Y-m-d');
                    $is_paused = $il['status'] === 'paused';
                ?>
                <tr style="<?= $is_paused?'opacity:.55;':'' ?>">
                    <td style="font-family:var(--mono);font-weight:700;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h((string)$il['phone']) ?></td>
                    <td style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h((string)($il['business_name']??'')) ?><?= $il['notes']?' · '.h($il['notes']):'' ?>"><?= $il['business_name']?h((string)$il['business_name']):'—' ?><?php if($il['notes']): ?> <span style="font-size:10px;color:var(--muted);">💬</span><?php endif; ?></td>
                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><span class="code-badge"><?= h((string)($il['campaign_code']??'—')) ?></span></td>
                    <td style="font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h((string)($il['agent']??'—')) ?></td>
                    <td style="font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= $il['followup_method']?h((string)$il['followup_method']):'—' ?></td>
                    <td><?php if($il['followup_date']): ?><span style="font-size:11px;font-weight:700;white-space:nowrap;color:<?= $is_due?'var(--red)':'var(--muted)' ?>;"><?= $is_due?'⚠ ':'' ?><?= date('d M',strtotime($il['followup_date'])) ?></span><?php else: ?>—<?php endif; ?></td>
                    <td><span class="badge <?= $is_paused?'badge-off':'badge-on' ?>"><?= $is_paused?'Paused':'Active' ?></span></td>
                    <td>
                        <div class="act-row">
                            <button type="button" class="icon-btn ib-edit" title="Edit"
                                onclick="openAdminIntEdit(<?= (int)$il['id'] ?>,'<?= h(addslashes($il['notes'])) ?>','<?= h(addslashes($il['followup_method'])) ?>',<?= (int)$il['followup_days'] ?>)">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <form method="post" style="display:contents;">
                                <input type="hidden" name="action" value="set_int_status">
                                <input type="hidden" name="note_id" value="<?= (int)$il['id'] ?>">
                                <input type="hidden" name="tab" value="interested">
                                <input type="hidden" name="new_status" value="<?= $is_paused?'active':'paused' ?>">
                                <button type="submit" class="icon-btn ib-reset" title="<?= $is_paused?'Resume':'Pause' ?>">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><?= $is_paused?'<polygon points="5 3 19 12 5 21 5 3"/>':'<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>' ?></svg>
                                </button>
                            </form>
                            <form method="post" style="display:contents;" onsubmit="return confirm('Remove from interested?');">
                                <input type="hidden" name="action" value="set_int_status">
                                <input type="hidden" name="note_id" value="<?= (int)$il['id'] ?>">
                                <input type="hidden" name="tab" value="interested">
                                <input type="hidden" name="new_status" value="deleted">
                                <button type="submit" class="icon-btn ib-del" title="Remove">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php elseif($tab==='trash'): ?>
    <div class="page-hd"><div><div class="page-title">Trash</div><div class="page-sub"><?= count($trash) ?> deleted</div></div></div>
    <?php if(!empty($trash)): ?><div class="trash-warn">⚠ Permanently deleted campaigns and all their leads cannot be recovered.</div><?php endif; ?>
    <div class="card">
        <table>
            <thead><tr><th style="width:90px;">Code</th><th>Name</th><th style="width:110px;">Deleted By</th><th style="width:130px;">Deleted At</th><th style="width:65px;">Leads</th><th style="width:200px;">Actions</th></tr></thead>
            <tbody>
                <?php if(empty($trash)): ?><tr><td colspan="6"><div class="empty"><div class="empty-ico">🗑</div><div>Trash is empty.</div></div></td></tr><?php endif; ?>
                <?php foreach($trash as $t): ?>
                <tr>
                    <td><span class="code-badge"><?= h($t['campaign_code']) ?></span></td>
                    <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;" title="<?= h($t['name']) ?>"><?= h($t['name']) ?></td>
                    <td style="color:var(--muted);font-size:12px;"><?= h((string)($t['deleted_by_name']??'—')) ?></td>
                    <td style="font-family:var(--mono);font-size:11px;color:var(--muted);"><?= $t['deleted_at']?date('d M Y H:i',strtotime((string)$t['deleted_at'])):'—' ?></td>
                    <td style="font-family:var(--mono);font-size:12px;"><?= (int)$t['total'] ?></td>
                    <td>
                        <div class="act-row">
                            <form method="post" style="display:contents;">
                                <input type="hidden" name="action" value="restore_campaign">
                                <input type="hidden" name="tab" value="trash">
                                <input type="hidden" name="campaign_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-green btn-sm">↩ Restore</button>
                            </form>
                            <form method="post" style="display:contents;" onsubmit="return confirm('PERMANENTLY delete <?= h(addslashes($t['campaign_code'])) ?> and ALL leads? Cannot be undone.');">
                                <input type="hidden" name="action" value="hard_delete">
                                <input type="hidden" name="tab" value="trash">
                                <input type="hidden" name="campaign_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑 Destroy</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</main>

<div class="modal-overlay" id="edit-modal">
    <div class="modal">
        <div class="modal-title">Edit User</div>
        <form method="post">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="tab" value="users">
            <input type="hidden" name="user_id" id="edit-uid">
            <div class="fgroup"><label class="inp-label">Username</label><input type="text" id="edit-uname" class="inp" style="width:100%;" disabled></div>
            <div class="fgroup"><label class="inp-label">New Password <span style="font-weight:400;opacity:.5;">(leave blank to keep)</span></label><input type="password" name="new_password" class="inp" style="width:100%;" placeholder="••••••••"></div>
            <div class="form-grid" style="margin-bottom:14px;">
                <div class="fgroup" style="margin-bottom:0;"><label class="inp-label">Role</label><select name="role" id="edit-role" class="inp" style="width:100%;"><option value="user">User</option><option value="admin">Admin</option></select></div>
                <div class="fgroup" style="margin-bottom:0;display:flex;flex-direction:column;gap:10px;justify-content:flex-end;">
                    <label class="checkbox-row"><input type="checkbox" name="active" id="edit-active"> Active</label>
                    <label class="checkbox-row"><input type="checkbox" name="can_upload" id="edit-cup"> Can Upload</label>
                    <label class="checkbox-row"><input type="checkbox" name="can_delete" id="edit-cdel"> Can Delete</label>
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">Save Changes</button>
                <button type="button" class="btn btn-ghost" onclick="closeEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="assign-modal">
    <div class="modal">
        <div class="modal-title" id="assign-title">Assign Users</div>
        <form method="post">
            <input type="hidden" name="action" value="assign_users">
            <input type="hidden" name="tab" value="campaigns">
            <input type="hidden" name="campaign_id" id="assign-cid">
            <div class="fgroup">
                <label class="inp-label">Users who can dial this campaign</label>
                <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px;">
                    <?php foreach($all_users as $u): if($u['role']==='admin') continue; ?>
                    <label class="checkbox-row"><input type="checkbox" name="assign_users[]" value="<?= (int)$u['id'] ?>" class="assign-chk" data-uid="<?= (int)$u['id'] ?>"> <?= h((string)$u['username']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">Save</button>
                <button type="button" class="btn btn-ghost" onclick="closeAssign()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ADMIN INTERESTED EDIT MODAL -->
<div id="admin-int-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border:1px solid var(--border);border-radius:14px;padding:28px;width:100%;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,.12);">
        <div style="font-size:15px;font-weight:700;color:#1e293b;margin-bottom:18px;">Edit Follow-up</div>
        <form method="post">
            <input type="hidden" name="action" value="update_int_note">
            <input type="hidden" name="tab" value="interested">
            <input type="hidden" name="note_id" id="ai-note-id">
            <div class="fgroup">
                <label class="inp-label">Notes</label>
                <textarea id="ai-notes" name="notes" class="inp" rows="4" style="resize:vertical;width:100%;"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="fgroup" style="margin-bottom:0;">
                    <label class="inp-label">Follow-up Method</label>
                    <select name="followup_method" id="ai-method" class="inp">
                        <option value="">— None —</option>
                        <option value="Call">📞 Call</option>
                        <option value="WhatsApp">💬 WhatsApp</option>
                        <option value="Email">✉ Email</option>
                        <option value="Visit">🚗 Visit</option>
                    </select>
                </div>
                <div class="fgroup" style="margin-bottom:0;">
                    <label class="inp-label">Follow-up in (days)</label>
                    <input type="number" name="followup_days" id="ai-days" class="inp" min="0" max="365" value="0">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">Save Changes</button>
                <button type="button" class="btn btn-ghost" onclick="closeAdminIntEdit()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAdminIntEdit(id, notes, method, days) {
    document.getElementById('ai-note-id').value = id;
    document.getElementById('ai-notes').value = notes;
    var sel = document.getElementById('ai-method');
    for (var i=0; i<sel.options.length; i++) {
        if (sel.options[i].value === method) { sel.selectedIndex = i; break; }
    }
    document.getElementById('ai-days').value = days;
    document.getElementById('admin-int-modal').style.display = 'flex';
    document.getElementById('ai-notes').focus();
}
function closeAdminIntEdit() {
    document.getElementById('admin-int-modal').style.display = 'none';
}
document.getElementById('admin-int-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAdminIntEdit();
});

function openEdit(id,username,role,canUpload,canDelete,active){
    document.getElementById('edit-uid').value=id;
    document.getElementById('edit-uname').value=username;
    document.getElementById('edit-role').value=role;
    document.getElementById('edit-cup').checked=canUpload;
    document.getElementById('edit-cdel').checked=canDelete;
    document.getElementById('edit-active').checked=active;
    document.getElementById('edit-modal').classList.add('open');
}
function closeEdit(){ document.getElementById('edit-modal').classList.remove('open'); }
document.getElementById('edit-modal').addEventListener('click',function(e){ if(e.target===this) closeEdit(); });

var assignData=<?php
$assign_map=[];
$rows=$pdo->query("SELECT campaign_id,user_id FROM public.campaign_users")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) $assign_map[(int)$r['campaign_id']][]=(int)$r['user_id'];
echo json_encode($assign_map);
?>;
function openAssign(cid,code){
    document.getElementById('assign-cid').value=cid;
    document.getElementById('assign-title').textContent='Assign Users — '+code;
    var assigned=assignData[cid]||[];
    document.querySelectorAll('.assign-chk').forEach(function(chk){ chk.checked=assigned.indexOf(parseInt(chk.dataset.uid))!==-1; });
    document.getElementById('assign-modal').classList.add('open');
}
function closeAssign(){ document.getElementById('assign-modal').classList.remove('open'); }
document.getElementById('assign-modal').addEventListener('click',function(e){ if(e.target===this) closeAssign(); });
</script>
</body>
</html>
