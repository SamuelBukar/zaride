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
    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session could not be verified. Please try again.');
        header('Location: login.php?type=' . $type);
        exit;
    }

    $postedType = normalizeAccountType($_POST['account_type'] ?? null);
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($postedType === null || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        setFlash('error', 'Enter a valid email address, password, and account type.');
        header('Location: login.php?type=' . ($postedType ?? $type));
        exit;
    }

    try {
        $account = findAccountByEmail($postedType, $email);
    } catch (PDOException $exception) {
        error_log('ZaRide sign-in failed: ' . $exception->getMessage());
        setFlash('error', 'Sign-in is temporarily unavailable. Please try again in a few moments.');
        header('Location: login.php?type=' . $postedType);
        exit;
    }

    if (!$account || !password_verify($password, (string) $account['password_hash'])) {
        setFlash('error', 'The email, password, or account type does not match.');
        header('Location: login.php?type=' . $postedType);
        exit;
    }

    if (!signInFoundAccount($postedType, $account)) {
        header('Location: login.php?type=' . $postedType);
        exit;
    }
    header('Location: ' . dashboardUrl($postedType));
    exit;
}

$flash = getFlash();
renderAuthStart('ZaRide | Sign in');
?>
            <h1 class="auth-title">Welcome back</h1>
            <p class="auth-subtitle">Sign in to continue</p>

            <?php renderAuthFlash($flash); ?>
            <?php renderAuthRoleSwitch($type, 'login.php'); ?>

            <form method="post" action="login.php" id="login-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="account_type" class="auth-account-type" value="<?= e($type) ?>">

                <div class="auth-group">
                    <label class="auth-label" for="email">Email address</label>
                    <div class="auth-field">
                        <span class="auth-icon"><?= authMailIcon() ?></span>
                        <input class="auth-input" id="email" name="email" type="email" placeholder="you@example.com" autocomplete="email" required>
                    </div>
                </div>

                <div class="auth-group">
                    <label class="auth-label" for="password">Password</label>
                    <div class="auth-field">
                        <span class="auth-icon"><?= authLockIcon() ?></span>
                        <input class="auth-input auth-input-secret" id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button class="auth-eye" type="button" data-toggle-password="password" aria-label="Show password"><?= authEyeIcon() ?></button>
                    </div>
                    <a class="auth-forgot" data-role-href="forgot-password.php" href="forgot-password.php?type=<?= e($type) ?>">Forgot password?</a>
                </div>

                <button class="auth-submit" type="submit">Sign in</button>
            </form>

            <div class="auth-divider"><span>or</span></div>
            <?php renderAuthGoogleButton($type, 'login'); ?>

            <p class="auth-switch">Don't have an account? <a data-role-href="register.php" href="register.php?type=<?= e($type) ?>">Create one</a></p>
<?php
renderAuthEnd();
