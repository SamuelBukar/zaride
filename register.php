<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth-ui.php';

if (isset($_GET['cancel_google'])) {
    unset($_SESSION['google_pending']);
    $cancelType = authSelectedType();
    header('Location: register.php?type=' . $cancelType);
    exit;
}

if (currentAccountId() !== null && currentAccountType() !== null) {
    header('Location: ' . dashboardUrl());
    exit;
}

$pending = googlePendingProfile();
$type = isset($_GET['type']) ? authSelectedType() : ($pending['account_type'] ?? authSelectedType());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session could not be verified. Please try registering again.');
        header('Location: register.php?type=' . $type);
        exit;
    }

    $accountType = normalizeAccountType($_POST['account_type'] ?? null) ?? '';
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $city = trim((string) ($_POST['city'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $servicesOffered = trim((string) ($_POST['services_offered'] ?? 'both'));
    $vehicleType = trim((string) ($_POST['vehicle_type'] ?? ''));
    $pending = googlePendingProfile();
    $googleSub = null;

    if ($pending) {
        $email = $pending['email'];
        $googleSub = $pending['sub'];
    }

    $errors = [];
    if (!in_array($accountType, ['customer', 'rider'], true)) {
        $errors[] = 'Choose passenger or rider.';
    }
    if (preg_match('/\A.{1,120}\z/us', $fullName) !== 1) {
        $errors[] = 'Enter your name (up to 120 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!preg_match('/^[+0-9(). -]{7,25}$/', $phone)) {
        $errors[] = 'Enter a valid phone number (7 to 25 characters).';
    }
    if (preg_match('/\A.{1,100}\z/us', $city) !== 1) {
        $errors[] = 'Enter your city (up to 100 characters).';
    }
    if ($googleSub === null && strlen($password) < 8) {
        $errors[] = 'Your password must contain at least 8 characters.';
    }
    if ($googleSub !== null && $password !== '' && strlen($password) < 8) {
        $errors[] = 'Your password must contain at least 8 characters, or leave it blank to use Google only.';
    }
    if ($accountType === 'rider' && !in_array($servicesOffered, ['both', 'travel', 'delivery'], true)) {
        $errors[] = 'Choose the services you offer.';
    }
    if ($accountType === 'rider' && preg_match('/\A.{1,100}\z/us', $vehicleType) !== 1) {
        $errors[] = 'Enter a vehicle type (up to 100 characters).';
    }

    if ($errors !== []) {
        setFlash('error', implode(' ', $errors));
        header('Location: register.php?type=' . ($accountType !== '' ? $accountType : $type));
        exit;
    }

    $result = createZaRideAccount(
        $accountType,
        $fullName,
        $email,
        $phone,
        $city,
        $password,
        $servicesOffered,
        $vehicleType,
        $googleSub
    );

    if (!$result['ok']) {
        setFlash('error', (string) $result['error']);
        header('Location: register.php?type=' . $accountType);
        exit;
    }

    setFlash('success', 'Welcome to ZaRide, ' . $fullName . '!');
    rememberSignedInAccount($accountType, (int) $result['id']);
    header('Location: ' . dashboardUrl($accountType));
    exit;
}

$pending = googlePendingProfile();
$flash = getFlash();
$prefillName = $pending['name'] ?? '';
$prefillEmail = $pending['email'] ?? '';
renderAuthStart('ZaRide | Create account');
?>
            <h1 class="auth-title">Create an account</h1>
            <p class="auth-subtitle">Sign up to continue</p>

            <?php renderAuthFlash($flash); ?>

            <?php if ($pending): ?>
                <div class="auth-google-banner">
                    Google confirmed <strong><?= e($pending['email']) ?></strong>. Add your phone and city to finish.
                    <a href="register.php?cancel_google=1&amp;type=<?= e($type) ?>">Use a different email</a>
                </div>
            <?php endif; ?>

            <?php renderAuthRoleSwitch($type, 'register.php'); ?>

            <form method="post" action="register.php?type=<?= e($type) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="account_type" class="auth-account-type" value="<?= e($type) ?>">

                <div class="auth-group">
                    <label class="auth-label" for="full_name">Full name</label>
                    <input class="auth-input auth-input-plain" id="full_name" name="full_name" type="text" placeholder="Your name" value="<?= e($prefillName) ?>" autocomplete="name" maxlength="120" required>
                </div>

                <div class="auth-group">
                    <label class="auth-label" for="email">Email address</label>
                    <div class="auth-field">
                        <span class="auth-icon"><?= authMailIcon() ?></span>
                        <input class="auth-input" id="email" name="email" type="email" placeholder="you@example.com" value="<?= e($prefillEmail) ?>" autocomplete="email" maxlength="254" <?= $pending ? 'readonly' : '' ?> required>
                    </div>
                </div>

                <div class="auth-group">
                    <label class="auth-label" for="phone">Phone number</label>
                    <input class="auth-input auth-input-plain" id="phone" name="phone" type="tel" placeholder="08012345678" autocomplete="tel" maxlength="25" required>
                </div>

                <div class="auth-group">
                    <label class="auth-label" for="city">City</label>
                    <input class="auth-input auth-input-plain" id="city" name="city" type="text" placeholder="Zaria" value="Zaria" maxlength="100" required>
                </div>

                <div class="auth-group" data-rider-only <?= $type === 'rider' ? '' : 'hidden' ?>>
                    <label class="auth-label" for="services_offered">Services offered</label>
                    <select class="auth-input auth-input-plain" id="services_offered" name="services_offered">
                        <option value="both" selected>Travels and deliveries</option>
                        <option value="travel">Passenger travel only</option>
                        <option value="delivery">Parcel delivery only</option>
                    </select>
                </div>

                <div class="auth-group" data-rider-only <?= $type === 'rider' ? '' : 'hidden' ?>>
                    <label class="auth-label" for="vehicle_type">Vehicle type</label>
                    <input class="auth-input auth-input-plain" id="vehicle_type" name="vehicle_type" type="text" maxlength="100" placeholder="Car, motorcycle, van" data-rider-required <?= $type === 'rider' ? 'required' : '' ?>>
                </div>

                <div class="auth-group">
                    <label class="auth-label" for="password"><?= $pending ? 'Password (optional)' : 'Password' ?></label>
                    <div class="auth-field">
                        <span class="auth-icon"><?= authLockIcon() ?></span>
                        <input class="auth-input auth-input-secret" id="password" name="password" type="password" placeholder="<?= $pending ? 'Leave blank to use Google only' : 'At least 8 characters' ?>" autocomplete="new-password" <?= $pending ? '' : 'minlength="8" required' ?>>
                        <button class="auth-eye" type="button" data-toggle-password="password" aria-label="Show password"><?= authEyeIcon() ?></button>
                    </div>
                </div>

                <button class="auth-submit" type="submit">Create account</button>
            </form>

            <?php if (!$pending): ?>
                <div class="auth-divider"><span>or</span></div>
                <?php renderAuthGoogleButton($type, 'register'); ?>
            <?php endif; ?>

            <p class="auth-switch">Already have an account? <a data-role-href="login.php" href="login.php?type=<?= e($type) ?>">Sign in</a></p>
<?php
renderAuthEnd();
