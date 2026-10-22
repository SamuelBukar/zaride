<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff-dashboard.php');
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken(is_string($token) ? $token : null)) {
    setFlash('error', 'Session verification failed. Please try again.');
    header('Location: staff-dashboard.php');
    exit;
}

$staff = requireStaff();
$action = (string) ($_POST['action'] ?? '');
$tab = (string) ($_POST['return_tab'] ?? 'overview');
$allowedTabs = ['overview', 'riders', 'customers', 'verify', 'deletions', 'earnings', 'staff', 'rider'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'overview';
}

$redirect = 'staff-dashboard.php?tab=' . rawurlencode($tab);
if ($tab === 'rider') {
    $redirect .= '&id=' . (int) ($_POST['rider_id'] ?? 0);
}

try {
    if ($action === 'add_admin') {
        if (!staffIsSuper($staff)) {
            setFlash('error', 'Only the super admin can add admins.');
            header('Location: staff-dashboard.php');
            exit;
        }
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            setFlash('error', 'Enter a name, a valid email, and a password of at least 8 characters.');
            header('Location: staff-dashboard.php?tab=staff');
            exit;
        }
        $exists = db()->prepare('SELECT id FROM staff WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            setFlash('error', 'That email is already used by a staff account.');
            header('Location: staff-dashboard.php?tab=staff');
            exit;
        }
        db()->prepare(
            "INSERT INTO staff (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'admin', 'active')"
        )->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        setFlash('success', 'Admin account created.');
        header('Location: staff-dashboard.php?tab=staff');
        exit;
    }

    if ($action === 'set_staff_status') {
        if (!staffIsSuper($staff)) {
            setFlash('error', 'Only the super admin can change staff accounts.');
            header('Location: staff-dashboard.php');
            exit;
        }
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
        $target = db()->prepare('SELECT * FROM staff WHERE id = ?');
        $target->execute([$staffId]);
        $targetRow = $target->fetch();
        if (!$targetRow || ($targetRow['role'] ?? '') === 'super_admin' || $staffId === (int) $staff['id']) {
            setFlash('error', 'That staff account cannot be changed here.');
            header('Location: staff-dashboard.php?tab=staff');
            exit;
        }
        db()->prepare('UPDATE staff SET status = ? WHERE id = ? AND role = "admin"')->execute([$status, $staffId]);
        setFlash('success', $status === 'suspended' ? 'Admin suspended.' : 'Admin restored.');
        header('Location: staff-dashboard.php?tab=staff');
        exit;
    }

    if ($action === 'delete_admin') {
        if (!staffIsSuper($staff)) {
            setFlash('error', 'Only the super admin can delete an admin account.');
            header('Location: staff-dashboard.php');
            exit;
        }
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $target = db()->prepare('SELECT id, role FROM staff WHERE id = ?');
        $target->execute([$staffId]);
        $targetRow = $target->fetch();
        if (!$targetRow || ($targetRow['role'] ?? '') !== 'admin' || $staffId === (int) $staff['id']) {
            setFlash('error', 'That admin account cannot be deleted.');
            header('Location: staff-dashboard.php?tab=staff');
            exit;
        }
        db()->prepare('DELETE FROM staff WHERE id = ? AND role = "admin"')->execute([$staffId]);
        setFlash('success', 'Admin account deleted.');
        header('Location: staff-dashboard.php?tab=staff');
        exit;
    }

    if ($action === 'review_vehicle') {
        $riderId = (int) ($_POST['rider_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
        $note = trim((string) ($_POST['note'] ?? ''));
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 400);
        } else {
            $note = substr($note, 0, 400);
        }
        $rider = fetchRiderById($riderId);
        if ($rider === null || !empty($rider['deleted_at'])) {
            setFlash('error', 'That rider could not be found.');
            header('Location: staff-dashboard.php?tab=verify');
            exit;
        }
        db()->prepare(
            'UPDATE riders
             SET verification_status = ?, verification_note = ?, verified_at = NOW(), verified_by = ?, status = ?
             WHERE id = ?'
        )->execute([
            $decision,
            $note !== '' ? $note : null,
            (int) $staff['id'],
            $decision === 'approved' ? (string) ($rider['status'] ?? 'offline') : 'offline',
            $riderId,
        ]);
        setFlash('success', $decision === 'approved' ? 'Vehicle approved.' : 'Vehicle rejected.');
        header('Location: staff-dashboard.php?tab=verify');
        exit;
    }

    if ($action === 'delete_account') {
        $accountType = ($_POST['account_type'] ?? '') === 'customer' ? 'customer' : 'rider';
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $error = removePersonAccount($accountType, $accountId);
        if ($error === '') {
            db()->prepare(
                "UPDATE deletion_requests
                 SET status = 'approved', review_note = 'Deleted by an admin', reviewed_by = ?, reviewed_at = NOW()
                 WHERE account_type = ? AND account_id = ? AND status = 'pending'"
            )->execute([(int) $staff['id'], $accountType, $accountId]);
            setFlash('success', $accountType === 'rider' ? 'Rider account deleted.' : 'Customer account deleted.');
        } else {
            setFlash('error', $error);
        }
        $back = $accountType === 'customer' ? 'customers' : 'riders';
        header('Location: staff-dashboard.php?tab=' . $back);
        exit;
    }

    if ($action === 'set_account_status') {
        $accountType = ($_POST['account_type'] ?? '') === 'customer' ? 'customer' : 'rider';
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $table = $accountType === 'rider' ? 'riders' : 'customers';
        $person = db()->prepare("SELECT id, deleted_at FROM {$table} WHERE id = ?");
        $person->execute([$accountId]);
        $row = $person->fetch();
        if (!$row || !empty($row['deleted_at'])) {
            setFlash('error', 'That account could not be changed.');
            header('Location: ' . $redirect);
            exit;
        }
        if ($accountType === 'rider') {
            db()->prepare(
                "UPDATE riders SET account_status = ?, suspension_reason = ?, status = IF(? = 'suspended', 'offline', status) WHERE id = ?"
            )->execute([$status, $status === 'suspended' ? ($reason !== '' ? $reason : null) : null, $status, $accountId]);
        } else {
            db()->prepare(
                'UPDATE customers SET account_status = ?, suspension_reason = ? WHERE id = ?'
            )->execute([$status, $status === 'suspended' ? ($reason !== '' ? $reason : null) : null, $accountId]);
        }
        setFlash('success', $status === 'suspended' ? 'Account suspended.' : 'Account restored.');
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'approve_deletion' || $action === 'reject_deletion') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($action === 'approve_deletion') {
            $error = approveAccountDeletion($requestId, (int) $staff['id'], $note);
            setFlash($error === '' ? 'success' : 'error', $error === '' ? 'Account deleted.' : $error);
        } else {
            $updated = db()->prepare(
                "UPDATE deletion_requests
                 SET status = 'rejected', review_note = ?, reviewed_by = ?, reviewed_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $updated->execute([$note !== '' ? $note : null, (int) $staff['id'], $requestId]);
            setFlash($updated->rowCount() > 0 ? 'success' : 'error', $updated->rowCount() > 0 ? 'Deletion request rejected.' : 'That request is no longer waiting.');
        }
        header('Location: staff-dashboard.php?tab=deletions');
        exit;
    }
} catch (PDOException $exception) {
    error_log('Staff action failed: ' . $exception->getMessage());
    setFlash('error', 'That action could not be completed.');
}

header('Location: staff-dashboard.php');
exit;
