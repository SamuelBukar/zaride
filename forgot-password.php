<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-ui.php';

if (currentAccountId() !== null && currentAccountType() !== null) {
    header('Location: ' . dashboardUrl());
    exit;
}

$type = authSelectedType();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $postedType = normalizeAccountType($_POST['account_type'] ?? null);
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $postedType = $postedType ?? $type;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session could not be verified. Please try again.');
        header('Location: forgot-password.php?type=' . $postedType);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', 'Enter the email address on your account.');
        header('Location: forgot-password.php?type=' . $postedType);
        exit;
    }

    try {
        $devLink = requestPasswordReset($postedType, $email);
    } catch (PDOException $exception) {
        error_log('Password reset failed: ' . $exception->getMessage());
        setFlash('error', 'Password reset is temporarily unavailable. Please try again.');
        header('Location: forgot-password.php?type=' . $postedType);
        exit;
    }

    if (is_string($devLink) && $devLink !== '') {
        $_SESSION['dev_reset_link'] = $devLink;
        setFlash('success', 'Mail is not running on this computer. Use the reset link below.');
    } else {
        setFlash('success', 'If that account exists, a reset link is on its way. It expires in one hour.');
    }
    header('Location: forgot-password.php?type=' . $postedType);
    exit;
}

$flash = getFlash();
$devLink = $_SESSION['dev_reset_link'] ?? null;
unset($_SESSION['dev_reset_link']);
$devLink = is_string($devLink) && str_starts_with($devLink, appBaseUrl() . '/reset-password?token=') ? $devLink : null;
renderAuthStart('ZaRide | Forgot password');
?>
            <h1 class="auth-title">Forgot password</h1>
            <p class="auth-subtitle">We'll email you a reset link</p>

            <?php renderAuthFlash($flash); ?>
            <?php if ($devLink): ?>
                <p class="auth-dev-link"><a href="<?= e($devLink) ?>">Open reset link</a></p>
            <?php endif; ?>
            <?php renderAuthRoleSwitch($type, 'forgot-password.php'); ?>

            <form method="post" action="forgot-password.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="account_type" class="auth-account-type" value="<?= e($type) ?>">

                <div class="auth-group">
                    <label class="auth-label" for="email">Email address</label>
                    <div class="auth-field">
                        <span class="auth-icon"><?= authMailIcon() ?></span>
                        <input class="auth-input" id="email" name="email" type="email" placeholder="you@example.com" autocomplete="email" required>
                    </div>
                </div>

                <button class="auth-submit" type="submit">Send reset link</button>
            </form>

            <p class="auth-switch"><a data-role-href="login.php" href="login.php?type=<?= e($type) ?>">Back to sign in</a></p>
<?php
renderAuthEnd();
