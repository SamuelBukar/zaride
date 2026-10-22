<?php
declare(strict_types=1);

function ensureAuthSchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo = db();
    foreach ([
        'ALTER TABLE customers ADD COLUMN google_sub VARCHAR(255) NULL',
        'ALTER TABLE riders ADD COLUMN google_sub VARCHAR(255) NULL',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Column already exists.
        }
    }

    foreach ([
        'CREATE UNIQUE INDEX uq_customers_google_sub ON customers (google_sub)',
        'CREATE UNIQUE INDEX uq_riders_google_sub ON riders (google_sub)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Index already exists.
        }
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS password_resets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_type ENUM('customer', 'rider') NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_password_resets_token (token_hash),
                KEY idx_password_resets_account (account_type, account_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        error_log('Could not create password_resets table: ' . $e->getMessage());
    }
}

function accountTable(string $accountType): string
{
    if ($accountType === 'rider') {
        return 'riders';
    }
    if ($accountType === 'customer') {
        return 'customers';
    }
    throw new InvalidArgumentException('Invalid account type');
}

function normalizeAccountType(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = strtolower(trim($value));
    if ($value === 'passenger') {
        $value = 'customer';
    }
    return in_array($value, ['customer', 'rider'], true) ? $value : null;
}

function googleLocalConfig(): array
{
    static $loaded = false;
    static $config = [];
    if ($loaded) {
        return $config;
    }
    $loaded = true;
    $path = __DIR__ . '/google.local.php';
    if (!is_file($path)) {
        return $config;
    }
    $data = require $path;
    if (is_array($data)) {
        $config = $data;
    }
    return $config;
}

function appBaseUrl(): string
{
    $env = getenv('ZARIDE_BASE_URL');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }

    $local = googleLocalConfig();
    if (!empty($local['base_url']) && is_string($local['base_url']) && trim($local['base_url']) !== '') {
        return rtrim(trim($local['base_url']), '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($script), '/\\');
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        $dir = '';
    }

    return (isHttpsRequest() ? 'https://' : 'http://') . $host . $dir;
}

function googleRedirectUri(): string
{
    return appBaseUrl() . '/google-callback';
}

function googleOAuthConfig(): array
{
    $local = googleLocalConfig();
    $envId = getenv('ZARIDE_GOOGLE_CLIENT_ID');
    $envSecret = getenv('ZARIDE_GOOGLE_CLIENT_SECRET');
    $clientId = is_string($envId) && trim($envId) !== ''
        ? trim($envId)
        : trim((string) ($local['client_id'] ?? ''));
    $clientSecret = is_string($envSecret) && trim($envSecret) !== ''
        ? trim($envSecret)
        : trim((string) ($local['client_secret'] ?? ''));

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ];
}

function googleOAuthReady(): bool
{
    $config = googleOAuthConfig();
    return $config['client_id'] !== '' && $config['client_secret'] !== '';
}

function base64UrlEncode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function httpJsonRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for Google sign-in.');
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException($error !== '' ? $error : 'Request failed');
    }

    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

function findAccountByEmail(string $accountType, string $email): ?array
{
    $table = accountTable($accountType);
    $statement = db()->prepare("SELECT * FROM {$table} WHERE email = ? LIMIT 1");
    $statement->execute([$email]);
    $row = $statement->fetch();
    return $row ?: null;
}

function findAccountByGoogleSub(string $accountType, string $sub): ?array
{
    $table = accountTable($accountType);
    $statement = db()->prepare("SELECT * FROM {$table} WHERE google_sub = ? LIMIT 1");
    $statement->execute([$sub]);
    $row = $statement->fetch();
    return $row ?: null;
}

function linkGoogleSub(string $accountType, int $accountId, string $sub): void
{
    $table = accountTable($accountType);
    $statement = db()->prepare("UPDATE {$table} SET google_sub = ? WHERE id = ?");
    $statement->execute([$sub, $accountId]);
}

