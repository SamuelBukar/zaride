<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gate-9f3k.php');
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!is_string($token) || !verifyCsrfToken($token)) {
    setFlash('error', 'Your sign-out request could not be verified.');
    header('Location: staff-dashboard.php');
    exit;
}

unset($_SESSION['staff_id']);
setFlash('success', 'You have signed out.');
header('Location: gate-9f3k.php');
exit;
