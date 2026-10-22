<?php
declare(strict_types=1);

function authSelectedType(): string
{
    return normalizeAccountType($_GET['type'] ?? null) ?? 'customer';
}

function renderAuthStart(string $title): void
{
    $safeTitle = e($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{$safeTitle}</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="auth-body">
    <main class="auth-page">
        <div class="auth-shell">
            <a class="auth-logo" href="index.php">ZaRide</a>
HTML;
}

function renderAuthEnd(): void
{
    echo <<<'HTML'
        </div>
    </main>
    <script>
    document.querySelectorAll('.auth-role-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            var role = button.getAttribute('data-role');
            document.querySelectorAll('.auth-account-type').forEach(function (input) {
                input.value = role;
            });
            document.querySelectorAll('.auth-role-btn').forEach(function (other) {
                var active = other.getAttribute('data-role') === role;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll('[data-rider-only]').forEach(function (block) {
                block.hidden = role !== 'rider';
            });
            document.querySelectorAll('[data-rider-required]').forEach(function (input) {
                input.required = role === 'rider';
            });
            document.querySelectorAll('[data-role-href]').forEach(function (link) {
                link.href = link.getAttribute('data-role-href') + '?type=' + encodeURIComponent(role);
            });
        });
    });

    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-toggle-password'));
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            var openEye = button.querySelector('.eye-open');
            var shutEye = button.querySelector('.eye-shut');
            if (openEye) openEye.hidden = show;
            if (shutEye) shutEye.hidden = !show;
        });
    });
    </script>
</body>
</html>
HTML;
}

function renderAuthFlash(?array $flash): void
{
    if ($flash === null) {
        return;
    }
    $type = ($flash['type'] ?? '') === 'success' ? 'success' : 'error';
    echo '<div class="auth-notice auth-notice-' . e($type) . '" role="alert">' . e((string) ($flash['message'] ?? '')) . '</div>';
}

function renderAuthRoleSwitch(string $type, string $page): void
{
    $customerClass = $type === 'customer' ? ' is-active' : '';
    $riderClass = $type === 'rider' ? ' is-active' : '';
    $customerSelected = $type === 'customer' ? 'true' : 'false';
    $riderSelected = $type === 'rider' ? 'true' : 'false';
    $customerHref = e($page . '?type=customer');
    $riderHref = e($page . '?type=rider');
    echo <<<HTML
<div class="auth-role" role="tablist" aria-label="Account type">
    <a class="auth-role-btn{$customerClass}" href="{$customerHref}" data-role="customer" role="tab" aria-selected="{$customerSelected}">Passenger</a>
    <a class="auth-role-btn{$riderClass}" href="{$riderHref}" data-role="rider" role="tab" aria-selected="{$riderSelected}">Rider</a>
</div>
HTML;
}

function authMailIcon(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="m4 7 8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
}

function authLockIcon(): string
{
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
}

function authEyeIcon(): string
{
    return '<span class="eye-open"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12Z" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.7"/></svg></span>'
        . '<span class="eye-shut" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3.5 21 20.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M9.2 6.9A9.8 9.8 0 0 1 12 6.5c6 0 9.5 5.5 9.5 5.5a16 16 0 0 1-3.2 3.6M6.1 8.2C3.8 9.8 2.5 12 2.5 12S6 17.5 12 17.5c1.1 0 2.1-.2 3-.6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M10 10.2a2.6 2.6 0 0 0 3.6 3.6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>';
}

function renderAuthGoogleButton(string $type, string $intent): void
{
    $csrf = e(csrfToken());
    $safeType = e($type);
    $safeIntent = e($intent);
    echo <<<HTML
<form method="post" action="google-start.php" class="auth-google-form">
    <input type="hidden" name="csrf_token" value="{$csrf}">
    <input type="hidden" name="account_type" class="auth-account-type" value="{$safeType}">
    <input type="hidden" name="intent" value="{$safeIntent}">
    <button class="auth-google" type="submit">
        <svg class="auth-google-mark" viewBox="0 0 48 48" aria-hidden="true">
            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.2-.1-2.3-.4-3.5z"/>
            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 12 24 12c3.1 0 5.8 1.2 8 3.1l5.7-5.7C34.2 6.1 29.4 4 24 4 16.3 4 9.6 8.3 6.3 14.7z"/>
            <path fill="#4CAF50" d="M24 44c5.2 0 10-2 13.6-5.2l-6.3-5.3C29.3 35.1 26.8 36 24 36c-5.3 0-9.7-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-1.1 3.2-3.5 5.7-6.6 7.1l6.3 5.3C37.4 38.4 44 34 44 24c0-1.2-.1-2.3-.4-3.5z"/>
        </svg>
        Continue with Google
    </button>
</form>
HTML;
}
