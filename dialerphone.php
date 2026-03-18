<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require_login();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function normalize_phone(string $raw): string {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === null) $digits = '';
    $digits = ltrim($digits, '0');
    return (strlen($digits) > 15) ? substr($digits, -15) : $digits;
}

$uid    = current_user_id();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// Release session lock immediately for AJAX requests so concurrent
// calls (save_outcome, next_lead, ping) don't queue behind each other
if ($action !== '') {
    session_write_close();
}

// ── AJAX: save outcome ────────────────────────────────────────────────────────
if ($action === 'save_outcome' && isset($_POST['lead_id'])) {
    $lead_id = (int)$_POST['lead_id'];
    $cid     = (int)$_POST['campaign_id'];
    $outcome = $_POST['outcome'] ?? 'called';
    $sess_id = (int)($_POST['dial_session_id'] ?? 0);
    $allowed = ['interested','not_interested','no_answer','called','callback'];
    if (!in_array($outcome, $allowed, true)) $outcome = 'called';

    // Map callback to 'called' for lead status (keeps it dialable)
    $lead_status = $outcome === 'callback' ? 'called' : $outcome;
    $pdo->prepare("UPDATE public.leads SET status=:st, last_result=:res, last_call_time=(NOW() AT TIME ZONE 'America/New_York'), attempts=attempts+1 WHERE id=:id")
        ->execute([':st'=>$lead_status,':res'=>$outcome,':id'=>$lead_id]);
    $pdo->prepare("INSERT INTO public.call_logs (lead_id,campaign_id,user_id,outcome) VALUES (:lid,:cid,:uid,:out)")
        ->execute([':lid'=>$lead_id,':cid'=>$cid,':uid'=>$uid,':out'=>$outcome]);
    if ($outcome === 'interested') {
        $pdo->prepare("INSERT INTO public.interested_notes (lead_id,campaign_id,user_id,status) VALUES (:lid,:cid,:uid,'active') ON CONFLICT (lead_id) DO UPDATE SET status='active', updated_at=NOW()")
            ->execute([':lid'=>$lead_id,':cid'=>$cid,':uid'=>$uid]);
    }
    if ($outcome === 'callback') {
        $pdo->prepare("INSERT INTO public.callback_notes (lead_id,campaign_id,user_id,status) VALUES (:lid,:cid,:uid,'active') ON CONFLICT (lead_id) DO UPDATE SET status='active', updated_at=NOW()")
            ->execute([':lid'=>$lead_id,':cid'=>$cid,':uid'=>$uid]);
    }
    if ($sess_id > 0) {
        $pdo->prepare("UPDATE public.dial_sessions SET status='closed', ended_at=NOW() AT TIME ZONE 'America/New_York', total_seconds=GREATEST(0,EXTRACT(EPOCH FROM (last_ping-started_at))::INT) WHERE id=:sid AND user_id=:uid AND status='active'")
            ->execute([':sid'=>$sess_id,':uid'=>$uid]);
    }
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true]);
    exit;
}

// ── AJAX: get next lead ───────────────────────────────────────────────────────
if ($action === 'next_lead' && isset($_GET['campaign_id'])) {
    $cid = (int)$_GET['campaign_id'];
    // close stale sessions
    $pdo->prepare("UPDATE public.dial_sessions SET status='closed', ended_at=NOW() AT TIME ZONE 'America/New_York', total_seconds=GREATEST(0,EXTRACT(EPOCH FROM (last_ping-started_at))::INT) WHERE status='active' AND last_ping < ((NOW() AT TIME ZONE 'America/New_York') - INTERVAL '5 minutes')")->execute();
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT id, business_name, phone FROM public.leads WHERE campaign_id=:cid AND status='new' ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
    $st->execute([':cid'=>$cid]);
    $lead = $st->fetch(PDO::FETCH_ASSOC);
    $sess_id = 0;
    if ($lead) {
        $pdo->prepare("UPDATE public.leads SET status='calling' WHERE id=:id")->execute([':id'=>$lead['id']]);
        $ds = $pdo->prepare("INSERT INTO public.dial_sessions (user_id,campaign_id) VALUES (:uid,:cid) RETURNING id");
        $ds->execute([':uid'=>$uid,':cid'=>$cid]);
        $sess_id = (int)$ds->fetchColumn();
        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true,'lead'=>$lead,'dial_session_id'=>$sess_id]);
    } else {
        $pdo->commit();
        header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'message'=>'No more leads in this campaign.']);
    }
    exit;
}

