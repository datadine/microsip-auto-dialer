<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require_login();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function format_duration(int $seconds): string {
    if ($seconds <= 0) return '0m';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}

$me      = current_user_id();
$admin   = is_admin();
$my_name = current_username();

// ── Who are we viewing? ───────────────────────────────────────────────────────
// Admins can pass ?user_id=X to view any agent. Agents always see themselves.
$view_uid = $me;
$view_name = $my_name;
if ($admin && isset($_GET['user_id'])) {
    $view_uid = (int)$_GET['user_id'];
    $row = $pdo->prepare("SELECT username FROM public.users WHERE id=?");
    $row->execute([$view_uid]);
    $found = $row->fetchColumn();
    if ($found) $view_name = (string)$found;
    else { $view_uid = $me; $view_name = $my_name; }
}

// ── Date range ────────────────────────────────────────────────────────────────
$range = $_GET['range'] ?? 'today';
$valid_ranges = ['today','yesterday','week','month','alltime'];
if (!in_array($range, $valid_ranges, true)) $range = 'today';

$tz = 'America/New_York';
switch ($range) {
    case 'today':
        $date_where = "DATE(cl.call_time AT TIME ZONE '$tz') = CURRENT_DATE";
        $ds_where   = "DATE(ds.started_at AT TIME ZONE '$tz') = CURRENT_DATE";
        $range_label = 'Today';
        break;
    case 'yesterday':
        $date_where = "DATE(cl.call_time AT TIME ZONE '$tz') = CURRENT_DATE - INTERVAL '1 day'";
        $ds_where   = "DATE(ds.started_at AT TIME ZONE '$tz') = CURRENT_DATE - INTERVAL '1 day'";
        $range_label = 'Yesterday';
        break;
    case 'week':
        $date_where = "cl.call_time AT TIME ZONE '$tz' >= date_trunc('week', NOW() AT TIME ZONE '$tz')";
        $ds_where   = "ds.started_at AT TIME ZONE '$tz' >= date_trunc('week', NOW() AT TIME ZONE '$tz')";
        $range_label = 'This Week';
        break;
    case 'month':
        $date_where = "cl.call_time AT TIME ZONE '$tz' >= date_trunc('month', NOW() AT TIME ZONE '$tz')";
        $ds_where   = "ds.started_at AT TIME ZONE '$tz' >= date_trunc('month', NOW() AT TIME ZONE '$tz')";
        $range_label = 'This Month';
        break;
    default: // alltime
        $date_where = "1=1";
        $ds_where   = "1=1";
        $range_label = 'All Time';
}

// ── Fetch outcome summary ─────────────────────────────────────────────────────
$st = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN outcome='interested'     THEN 1 ELSE 0 END) AS interested,
        SUM(CASE WHEN outcome='not_interested' THEN 1 ELSE 0 END) AS not_interested,
        SUM(CASE WHEN outcome='no_answer'      THEN 1 ELSE 0 END) AS no_answer,
        SUM(CASE WHEN outcome='callback'       THEN 1 ELSE 0 END) AS callback,
        SUM(CASE WHEN outcome='called'         THEN 1 ELSE 0 END) AS called
    FROM public.call_logs cl
    WHERE cl.user_id = :uid AND $date_where
");
$st->execute([':uid' => $view_uid]);
$summary = $st->fetch(PDO::FETCH_ASSOC);
$total        = (int)($summary['total']         ?? 0);
$interested   = (int)($summary['interested']    ?? 0);
$not_int      = (int)($summary['not_interested']?? 0);
$no_answer    = (int)($summary['no_answer']     ?? 0);
$callback     = (int)($summary['callback']      ?? 0);
$called       = (int)($summary['called']        ?? 0);

