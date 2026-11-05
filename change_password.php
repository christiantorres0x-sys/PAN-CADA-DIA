<?php
/**
 * Change the signed-in user's own password.
 *
 * Only ever touches the row for $_SESSION['user_id'], so no other account can
 * be affected. The current password must be correct (wrong guesses count
 * toward the same lockout the login page uses), and passwords are never
 * echoed back, logged, or placed in a URL — the form posts, then redirects.
 */
require_once __DIR__ . '/db.php';
require_login();

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_BYTES  = 72; // bcrypt ignores anything past 72 bytes, so longer passwords are refused

$userId = (int)$_SESSION['user_id'];

/** Returns a plain-language reason the new password isn't acceptable, or null if it is. */
function pcd_new_password_problem(string $new, string $current, string $username): ?string {
    if (strlen($new) > PASSWORD_MAX_BYTES) {
        return 'The new password is too long. Use ' . PASSWORD_MAX_BYTES . ' characters or fewer.';
    }
    if (strlen($new) < PASSWORD_MIN_LENGTH) {
        return 'The new password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    if (!preg_match('/\p{L}/u', $new) || !preg_match('/\d/', $new)) {
        return 'The new password must include at least one letter and one number.';
    }
    if (hash_equals($current, $new)) {
        return 'The new password must be different from your current password.';
    }
    if (str_lower_utf8($new) === str_lower_utf8($username)) {
        return 'The new password cannot be the same as your username.';
    }
    return null;
}

function pcd_password_reply(bool $ok, string $message): void {
    flash($ok ? 'success' : 'danger', $message);
    header('Location: change_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    // Reuses the login lockout, keyed to this account, so a walk-up attacker on an
    // unlocked screen can't guess the current password without limit.
    login_throttle_ensure_table($pdo);
    $throttleKey = 'pw:' . $userId;
    $lockSeconds = login_throttle_remaining($pdo, $throttleKey);
    if ($lockSeconds > 0) {
        pcd_password_reply(false, 'Too many incorrect current passwords. Please try again in ' . format_lock_duration($lockSeconds) . '.');
    }

    if ($current === '' || $new === '' || $confirm === '') {
        pcd_password_reply(false, 'Please fill in your current password, new password, and confirmation.');
    }

    $stmt = $pdo->prepare("SELECT user_id, username, password_hash FROM users WHERE user_id = ? AND status = 'ACTIVE' LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        // The account was removed or deactivated while signed in: end the session.
        header('Location: logout.php');
        exit;
    }

    if (!password_verify($current, $user['password_hash'])) {
        $fail = login_throttle_record_failure($pdo, $throttleKey, LOGIN_MAX_ATTEMPTS);
        if ($fail['locked_for'] > 0) {
            log_action($pdo, 'PASSWORD_CHANGE_LOCKED', 'users', $userId, 'Password change locked after repeated incorrect current passwords');
            pcd_password_reply(false, 'Too many incorrect current passwords. Please try again in ' . format_lock_duration($fail['locked_for']) . '.');
        }
        $message = 'Your current password is incorrect.';
        $left = LOGIN_MAX_ATTEMPTS - $fail['count'];
        if ($left > 0 && $left <= 2) {
            $message .= ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining before a temporary lock.';
        }
        pcd_password_reply(false, $message);
    }

    if ($new !== $confirm) {
        pcd_password_reply(false, 'The new password and its confirmation do not match.');
    }

    $problem = pcd_new_password_problem($new, $current, (string)$user['username']);
    if ($problem !== null) {
        pcd_password_reply(false, $problem);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT, ['cost' => 12]), $userId]);
        log_action($pdo, 'CHANGE_PASSWORD', 'users', $userId, 'User changed their own password');
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        pcd_password_reply(false, 'Could not change your password, so nothing was changed. Please try again.');
    }

    login_throttle_clear($pdo, $throttleKey);
    session_regenerate_id(true); // fresh session ID after a credential change; the user stays signed in
    pcd_password_reply(true, 'Your password was changed. Use the new password the next time you sign in.');
}

$pageTitle = 'Change Password';
$pageSubtitle = 'Signed in as ' . ($_SESSION['user_name'] ?? '');
require __DIR__ . '/partials/header.php';
?>
<div class="form-panel form-panel-narrow">
    <section class="panel change-password-panel">
    <h2>Update Your Password</h2>
    <form method="post" action="change_password.php" id="change-password-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="form-grid">
            <div class="full-col">
                <label for="current_password">Current Password *</label>
                <input id="current_password" type="password" name="current_password" required autofocus autocomplete="current-password">
            </div>
            <div class="full-col">
                <label for="new_password">New Password *</label>
                <input id="new_password" type="password" name="new_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                <p class="hint-line">At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.</p>
            </div>
            <div class="full-col">
                <label for="confirm_password">Confirm New Password *</label>
                <input id="confirm_password" type="password" name="confirm_password" required autocomplete="new-password">
            </div>
        </div>
        <div class="form-actions">
            <a class="btn danger cancel-button" href="dashboard.php">Cancel</a>
            <button class="btn primary" type="submit">Change Password</button>
        </div>
    </form>
    </section>
</div>
<script>
(function () {
    // Friendly early warning when the confirmation doesn't match; the server checks again.
    var form = document.getElementById('change-password-form');
    var next = document.getElementById('new_password');
    var confirm = document.getElementById('confirm_password');
    function check() {
        confirm.setCustomValidity(confirm.value !== '' && confirm.value !== next.value ? 'Passwords do not match.' : '');
    }
    next.addEventListener('input', check);
    confirm.addEventListener('input', check);
    form.addEventListener('submit', check);
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
