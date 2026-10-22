<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('X-Robots-Tag: noindex, nofollow');

$staff = requireStaff();
$isSuper = staffIsSuper($staff);
$tab = (string) ($_GET['tab'] ?? 'overview');
$allowed = ['overview', 'riders', 'customers', 'verify', 'deletions', 'earnings', 'staff', 'rider'];
if (!in_array($tab, $allowed, true) || ($tab === 'staff' && !$isSuper)) {
    $tab = 'overview';
}

$flash = getFlash();
$riderId = (int) ($_GET['id'] ?? 0);

$counts = [
    'riders' => 0,
    'customers' => 0,
    'pending_cars' => 0,
    'pending_deletions' => 0,
    'earnings' => 0.0,
    'suspended' => 0,
];
try {
    $counts['riders'] = (int) db()->query("SELECT COUNT(*) FROM riders WHERE deleted_at IS NULL")->fetchColumn();
    $counts['customers'] = (int) db()->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")->fetchColumn();
    $counts['pending_cars'] = (int) db()->query(
        "SELECT COUNT(*) FROM riders
         WHERE deleted_at IS NULL AND verification_status = 'pending'"
    )->fetchColumn();
    $counts['pending_deletions'] = (int) db()->query("SELECT COUNT(*) FROM deletion_requests WHERE status = 'pending'")->fetchColumn();
    $counts['earnings'] = (float) db()->query("SELECT COALESCE(SUM(fare_amount), 0) FROM bookings WHERE status = 'completed'")->fetchColumn();
    $counts['suspended'] = (int) db()->query(
        "SELECT
            (SELECT COUNT(*) FROM riders WHERE account_status = 'suspended')
            + (SELECT COUNT(*) FROM customers WHERE account_status = 'suspended')"
    )->fetchColumn();
} catch (PDOException $e) {
    error_log('Staff overview failed: ' . $e->getMessage());
}

$riders = [];
$customers = [];
$queue = [];
$deletions = [];
$staffRows = [];
$focusRider = null;
$focusEarnings = null;
$focusTrips = [];

