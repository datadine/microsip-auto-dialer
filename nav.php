<?php
// nav.php - shared sidebar navigation - include inside <body> of every page
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <span style="font-size:22px;">📞</span>
        <span class="sidebar-logo-text">Leads Lite</span>
    </div>
    <nav class="sidebar-nav">
        <a href="/leads/" class="snav-item <?= basename($_SERVER['PHP_SELF'])==='index.php'?'active':'' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            <span>Campaigns</span>
        </a>
        <a href="/leads/interested.php" class="snav-item <?= basename($_SERVER['PHP_SELF'])==='interested.php'?'active':'' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            <span>Interested</span>
            <?php
            if (isset($pdo)) {
                try {
                    $uid_nav = current_user_id();
                    $af = is_admin() ? "" : "AND user_id=$uid_nav";
                    $n = $pdo->query("SELECT COUNT(*) FROM public.interested_notes WHERE status IN ('active','paused') $af")->fetchColumn();
                    if ($n > 0) echo '<span class="snav-badge">'.(int)$n.'</span>';
                } catch(Throwable $e) {}
            }
            ?>
        </a>
        <a href="/leads/callback.php" class="snav-item <?= basename($_SERVER['PHP_SELF'])==='callback.php'?'active':'' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.35 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/><polyline points="15 3 18 3 18 6"/><line x1="22" y1="3" x2="18" y2="7"/></svg>
            <span>Call Back</span>
            <?php
            if (isset($pdo)) {
                try {
                    $uid_nav = current_user_id();
                    $af = is_admin() ? "" : "AND user_id=$uid_nav";
                    $n = $pdo->query("SELECT COUNT(*) FROM public.callback_notes WHERE status IN ('active','paused') $af")->fetchColumn();
                    if ($n > 0) echo '<span class="snav-badge snav-badge-orange">'.(int)$n.'</span>';
                } catch(Throwable $e) {}
            }
            ?>
        </a>
        <a href="/leads/tasks.php" class="snav-item <?= basename($_SERVER['PHP_SELF'])==='tasks.php'?'active':'' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Tasks</span>
        </a>
        <?php /*<a href="/leads/dialerphone.php" class="snav-item <?= basename($_SERVER['PHP_SELF'])==='dialerphone.php'?'active':'' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.35 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            <span>Dialers</span>
        </a>*/ ?>
        <?php if (function_exists('is_admin') && is_admin()): ?>
        <a href="/leads/admin.php" class="snav-item <?= basename($_SERVER['PHP_SELF'])==='admin.php'?'active':'' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            <span>Admin</span>
        </a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= strtoupper(substr(current_username(),0,1)) ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-username"><?= h(current_username()) ?></div>
                <a href="/leads/logout.php" class="sidebar-logout">Sign out</a>
            </div>
        </div>
    </div>
</div>
