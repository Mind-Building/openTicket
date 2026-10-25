<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Session;
use App\Core\Csrf;
use App\Core\RateLimiter;

Session::start();

// Redirect if already logged in
if (Auth::check()) {
    header('Location: ' . Auth::getLoginRedirectUrl());
    exit;
}

$error = '';
$rateLimitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!Csrf::validatePost()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Two complementary rate limits:
        //   userKey – per (IP, username): protects a known account against
        //             targeted brute force from one source.
        //   ipKey   – per IP only: prevents username spraying – an attacker
        //             cannot dodge the user-scoped limit by rotating usernames.
        $ip      = RateLimiter::getClientIp();
        $userKey = 'login:' . $ip . ':' . strtolower($username);
        $ipKey   = 'login_ip:' . $ip;

        $ipLimit   = RateLimiter::check($ipKey, 20, 900); // 20 attempts per IP / 15 min
        $userLimit = RateLimiter::check($userKey, 5, 900); // 5 attempts per (IP+user) / 15 min

        if (!$ipLimit['allowed']) {
            if ($ipLimit['blocked'] ?? false) {
                $resetTime = date('H:i', $ipLimit['reset_at']);
                $rateLimitError = "Zu viele fehlgeschlagene Anmeldeversuche von dieser IP. Bitte versuchen Sie es später erneut (gesperrt bis $resetTime Uhr).";
            } else {
                $rateLimitError = "Zu viele Anmeldeversuche von dieser IP. Bitte warten Sie einen Moment.";
            }
        } elseif (!$userLimit['allowed']) {
            if ($userLimit['blocked'] ?? false) {
                $resetTime = date('H:i', $userLimit['reset_at']);
                $rateLimitError = "Zu viele fehlgeschlagene Anmeldeversuche. Bitte versuchen Sie es später erneut (gesperrt bis $resetTime Uhr).";
            } else {
                $remaining = $userLimit['remaining'];
                $rateLimitError = "Zu viele Anmeldeversuche. Bitte warten Sie einen Moment. Verbleibende Versuche: $remaining";
            }
        } else {
            if (empty($username) || empty($password)) {
                $error = 'Bitte geben Sie Benutzername und Passwort ein.';
                RateLimiter::record($userKey);
                RateLimiter::record($ipKey);
            } elseif (Auth::login($username, $password)) {
                // Successful login – reset both rate limits
                RateLimiter::reset($userKey);
                RateLimiter::reset($ipKey);
                header('Location: ' . Auth::getLoginRedirectUrl());
                exit;
            } else {
                // Failed login – record on both keys
                RateLimiter::record($userKey);
                RateLimiter::record($ipKey);
                $remaining = $userLimit['remaining'];
                if ($remaining > 0) {
                    $error = "Ungültiger Benutzername oder Passwort. Verbleibende Versuche: $remaining";
                } else {
                    $error = "Ungültiger Benutzername oder Passwort. Zu viele Versuche - bitte warten Sie einen Moment.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Ticket System</title>
    <link href="<?= url('libs/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('libs/css/bootstrap-icons.css') ?>" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            padding: 2rem;
            width: 100%;
            max-width: 400px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="text-center mb-4">
            <i class="bi bi-ticket-perforated" style="font-size: 3rem; color: #667eea;"></i>
            <h2 class="mt-3">Ticket System</h2>
            <p class="text-muted">Bitte melden Sie sich an</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($rateLimitError): ?>
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-shield-exclamation"></i> <?= htmlspecialchars($rateLimitError) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label for="username" class="form-label">Benutzername</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="username" name="username" required autofocus>
                </div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Passwort</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">
                <i class="bi bi-box-arrow-in-right"></i> Anmelden
            </button>
        </form>
    </div>

    <script src="<?= url('libs/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>