// ── Talk time ─────────────────────────────────────────────────────────────────
$ts = $pdo->prepare("
    SELECT COALESCE(SUM(ds.total_seconds), 0)
    FROM public.dial_sessions ds
    WHERE ds.user_id = :uid AND ds.status = 'closed' AND $ds_where
");
$ts->execute([':uid' => $view_uid]);
$talk_seconds = (int)$ts->fetchColumn();

// ── Last 7 days chart data ────────────────────────────────────────────────────
$chart_st = $pdo->prepare("
    SELECT
        DATE(cl.call_time AT TIME ZONE '$tz') AS day,
        COUNT(*) AS total,
        SUM(CASE WHEN outcome='interested' THEN 1 ELSE 0 END) AS interested
    FROM public.call_logs cl
    WHERE cl.user_id = :uid
      AND cl.call_time AT TIME ZONE '$tz' >= (NOW() AT TIME ZONE '$tz') - INTERVAL '6 days'
    GROUP BY DATE(cl.call_time AT TIME ZONE '$tz')
    ORDER BY day ASC
");
$chart_st->execute([':uid' => $view_uid]);
$chart_rows = $chart_st->fetchAll(PDO::FETCH_ASSOC);

// Build 7-day array
$chart_days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_days[$d] = ['total' => 0, 'interested' => 0];
}
foreach ($chart_rows as $r) {
    $d = $r['day'];
    if (isset($chart_days[$d])) {
        $chart_days[$d]['total']      = (int)$r['total'];
        $chart_days[$d]['interested'] = (int)$r['interested'];
    }
}

// ── Per-campaign breakdown ────────────────────────────────────────────────────
$camp_st = $pdo->prepare("
    SELECT
        COALESCE(c.campaign_code, '[Deleted #' || cl.campaign_id || ']') AS code,
        COALESCE(c.name, 'Deleted Campaign')                             AS camp_name,
        c.deleted,
        COUNT(*) AS total,
        SUM(CASE WHEN cl.outcome='interested'     THEN 1 ELSE 0 END) AS interested,
        SUM(CASE WHEN cl.outcome='not_interested' THEN 1 ELSE 0 END) AS not_interested,
        SUM(CASE WHEN cl.outcome='no_answer'      THEN 1 ELSE 0 END) AS no_answer,
        SUM(CASE WHEN cl.outcome='callback'       THEN 1 ELSE 0 END) AS callback
    FROM public.call_logs cl
    LEFT JOIN public.campaigns c ON c.id = cl.campaign_id
    WHERE cl.user_id = :uid AND $date_where
    GROUP BY cl.campaign_id, c.campaign_code, c.name, c.deleted
    ORDER BY total DESC
");
$camp_st->execute([':uid' => $view_uid]);
$camp_rows = $camp_st->fetchAll(PDO::FETCH_ASSOC);

// ── All agents list for admin switcher ────────────────────────────────────────
$all_agents = [];
if ($admin) {
    $ag = $pdo->query("SELECT id, username FROM public.users WHERE active=TRUE ORDER BY username ASC");
    $all_agents = $ag->fetchAll(PDO::FETCH_ASSOC);
}

// ── Chart JSON ───────────────────────────────────────────────────────────────
$chart_labels  = [];
$chart_totals  = [];
$chart_ints    = [];
foreach ($chart_days as $d => $v) {
    $chart_labels[] = date('D d', strtotime($d));
    $chart_totals[] = $v['total'];
    $chart_ints[]   = $v['interested'];
}
$json_labels = json_encode($chart_labels);
$json_totals = json_encode($chart_totals);
$json_ints   = json_encode($chart_ints);

$interest_rate = $total > 0 ? round($interested / $total * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Performance – Leads</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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
    --green-lt:#ecfdf5;
    --red:     #ef4444;
    --red-lt:  #fef2f2;
    --blue:    #2563eb;
    --blue-lt: #eff6ff;
    --yellow:  #f59e0b;
    --yel-lt:  #fffbeb;
    --purple:  #7c3aed;
    --pur-lt:  #f5f3ff;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; }

/* SIDEBAR */
.sidebar { width: 200px; flex-shrink: 0; background: var(--surf); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 50; box-shadow: 2px 0 8px rgba(0,0,0,.04); }
.sidebar-logo { display: flex; align-items: center; gap: 10px; padding: 18px 16px 16px; border-bottom: 1px solid var(--border); font-size: 15px; font-weight: 800; color: #1e293b; }
.sidebar-nav { flex: 1; padding: 10px 8px; display: flex; flex-direction: column; gap: 2px; overflow-y: auto; }
.snav-item { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--gray); text-decoration: none; transition: all .12s; }
.snav-item:hover { background: var(--bg); color: #1e293b; }
.snav-item.active { background: var(--blue-lt); color: var(--blue); font-weight: 700; }
.sidebar-footer { padding: 12px 12px 16px; border-top: 1px solid var(--border); }
.sidebar-user { display: flex; align-items: center; gap: 9px; margin-bottom: 8px; }
.sidebar-avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--blue); color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sidebar-username { font-size: 12px; font-weight: 600; color: #1e293b; }
.sidebar-logout { font-size: 11px; color: var(--red); text-decoration: none; font-weight: 500; }

/* MAIN */
.main-content { margin-left: 200px; flex: 1; padding: 28px 32px; max-width: 1100px; }

/* PAGE HEADER */
.page-hd { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 20px; font-weight: 800; color: #1e293b; }
.page-sub { font-size: 13px; color: var(--muted); margin-top: 2px; }

/* CONTROLS ROW */
.controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.range-tabs { display: flex; gap: 4px; background: var(--surf2); border: 1px solid var(--border); border-radius: 8px; padding: 3px; }
.range-tab { padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; color: var(--muted); text-decoration: none; transition: all .12s; }
.range-tab:hover { color: #1e293b; }
.range-tab.active { background: var(--surf); color: var(--blue); box-shadow: 0 1px 3px rgba(0,0,0,.1); }
.agent-select { background: var(--surf); border: 1px solid var(--border); color: var(--text); padding: 7px 12px; border-radius: 8px; font-size: 13px; font-family: inherit; outline: none; cursor: pointer; }

/* STAT CARDS */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 24px; }
.stat-card { background: var(--surf); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; }
.stat-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 8px; }
.stat-value { font-size: 32px; font-weight: 800; line-height: 1; }
.stat-sub { font-size: 11px; color: var(--muted); margin-top: 4px; }
.sv-blue   { color: var(--blue); }
.sv-green  { color: var(--green); }
.sv-red    { color: var(--red); }
.sv-yellow { color: var(--yellow); }
.sv-gray   { color: var(--gray); }
.sv-purple { color: var(--purple); }

/* CHART CARD */
.card { background: var(--surf); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 24px; overflow: hidden; }
.card-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
.card-title { font-size: 14px; font-weight: 700; color: #334155; }
.card-body { padding: 20px; }
.chart-wrap { height: 220px; position: relative; }

/* TABLE */
table { width: 100%; border-collapse: collapse; }
th { text-align: left; padding: 11px 16px; background: #f8fafc; color: var(--gray); font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; border-bottom: 1px solid var(--border); }
td { padding: 12px 16px; border-bottom: 1px solid var(--border); font-size: 13px; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8fafc; }
.code-badge { font-family: monospace; font-size: 11px; font-weight: 700; color: var(--blue); background: var(--blue-lt); padding: 3px 7px; border-radius: 4px; border: 1px solid #bfdbfe; white-space: nowrap; }
.deleted-badge { font-size: 10px; font-weight: 700; color: #991b1b; background: var(--red-lt); padding: 2px 6px; border-radius: 4px; border: 1px solid #fecaca; margin-left: 6px; }

/* OUTCOME PILLS inline */
.pill { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.pill-green  { background: var(--green-lt); color: #065f46; }
.pill-red    { background: var(--red-lt);   color: #991b1b; }
.pill-gray   { background: #f1f5f9;         color: #475569; }
.pill-yellow { background: var(--yel-lt);   color: #92400e; }

.empty { text-align: center; padding: 40px; color: var(--gray); font-size: 13px; }
</style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.21h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.82a16 16 0 0 0 6.29 6.29l.98-.98a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        SIP Dialer
    </div>
    <nav class="sidebar-nav">
        <a href="/leads/" class="snav-item">🏠 Home</a>
        <a href="/leads/dialerphone.php" class="snav-item">📞 Dialer</a>
        <a href="/leads/myperf.php" class="snav-item active">↗ Performance</a>
        <a href="/leads/interested.php" class="snav-item">✓ Interested</a>
        <a href="/leads/tasks.php" class="snav-item">📅 Tasks</a>
        <?php if ($admin): ?>
        <a href="/leads/admin.php" class="snav-item">⚙ Admin</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= strtoupper(substr($my_name, 0, 1)) ?></div>
            <div>
                <div class="sidebar-username"><?= h($my_name) ?></div>
                <?php if ($admin): ?><div style="font-size:10px;color:var(--muted);">Admin</div><?php endif; ?>
            </div>
        </div>
        <a href="/leads/logout.php" class="sidebar-logout">← Sign out</a>
    </div>
</aside>

<div class="main-content">

    <div class="page-hd">
        <div>
            <div class="page-title">↗ Performance</div>
            <div class="page-sub">
                <?php if ($admin && $view_uid !== $me): ?>
                    Viewing: <strong><?= h($view_name) ?></strong>
                <?php else: ?>
                    Your stats, <?= h($view_name) ?>
                <?php endif; ?>
                — <?= $range_label ?>
            </div>
        </div>
        <div class="controls">
            <?php if ($admin): ?>
            <select class="agent-select" onchange="location='myperf.php?range=<?= $range ?>&user_id='+this.value">
                <option value="<?= $me ?>">My Stats</option>
                <?php foreach ($all_agents as $ag): ?>
                    <option value="<?= (int)$ag['id'] ?>" <?= (int)$ag['id'] === $view_uid ? 'selected' : '' ?>>
                        <?= h($ag['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <div class="range-tabs">
                <?php foreach (['today'=>'Today','yesterday'=>'Yesterday','week'=>'This Week','month'=>'This Month','alltime'=>'All Time'] as $r => $label): ?>
                <a href="myperf.php?range=<?= $r ?>&user_id=<?= $view_uid ?>" class="range-tab <?= $range === $r ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-label">Total Calls</div>
            <div class="stat-value sv-blue"><?= $total ?></div>
            <div class="stat-sub"><?= $range_label ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Interested</div>
            <div class="stat-value sv-green"><?= $interested ?></div>
            <div class="stat-sub"><?= $interest_rate ?>% rate</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Not Interested</div>
            <div class="stat-value sv-red"><?= $not_int ?></div>
            <div class="stat-sub">&nbsp;</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">No Answer</div>
            <div class="stat-value sv-gray"><?= $no_answer ?></div>
            <div class="stat-sub">&nbsp;</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Call Back</div>
            <div class="stat-value sv-yellow"><?= $callback ?></div>
            <div class="stat-sub">&nbsp;</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Talk Time</div>
            <div class="stat-value sv-purple" style="font-size:24px;padding-top:4px;"><?= format_duration($talk_seconds) ?></div>
            <div class="stat-sub"><?= $range_label ?></div>
        </div>
    </div>

    <!-- 7-DAY CHART -->
    <div class="card">
        <div class="card-head">
            <span class="card-title">Calls — Last 7 Days</span>
        </div>
        <div class="card-body">
            <div class="chart-wrap">
                <canvas id="callChart"></canvas>
            </div>
        </div>
    </div>

    <!-- PER CAMPAIGN BREAKDOWN -->
    <div class="card">
        <div class="card-head">
            <span class="card-title">By Campaign</span>
            <span style="font-size:12px;color:var(--muted);"><?= $range_label ?></span>
        </div>
        <?php if (empty($camp_rows)): ?>
        <div class="empty">No calls in this period.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Total</th>
                    <th>Interested</th>
                    <th>Not Interested</th>
                    <th>No Answer</th>
                    <th>Call Back</th>
                    <th>Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($camp_rows as $c):
                    $ct = (int)$c['total'];
                    $ci = (int)$c['interested'];
                    $cr = $ct > 0 ? round($ci / $ct * 100, 1) : 0;
                ?>
                <tr>
                    <td>
                        <span class="code-badge"><?= h($c['code']) ?></span>
                        <?php if ($c['deleted']): ?><span class="deleted-badge">deleted</span><?php endif; ?>
                        <div style="font-size:12px;color:var(--muted);margin-top:3px;"><?= h($c['camp_name']) ?></div>
                    </td>
                    <td><strong><?= $ct ?></strong></td>
                    <td><span class="pill pill-green"><?= $ci ?></span></td>
                    <td><span class="pill pill-red"><?= (int)$c['not_interested'] ?></span></td>
                    <td><span class="pill pill-gray"><?= (int)$c['no_answer'] ?></span></td>
                    <td><span class="pill pill-yellow"><?= (int)$c['callback'] ?></span></td>
                    <td style="font-weight:700;color:<?= $cr >= 10 ? 'var(--green)' : ($cr >= 5 ? 'var(--yellow)' : 'var(--gray)') ?>">
                        <?= $cr ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<script>
const labels  = <?= $json_labels ?>;
const totals  = <?= $json_totals ?>;
const ints    = <?= $json_ints ?>;

const ctx = document.getElementById('callChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Total Calls',
                data: totals,
                backgroundColor: 'rgba(37,99,235,0.15)',
                borderColor: 'rgba(37,99,235,0.7)',
                borderWidth: 2,
                borderRadius: 6,
                order: 2
            },
            {
                label: 'Interested',
                data: ints,
                backgroundColor: 'rgba(16,185,129,0.7)',
                borderColor: 'rgba(16,185,129,1)',
                borderWidth: 2,
                borderRadius: 6,
                order: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 12 } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: 'rgba(0,0,0,.05)' } }
        }
    }
});
</script>
</body>
</html>
