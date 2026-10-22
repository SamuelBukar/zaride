<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-ui.php';

if (currentAccountId() !== null && currentAccountType() !== null) {
    header('Location: ' . dashboardUrl());
    exit;
}

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$reset = null;
try {
    $reset = findPasswordReset($token);
} catch (PDOException $exception) {
    error_log('Password reset lookup failed: ' . $exception->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        setFlash('error', 'Your session could not be verified. Please try again.');
        header('Location: reset-password.php?token=' . rawurlencode($token));
        exit;
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    if (strlen($password) < 8) {
        setFlash('error', 'Your new password must contain at least 8 characters.');
        header('Location: reset-password.php?token=' . rawurlencode($token));
        exit;
    }
    if ($password !== $confirm) {
        setFlash('error', 'The two passwords do not match.');
        header('Location: reset-password.php?token=' . rawurlencode($token));
        exit;
    }

    try {
        $updated = completePasswordReset($token, $password);
    } catch (PDOException $exception) {
        error_log('Password reset update failed: ' . $exception->getMessage());
        $updated = false;
    }

    if (!$updated) {
        setFlash('error', 'This reset link is invalid or has expired. Request a new one.');
        header('Location: forgot-password.php');
        exit;
    }

    setFlash('success', 'Your password is updated. Sign in with the new one.');
    header('Location: login.php');
    exit;
}

$flash = getFlash();
renderAuthStart('ZaRide | Reset password');
?>
            <h1 class="auth-title">Choose a new password</h1>
            <p class="auth-subtitle">Use at least 8 characters</p>

            <?php renderAuthFlash($flash); ?>

            <?php if (!$reset): ?>
                <div class="auth-notice auth-notice-error" role="alert">This reset link is invalid or has expired.</div>
                <p class="auth-switch"><a href="forgot-password.php">Request a new link</a></p>
            <?php else: ?>
                <form method="post" action="reset-password.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="auth-group">
                        <label class="auth-label" for="password">New password</label>
                        <div class="auth-field">
                            <span class="auth-icon"><?= authLockIcon() ?></span>
                            <input class="auth-input auth-input-secret" id="password" name="password" type="password" placeholder="At least 8 characters" autocomplete="new-password" minlength="8" required>
                            <button class="auth-eye" type="button" data-toggle-password="password" aria-label="Show password"><?= authEyeIcon() ?></button>
                        </div>
                    </div>

                    <div class="auth-group">
                        <label class="auth-label" for="password_confirm">Confirm password</label>
                        <div class="auth-field">
                            <span class="auth-icon"><?= authLockIcon() ?></span>
                            <input class="auth-input auth-input-secret" id="password_confirm" name="password_confirm" type="password" placeholder="Repeat your password" autocomplete="new-password" minlength="8" required>
                            <button class="auth-eye" type="button" data-toggle-password="password_confirm" aria-label="Show password"><?= authEyeIcon() ?></button>
                        </div>
                    </div>

                    <button class="auth-submit" type="submit">Update password</button>
                </form>
            <?php endif; ?>

            <p class="auth-switch"><a href="login.php">Back to sign in</a></p>
<?php
renderAuthEnd();
