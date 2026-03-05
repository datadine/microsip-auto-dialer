<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

if (is_logged_in()) {
    header('Location: ' . (is_admin() ? '/leads/admin.php' : '/leads/'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            $st = $pdo->prepare("
                SELECT id, username, password_hash, role, can_upload, can_delete, active
                FROM public.users WHERE username = :u LIMIT 1
            ");
            $st->execute([':u' => $username]);
            $user = $st->fetch(PDO::FETCH_ASSOC);

            if (!$user || !(bool)$user['active']) {
                $error = 'Invalid credentials.';
            } elseif (!password_verify($password, (string)$user['password_hash'])) {
                $error = 'Invalid credentials.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'         => (int)$user['id'],
                    'username'   => (string)$user['username'],
                    'role'       => (string)$user['role'],
                    'can_upload' => (bool)$user['can_upload'],
                    'can_delete' => (bool)$user['can_delete'],
                ];
                header('Location: ' . ($user['role'] === 'admin' ? '/leads/admin.php' : '/leads/'));
                exit;
            }
        } catch (Throwable $e) {
            $error = 'System error. Please try again.';
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Leads Lite — Sign In</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --blue:    #2563eb;
            --red:     #ef4444;
            --red-lt:  #fef2f2;
            --gray:    #64748b;
            --border:  #e2e8f0;
            --bg:      #f8fafc;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .wrap { width: 100%; max-width: 400px; }
        .logo { text-align: center; margin-bottom: 24px; }
        .logo-name {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: -0.5px;
        }
        .card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .card-title {
            font-size: 14px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 24px;
            text-align: center;
        }
        .fgroup { margin-bottom: 16px; }
        .flabel {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--gray);
            margin-bottom: 6px;
        }
        .finput {
            width: 100%;
            background: #fff;
            border: 1px solid var(--border);
            color: #1e293b;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: border-color 0.15s;
        }
        .finput:focus { border-color: var(--blue); }
        .finput::placeholder { color: #cbd5e1; }
        
        .btn-submit {
            width: 100%;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.12s;
        }
        .btn-submit:hover { background: #1d4ed8; }
        
        .error {
            background: var(--red-lt);
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
            font-weight: 500;
        }
        .footer-note {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: var(--gray);
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="logo">
        <div class="logo-name">📞 Leads Lite</div>
    </div>
    <div class="card">
        <div class="card-title">Sign in to your account</div>
        <?php if ($error): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="fgroup">
                <label class="flabel" for="username">Username</label>
                <input class="finput" type="text" id="username" name="username"
                       value="<?= h($_POST['username'] ?? '') ?>"
                       autocomplete="username" autofocus placeholder="Enter username">
            </div>
            <div class="fgroup">
                <label class="flabel" for="password">Password</label>
                <input class="finput" type="password" id="password" name="password"
                       autocomplete="current-password" placeholder="••••••••">
            </div>
            <button type="submit" class="btn-submit">Sign In →</button>
        </form>
    </div>
    <div class="footer-note">Leads Lite &copy; <?= date('Y') ?></div>
</div>
</body>
</html>
