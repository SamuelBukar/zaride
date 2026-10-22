<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$oauth = $_SESSION['google_oauth'] ?? null;
$oauth = is_array($oauth) ? $oauth : [];
$type = normalizeAccountType($oauth['account_type'] ?? null) ?? 'customer';
$intent = ($oauth['intent'] ?? '') === 'register' ? 'register' : 'login';
$return = ($intent === 'register' ? 'register.php?type=' : 'login.php?type=') . rawurlencode($type);

$fail = static function (string $message) use ($return): void {
    unset($_SESSION['google_oauth']);
    setFlash('error', $message);
    header('Location: ' . $return);
    exit;
};

if (!googleOAuthReady()) {
    $fail('Google sign-in is not set up yet.');
}

$createdAt = (int) ($oauth['created_at'] ?? 0);
$expectedState = (string) ($oauth['state'] ?? '');
$verifier = (string) ($oauth['verifier'] ?? '');
$returnedState = (string) ($_GET['state'] ?? '');

if ($expectedState === '' || $verifier === '' || $createdAt < time() - 600
    || strlen($expectedState) !== strlen($returnedState) || !hash_equals($expectedState, $returnedState)) {
    $fail('Google sign-in could not be verified. Please try again.');
}

unset($_SESSION['google_oauth']);

if (isset($_GET['error'])) {
    $fail(($_GET['error'] ?? '') === 'access_denied'
        ? 'Google sign-in was cancelled.'
        : 'Google sign-in did not complete. Please try again.');
}

$code = $_GET['code'] ?? '';
if (!is_string($code) || $code === '') {
    $fail('Google did not return a sign-in code. Please try again.');
}

$config = googleOAuthConfig();

try {
    $tokenResponse = httpJsonRequest(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => googleRedirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ])
    );
} catch (RuntimeException $exception) {
    error_log('Google token request failed: ' . $exception->getMessage());
    $fail('Could not reach Google. Check your connection and try again.');
}

$accessToken = (string) ($tokenResponse['body']['access_token'] ?? '');
if ($tokenResponse['status'] !== 200 || $accessToken === '') {
    error_log('Google token exchange failed: ' . (string) ($tokenResponse['body']['error'] ?? 'unknown'));
    $fail('Google sign-in could not be completed. Please try again.');
}

try {
    $profileResponse = httpJsonRequest(
        'GET',
        'https://openidconnect.googleapis.com/v1/userinfo',
        ['Authorization: Bearer ' . $accessToken]
    );
} catch (RuntimeException $exception) {
    error_log('Google profile request failed: ' . $exception->getMessage());
    $fail('Could not read your Google profile. Please try again.');
}

$profile = $profileResponse['body'];
$email = strtolower(trim((string) ($profile['email'] ?? '')));
$sub = trim((string) ($profile['sub'] ?? ''));
$verified = $profile['email_verified'] ?? false;
$verified = $verified === true || $verified === 1 || $verified === '1' || $verified === 'true';
$name = trim((string) ($profile['name'] ?? ''));

if ($profileResponse['status'] !== 200 || $sub === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$verified) {
    $fail('Google did not confirm a verified email address for this account.');
}

if ($name === '') {
    $name = strstr($email, '@', true) ?: 'ZaRide user';
}
if (preg_match('/\A(.{1,120})/us', $name, $nameMatch) === 1) {
    $name = $nameMatch[1];
}

try {
    $account = findAccountByGoogleSub($type, $sub);
    if (!$account) {
        $account = findAccountByEmail($type, $email);
    }
} catch (PDOException $exception) {
    error_log('Google account lookup failed: ' . $exception->getMessage());
    $fail('Sign-in is temporarily unavailable. Please try again in a few moments.');
}

if ($account) {
    $linkedSub = (string) ($account['google_sub'] ?? '');
    if ($linkedSub !== '' && $linkedSub !== $sub) {
        $fail('That email is already linked to a different Google account.');
    }
    if (!signInFoundAccount($type, $account, $sub)) {
        header('Location: login.php?type=' . rawurlencode($type));
        exit;
    }
    header('Location: ' . dashboardUrl($type));
    exit;
}

$_SESSION['google_pending'] = [
    'sub' => $sub,
    'email' => $email,
    'name' => $name,
    'account_type' => $type,
];
setFlash('success', 'Google confirmed ' . $email . '. Finish your profile to create the account.');
header('Location: register.php?type=' . rawurlencode($type));
exit;
