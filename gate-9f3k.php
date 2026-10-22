<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('X-Robots-Tag: noindex, nofollow');

if (currentStaff() !== null) {
    header('Location: staff-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session could not be verified. Please try again.');
        header('Location: gate-9f3k.php');
        exit;
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $staff = null;
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '') {
        try {
            $stmt = db()->prepare('SELECT * FROM staff WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $staff = $stmt->fetch() ?: null;
        } catch (PDOException $exception) {
            error_log('Staff sign-in failed: ' . $exception->getMessage());
            setFlash('error', 'Sign-in is temporarily unavailable.');
            header('Location: gate-9f3k.php');
            exit;
        }
    }

    if (!$staff || !password_verify($password, (string) $staff['password_hash'])) {
        setFlash('error', 'The email or password does not match.');
        header('Location: gate-9f3k.php');
        exit;
    }

    if (($staff['status'] ?? '') !== 'active') {
        setFlash('error', 'This staff account is suspended.');
        header('Location: gate-9f3k.php');
        exit;
    }

    rememberStaff((int) $staff['id']);
    setFlash('success', 'Welcome back.');
    header('Location: staff-dashboard.php');
    exit;
}

$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="staff-gate-body">
    <main class="staff-gate">
        <h1>Staff sign in</h1>
        <p>Admins and the super admin use this page.</p>
        <?php if ($flash): ?>
            <div class="notice notice-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <form method="post" action="gate-9f3k.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" id="email" name="email" type="email" autocomplete="username" required>
            <label class="form-label" for="password">Password</label>
            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="button button-primary button-lg" type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
