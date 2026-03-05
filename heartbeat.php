<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'reason' => 'not_logged_in']);
    exit;
}

$session_id = (int)($_POST['session_id'] ?? 0);
$uid        = current_user_id();

if ($session_id <= 0) {
    echo json_encode(['ok' => false, 'reason' => 'no_session_id']);
    exit;
}

try {
    $st = $pdo->prepare("
        UPDATE public.dial_sessions
           SET last_ping = (NOW() AT TIME ZONE 'America/New_York')
         WHERE id = :sid AND user_id = :uid AND status = 'active'
    ");
    $st->execute([':sid' => $session_id, ':uid' => $uid]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'reason' => 'db_error']);
}
