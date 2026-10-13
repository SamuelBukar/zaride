<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (currentAccountId() !== null && currentAccountType() !== null) {
    header('Location: ' . dashboardUrl());
    exit;
}

$type = $_GET['type'] ?? 'customer';
$type = is_string($type) && in_array($type, ['customer', 'rider'], true) ? $type : 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        setFlash('error', 'Your session could not be verified. Please try again.');
        header('Location: login.php?type=' . $type);
        exit;
    }

    $postedType = $_POST['account_type'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $postedType = is_string($postedType) ? $postedType : '';
    $email = is_string($email) ? strtolower(trim($email)) : '';
    $password = is_string($password) ? $password : '';

    if (!in_array($postedType, ['customer', 'rider'], true)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $password === '') {
        setFlash('error', 'Enter a valid email address, password, and account type.');
        header('Location: login.php?type=' . $type);
        exit;
    }

    $table = $postedType === 'rider' ? 'riders' : 'customers';
    try {
        $statement = db()->prepare("SELECT id, password_hash, full_name FROM {$table} WHERE email = ?");
        $statement->execute([$email]);
        $account = $statement->fetch();
    } catch (PDOException $exception) {
        error_log('ZaRide sign-in failed: ' . $exception->getMessage());
        setFlash('error', 'Sign-in is temporarily unavailable. Please try again in a few moments.');
        header('Location: login.php?type=' . $postedType);
        exit;
    }

    if (!$account || !password_verify($password, $account['password_hash'])) {
        setFlash('error', 'The email, password, or account type does not match.');
        header('Location: login.php?type=' . $postedType);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['account_type'] = $postedType;
    $_SESSION['account_id'] = (int) $account['id'];
    setFlash('success', 'Welcome back, ' . htmlspecialchars($account['full_name']) . '!');
    header('Location: ' . dashboardUrl($postedType));
    exit;
}

$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | Sign In to Your P2P Account</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body style="background: var(--surface-alt);">
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">P2P Mobility</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="travels.php">Find Travels</a>
            <a class="nav-link" href="deliveries.php">Deliveries</a>
            <a class="nav-link" href="zaride.php">Riders</a>
            <a class="button button-sm button-secondary" href="index.php#register">Create Account</a>
        </nav>
    </header>

    <main style="min-height: calc(100vh - 160px); display: flex; align-items: center; justify-content: center; padding: 40px 20px;">
        <div style="width: 100%; max-width: 460px; background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 36px 32px; box-shadow: var(--shadow-lg);">
            <div style="text-align: center; margin-bottom: 26px;">
                <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary); border-color: var(--primary-light);">Welcome back</span>
                <h1 style="font-size: 26px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 6px;">Sign in to ZaRide</h1>
                <p style="color: var(--muted); font-size: 14px;">Select your role to access your dashboard</p>
            </div>

            <?php if ($flash !== null): ?>
                <div class="notice notice-<?= e($flash['type']) ?>" role="alert">
                    <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>

            <!-- Role Selector Tabs -->
            <div style="display: flex; gap: 6px; background: var(--surface-alt); padding: 5px; border-radius: var(--radius-md); margin-bottom: 24px;">
                <button type="button" onclick="selectRole('customer')" id="tab-customer" class="button button-sm button-full <?= $type === 'customer' ? 'button-primary' : 'button-secondary' ?>" style="border: 0; box-shadow: none;">
                    👤 Passenger / Sender
                </button>
                <button type="button" onclick="selectRole('rider')" id="tab-rider" class="button button-sm button-full <?= $type === 'rider' ? 'button-primary' : 'button-secondary' ?>" style="border: 0; box-shadow: none;">
                    🛵 Rider / Driver
                </button>
            </div>

            <form method="post" action="login.php" id="login-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="account_type" id="account_type_input" value="<?= e($type) ?>">

                <div class="form-group">
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control" id="email" name="email" type="email" placeholder="e.g. name@example.com" autocomplete="email" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" placeholder="••••••••" autocomplete="current-password" required>
                </div>

                <button class="button button-primary button-full button-lg" type="submit" style="margin-top: 10px;">
                    Sign in to Account →
                </button>
            </form>

            <!-- Quick Demo Auto-fill Helper for convenient evaluation -->
            <div style="margin-top: 28px; padding-top: 20px; border-top: 1px dashed var(--line); text-align: center;">
                <p style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--muted); margin-bottom: 10px;">
                    ⚡ Quick Demo Logins
                </p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <button type="button" class="button button-sm button-secondary" onclick="fillDemo('bukarsamuelisaac@gmail.com', 'rider')" style="font-size: 12px; justify-content: flex-start;">
                        🛵 <strong>Bukar Samuel</strong> (Online Rider / Driver)
                    </button>
                    <button type="button" class="button button-sm button-secondary" onclick="fillDemo('david.adeyemi@zaride.example', 'rider')" style="font-size: 12px; justify-content: flex-start;">
                        🚗 <strong>David Adeyemi</strong> (Lagos Active Trips)
                    </button>
                    <button type="button" class="button button-sm button-secondary" onclick="fillDemo('amaka.customer@zaride.example', 'customer')" style="font-size: 12px; justify-content: flex-start;">
                        👤 <strong>Amaka Johnson</strong> (Passenger / Customer)
                    </button>
                </div>
                <small style="color: var(--muted); font-size: 11px; display: block; margin-top: 8px;">Demo accounts use password: <code>password123</code></small>
            </div>

            <p style="margin-top: 24px; text-align: center; font-size: 13px; color: var(--muted);">
                Don't have an account yet? <a href="index.php#register" style="color: var(--primary); font-weight: 700;">Join ZaRide</a>
            </p>
        </div>
    </main>

    <footer class="site-footer" style="padding: 20px 0; text-align: center;">
        <div class="container">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network. Secure session.</span>
        </div>
    </footer>

    <script>
    function selectRole(role) {
        document.getElementById('account_type_input').value = role;
        const tabCust = document.getElementById('tab-customer');
        const tabRider = document.getElementById('tab-rider');
        if (role === 'customer') {
            tabCust.className = 'button button-sm button-full button-primary';
            tabRider.className = 'button button-sm button-full button-secondary';
        } else {
            tabCust.className = 'button button-sm button-full button-secondary';
            tabRider.className = 'button button-sm button-full button-primary';
        }
    }

    function fillDemo(email, role) {
        selectRole(role);
        document.getElementById('email').value = email;
        document.getElementById('password').value = 'password123';
    }
    </script>
    <?= renderMobileBottomNav('account') ?>
</body>
</html>