// ── AJAX: heartbeat ───────────────────────────────────────────────────────────
if ($action === 'ping' && isset($_POST['dial_session_id'])) {
    $sess_id = (int)$_POST['dial_session_id'];
    $pdo->prepare("UPDATE public.dial_sessions SET last_ping=NOW() AT TIME ZONE 'America/New_York' WHERE id=:sid AND user_id=:uid AND status='active'")
        ->execute([':sid'=>$sess_id,':uid'=>$uid]);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Load campaigns for this user ──────────────────────────────────────────────
$campaigns = [];
try {
    if (is_admin()) {
        $st = $pdo->query("SELECT c.id, c.name, c.campaign_code, COUNT(l.id) FILTER (WHERE l.status='new') as remaining FROM public.campaigns c LEFT JOIN public.leads l ON l.campaign_id=c.id GROUP BY c.id ORDER BY c.id DESC");
    } else {
        $st = $pdo->prepare("SELECT c.id, c.name, c.campaign_code, COUNT(l.id) FILTER (WHERE l.status='new') as remaining FROM public.campaigns c JOIN public.campaign_users cu ON cu.campaign_id=c.id LEFT JOIN public.leads l ON l.campaign_id=c.id WHERE cu.user_id=:u GROUP BY c.id ORDER BY c.id DESC");
        $st->execute([':u'=>$uid]);
    }
    $campaigns = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Dialer Phone</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="/saraphone/css/bootstrap.min.css" rel="stylesheet">
<style>
:root {
    --bg:      #f8fafc;
    --surf:    #ffffff;
    --surf2:   #f1f5f9;
    --border:  #e2e8f0;
    --text:    #1e293b;
    --muted:   #64748b;
    --gray:    #64748b;
    --green:   #10b981;
    --green-dk:#059669;
    --green-lt:#ecfdf5;
    --red:     #ef4444;
    --red-dk:  #dc2626;
    --red-lt:  #fef2f2;
    --blue:    #2563eb;
    --blue-lt: #eff6ff;
    --yellow:  #f59e0b;
    --yel-lt:  #fffbeb;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

/* TOPBAR */
.topbar {
    position: fixed; top: 0; left: 0; right: 0;
    display: flex; justify-content: space-between; align-items: center;
    padding: 10px 20px;
    background: var(--surf);
    border-bottom: 1px solid var(--border);
    z-index: 100;
    flex-wrap: wrap;
    gap: 8px;
}
.topbar-title, .topbar-title:hover { font-size: 15px; font-weight: 700; color: var(--text); text-decoration: none; }
.topbar-links { display: flex; gap: 6px; align-items: center; font-size: 12px; flex-wrap: wrap; }
.tl-btn { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 6px; text-decoration: none; border: 1px solid; }
.tl-purple { color: #7c3aed; border-color: #c4b5fd; background: #f5f3ff; }
.tl-green  { color: #065f46; border-color: #a7f3d0; background: #ecfdf5; }
.tl-blue   { color: #1d4ed8; border-color: #bfdbfe; background: #eff6ff; }
.tl-red    { color: #991b1b; border-color: #fecaca; background: #fef2f2; }
.tl-orange { color: #f97316; border-color: #ea580c; background: #fff7ed; } .tl-orange:hover { background: #ffedd5; color: #c2410c; }

/* LAYOUT */
.main-wrap {
    display: flex;
    gap: 20px;
    padding: 80px 20px 20px;
    max-width: 1100px;
    margin: 0 auto;
}

/* LEFT: Phone */
.phone-col { width: 320px; flex-shrink: 0; }

/* RIGHT: Lead panel */
.lead-col { flex: 1; min-width: 0; }

/* PHONE CARD */
.phone-card {
    background: var(--surf);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(0,0,0,.08);
}
.status-bar {
    padding: 8px 16px;
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 600;
    border-bottom: 1px solid var(--border);
    background: var(--surf2);
}
.status-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.status-dot.unregistered { background: var(--muted); }
.status-dot.registering  { background: var(--yellow); animation: pulse 1s infinite; }
.status-dot.registered   { background: var(--green); }
.status-dot.error        { background: var(--red); }
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.3} }

.phone-display {
    padding: 16px;
    text-align: center;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}
.phone-number {
    font-size: 24px; font-weight: 300; letter-spacing: 2px;
    font-family: monospace; color: var(--text); min-height: 36px;
}
.phone-status { font-size: 12px; color: var(--muted); min-height: 18px; margin-top: 4px; }
.phone-timer  { font-size: 18px; font-weight: 600; color: var(--green); font-family: monospace; display: none; margin-top: 4px; }

.keypad {
    display: grid; grid-template-columns: repeat(3,1fr);
    gap: 1px; background: var(--border);
}
.key {
    background: var(--surf); border: none; padding: 14px 8px;
    cursor: pointer; text-align: center; transition: background .08s;
}
.key:hover { background: #f1f5f9; }
.key-digit { font-size: 18px; font-weight: 500; color: var(--text); }
.key-sub   { font-size: 9px; color: var(--muted); letter-spacing: 1px; margin-top: 2px; }

.phone-actions {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 10px; padding: 14px 16px;
    background: var(--surf2);
    border-top: 1px solid var(--border);
}
.act-btn {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 4px; padding: 10px 6px;
    border: none; border-radius: 10px; cursor: pointer;
    font-size: 10px; font-weight: 600; color: #334155; transition: all .12s;
}
.btn-call   { background: var(--green); grid-column: span 3; flex-direction: row; gap: 8px; font-size: 13px; padding: 13px; }
.btn-call:hover { background: var(--green-dk); }
.btn-hangup { background: var(--red); grid-column: span 3; flex-direction: row; gap: 8px; font-size: 13px; padding: 13px; }
.btn-hangup:hover { background: var(--red-dk); }
.btn-gray   { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
.btn-gray:hover { background: #e2e8f0; }
.btn-mute.active { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.btn-hold.active { background: var(--blue); }

/* SIP SETUP */
.sip-setup-link { text-align: center; padding: 10px; font-size: 11px; color: var(--muted); }
.sip-setup-link a { color: var(--blue); text-decoration: none; }

/* MODAL */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,23,42,.4); z-index: 300;
    align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--surf); border: 1px solid var(--border);
    border-radius: 14px; padding: 24px; width: 100%; max-width: 320px;
}
.modal-box h2 { font-size: 17px; font-weight: 700; margin-bottom: 14px; }
.inp-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 4px; }
.inp {
    width: 100%; background: var(--bg); border: 1px solid var(--border);
    color: var(--text); padding: 9px 11px; border-radius: 7px;
    font-size: 13px; font-family: inherit; outline: none; margin-bottom: 12px;
}
.inp:focus { border-color: var(--blue); }
.btn-primary-full {
    width: 100%; background: var(--blue); color: #fff; border: none;
    padding: 11px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.modal-error { color: var(--red); font-size: 12px; margin-bottom: 10px; display: none; }

/* LEAD PANEL */
.lead-panel {
    background: var(--surf);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: visible;
}
.lead-panel-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    background: var(--surf2);
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    flex-wrap: wrap;
    border-radius: 16px 16px 0 0;
}
.lead-panel-header h3 { font-size: 14px; font-weight: 700; }
.campaign-select {
    background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 6px 10px; border-radius: 7px; font-size: 13px; font-family: inherit; outline: none;
}
/* Searchable campaign dropdown */
.camp-search-wrap { position: relative; min-width: 220px; }
.camp-search-trigger {
    background: var(--surf); border: 1px solid var(--border); color: var(--text);
    padding: 7px 12px; border-radius: 8px; font-size: 13px; cursor: pointer;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    user-select: none; transition: border-color .12s;
}
.camp-search-trigger:hover { border-color: #94a3b8; }
.camp-search-trigger.open { border-color: var(--blue); }
.camp-arrow { font-size: 11px; color: var(--muted); transition: transform .15s; }
.camp-arrow.open { transform: rotate(180deg); }
.camp-dropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: var(--surf); border: 1px solid var(--border); border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 200; overflow: hidden;
}
.camp-search-input {
    width: 100%; padding: 9px 12px; border: none; border-bottom: 1px solid var(--border);
    font-size: 13px; font-family: inherit; outline: none; background: var(--bg); color: var(--text);
}
.camp-options { max-height: 220px; overflow-y: auto; }
.camp-option {
    padding: 9px 12px; font-size: 13px; cursor: pointer; transition: background .08s;
    border-radius: 6px; margin: 2px 4px;
}
.camp-option:hover { background: #eff6ff; color: #2563eb; }
.camp-option.selected { background: #eff6ff; color: #2563eb; font-weight: 600; }
.camp-option.hidden { display: none; }
.lead-body { padding: 20px 18px; }

/* Current lead card */
.lead-card {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 16px;
}
.lead-card-name { font-size: 20px; font-weight: 700; margin-bottom: 6px; }
.lead-card-phone { font-size: 28px; font-weight: 300; font-family: monospace; color: var(--green); margin-bottom: 4px; }
.lead-card-meta  { font-size: 12px; color: var(--muted); }

/* Outcome buttons — matches index.php exactly */
.outcome-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 10px; margin-bottom: 16px;
}
.outcome-btn {
    padding: 16px 8px; border-radius: 10px; border: 2px solid transparent;
    font-size: 13px; font-weight: 700; cursor: pointer;
    display: flex; flex-direction: column; align-items: center; gap: 5px;
    transition: all .12s; background: #f8fafc; color: var(--gray);
}
.outcome-btn .ico { font-size: 22px; }
.out-interested     { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
.out-interested:hover:not(:disabled) { background: #d1fae5; }
.out-not-interested { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.out-not-interested:hover:not(:disabled) { background: #fee2e2; }
.out-no-answer      { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
.out-no-answer:hover:not(:disabled) { background: #e2e8f0; }
.out-callback       { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.out-callback:hover:not(:disabled) { background: #fef3c7; }

.autodial-bar {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 18px;
    border-top: 1px solid var(--border);
    background: var(--surf2);
    flex-wrap: wrap;
}
.autodial-label { font-size: 12px; color: var(--muted); }
.toggle-btn {
    padding: 7px 14px; border: none; border-radius: 7px;
    font-size: 12px; font-weight: 600; cursor: pointer; color: #fff;
    background: var(--blue);
}
.toggle-btn.active { background: var(--red); }
.delay-select {
    background: var(--bg); border: 1px solid var(--border); color: var(--text);
    padding: 5px 8px; border-radius: 6px; font-size: 12px; font-family: inherit;
}

.no-lead-msg { text-align: center; padding: 40px 20px; color: var(--muted); font-size: 14px; }
/* Countdown circle */
.countdown-wrap { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; gap: 14px; }
.countdown-circle { position: relative; width: 80px; height: 80px; }
.countdown-circle svg { transform: rotate(-90deg); }
.countdown-circle .track { fill: none; stroke: #e2e8f0; stroke-width: 6; }
.countdown-circle .progress { fill: none; stroke: var(--blue); stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset .1s linear; }
.countdown-circle .num { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; color: var(--blue); font-family: monospace; }
.countdown-label { font-size: 13px; color: var(--muted); font-weight: 500; }
/* Countdown circle */
.countdown-wrap { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; gap: 14px; }
.countdown-circle { position: relative; width: 80px; height: 80px; }
.countdown-circle svg { transform: rotate(-90deg); }
.countdown-circle .track { fill: none; stroke: #e2e8f0; stroke-width: 6; }
.countdown-circle .progress { fill: none; stroke: var(--blue); stroke-width: 6; stroke-linecap: round; transition: stroke-dashoffset .1s linear; }
.countdown-circle .num { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; color: var(--blue); font-family: monospace; }
.countdown-label { font-size: 13px; color: var(--muted); font-weight: 500; }
.flash-msg { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }

/* Incoming call */
.incoming-notify {
    display: none; position: fixed; bottom: 20px; right: 20px;
    background: var(--surf); border: 1px solid var(--green);
    border-radius: 14px; padding: 14px 18px; z-index: 400;
    box-shadow: 0 8px 32px rgba(16,185,129,.3); min-width: 240px;
}
.incoming-notify.show { display: block; }
.incoming-caller { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
.incoming-label  { font-size: 11px; color: var(--muted); margin-bottom: 12px; }
.incoming-btns   { display: flex; gap: 8px; }
.inc-answer { flex:1; background:var(--green); color:#fff; border:none; padding:9px; border-radius:7px; font-weight:600; cursor:pointer; }
.inc-reject { flex:1; background:var(--red); color:#fff; border:none; padding:9px; border-radius:7px; font-weight:600; cursor:pointer; }
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <a href="/leads/" class="topbar-title">📞 Dialer Phone</a>
    <div class="topbar-links">
        Signed in as <strong><?= h(current_username()) ?></strong>
        <a href="/leads/" class="tl-btn tl-purple">▦ Campaigns</a>
        <a href="/leads/myperf.php" class="tl-btn tl-blue">↗ Performance</a>
        <a href="/leads/interested.php" class="tl-btn tl-green">✓ Interested</a>
<a href="/leads/callback.php" class="tl-btn tl-orange">✓ Call Back</a>
        <a href="/leads/tasks.php" class="tl-btn tl-blue">📅 Tasks</a>
        <a href="/leads/logout.php" class="tl-btn tl-red">Sign Out</a>
    </div>
</div>

<div class="main-wrap">

    <!-- ── PHONE ── -->
    <div class="phone-col">
        <div class="phone-card">
            <div class="status-bar">
                <div class="status-dot unregistered" id="status-dot"></div>
                <span id="status-text">Not connected</span>
                <span style="margin-left:auto;font-size:11px;color:var(--muted)" id="ext-display"></span>
            </div>
            <div class="phone-display">
                <div class="phone-number" id="display-number">—</div>
                <div class="phone-status" id="display-status">Enter a number or select a lead</div>
                <div class="phone-timer" id="call-timer">00:00</div>
            </div>
            <div class="keypad">
                <?php
                $keys = [
                    ['1',''],['2','ABC'],['3','DEF'],
                    ['4','GHI'],['5','JKL'],['6','MNO'],
                    ['7','PQRS'],['8','TUV'],['9','WXYZ'],
                    ['*',''],['0','+'],['#',''],
                ];
                foreach ($keys as $k): ?>
                <button class="key" onclick="pressKey('<?= $k[0] ?>')">
                    <div class="key-digit"><?= $k[0] ?></div>
                    <?php if ($k[1]): ?><div class="key-sub"><?= $k[1] ?></div><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- IDLE ACTIONS -->
            <div class="phone-actions" id="actions-idle">
                <button class="act-btn btn-gray" onclick="backspace()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"/><line x1="18" y1="9" x2="12" y2="15"/><line x1="12" y1="9" x2="18" y2="15"/></svg>
                    Delete
                </button>
                <button class="act-btn btn-gray" onclick="clearNumber()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Clear
                </button>
                <button class="act-btn btn-gray" onclick="showSetup()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Setup
                </button>
                <button class="act-btn btn-call" onclick="makeCall()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.35 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    Call
                </button>
            </div>

            <!-- IN-CALL ACTIONS -->
            <div class="phone-actions" id="actions-incall" style="display:none;">
                <button class="act-btn btn-gray btn-mute" id="mute-btn" onclick="toggleMute()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                    <span id="mute-label">Mute</span>
                </button>
                <button class="act-btn btn-gray btn-hold" id="hold-btn" onclick="toggleHold()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                    <span id="hold-label">Hold</span>
                </button>
                <button class="act-btn btn-gray" onclick="sendDTMFMode()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                    Keypad
                </button>
                <button class="act-btn btn-hangup" onclick="hangUp()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.6M23 1 1 23"/></svg>
                    Hang Up
                </button>
            </div>
        </div>
        <div class="sip-setup-link"><a href="#" onclick="showSetup();return false;">⚙ SIP Settings</a></div>
    </div>

    <!-- ── LEAD PANEL ── -->
    <div class="lead-col">
        <div class="lead-panel">
            <div class="lead-panel-header">
                <h3>📋 Leads Dialer</h3>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <div class="camp-search-wrap" id="camp-search-wrap">
                        <div class="camp-search-trigger" id="camp-trigger" onclick="toggleCampDropdown()">
                            <span id="camp-trigger-text">— Select Campaign —</span>
                            <span class="camp-arrow" id="camp-arrow">▾</span>
                        </div>
                        <div class="camp-dropdown" id="camp-dropdown" style="display:none;">
                            <input type="text" class="camp-search-input" id="camp-search-input"
                                placeholder="Search campaigns…" oninput="filterCampaigns(this.value)" onkeydown="event.stopPropagation()" onclick="event.stopPropagation()">
                            <div class="camp-options" id="camp-options">
                                <?php foreach ($campaigns as $c): ?>
                                <div class="camp-option" data-value="<?= h((string)$c['id']) ?>"
                                     onclick="selectCampaign('<?= h((string)$c['id']) ?>','<?= h(addslashes($c['name'])) ?> (<?= (int)($c['remaining']??0) ?> left)')">
                                    <?= h($c['name']) ?> <span style="color:var(--muted);font-size:11px;">(<?= (int)($c['remaining']??0) ?> left)</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" id="campaign-select" value="">
                    </div>
                    <button class="toggle-btn" id="autodial-toggle" onclick="toggleAutodial()" style="display:none;">▶ Start Autodial</button>
                </div>
            </div>

            <div class="lead-body" id="lead-body">
                <div class="no-lead-msg">Select a campaign above to start dialing leads.</div>
            </div>

            <div class="autodial-bar" id="autodial-bar" style="display:none;">
                <span class="autodial-label">Auto-advance after hangup:</span>
                <select class="delay-select" id="autodial-delay">
                    <option value="0">Immediately</option>
                    <option value="3" selected>3 seconds</option>
                    <option value="5">5 seconds</option>
                    <option value="10">10 seconds</option>
                </select>
                <span class="autodial-label" id="autodial-status" style="color:var(--green);"></span>
            </div>
        </div>
    </div>

</div>

<!-- INCOMING CALL -->
<div class="incoming-notify" id="incoming-notify">
    <div class="incoming-caller" id="incoming-caller">Unknown</div>
    <div class="incoming-label">📞 Incoming call…</div>
    <div class="incoming-btns">
        <button class="inc-answer" onclick="answerCall()">Answer</button>
        <button class="inc-reject" onclick="rejectCall()">Decline</button>
    </div>
</div>

<!-- SIP SETUP MODAL -->
<div class="modal-overlay" id="setup-modal">
    <div class="modal-box">
        <h2>📞 SIP Setup</h2>
        <label class="inp-label">Extension</label>
        <input type="text" class="inp" id="setup-ext" placeholder="e.g. 5001">
        <label class="inp-label">Password</label>
        <input type="password" class="inp" id="setup-pass" placeholder="••••••••">
        <label class="inp-label">Display Name (optional)</label>
        <input type="text" class="inp" id="setup-name" placeholder="e.g. John">
        <div class="modal-error" id="setup-error"></div>
        <button class="btn-primary-full" onclick="saveSetup()">Connect</button>
    </div>
</div>

<!-- Remote audio element (SaraPhone-style) -->
<video id="audio" width="1" autoplay playsinline style="position:absolute;left:-9999px;"></video>

<script type="text/javascript" src="/saraphone/js/adapter.js"></script>
<script type="text/javascript" src="/saraphone/js/jquery.min.js"></script>
<script type="text/javascript" src="/saraphone/js/sip.js"></script>
<script>
// ── Config ────────────────────────────────────────────────────────────────────
var WS_SERVER  = 'wss://sip.domain.com:7443';
var SIP_DOMAIN = 'domain.com';

// ── State ─────────────────────────────────────────────────────────────────────
var ua = null;
var cur_call = null;
var incomingSession = null;
var isMuted  = false;
var isOnHold = false;
var timerInterval = null;
var timerSeconds  = 0;
var dialedNumber  = '';
var isRegistered  = false;

// Lead/autodial state
var currentLead       = null;
var dialSessionId     = 0;
var selectedCampaign  = 0;
var autodialActive    = false;
var savingOutcome     = false;
var autodialTimer     = null;
var pingInterval      = null;

// ── DOM ───────────────────────────────────────────────────────────────────────
function $(id) { return document.getElementById(id); }
function setStatus(state, text) {
    $('status-dot').className = 'status-dot ' + state;
    $('status-text').textContent = text;
}
function setDisplay(number, status) {
    $('display-number').textContent = number || '—';
    $('display-status').textContent = status || '';
}

// ── Audio feedback ────────────────────────────────────────────────────────────
var audioCtx = null;
function getAudioCtx() {
    if (!audioCtx) {
        var C = window.AudioContext || window.webkitAudioContext;
        if (C) audioCtx = new C();
    }
    return audioCtx;
}
function beep(duration, freq, vol) {
    try {
        var ctx = getAudioCtx(); if (!ctx) return;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        gain.gain.value = vol || 0.2;
        osc.frequency.value = freq || 440;
        osc.type = 'sine'; osc.start();
        osc.stop(ctx.currentTime + (duration || 0.08));
    } catch(e) {}
}
function playKeyClick() { beep(0.06, 800, 0.15); }

// ── Number input ──────────────────────────────────────────────────────────────
function pressKey(k) {
    playKeyClick();
    dialedNumber += k;
    $('display-number').textContent = dialedNumber;
    $('display-status').textContent = '';
    if (cur_call && typeof cur_call.dtmf === 'function') {
        try { cur_call.dtmf(k, {duration:100,interToneGap:100}); } catch(e) {}
    }
}
function backspace() {
    dialedNumber = dialedNumber.slice(0,-1);
    $('display-number').textContent = dialedNumber || '—';
}
function clearNumber() {
    dialedNumber = '';
    $('display-number').textContent = '—';
    $('display-status').textContent = 'Enter a number or select a lead';
}
function sendDTMFMode() {
    $('display-status').textContent = 'DTMF mode — press keys';
}

// ── Timer ─────────────────────────────────────────────────────────────────────
function startTimer() {
    timerSeconds = 0;
    $('call-timer').style.display = 'block';
    timerInterval = setInterval(function() {
        timerSeconds++;
        var m = String(Math.floor(timerSeconds/60)).padStart(2,'0');
        var s = String(timerSeconds%60).padStart(2,'0');
        $('call-timer').textContent = m+':'+s;
    }, 1000);
}
function stopTimer() {
    clearInterval(timerInterval);
    $('call-timer').style.display = 'none';
    $('call-timer').textContent = '00:00';
}
function showIdle() {
    $('actions-idle').style.display = 'grid';
    $('actions-incall').style.display = 'none';
    stopTimer();
}
function showInCall() {
    $('actions-idle').style.display = 'none';
    $('actions-incall').style.display = 'grid';
    startTimer();
}
function resetCallUI() {
    isMuted = false; isOnHold = false;
    $('mute-btn').classList.remove('active'); $('mute-label').textContent = 'Mute';
    $('hold-btn').classList.remove('active'); $('hold-label').textContent = 'Hold';
}

// ── SIP Setup ─────────────────────────────────────────────────────────────────
function showSetup() {
    $('setup-ext').value  = localStorage.getItem('sip_ext')  || '';
    $('setup-pass').value = localStorage.getItem('sip_pass') || '';
    $('setup-name').value = localStorage.getItem('sip_name') || '';
    $('setup-modal').classList.add('open');
    setTimeout(function(){ ($('setup-ext').value ? $('setup-pass') : $('setup-ext')).focus(); }, 80);
}
function hideSetup() { $('setup-modal').classList.remove('open'); }
function saveSetup() {
    var ext  = $('setup-ext').value.trim();
    var pass = $('setup-pass').value;
    if (!ext || !pass) { $('setup-error').textContent = 'Extension and password required.'; $('setup-error').style.display='block'; return; }
    $('setup-error').style.display = 'none';
    var name = $('setup-name').value.trim() || ext;
    localStorage.setItem('sip_ext', ext);
    localStorage.setItem('sip_pass', pass);
    localStorage.setItem('sip_name', name);
    hideSetup();
    initSIP(ext, pass, name);
}

// ── JsSIP / SIP.js init ───────────────────────────────────────────────────────
function initSIP(ext, pass, name) {
    if (ua) { try { ua.stop(); } catch(e) {} ua = null; }
    setStatus('registering', 'Connecting…');
    $('ext-display').textContent = 'Ext ' + ext;

    ua = new SIP.UA({
        wsServers        : WS_SERVER,
        uri              : ext + '@' + 'sip.domain.com',
        password         : pass,
        displayName      : name || ext,
        hackWssInTransport: true,
        registerExpires  : 300,
        sessionTimers    : false,
        log              : { level: 1 }
    });

    ua.on('registered', function() {
        setStatus('registered', 'Ready');
        isRegistered = true;
    });
    ua.on('unregistered', function() {
        setStatus('unregistered', 'Not registered');
        isRegistered = false;
    });
    ua.on('registrationFailed', function() {
        setStatus('error', 'Auth failed — check credentials');
        setTimeout(showSetup, 1000);
    });
    ua.on('disconnected', function() {
        setStatus('error', 'Disconnected…');
        isRegistered = false;
    });

    // Incoming call
    ua.on('invite', function(session) {
        incomingSession = session;
        var caller = session.remoteIdentity ? session.remoteIdentity.uri.user : 'Unknown';
        $('incoming-caller').textContent = caller;
        $('incoming-notify').classList.add('show');
        session.once('cancel', function() { $('incoming-notify').classList.remove('show'); incomingSession = null; });
        session.once('failed', function() { $('incoming-notify').classList.remove('show'); incomingSession = null; });
    });
}

// ── Make call (SaraPhone-style with render:remote) ────────────────────────────
function makeCall() {
    if (!ua || !isRegistered) { setDisplay(dialedNumber || '—', '⚠ Not registered'); return; }
    var num = dialedNumber.trim();
    if (!num) { setDisplay('—', '⚠ Enter a number first'); return; }

    if (cur_call) { try { cur_call.terminate(); } catch(e) {} cur_call = null; }

    setDisplay(num, '📞 Calling…');
    $('actions-idle').style.display = 'none';
    $('actions-incall').style.display = 'grid';

    cur_call = ua.invite(num, {
        media: {
            constraints: { audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false }, video: false },
            render: { remote: document.getElementById('audio') }
        }
    });

    cur_call.on('accepted', function() {
        setDisplay(num, '✓ Connected');
        showInCall();
    });
    cur_call.once('failed', function(response, cause) {
        setDisplay(num, '✕ ' + (cause || 'Failed'));
        cur_call = null; resetCallUI(); showIdle();
        onCallEnded();
    });
    cur_call.once('bye', function() {
        setDisplay(num, '↩ Call ended');
        cur_call = null; resetCallUI(); showIdle();
        onCallEnded();
    });
    cur_call.once('cancel', function() {
        setDisplay(num, '↩ Cancelled');
        cur_call = null; resetCallUI(); showIdle();
        onCallEnded();
    });
}

// ── Answer / Reject incoming ──────────────────────────────────────────────────
function answerCall() {
    if (!incomingSession) return;
    $('incoming-notify').classList.remove('show');
    cur_call = incomingSession;
    incomingSession = null;

    cur_call.accept({
        media: {
            constraints: { audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false }, video: false },
            render: { remote: document.getElementById('audio') }
        }
    });

    var caller = cur_call.remoteIdentity ? cur_call.remoteIdentity.uri.user : 'Unknown';
    setDisplay(caller, '✓ Connected');
    showInCall();

    cur_call.on('accepted', function() {});
    cur_call.once('bye',    function() { cur_call=null; resetCallUI(); showIdle(); setDisplay('—','Call ended'); });
    cur_call.once('failed', function() { cur_call=null; resetCallUI(); showIdle(); });
}
function rejectCall() {
    if (incomingSession) { try { incomingSession.reject({statusCode:'486',reasonPhrase:'Busy'}); } catch(e) {} incomingSession=null; }
    $('incoming-notify').classList.remove('show');
}

// ── Hang up ───────────────────────────────────────────────────────────────────
function hangUp() {
    if (cur_call) { try { cur_call.terminate(); } catch(e) {} cur_call = null; }
    resetCallUI(); showIdle();
    setDisplay('—', 'Call ended');
    onCallEnded();
}

// ── Mute / Hold ───────────────────────────────────────────────────────────────
function toggleMute() {
    if (!cur_call) return;
    isMuted = !isMuted;
    if (isMuted) { cur_call.mute();   $('mute-btn').classList.add('active');    $('mute-label').textContent='Unmute'; }
    else         { cur_call.unmute(); $('mute-btn').classList.remove('active'); $('mute-label').textContent='Mute'; }
}
function toggleHold() {
    if (!cur_call) return;
    isOnHold = !isOnHold;
    if (isOnHold) { $('hold-btn').classList.add('active');    $('hold-label').textContent='Resume'; $('display-status').textContent='⏸ On hold'; }
    else          { $('hold-btn').classList.remove('active'); $('hold-label').textContent='Hold';   $('display-status').textContent='In call'; }
}

// ── Lead / Campaign logic ─────────────────────────────────────────────────────
function toggleCampDropdown() {
    var dd = $('camp-dropdown');
    var trigger = $('camp-trigger');
    var arrow = $('camp-arrow');
    var isOpen = dd.style.display !== 'none';
    if (isOpen) {
        dd.style.display = 'none';
        trigger.classList.remove('open');
        arrow.classList.remove('open');
    } else {
        dd.style.display = 'block';
        trigger.classList.add('open');
        arrow.classList.add('open');
        setTimeout(function(){ $('camp-search-input').focus(); }, 50);
    }
}
function filterCampaigns(q) {
    var opts = document.querySelectorAll('#camp-options .camp-option');
    q = q.toLowerCase();
    opts.forEach(function(opt) {
        if (opt.dataset.value === '') return; // always show placeholder
        opt.classList.toggle('hidden', !opt.textContent.toLowerCase().includes(q));
    });
}
function selectCampaign(val, label) {
    $('campaign-select').value = val;
    $('camp-trigger-text').textContent = label;
    $('camp-dropdown').style.display = 'none';
    $('camp-trigger').classList.remove('open');
    $('camp-arrow').classList.remove('open');
    $('camp-search-input').value = '';
    filterCampaigns('');
    // mark selected
    document.querySelectorAll('#camp-options .camp-option').forEach(function(o){
        o.classList.toggle('selected', o.dataset.value === val);
    });
    onCampaignChange();
}
// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    var wrap = $('camp-search-wrap');
    if (wrap && !wrap.contains(e.target)) {
        $('camp-dropdown').style.display = 'none';
        $('camp-trigger').classList.remove('open');
        $('camp-arrow').classList.remove('open');
    }
});
function onCampaignChange() {
    selectedCampaign = parseInt($('campaign-select').value) || 0;
    if (selectedCampaign) {
        $('autodial-toggle').style.display = 'inline-block';
        $('autodial-bar').style.display    = 'flex';
        renderNoLead('Click "Start Autodial" or load the first lead.');
    } else {
        $('autodial-toggle').style.display = 'none';
        $('autodial-bar').style.display    = 'none';
        renderNoLead('Select a campaign above to start dialing leads.');
        stopAutodial();
    }
}

function toggleAutodial() {
    if (autodialActive) {
        stopAutodial();
    } else {
        startAutodial();
    }
}

function startAutodial() {
    if (!selectedCampaign) return;
    autodialActive = true;
    $('autodial-toggle').textContent = '⏹ Stop Autodial';
    $('autodial-toggle').classList.add('active');
    $('autodial-status').textContent = 'Running…';
    loadNextLead();
}

function stopAutodial() {
    autodialActive = false;
    clearTimeout(autodialTimer);
    $('autodial-toggle').textContent = '▶ Start Autodial';
    $('autodial-toggle').classList.remove('active');
    $('autodial-status').textContent = '';
}

function loadNextLead() {
    if (!selectedCampaign) return;
    fetch('/leads/dialerphone.php?action=next_lead&campaign_id=' + selectedCampaign)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                currentLead    = data.lead;
                dialSessionId  = data.dial_session_id;
                renderLead(data.lead);
                startPing();
                if (autodialActive && isRegistered) {
                    dialedNumber = currentLead.phone;
                    makeCall();
                }
            } else {
                currentLead   = null;
                dialSessionId = 0;
                stopAutodial();
                renderNoLead(data.message || 'No more leads.');
            }
        })
        .catch(function() { renderNoLead('Error loading next lead.'); });
}

function renderLead(lead) {
    var html = '<div class="lead-card">';
    html += '<div class="lead-card-name">' + esc(lead.business_name || 'Unknown') + '</div>';
    html += '<div class="lead-card-phone">' + esc(lead.phone) + '</div>';
    html += '<div class="lead-card-meta">Lead #' + esc(String(lead.id)) + ' &nbsp;·&nbsp; Campaign ' + selectedCampaign + '</div>';
    html += '</div>';
    html += '<div style="margin-bottom:10px;font-size:12px;color:var(--muted);font-weight:600;">OUTCOME</div>';
    html += '<div class="outcome-grid">';
    html += '<button class="outcome-btn out-interested"     onclick="saveOutcome(\'interested\')"><span class="ico">✓</span>Interested</button>';
    html += '<button class="outcome-btn out-no-answer"      onclick="saveOutcome(\'no_answer\')"><span class="ico">〜</span>No Answer</button>';
    html += '<button class="outcome-btn out-not-interested" onclick="saveOutcome(\'not_interested\')"><span class="ico">✕</span>Not Interested</button>';
    html += '<button class="outcome-btn out-callback"       onclick="saveOutcome(\'callback\')"><span class="ico">↩</span>Call Back</button>';
    html += '</div>';
    html += '<button onclick="dialedNumber=currentLead.phone;makeCall();" style="width:100%;padding:11px;background:var(--blue);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;margin-top:4px;">📞 Dial This Lead</button>';
    $('lead-body').innerHTML = html;
}

var countdownTimer = null;
var countdownInterval = null;

function startCountdown(seconds) {
    // Clear any existing countdown
    clearTimeout(countdownTimer);
    clearInterval(countdownInterval);

    if (seconds <= 0) {
        renderNoLead('Loading…');
        return;
    }

    var total = seconds;
    var remaining = seconds;
    var circumference = 2 * Math.PI * 34; // r=34

    var html = '<div class="countdown-wrap">'
        + '<div class="countdown-circle">'
        + '<svg width="80" height="80" viewBox="0 0 80 80">'
        + '<circle class="track" cx="40" cy="40" r="34"/>'
        + '<circle class="progress" id="cd-progress" cx="40" cy="40" r="34"'
        + ' stroke-dasharray="' + circumference + '"'
        + ' stroke-dashoffset="' + circumference + '"/>'
        + '</svg>'
        + '<div class="num" id="cd-num">' + remaining + '</div>'
        + '</div>'
        + '<div class="countdown-label">Next lead loading…</div>'
        + '</div>';
    $('lead-body').innerHTML = html;

    countdownInterval = setInterval(function() {
        remaining -= 0.1;
        if (remaining <= 0) remaining = 0;
        var pct = 1 - (remaining / total);
        var offset = circumference - (pct * circumference);
        var prog = document.getElementById('cd-progress');
        var num  = document.getElementById('cd-num');
        if (prog) prog.style.strokeDashoffset = offset;
        if (num)  num.textContent = Math.ceil(remaining);
    }, 100);
}

function stopCountdown() {
    clearInterval(countdownInterval);
    countdownInterval = null;
}

function renderNoLead(msg) {
    $('lead-body').innerHTML = '<div class="no-lead-msg">' + esc(msg) + '</div>';
}

function saveOutcome(outcome) {
    if (!currentLead) return;
    savingOutcome = true;

    // Show confirmation badge immediately — replaces buttons so no double-click possible
    var labels = {'interested':'✓ Interested','no_answer':'〜 No Answer','not_interested':'✕ Not Interested','callback':'↩ Call Back'};
    var colors = {
        'interested':     'background:#ecfdf5;border-color:#a7f3d0;color:#065f46;',
        'no_answer':      'background:#f1f5f9;border-color:#cbd5e1;color:#475569;',
        'not_interested': 'background:#fef2f2;border-color:#fecaca;color:#991b1b;',
        'callback':       'background:#fffbeb;border-color:#fde68a;color:#92400e;'
    };
    $('lead-body').innerHTML = '<div style="padding:30px 20px;text-align:center;">'
        + '<div style="display:inline-flex;align-items:center;gap:8px;padding:14px 24px;border-radius:12px;border:2px solid;font-size:15px;font-weight:700;'
        + (colors[outcome]||'background:#f1f5f9;border-color:#e2e8f0;color:#475569;') + '">'
        + (labels[outcome]||'Saved') + '</div>'
        + '<div style="margin-top:10px;font-size:12px;color:var(--muted);">Saving…</div>'
        + '</div>';

    // Clear immediately so any re-trigger cannot reuse this lead
    var leadId = currentLead.id;
    var sessId = dialSessionId;
    currentLead   = null;
    dialSessionId = 0;

    if (cur_call) { try { cur_call.terminate(); } catch(e) {} cur_call = null; resetCallUI(); showIdle(); }
    stopPing();

    fetch('/leads/dialerphone.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=save_outcome&lead_id=' + leadId + '&campaign_id=' + selectedCampaign + '&outcome=' + outcome + '&dial_session_id=' + sessId
    }).then(function() {
        if (autodialActive) {
            var delay = parseInt($('autodial-delay').value) * 1000;
            startCountdown(delay / 1000);
            autodialTimer = setTimeout(function() {
                stopCountdown();
                loadNextLead();
            }, delay);
        } else {
            renderNoLead('Saved. Click "Start Autodial" or load next lead manually.');
        }
    });
}

function onCallEnded() {
    // If autodial is on and we have a current lead but no outcome saved yet,
    // wait for the user to pick an outcome (outcome buttons are shown)
    // If no lead loaded yet, load one
    if (autodialActive && !currentLead && !savingOutcome) {
        var delay = parseInt($('autodial-delay').value) * 1000;
        autodialTimer = setTimeout(loadNextLead, delay);
    }
}

// ── Heartbeat ping ────────────────────────────────────────────────────────────
function startPing() {
    stopPing();
    pingInterval = setInterval(function() {
        if (!dialSessionId) return;
        fetch('/leads/dialerphone.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=ping&dial_session_id=' + dialSessionId
        });
    }, 30000);
}
function stopPing() { clearInterval(pingInterval); }

// ── Keyboard ──────────────────────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if ($('setup-modal').classList.contains('open')) return;
    if ('0123456789*#'.indexOf(e.key) !== -1) pressKey(e.key);
    if (e.key === 'Backspace') { e.preventDefault(); backspace(); }
    if (e.key === 'Enter' && !cur_call) makeCall();
    if (e.key === 'Escape' && cur_call) hangUp();
});

// ── Escape helper ─────────────────────────────────────────────────────────────
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Auto-init on load ─────────────────────────────────────────────────────────
window.addEventListener('load', function() {
    var ext  = localStorage.getItem('sip_ext');
    var pass = localStorage.getItem('sip_pass');
    if (ext && pass) {
        var name = localStorage.getItem('sip_name') || ext;
        $('ext-display').textContent = 'Ext ' + ext;
        initSIP(ext, pass, name);
    } else {
        showSetup();
    }
});
</script>
</body>
</html>
