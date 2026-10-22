<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$token = $_POST['csrf_token'] ?? '';
$type = normalizeAccountType($_POST['account_type'] ?? null) ?? 'customer';
$intent = ($_POST['intent'] ?? '') === 'register' ? 'register' : 'login';
$return = ($intent === 'register' ? 'register.php?type=' : 'login.php?type=') . rawurlencode($type);

if (!verifyCsrfToken(is_string($token) ? $token : null)) {
    setFlash('error', 'Your session could not be verified. Please try again.');
    header('Location: ' . $return);
    exit;
}

if (!googleOAuthReady()) {
    setFlash(
        'error',
        'Google sign-in is not set up yet. Add your OAuth client in google.local.php. Redirect URI: ' . googleRedirectUri()
    );
    header('Location: ' . $return);
    exit;
}

$state = base64UrlEncode(random_bytes(24));
$verifier = base64UrlEncode(random_bytes(32));
$challenge = base64UrlEncode(hash('sha256', $verifier, true));

$_SESSION['google_oauth'] = [
    'state' => $state,
    'verifier' => $verifier,
    'account_type' => $type,
    'intent' => $intent,
    'created_at' => time(),
];

$config = googleOAuthConfig();
$query = http_build_query([
    'client_id' => $config['client_id'],
    'redirect_uri' => googleRedirectUri(),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'prompt' => 'select_account',
    'access_type' => 'online',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $query);
exit;
