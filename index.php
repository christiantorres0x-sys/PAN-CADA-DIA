<?php
require_once __DIR__ . '/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$lockSeconds = 0; // > 0 while this login is temporarily locked (drives the countdown + disabled form)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    login_throttle_ensure_table($pdo);
    $userKey = login_throttle_key_username($username);
    $ipKey = login_throttle_key_ip(login_client_ip());

    // Check the lock BEFORE looking at the password, so a locked account
    // can't be probed — even the correct password is refused until it expires.
    $lockSeconds = max(
        login_throttle_remaining($pdo, $userKey),
        login_throttle_remaining($pdo, $ipKey)
    );

    if ($lockSeconds > 0) {
        $error = 'Too many failed login attempts. Please try again in ' . format_lock_duration($lockSeconds) . '.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            login_throttle_clear($pdo, $userKey);
            session_regenerate_id(true); // new session ID on login (prevents session fixation)
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['role'] = normalize_role($user['role']);
            header('Location: dashboard.php');
            exit;
        }

        $byUser = login_throttle_record_failure($pdo, $userKey, LOGIN_MAX_ATTEMPTS);
        $byIp = login_throttle_record_failure($pdo, $ipKey, LOGIN_IP_MAX_ATTEMPTS);
        $lockSeconds = max($byUser['locked_for'], $byIp['locked_for']);

        if ($lockSeconds > 0) {
            log_action($pdo, 'LOGIN_LOCKED', 'users', null, 'Login locked for "' . str_limit_utf8($username, 80) . '" from ' . login_client_ip());
            $error = 'Too many failed login attempts. Please try again in ' . format_lock_duration($lockSeconds) . '.';
        } else {
            $error = 'Invalid username or password.';
            $left = LOGIN_MAX_ATTEMPTS - $byUser['count'];
            if ($left > 0 && $left <= 2) {
                $error .= ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining before a temporary lock.';
            }
        }
    }
}
?>
<!doctype html> 
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e(APP_NAME) ?> | Login</title>

<link rel="icon" type="image/png" href="/favicon.png">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/components.css">
<link rel="stylesheet" href="assets/css/forms.css">
<link rel="stylesheet" href="assets/css/login.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/login.css') ?>">
</head>
<body class="login-page">
<aside class="login-brand" aria-label="Ateneo de Zamboanga University">
    <div class="login-brand__photo" aria-hidden="true"></div>
    <img class="login-brand__seal" src="assets/images/AdZU-logo.svg" width="768" height="764" alt="" aria-hidden="true">
    <div class="login-brand__lockup">
        <img src="assets/images/AdZU-logo.svg" width="768" height="764" alt="Ateneo de Zamboanga University seal">
        <span>Ateneo de Zamboanga University</span>
    </div>
    <div class="login-brand__message">
        <h2>Track the health and nutrition of every child in the feeding program.</h2>
        <p>A program of the Center for Community Extension Services.</p>
    </div>
</aside>

<main class="login-panel">
    <div class="login-card">
        <div class="login-mark">
            <img src="assets/images/CCES-logo.png" width="240" height="240" alt="<?= e(APP_NAME) ?> logo">
        </div>
        <h1><?= e(APP_NAME) ?></h1>
        <p class="login-subtitle"><?= e(defined('APP_FULL_NAME') ? APP_FULL_NAME : 'Feeding Program Health Monitoring System') ?></p>

        <?php if ($error): ?>
            <div class="alert danger" id="login-alert" role="alert"<?= $lockSeconds > 0 ? ' data-lock-seconds="' . (int)$lockSeconds . '"' : '' ?>><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" id="login-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" required autofocus autocomplete="username" data-no-capitalize value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="password-field">
                    <input id="password" type="password" name="password" required autocomplete="current-password">
                    <button type="button" class="password-toggle" id="password-toggle" aria-label="Show password" aria-pressed="false" aria-controls="password">
                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.4 10.4 0 0 1 12 19c-6.4 0-10-7-10-7a17.7 17.7 0 0 1 4.06-5.06M9.9 5.24A9.8 9.8 0 0 1 12 5c6.4 0 10 7 10 7a17.8 17.8 0 0 1-2.17 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M2 2l20 20"/></svg>
                    </button>
                </div>
            </div>
            <button class="btn primary full" type="submit" id="login-submit">Log In</button>
        </form>

        <p class="login-foot">Center for Community Extension Services<br>Ateneo de Zamboanga University</p>
    </div>
</main>

<footer class="login-legal">
    <p>PAN CADA DIA | FEEDING PROGRAM HEALTH MONITORING SYSTEM</p>
    <p>Designed &amp; Developed by Christian Torres, Yasmien Maali, Kristian Dominic Aizon</p>
    <p>&copy; 2026 PAN CADA DIA | All Rights Reserved.</p>
</footer>
<script>
(function () {
    // Show / hide password. Keeps the caret where it was and never changes the typed value.
    var input = document.getElementById('password');
    var toggle = document.getElementById('password-toggle');
    toggle.addEventListener('click', function () {
        var show = input.type === 'password';
        var start = input.selectionStart, end = input.selectionEnd;
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.classList.toggle('is-visible', show);
        input.focus();
        try { input.setSelectionRange(start, end); } catch (e) {}
    });

    // Lockout: disable the form and count down, then let the user try again.
    var alertEl = document.getElementById('login-alert');
    var secs = alertEl ? parseInt(alertEl.getAttribute('data-lock-seconds'), 10) : 0;
    if (secs > 0) {
        var form = document.getElementById('login-form');
        var submit = document.getElementById('login-submit');
        var fields = form.querySelectorAll('input:not([type="hidden"]), button');
        var setLocked = function (locked) {
            fields.forEach(function (el) { el.disabled = locked; });
        };
        var render = function () {
            var m = Math.floor(secs / 60), s = secs % 60;
            alertEl.textContent = 'Too many failed login attempts. Try again in ' + m + ':' + (s < 10 ? '0' : '') + s + '.';
            submit.textContent = 'Locked (' + m + ':' + (s < 10 ? '0' : '') + s + ')';
        };
        setLocked(true);
        render();
        var timer = setInterval(function () {
            secs -= 1;
            if (secs <= 0) {
                clearInterval(timer);
                setLocked(false);
                submit.textContent = 'Log In';
                alertEl.className = 'alert success';
                alertEl.textContent = 'You can try logging in again.';
                document.getElementById('password').value = '';
                document.getElementById('password').focus();
            } else {
                render();
            }
        }, 1000);
    }
})();
</script>
</body>
</html>