function googlePendingProfile(): ?array
{
    $pending = $_SESSION['google_pending'] ?? null;
    if (!is_array($pending)) {
        return null;
    }
    $email = strtolower(trim((string) ($pending['email'] ?? '')));
    $sub = trim((string) ($pending['sub'] ?? ''));
    $name = trim((string) ($pending['name'] ?? ''));
    $type = normalizeAccountType($pending['account_type'] ?? null) ?? 'customer';
    if ($email === '' || $sub === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return [
        'email' => $email,
        'sub' => $sub,
        'name' => $name,
        'account_type' => $type,
    ];
}

function createZaRideAccount(
    string $accountType,
    string $fullName,
    string $email,
    string $phone,
    string $city,
    string $password,
    string $servicesOffered,
    string $vehicleType,
    ?string $googleSub
): array {
    $existing = findAccountByEmail($accountType, $email);
    if ($existing) {
        if ($googleSub !== null && $googleSub !== '') {
            $linkedSub = (string) ($existing['google_sub'] ?? '');
            if ($linkedSub !== '' && $linkedSub !== $googleSub) {
                return ['ok' => false, 'error' => 'That email is already linked to a different Google account.'];
            }
            if ($linkedSub === '') {
                linkGoogleSub($accountType, (int) $existing['id'], $googleSub);
            }
            return ['ok' => true, 'id' => (int) $existing['id']];
        }
        return ['ok' => false, 'error' => 'That email address is already registered for this account type.'];
    }

    if ($googleSub !== null && $googleSub !== '') {
        $bySub = findAccountByGoogleSub($accountType, $googleSub);
        if ($bySub) {
            return ['ok' => true, 'id' => (int) $bySub['id']];
        }
    }

    $passwordHash = $password === ''
        ? password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)
        : password_hash($password, PASSWORD_DEFAULT);

    try {
        if ($accountType === 'customer') {
            $statement = db()->prepare(
                'INSERT INTO customers (full_name, email, phone, city, password_hash, google_sub)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$fullName, $email, $phone, $city, $passwordHash, $googleSub]);
        } else {
            $statement = db()->prepare(
                'INSERT INTO riders (full_name, email, phone, city, services_offered, vehicle_type, password_hash, status, verification_status, google_sub)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "offline", "unsubmitted", ?)'
            );
            $statement->execute([
                $fullName,
                $email,
                $phone,
                $city,
                $servicesOffered,
                $vehicleType,
                $passwordHash,
                $googleSub,
            ]);
        }
        return ['ok' => true, 'id' => (int) db()->lastInsertId()];
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            $detail = $exception->getMessage();
            if (stripos($detail, 'phone') !== false) {
                return ['ok' => false, 'error' => 'That phone number is already registered for this account type.'];
            }
            return ['ok' => false, 'error' => 'That email address or phone number is already registered for this account type.'];
        }
        error_log('ZaRide registration failed: ' . $exception->getMessage());
        return ['ok' => false, 'error' => 'Registration could not be completed. Check the database connection and try again.'];
    }
}

function signInFoundAccount(string $accountType, array $account, ?string $googleSub = null): bool
{
    $block = accountSignInBlock($account);
    if ($block !== null) {
        setFlash('error', $block);
        return false;
    }

    if ($googleSub !== null && $googleSub !== '') {
        $linkedSub = (string) ($account['google_sub'] ?? '');
        if ($linkedSub === '') {
            linkGoogleSub($accountType, (int) $account['id'], $googleSub);
        }
    }
    $name = trim((string) ($account['full_name'] ?? 'there'));
    setFlash('success', 'Welcome back, ' . $name . '!');
    rememberSignedInAccount($accountType, (int) $account['id']);
    return true;
}

function isLocalRequest(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function sendAppMail(string $to, string $subject, string $body): bool
{
    $from = getenv('ZARIDE_MAIL_FROM');
    $from = is_string($from) && trim($from) !== '' ? trim($from) : 'no-reply@localhost';
    $headers = "From: ZaRide <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, $subject, $body, $headers);
}

function requestPasswordReset(string $accountType, string $email): ?string
{
    $account = findAccountByEmail($accountType, $email);
    if (!$account) {
        return null;
    }

    $accountId = (int) $account['id'];
    $recent = db()->prepare(
        'SELECT COUNT(*) FROM password_resets
         WHERE account_type = ? AND account_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $recent->execute([$accountType, $accountId]);
    if ((int) $recent->fetchColumn() >= 5) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    db()->prepare('DELETE FROM password_resets WHERE account_type = ? AND account_id = ? AND expires_at < NOW()')
        ->execute([$accountType, $accountId]);
    db()->prepare(
        'INSERT INTO password_resets (account_type, account_id, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
    )->execute([$accountType, $accountId, $hash]);

    $link = appBaseUrl() . '/reset-password?token=' . rawurlencode($token);
    $sent = sendAppMail(
        $email,
        'Reset your ZaRide password',
        "Use this link to choose a new ZaRide password. It expires in one hour.\n\n{$link}\n\nIf you did not ask for this, you can ignore this email."
    );

    if (!$sent) {
        error_log('ZaRide password reset email could not be sent for account ' . $accountId);
        if (isLocalRequest()) {
            return $link;
        }
    }

    return null;
}

function findPasswordReset(string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return null;
    }
    $statement = db()->prepare(
        'SELECT * FROM password_resets WHERE token_hash = ? AND expires_at >= NOW() LIMIT 1'
    );
    $statement->execute([hash('sha256', $token)]);
    $row = $statement->fetch();
    return $row ?: null;
}

function completePasswordReset(string $token, string $password): bool
{
    $reset = findPasswordReset($token);
    if (!$reset) {
        return false;
    }
    $accountType = normalizeAccountType($reset['account_type'] ?? null);
    if ($accountType === null) {
        return false;
    }
    $table = accountTable($accountType);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db()->prepare("UPDATE {$table} SET password_hash = ? WHERE id = ?")
        ->execute([$hash, (int) $reset['account_id']]);
    db()->prepare('DELETE FROM password_resets WHERE account_type = ? AND account_id = ?')
        ->execute([$accountType, (int) $reset['account_id']]);
    return true;
}
