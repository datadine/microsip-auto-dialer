<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (is_logged_in()) {
    try {
        $uid = current_user_id();
        $pdo->prepare("
            UPDATE public.dial_sessions
               SET status        = 'closed',
                   ended_at      = NOW() AT TIME ZONE 'America/New_York',
                   total_seconds = GREATEST(0, EXTRACT(EPOCH FROM (last_ping - started_at))::INT)
             WHERE user_id = :uid AND status = 'active'
        ")->execute([':uid' => $uid]);
    } catch (Throwable $e) {}
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: /leads/login.php');
exit;