try {
    if ($tab === 'riders' || $tab === 'earnings') {
        $riders = db()->query(
            "SELECT r.*,
                    COALESCE(SUM(CASE WHEN b.status = 'completed' THEN b.fare_amount ELSE 0 END), 0) AS total_earned,
                    COALESCE(SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_rides
             FROM riders r
             LEFT JOIN bookings b ON b.rider_id = r.id
             WHERE r.deleted_at IS NULL
             GROUP BY r.id
             ORDER BY total_earned DESC, r.full_name ASC"
        )->fetchAll();
    }
    if ($tab === 'customers') {
        $customers = db()->query(
            "SELECT * FROM customers WHERE deleted_at IS NULL ORDER BY created_at DESC"
        )->fetchAll();
    }
    if ($tab === 'verify') {
        $queue = db()->query(
            "SELECT * FROM riders
             WHERE deleted_at IS NULL
               AND verification_status = 'pending'
             ORDER BY created_at ASC"
        )->fetchAll();
    }
    if ($tab === 'deletions') {
        $deletions = db()->query(
            "SELECT d.*,
                    CASE WHEN d.account_type = 'rider' THEN rd.full_name ELSE cu.full_name END AS person_name,
                    CASE WHEN d.account_type = 'rider' THEN rd.email ELSE cu.email END AS person_email
             FROM deletion_requests d
             LEFT JOIN riders rd ON d.account_type = 'rider' AND rd.id = d.account_id
             LEFT JOIN customers cu ON d.account_type = 'customer' AND cu.id = d.account_id
             WHERE d.status = 'pending'
             ORDER BY d.created_at ASC"
        )->fetchAll();
    }
    if ($tab === 'staff' && $isSuper) {
        $staffRows = db()->query("SELECT id, full_name, email, role, status, created_at FROM staff ORDER BY role DESC, full_name ASC")->fetchAll();
    }
    if ($tab === 'rider' && $riderId > 0) {
        $focusRider = fetchRiderById($riderId);
        if ($focusRider) {
            $focusEarnings = riderEarnings($riderId);
            $tripStmt = db()->prepare(
                "SELECT *
                 FROM admin_trip_history
                 WHERE rider_id = ?
                 ORDER BY confirmed_at DESC, id DESC"
            );
            $tripStmt->execute([$riderId]);
            $focusTrips = $tripStmt->fetchAll();
        }
    }
} catch (PDOException $e) {
    error_log('Staff tab query failed: ' . $e->getMessage());
}

function staffRoleLabel(string $role): string
{
    return $role === 'super_admin' ? 'Super admin' : 'Admin';
}

function verificationLabel(string $status): string
{
    return match ($status) {
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'pending' => 'Submitted',
        default => 'Not submitted',
    };
}

function accountStateLabel(string $status): string
{
    return match ($status) {
        'suspended' => 'Suspended',
        'deleted' => 'Deleted',
        default => 'Active',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ZaRide | <?= $isSuper ? 'Super admin' : 'Admin' ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="dash-app">
    <header class="site-header dash-topbar">
        <div class="brand">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag"><?= $isSuper ? 'Super admin' : 'Admin' ?></span>
            </div>
        </div>
        <form method="post" action="staff-logout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <button class="button button-sm button-secondary" type="submit">Sign out</button>
        </form>
    </header>

    <main class="container">
        <div class="dashboard-layout">
            <aside class="dashboard-sidebar">
                <div class="user-profile-summary">
                    <span class="avatar-fallback avatar-large"><?= e(strtoupper(substr((string) $staff['full_name'], 0, 1))) ?></span>
                    <div class="dash-identity-text">
                        <h3><?= e((string) $staff['full_name']) ?></h3>
                        <p><?= e((string) $staff['email']) ?></p>
                        <span class="role-tag rider"><?= e(staffRoleLabel((string) $staff['role'])) ?></span>
                    </div>
                </div>
                <nav class="sidebar-nav" aria-label="Staff sections">
                    <?php
                    $links = [
                        'overview' => 'Overview',
                        'verify' => 'Car verification',
                        'earnings' => 'Rider earnings',
                        'riders' => 'Riders',
                        'customers' => 'Customers',
                        'deletions' => 'Deletion requests',
                    ];
                    if ($isSuper) {
                        $links['staff'] = 'Admins';
                    }
                    foreach ($links as $key => $label):
                    ?>
                        <a class="sidebar-link <?= $tab === $key ? 'active' : '' ?>" href="staff-dashboard.php?tab=<?= e($key) ?>">
                            <span class="tab-full"><?= e($label) ?></span>
                            <span class="tab-short"><?= e($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>

            <section class="dashboard-content">
                <?php if ($flash): ?>
                    <div class="notice notice-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
                <?php endif; ?>

                <?php if ($tab === 'overview'): ?>
                    <div class="stats-grid">
                        <div class="stat-card"><div class="stat-meta"><span class="stat-label">Riders</span><span class="stat-value"><?= $counts['riders'] ?></span></div></div>
                        <div class="stat-card"><div class="stat-meta"><span class="stat-label">Customers</span><span class="stat-value"><?= $counts['customers'] ?></span></div></div>
                        <div class="stat-card"><div class="stat-meta"><span class="stat-label">Cars waiting</span><span class="stat-value"><?= $counts['pending_cars'] ?></span></div></div>
                        <div class="stat-card"><div class="stat-meta"><span class="stat-label">Deletion requests</span><span class="stat-value"><?= $counts['pending_deletions'] ?></span></div></div>
                        <div class="stat-card"><div class="stat-meta"><span class="stat-label">Completed-ride earnings</span><span class="stat-value"><?= e(formatMoney($counts['earnings'])) ?></span></div></div>
                        <div class="stat-card"><div class="stat-meta"><span class="stat-label">Suspended accounts</span><span class="stat-value"><?= $counts['suspended'] ?></span></div></div>
                    </div>
                    <div class="dash-card" style="margin-top: 16px;">
                        <h2 class="dash-card-title"><?= $isSuper ? 'Super admin' : 'Admin' ?> desk</h2>
                        <p class="dash-card-copy">Review traveling cars, watch each rider’s completed-ride earnings, suspend accounts, and approve deletion requests.</p>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'verify'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Submitted vehicles</h2>
                                <p class="dash-card-copy">These riders confirmed their vehicle details. Approve one before that rider can take trips.</p>
                            </div>
                        </div>
                        <?php if ($queue === []): ?>
                            <div class="empty-box"><h3>No vehicles waiting</h3><p>A rider appears here after they confirm submission from their vehicle page.</p></div>
                        <?php else: ?>
                            <?php foreach ($queue as $rider): ?>
                                <article class="staff-record">
                                    <div>
                                        <strong><?= e((string) $rider['full_name']) ?></strong>
                                        <p><?= e((string) $rider['email']) ?> · <?= e((string) ($rider['phone'] ?? '')) ?> · <?= e((string) ($rider['city'] ?? '')) ?></p>
                                        <p><?= e((string) ($rider['vehicle_model'] ?: $rider['vehicle_type'])) ?>
                                            <?= !empty($rider['vehicle_color']) ? ' · ' . e((string) $rider['vehicle_color']) : '' ?>
                                            · Plate <?= e((string) ($rider['plate_number'] ?: 'not set')) ?></p>
                                        <p><?= (int) ($rider['seats_available'] ?? 0) ?> seats · <?= e((string) ($rider['services_offered'] ?? 'both')) ?></p>
                                        <span class="role-tag"><?= e(verificationLabel((string) $rider['verification_status'])) ?></span>
                                        <div class="staff-photos">
                                            <?= renderVehiclePhoto($rider, 'vehicle-photo-preview') ?>
                                            <?php if (!empty($rider['plate_photo_path'])): ?>
                                                <img class="vehicle-photo vehicle-photo-preview" alt="Plate photo" src="rider-file.php?file=plate&rider_id=<?= (int) $rider['id'] ?>">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="post" action="staff-action.php" class="staff-review-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="review_vehicle">
                                        <input type="hidden" name="rider_id" value="<?= (int) $rider['id'] ?>">
                                        <input type="hidden" name="return_tab" value="verify">
                                        <label class="form-label" for="note-<?= (int) $rider['id'] ?>">Note</label>
                                        <textarea class="form-control" id="note-<?= (int) $rider['id'] ?>" name="note" placeholder="What you checked, or why it was rejected"></textarea>
                                        <div class="staff-actions">
                                            <button class="button button-emerald button-sm" name="decision" value="approved" type="submit">Approve</button>
                                            <button class="button button-danger button-sm" name="decision" value="rejected" type="submit">Reject</button>
                                        </div>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'earnings' || $tab === 'riders'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title"><?= $tab === 'earnings' ? 'Rider earnings' : 'Riders' ?></h2>
                                <p class="dash-card-copy">Totals come from completed rides only.</p>
                            </div>
                        </div>
                        <div class="staff-table-wrap">
                            <table class="staff-table">
                                <thead>
                                    <tr>
                                        <th>Rider</th>
                                        <th>Car</th>
                                        <th>Completed</th>
                                        <th>Earned</th>
                                        <th>Account</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($riders as $rider): ?>
                                        <tr>
                                            <td>
                                                <strong><?= e((string) $rider['full_name']) ?></strong>
                                                <small><?= e((string) $rider['email']) ?></small>
                                            </td>
                                            <td><?= e(verificationLabel((string) ($rider['verification_status'] ?? 'pending'))) ?></td>
                                            <td><?= (int) $rider['completed_rides'] ?></td>
                                            <td><?= e(formatMoney($rider['total_earned'])) ?></td>
                                            <td><?= e(accountStateLabel((string) ($rider['account_status'] ?? 'active'))) ?></td>
                                            <td>
                                                <div class="staff-actions">
                                                    <a class="button button-sm button-secondary" href="staff-dashboard.php?tab=rider&id=<?= (int) $rider['id'] ?>">Open</a>
                                                    <form method="post" action="staff-action.php" onsubmit="return confirm('Delete this rider account? They will no longer be able to sign in.');">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="delete_account">
                                                        <input type="hidden" name="account_type" value="rider">
                                                        <input type="hidden" name="account_id" value="<?= (int) $rider['id'] ?>">
                                                        <button class="button button-sm button-danger" type="submit">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'rider' && $focusRider && $focusEarnings): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title"><?= e((string) $focusRider['full_name']) ?></h2>
                                <p class="dash-card-copy"><?= e((string) $focusRider['email']) ?> · <?= e((string) $focusRider['phone']) ?> · <?= e((string) ($focusRider['city'] ?? '')) ?></p>
                            </div>
                            <a class="button button-sm button-secondary" href="staff-dashboard.php?tab=riders">Back to riders</a>
                        </div>
                        <div class="stats-grid">
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">Total earned</span><span class="stat-value"><?= e(formatMoney($focusEarnings['total_earned'])) ?></span></div></div>
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">This month</span><span class="stat-value"><?= e(formatMoney($focusEarnings['month_earned'])) ?></span></div></div>
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">This week</span><span class="stat-value"><?= e(formatMoney($focusEarnings['week_earned'])) ?></span></div></div>
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">Completed rides</span><span class="stat-value"><?= (int) $focusEarnings['completed_rides'] ?></span></div></div>
                        </div>
                        <p>Travel <?= e(formatMoney($focusEarnings['travel_earned'])) ?> · Delivery <?= e(formatMoney($focusEarnings['delivery_earned'])) ?></p>
                        <p>Vehicle: <?= e((string) ($focusRider['vehicle_model'] ?: $focusRider['vehicle_type'])) ?><?= !empty($focusRider['plate_number']) ? ' · ' . e((string) $focusRider['plate_number']) : '' ?></p>
                        <div class="staff-photos">
                            <?= renderVehiclePhoto($focusRider, 'vehicle-photo-preview') ?>
                            <?php if (!empty($focusRider['plate_photo_path'])): ?>
                                <img class="vehicle-photo vehicle-photo-preview" alt="Plate photo" src="rider-file.php?file=plate&rider_id=<?= (int) $focusRider['id'] ?>">
                            <?php endif; ?>
                        </div>
                        <p>Car <?= e(verificationLabel((string) ($focusRider['verification_status'] ?? 'pending'))) ?> · Account <?= e(accountStateLabel((string) ($focusRider['account_status'] ?? 'active'))) ?></p>
                        <?php if (!empty($focusRider['deleted_at'])): ?>
                            <p>This account has been deleted. Completed rides are still included in earnings.</p>
                        <?php else: ?>
                            <form method="post" action="staff-action.php" class="staff-review-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="set_account_status">
                                <input type="hidden" name="account_type" value="rider">
                                <input type="hidden" name="account_id" value="<?= (int) $focusRider['id'] ?>">
                                <input type="hidden" name="return_tab" value="rider">
                                <input type="hidden" name="rider_id" value="<?= (int) $focusRider['id'] ?>">
                                <label class="form-label" for="suspend-reason">Reason</label>
                                <input class="form-control" id="suspend-reason" name="reason" value="<?= e((string) ($focusRider['suspension_reason'] ?? '')) ?>" placeholder="Why this rider is suspended">
                                <div class="staff-actions">
                                    <?php if (($focusRider['account_status'] ?? '') === 'suspended'): ?>
                                        <button class="button button-emerald" name="status" value="active" type="submit">Restore rider</button>
                                    <?php else: ?>
                                        <button class="button button-secondary" name="status" value="suspended" type="submit">Suspend rider</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <form method="post" action="staff-action.php" class="staff-review-form" onsubmit="return confirm('Delete this rider account? They will no longer be able to sign in.');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_account">
                                <input type="hidden" name="account_type" value="rider">
                                <input type="hidden" name="account_id" value="<?= (int) $focusRider['id'] ?>">
                                <button class="button button-danger" type="submit">Delete rider</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="dash-card" style="margin-top: 16px;">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Trip history</h2>
                                <p class="dash-card-copy">Saved when a trip is confirmed, including rides this driver has not finished. <?= count($focusTrips) ?> listed.</p>
                            </div>
                        </div>
                        <?php if ($focusTrips === []): ?>
                            <div class="empty-box">
                                <h3>No confirmed trips yet</h3>
                                <p>A trip is saved here as soon as it is confirmed, and it stays here if it is still underway.</p>
                            </div>
                        <?php else: ?>
                            <div class="staff-table-wrap">
                                <table class="staff-table trip-history-table">
                                    <thead>
                                        <tr>
                                            <th>Trip</th>
                                            <th>Driver</th>
                                            <th>Passenger</th>
                                            <th>When</th>
                                            <th>Status</th>
                                            <th>Fare</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($focusTrips as $trip): ?>
                                            <?php
                                            $partyLabel = ($trip['booking_type'] ?? '') === 'delivery' ? 'Sender' : 'Passenger';
                                            $detail = ucfirst((string) ($trip['booking_type'] ?? 'travel'));
                                            if ((int) $trip['seats_booked'] > 0) {
                                                $detail .= ' · ' . (int) $trip['seats_booked'] . ' seat' . ((int) $trip['seats_booked'] === 1 ? '' : 's');
                                            }
                                            if (trim((string) ($trip['package_desc'] ?? '')) !== '') {
                                                $detail .= ' · ' . trim((string) $trip['package_desc']);
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= e((string) $trip['pickup_location']) ?> → <?= e((string) $trip['dropoff_location']) ?></strong>
                                                    <small><?= e($detail) ?></small>
                                                </td>
                                                <td>
                                                    <strong><?= e((string) $trip['driver_name']) ?></strong>
                                                    <?php if (trim((string) ($trip['driver_phone'] ?? '')) !== ''): ?>
                                                        <small><?= e((string) $trip['driver_phone']) ?></small>
                                                    <?php endif; ?>
                                                    <?php if (trim((string) ($trip['vehicle_label'] ?? '')) !== '' || trim((string) ($trip['plate_number'] ?? '')) !== ''): ?>
                                                        <small><?= e(trim((string) ($trip['vehicle_label'] ?? '') . (trim((string) ($trip['plate_number'] ?? '')) !== '' ? ' · ' . trim((string) $trip['plate_number']) : ''))) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <strong><?= e((string) $trip['passenger_name']) ?></strong>
                                                    <small><?= e($partyLabel) ?><?= trim((string) ($trip['passenger_phone'] ?? '')) !== '' ? ' · ' . e((string) $trip['passenger_phone']) : '' ?></small>
                                                </td>
                                                <td>
                                                    <?= e(formatDateTime((string) ($trip['scheduled_at'] ?? ''))) ?>
                                                    <small>Confirmed <?= e(formatDateTime((string) ($trip['confirmed_at'] ?? ''))) ?></small>
                                                </td>
                                                <td><?= renderStatusBadge((string) $trip['status']) ?></td>
                                                <td><?= e(formatMoney($trip['fare_amount'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'customers'): ?>
                    <div class="dash-card">
                        <h2 class="dash-card-title">Customers</h2>
                        <div class="staff-table-wrap">
                            <table class="staff-table">
                                <thead>
                                    <tr><th>Customer</th><th>City</th><th>Status</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                        <tr>
                                            <td>
                                                <strong><?= e((string) $customer['full_name']) ?></strong>
                                                <small><?= e((string) $customer['email']) ?></small>
                                            </td>
                                            <td><?= e((string) ($customer['city'] ?? '')) ?></td>
                                            <td><?= e(accountStateLabel((string) ($customer['account_status'] ?? 'active'))) ?></td>
                                            <td>
                                                <div class="staff-actions">
                                                    <form method="post" action="staff-action.php">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="set_account_status">
                                                        <input type="hidden" name="account_type" value="customer">
                                                        <input type="hidden" name="account_id" value="<?= (int) $customer['id'] ?>">
                                                        <input type="hidden" name="return_tab" value="customers">
                                                        <?php if (($customer['account_status'] ?? '') === 'suspended'): ?>
                                                            <button class="button button-sm button-emerald" name="status" value="active" type="submit">Restore</button>
                                                        <?php else: ?>
                                                            <input type="hidden" name="reason" value="Suspended by admin">
                                                            <button class="button button-sm button-secondary" name="status" value="suspended" type="submit">Suspend</button>
                                                        <?php endif; ?>
                                                    </form>
                                                    <form method="post" action="staff-action.php" onsubmit="return confirm('Delete this customer account? They will no longer be able to sign in.');">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="delete_account">
                                                        <input type="hidden" name="account_type" value="customer">
                                                        <input type="hidden" name="account_id" value="<?= (int) $customer['id'] ?>">
                                                        <button class="button button-sm button-danger" type="submit">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'deletions'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Deletion requests</h2>
                                <p class="dash-card-copy">Approval removes the login and personal details. Completed rides stay on record.</p>
                            </div>
                        </div>
                        <?php if ($deletions === []): ?>
                            <div class="empty-box"><h3>No requests waiting</h3><p>Customers and riders can ask from their profile.</p></div>
                        <?php else: ?>
                            <?php foreach ($deletions as $request): ?>
                                <article class="staff-record">
                                    <div>
                                        <strong><?= e((string) ($request['person_name'] ?? 'Account')) ?></strong>
                                        <p><?= e(ucfirst((string) $request['account_type'])) ?> · <?= e((string) ($request['person_email'] ?? '')) ?></p>
                                        <p><?= e((string) ($request['reason'] ?? '')) ?></p>
                                        <small><?= e(formatDateTime((string) $request['created_at'])) ?></small>
                                    </div>
                                    <form method="post" action="staff-action.php" class="staff-review-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <label class="form-label">Note</label>
                                        <input class="form-control" name="note" placeholder="Optional note">
                                        <div class="staff-actions">
                                            <button class="button button-danger button-sm" name="action" value="approve_deletion" type="submit">Approve deletion</button>
                                            <button class="button button-secondary button-sm" name="action" value="reject_deletion" type="submit">Reject</button>
                                        </div>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'staff' && $isSuper): ?>
                    <div class="dash-card">
                        <h2 class="dash-card-title">Add an admin</h2>
                        <p class="dash-card-copy">Only the super admin can create or delete admin accounts. New staff sign in on the same hidden page.</p>
                        <form method="post" action="staff-action.php" class="staff-review-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="add_admin">
                            <label class="form-label" for="admin-name">Full name</label>
                            <input class="form-control" id="admin-name" name="full_name" required>
                            <label class="form-label" for="admin-email">Email</label>
                            <input class="form-control" id="admin-email" name="email" type="email" required>
                            <label class="form-label" for="admin-password">Password</label>
                            <input class="form-control" id="admin-password" name="password" type="password" minlength="8" required>
                            <button class="button button-primary" type="submit">Create admin</button>
                        </form>
                    </div>
                    <div class="dash-card" style="margin-top: 16px;">
                        <h2 class="dash-card-title">Staff</h2>
                        <div class="staff-table-wrap">
                            <table class="staff-table">
                                <thead>
                                    <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($staffRows as $row): ?>
                                        <tr>
                                            <td><?= e((string) $row['full_name']) ?></td>
                                            <td><?= e((string) $row['email']) ?></td>
                                            <td><?= e(staffRoleLabel((string) $row['role'])) ?></td>
                                            <td><?= e(accountStateLabel((string) $row['status'])) ?></td>
                                            <td>
                                                <?php if (($row['role'] ?? '') === 'admin'): ?>
                                                    <div class="staff-actions">
                                                        <form method="post" action="staff-action.php">
                                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                            <input type="hidden" name="action" value="set_staff_status">
                                                            <input type="hidden" name="staff_id" value="<?= (int) $row['id'] ?>">
                                                            <?php if (($row['status'] ?? '') === 'suspended'): ?>
                                                                <button class="button button-sm button-emerald" name="status" value="active" type="submit">Restore</button>
                                                            <?php else: ?>
                                                                <button class="button button-sm button-secondary" name="status" value="suspended" type="submit">Suspend</button>
                                                            <?php endif; ?>
                                                        </form>
                                                        <form method="post" action="staff-action.php" onsubmit="return confirm('Delete this admin account? They will no longer be able to sign in.');">
                                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                            <input type="hidden" name="action" value="delete_admin">
                                                            <input type="hidden" name="staff_id" value="<?= (int) $row['id'] ?>">
                                                            <button class="button button-sm button-danger" type="submit">Delete</button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
