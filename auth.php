<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function auth_user(): ?array          { return $_SESSION['user'] ?? null; }
function is_logged_in(): bool         { return !empty($_SESSION['user']['id']); }
function is_admin(): bool             { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
function can_upload(): bool           { return is_admin() || !empty($_SESSION['user']['can_upload']); }
function can_delete(): bool           { return is_admin() || !empty($_SESSION['user']['can_delete']); }
function current_user_id(): int       { return (int)($_SESSION['user']['id'] ?? 0); }
function current_username(): string   { return (string)($_SESSION['user']['username'] ?? ''); }

function require_login(bool $admin_only = false): void {
    if (!is_logged_in()) {
        header('Location: /leads/login.php');
        exit;
    }
    if ($admin_only && !is_admin()) {
        http_response_code(403);
        echo '<!doctype html><html><body style="font-family:sans-serif;padding:60px;text-align:center;">
              <h2>Access Denied</h2><p>Admins only.</p>
              <a href="/leads/">Back</a></body></html>';
        exit;
    }
}

