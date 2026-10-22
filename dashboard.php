<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$type = $_GET['type'] ?? currentAccountType();
$type = is_string($type) && in_array($type, ['customer', 'rider'], true) ? $type : 'customer';
$accountId = requireAccount($type);
$table = $type === 'rider' ? 'riders' : 'customers';

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        setFlash('error', 'Session verification failed. Please try again.');
        header('Location: dashboard.php?type=' . $type);
        exit;
    }

    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'submit_verification' && $type === 'rider') {
        $rider = fetchRiderById($accountId);
        $gaps = $rider === null ? ['vehicle details'] : riderSubmissionGaps($rider);
        if ($gaps !== []) {
            setFlash('error', 'Add your ' . implode(', ', $gaps) . ' before confirming submission.');
            header('Location: dashboard.php?type=rider&tab=vehicle');
            exit;
        }
        db()->prepare(
            "UPDATE riders
             SET verification_status = 'pending',
                 verification_note = NULL,
                 verified_at = NULL,
                 verified_by = NULL,
                 status = 'offline'
             WHERE id = ?"
        )->execute([$accountId]);
        setFlash('success', 'Vehicle details submitted. An admin will review them before you can take trips.');
        header('Location: dashboard.php?type=rider&tab=vehicle');
        exit;
    }

    if ($action === 'request_deletion') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $error = requestAccountDeletion($type, $accountId, $reason);
        setFlash($error === '' ? 'success' : 'error', $error === '' ? 'Deletion request sent. An admin will review it.' : $error);
        header('Location: dashboard.php?type=' . $type . '&tab=profile');
        exit;
    }

    if ($action === 'cancel_deletion') {
        cancelAccountDeletion($type, $accountId);
        setFlash('success', 'Deletion request cancelled.');
        header('Location: dashboard.php?type=' . $type . '&tab=profile');
        exit;
    }

    // 1. Rider posts a new scheduled trip
    if ($action === 'post_trip' && $type === 'rider') {
        $serviceType = $_POST['service_type'] ?? 'both';
        $originCity = trim($_POST['origin_city'] ?? 'Lagos');
        $originAddress = trim($_POST['origin_address'] ?? '');
        $destinationCity = trim($_POST['destination_city'] ?? 'Lagos');
        $destinationAddress = trim($_POST['destination_address'] ?? '');
        $departureTime = trim($_POST['departure_time'] ?? '');
        $seats = (int) ($_POST['available_seats'] ?? 3);
        $priceSeat = (float) ($_POST['price_per_seat'] ?? 0);
        $priceDelivery = (float) ($_POST['delivery_price'] ?? 0);
        $cargoDesc = trim($_POST['cargo_capacity_desc'] ?? '');
        $notes = trim($_POST['additional_notes'] ?? '');

        if ($originAddress === '' || $destinationAddress === '' || $departureTime === '') {
            setFlash('error', 'Please provide origin address, destination address, and departure time.');
            header('Location: dashboard.php?type=rider&tab=trips');
            exit;
        }

        $postingRider = fetchRiderById($accountId);
        if ($postingRider !== null && riderWorkBlock($postingRider) !== null) {
            setFlash('error', riderWorkBlock($postingRider));
            header('Location: dashboard.php?type=rider&tab=trips');
            exit;
        }

        try {
            $stmt = db()->prepare(
                "INSERT INTO trips (rider_id, service_type, origin_city, origin_address, destination_city,
                                    destination_address, departure_time, available_seats, price_per_seat,
                                    delivery_price, cargo_capacity_desc, additional_notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
            );
            $stmt->execute([
                $accountId,
                $serviceType,
                $originCity,
                $originAddress,
                $destinationCity,
                $destinationAddress,
                date('Y-m-d H:i:s', strtotime($departureTime)),
                $seats,
                $priceSeat,
                $priceDelivery,
                $cargoDesc ?: null,
                $notes ?: null
            ]);
            setFlash('success', 'Your new scheduled route has been published! Passengers and senders can now book it.');
        } catch (PDOException $e) {
            error_log('Failed to post trip: ' . $e->getMessage());
            setFlash('error', 'Failed to publish trip. Please try again.');
        }
        header('Location: dashboard.php?type=rider&tab=trips');
        exit;
    }

    // 2. Rider cancels an active trip
    if ($action === 'cancel_trip' && $type === 'rider') {
        $tripId = (int) ($_POST['trip_id'] ?? 0);
        try {
            $stmt = db()->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ? AND rider_id = ?");
            $stmt->execute([$tripId, $accountId]);
            setFlash('success', 'Trip cancelled.');
        } catch (PDOException $e) {
            setFlash('error', 'Could not cancel trip.');
        }
        header('Location: dashboard.php?type=rider&tab=trips');
        exit;
    }

    // 3. Customer Profile Update
    if ($action === 'update_profile' && $type === 'customer') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $city = trim($_POST['city'] ?? 'Lagos');

        if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
            setFlash('error', 'Please fill in valid name, email, and phone.');
            header('Location: dashboard.php?type=customer&tab=profile');
            exit;
        }

        try {
            $stmt = db()->prepare(
                'UPDATE customers SET full_name = ?, email = ?, phone = ?, city = ? WHERE id = ?'
            );
            $stmt->execute([$fullName, $email, $phone, $city, $accountId]);
            setFlash('success', 'Profile details updated.');
        } catch (PDOException $e) {
            setFlash('error', 'Failed to update profile. Email or phone may already be registered.');
        }
        header('Location: dashboard.php?type=customer&tab=profile');
        exit;
    }

    // 4. Rider Profile and Vehicle Update
    if (($action === 'update_profile' || $action === 'update_vehicle') && $type === 'rider') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $city = trim($_POST['city'] ?? 'Lagos');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $servicesOffered = $_POST['services_offered'] ?? 'both';
        $status = $_POST['status'] ?? 'online';
        $vehicleType = $_POST['vehicle_type'] ?? 'car';
        $vehicleModel = trim($_POST['vehicle_model'] ?? '');
        $vehicleColor = trim($_POST['vehicle_color'] ?? '');
        $plateNumber = trim($_POST['plate_number'] ?? '');
        $seatsAvailable = (int) ($_POST['seats_available'] ?? 4);
        $maxCargoKg = (int) ($_POST['max_cargo_kg'] ?? 20);
        $newAvatar = null;
        $newVehiclePhoto = null;

        if (preg_match('/\A.{1,100}\z/us', $vehicleType) !== 1) {
            setFlash('error', 'Enter a vehicle type (up to 100 characters).');
            header('Location: dashboard.php?type=rider&tab=' . ($action === 'update_vehicle' ? 'vehicle' : 'profile'));
            exit;
        }

        try {
            $profileQuery = db()->prepare(
                'SELECT avatar_path, vehicle_photo_path, vehicle_type, vehicle_model, plate_number, verification_status
                 FROM riders WHERE id = ?'
            );
            $profileQuery->execute([$accountId]);
            $oldProfile = $profileQuery->fetch();
            if (!is_array($oldProfile)) {
                throw new RuntimeException('Rider profile could not be found.');
            }

            if ($action === 'update_profile'
                && isset($_FILES['avatar'])
                && is_array($_FILES['avatar'])
                && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newAvatar = storeAvatarImage($_FILES['avatar']);
            }

            if ($action === 'update_vehicle'
                && isset($_FILES['vehicle_photo'])
                && is_array($_FILES['vehicle_photo'])
                && ($_FILES['vehicle_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newVehiclePhoto = storeVehiclePhoto($_FILES['vehicle_photo']);
            }

            $vehicleChanged = $newVehiclePhoto !== null
                || strcasecmp((string) ($oldProfile['vehicle_type'] ?? ''), $vehicleType) !== 0
                || strcasecmp((string) ($oldProfile['vehicle_model'] ?? ''), $vehicleModel) !== 0
                || strcasecmp((string) ($oldProfile['plate_number'] ?? ''), $plateNumber) !== 0;
            $verificationStatus = (string) ($oldProfile['verification_status'] ?? 'pending');
            $clearVerification = false;
            if ($action === 'update_vehicle' && $vehicleChanged) {
                $verificationStatus = 'unsubmitted';
                $clearVerification = true;
            }
            if ($verificationStatus !== 'approved') {
                $status = 'offline';
            }

            $stmt = db()->prepare(
                "UPDATE riders
                 SET full_name = ?, email = ?, phone = ?, city = ?, whatsapp = ?, bio = ?,
                     services_offered = ?, status = ?, vehicle_type = ?, vehicle_model = ?,
                     vehicle_color = ?, plate_number = ?, seats_available = ?, max_cargo_kg = ?,
                     avatar_path = ?, vehicle_photo_path = ?, verification_status = ?,
                     verification_note = IF(?, NULL, verification_note),
                     verified_at = IF(?, NULL, verified_at),
                     verified_by = IF(?, NULL, verified_by)
                 WHERE id = ?"
            );
            $stmt->execute([
                $fullName,
                $email,
                $phone,
                $city,
                $whatsapp ?: null,
                $bio ?: null,
                $servicesOffered,
                $status,
                $vehicleType,
                $vehicleModel ?: null,
                $vehicleColor ?: null,
                $plateNumber ?: null,
                $seatsAvailable,
                $maxCargoKg,
                $newAvatar ?? $oldProfile['avatar_path'],
                $newVehiclePhoto ?? $oldProfile['vehicle_photo_path'],
                $verificationStatus,
                $clearVerification ? 1 : 0,
                $clearVerification ? 1 : 0,
                $clearVerification ? 1 : 0,
                $accountId
            ]);

            if ($newAvatar !== null && !empty($oldProfile['avatar_path'])) {
                deleteAvatarImage($oldProfile['avatar_path']);
            }
            if ($newVehiclePhoto !== null && !empty($oldProfile['vehicle_photo_path'])) {
                deleteVehiclePhoto($oldProfile['vehicle_photo_path']);
            }
            setFlash('success', $clearVerification
                ? 'Vehicle details saved. Confirm submission so an admin can review them.'
                : 'Rider profile and vehicle details updated.');
        } catch (Exception $e) {
            if ($newAvatar !== null) {
                deleteAvatarImage($newAvatar);
            }
            if ($newVehiclePhoto !== null) {
                deleteVehiclePhoto($newVehiclePhoto);
            }
            setFlash('error', $e->getMessage());
        }
        header('Location: dashboard.php?type=rider&tab=' . ($action === 'update_vehicle' ? 'vehicle' : 'profile'));
        exit;
    }
}

// Fetch current user record
try {
    $userStmt = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
    $userStmt->execute([$accountId]);
    $user = $userStmt->fetch();
} catch (PDOException $e) {
    error_log('Dashboard account load failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Your account signed in, but the dashboard could not load. Check the database name and that this user can read the customers and riders tables.';
    exit;
}

if (!$user) {
    $_SESSION = [];
    session_regenerate_id(true);
    setFlash('error', 'Account not found. Please sign in again.');
    header('Location: login.php?type=' . $type);
    exit;
}

$tab = $_GET['tab'] ?? 'bookings';
$nearbyRequests = ($type === 'rider' && $tab === 'nearby') ? nearbyOpenRequestsForRider($user) : [];
$openNearbyRequest = null;
$nearbyCustomerRiders = [];
if ($type === 'customer' && $tab === 'nearby') {
    $nearbyStmt = db()->prepare(
        "SELECT *,
                UNIX_TIMESTAMP(COALESCE(search_started_at, created_at)) AS search_started_unix,
                UNIX_TIMESTAMP(NOW()) AS server_unix
         FROM requests
         WHERE customer_id = ? AND trip_mode = 'nearby' AND status = 'open'
         ORDER BY id DESC LIMIT 1"
    );
    $nearbyStmt->execute([$accountId]);
    $openNearbyRequest = $nearbyStmt->fetch() ?: null;
    $customerLat = $user['last_lat'] !== null ? (float) $user['last_lat'] : 0.0;
    $customerLng = $user['last_lng'] !== null ? (float) $user['last_lng'] : 0.0;
    $nearbyCustomerRiders = nearbyRidersForPoint($customerLat, $customerLng, (string) ($user['city'] ?? ''));
}
$flash = getFlash();
$riderEarnings = $type === 'rider' ? riderEarnings($accountId) : null;
$deletionRequest = pendingDeletionRequest($type, $accountId);
$latestDeletion = latestDeletionRequest($type, $accountId);
$riderBlock = $type === 'rider' ? riderWorkBlock($user) : null;

// Fetch bookings for this user
$bookings = [];
try {
    if ($type === 'rider') {
        $bStmt = db()->prepare(
            "SELECT b.*, c.full_name as customer_name, c.phone as customer_phone
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             WHERE b.rider_id = ?
               AND NOT (b.booking_type = 'delivery' AND b.status = 'cancelled')
             ORDER BY b.created_at DESC LIMIT 50"
        );
        $bStmt->execute([$accountId]);
    } else {
        $bStmt = db()->prepare(
            "SELECT b.*, r.full_name as rider_name, r.phone as rider_phone, r.whatsapp as rider_whatsapp,
                    r.vehicle_type, r.vehicle_model, r.plate_number, r.rating_avg, r.verification_status
             FROM bookings b
             JOIN riders r ON b.rider_id = r.id
             WHERE b.customer_id = ?
               AND NOT (b.booking_type = 'delivery' AND b.status = 'cancelled')
             ORDER BY b.created_at DESC LIMIT 50"
        );
        $bStmt->execute([$accountId]);
    }
    $bookings = $bStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Bookings query failed: ' . $e->getMessage());
}

// Fetch rider posted trips
$myTrips = [];
if ($type === 'rider') {
    try {
        $tStmt = db()->prepare(
            "SELECT t.*,
                    (SELECT COUNT(*)
                     FROM bookings b
                     WHERE b.trip_id = t.id
                       AND b.status IN ('pending', 'confirmed', 'in_transit', 'completed')
                    ) AS accepted_bookings
             FROM trips t
             WHERE t.rider_id = ?
             ORDER BY t.departure_time DESC
             LIMIT 30"
        );
        $tStmt->execute([$accountId]);
        $myTrips = $tStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('My trips query failed: ' . $e->getMessage());
    }
}

// Fetch customer open requests (or rider marketplace open requests)
$marketplaceRequests = [];
$travelBoard = [];
if ($type === 'rider') {
    try {
        $liveSearch = nearbySearchLiveSql('req');
        $mStmt = db()->query("SELECT req.*, c.full_name as customer_name, c.phone as customer_phone FROM requests req JOIN customers c ON req.customer_id = c.id WHERE req.status = 'open' AND {$liveSearch} ORDER BY req.created_at DESC LIMIT 20");
        $marketplaceRequests = $mStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Marketplace requests error: ' . $e->getMessage());
    }
} else {
    try {
        $cReqStmt = db()->prepare(
            "SELECT req.*,
                    r.full_name AS assigned_rider_name,
                    UNIX_TIMESTAMP(COALESCE(req.search_started_at, req.created_at)) AS search_started_unix,
                    UNIX_TIMESTAMP(NOW()) AS server_unix
             FROM requests req
             LEFT JOIN riders r ON r.id = req.assigned_rider_id
             WHERE req.customer_id = ?
               AND NOT (req.trip_mode = 'nearby' AND req.status = 'cancelled')
               AND NOT (req.request_type = 'delivery' AND req.status = 'cancelled')
             ORDER BY req.created_at DESC LIMIT 20"
        );
        $cReqStmt->execute([$accountId]);
        $marketplaceRequests = $cReqStmt->fetchAll();
        $boardStmt = db()->prepare(
            "SELECT t.id, t.origin_city, t.origin_address, t.destination_city, t.destination_address,
                    t.departure_time, t.available_seats, t.price_per_seat, t.additional_notes,
                    r.full_name AS rider_name, r.vehicle_type, r.vehicle_model, r.plate_number,
                    r.rating_avg, r.rating_count, r.trips_completed, r.verification_status, r.status AS rider_status
             FROM trips t
             JOIN riders r ON r.id = t.rider_id
             WHERE t.service_type IN ('travel', 'both')
               AND t.status = 'active'
               AND t.available_seats >= 1
               AND t.departure_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND r.account_status = 'active'
               AND r.deleted_at IS NULL
               AND r.verification_status = 'approved'
             ORDER BY t.departure_time ASC
             LIMIT 30"
        );
        $boardStmt->execute();
        $travelBoard = $boardStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Customer requests error: ' . $e->getMessage());
    }
}

// Fetch reviews for rider
$riderReviews = [];
if ($type === 'rider') {
    try {
        $revStmt = db()->prepare("SELECT rev.*, c.full_name as customer_name FROM reviews rev JOIN customers c ON rev.customer_id = c.id WHERE rev.rider_id = ? ORDER BY rev.created_at DESC LIMIT 20");
        $revStmt->execute([$accountId]);
        $riderReviews = $revStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Rider reviews query error: ' . $e->getMessage());
    }
}

// Compute dashboard stats
$totalBookingsCount = count($bookings);
$activeBookingsCount = count(array_filter($bookings, fn($b) => in_array($b['status'], ['pending', 'confirmed', 'in_transit'])));
$completedCount = count(array_filter($bookings, fn($b) => $b['status'] === 'completed'));
$unreadMsgs = unreadMessagesCount($accountId, $type);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | <?= $type === 'rider' ? 'Rider' : 'Customer' ?> Dashboard</title>
    <link rel="stylesheet" href="styles.css?v=<?= (int) @filemtime(__DIR__ . '/styles.css') ?>">
</head>
<body class="dash-app">
    <header class="site-header dash-topbar">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag"><?= $type === 'rider' ? 'Rider Console' : 'Customer Console' ?></span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="dashboard.php?type=<?= e($type) ?>&tab=nearby">📍 Nearby</a>
            <a class="nav-link" href="travels.php">🚗 Find Travels</a>
            <a class="nav-link" href="deliveries.php">📦 Deliveries</a>
            <a class="nav-link" href="zaride.php">👥 Riders</a>
            <form class="inline-form" method="post" action="logout.php" style="margin-left: 8px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button class="button button-sm button-secondary" type="submit">Sign Out</button>
            </form>
        </nav>
    </header>

    <main class="container">
        <!-- Top Status Bar for Rider -->
        <?php if ($type === 'rider'): ?>
            <div class="dash-status-bar">
                <div class="dash-status-copy">
                    <span class="status-badge <?= $user['status'] === 'online' ? 'badge-success' : 'badge-muted' ?>">
                        <span class="badge-dot"></span><?= strtoupper($user['status']) ?>
                    </span>
                    <span class="dash-status-note">
                        <?php if ($riderBlock !== null): ?>
                            <?= e($riderBlock) ?>
                        <?php else: ?>
                            <?= $user['status'] === 'online' ? 'Visible for trips and parcel requests' : 'Offline — not receiving new requests' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <form method="post" action="request-action.php" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_rider_status">
                    <input type="hidden" name="current_status" value="<?= e($user['status']) ?>">
                    <button class="button button-sm <?= $user['status'] === 'online' ? 'button-secondary' : 'button-emerald' ?>" type="submit" <?= ($riderBlock !== null && $user['status'] !== 'online') ? 'disabled' : '' ?>>
                        Switch to <?= $user['status'] === 'online' ? 'Offline' : 'Online (Ready)' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="dashboard-layout">
            <!-- Sidebar -->
            <aside class="dashboard-sidebar">
                <div class="user-profile-summary">
                    <?= renderUserAvatar($user, 'avatar-large') ?>
                    <div class="dash-identity-text">
                        <h3><?= e($user['full_name']) ?> <?= $type === 'rider' ? renderVerifiedTag($user) : '' ?></h3>
                        <p><?= e($user['email']) ?></p>
                        <span class="role-tag <?= e($type) ?>"><?= ucfirst($type) ?></span>
                        <?php if ($type === 'rider'): ?>
                            <?= renderRatingStars((float) ($user['rating_avg'] ?? 5.0), (int) ($user['rating_count'] ?? 0)) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <nav class="sidebar-nav" aria-label="Dashboard sections">
                    <a class="sidebar-link <?= $tab === 'bookings' ? 'active' : '' ?>" href="dashboard.php?type=<?= e($type) ?>&tab=bookings">
                        <span class="tab-full"><?= $type === 'rider' ? '📋 Booking Requests' : '🎫 My Bookings' ?></span>
                        <span class="tab-short">Bookings</span>
                        <?php if ($activeBookingsCount > 0): ?>
                            <span class="nav-badge"><?= $activeBookingsCount ?></span>
                        <?php endif; ?>
                    </a>

                    <?php if ($type === 'rider'): ?>
                        <a class="sidebar-link <?= $tab === 'trips' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=trips">
                            <span class="tab-full">🚗 My Posted Routes</span>
                            <span class="tab-short">Routes</span>
                            <span style="font-size: 12px; color: var(--muted);"><?= count($myTrips) ?></span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'nearby' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=nearby">
                            <span class="tab-full">📍 Nearby Short Rides</span>
                            <span class="tab-short">Nearby</span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'marketplace' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=marketplace">
                            <span class="tab-full">📦 Open Customer Board</span>
                            <span class="tab-short">Board</span>
                            <span class="nav-pill-badge">Claim</span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'vehicle' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=vehicle">
                            <span class="tab-full">🚘 Vehicle Details</span>
                            <span class="tab-short">Vehicle</span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'earnings' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=earnings">
                            <span class="tab-full">💰 Earnings</span>
                            <span class="tab-short">Earnings</span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'reviews' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=reviews">
                            <span class="tab-full">⭐ Reviews & Rating</span>
                            <span class="tab-short">Reviews</span>
                            <span style="font-size: 12px; color: var(--muted);"><?= count($riderReviews) ?></span>
                        </a>
                        <a class="sidebar-link" href="dashboard.php?type=rider&tab=trips#new-trip">
                            <span class="tab-full">➕ Post New Route</span>
                            <span class="tab-short">New Route</span>
                        </a>
                    <?php else: ?>
                        <a class="sidebar-link <?= $tab === 'nearby' ? 'active' : '' ?>" href="dashboard.php?type=customer&tab=nearby">
                            <span class="tab-full">📍 Nearby Ride Now</span>
                            <span class="tab-short">Nearby</span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'board' ? 'active' : '' ?>" href="dashboard.php?type=customer&tab=board">
                            <span class="tab-full">🚗 Travel Board</span>
                            <span class="tab-short">Board</span>
                            <span style="font-size: 12px; color: var(--muted);"><?= count($travelBoard) ?></span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'marketplace' ? 'active' : '' ?>" href="dashboard.php?type=customer&tab=marketplace">
                            <span class="tab-full">📢 My Custom Requests</span>
                            <span class="tab-short">Requests</span>
                            <span style="font-size: 12px; color: var(--muted);"><?= count($marketplaceRequests) ?></span>
                        </a>
                        <a class="sidebar-link" href="deliveries.php#send-package">
                            <span class="tab-full">📦 Send Parcels</span>
                            <span class="tab-short">Parcels</span>
                        </a>
                    <?php endif; ?>
                </nav>

                <div class="dash-shortcuts">
                    <a class="button button-sm button-primary button-full" href="dashboard.php?type=<?= e($type) ?>&tab=profile">
                        ⚙️ Profile
                    </a>
                </div>
            </aside>

            <!-- Main Content Area -->
            <section class="dashboard-content">
                <?php if ($flash): ?>
                    <div class="notice notice-<?= e($flash['type']) ?>" role="alert">
                        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= e($flash['message']) ?>
                    </div>
                <?php endif; ?>
                <?php if (suspensionMessage($user) !== null): ?>
                    <div class="notice notice-error" role="alert"><?= e((string) suspensionMessage($user)) ?></div>
                <?php endif; ?>

                <!-- Top Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🚗</div>
                        <div class="stat-meta">
                            <span class="stat-label">Active Journeys</span>
                            <span class="stat-value"><?= $activeBookingsCount ?></span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #ecfdf5; color: #059669;">🏁</div>
                        <div class="stat-meta">
                            <span class="stat-label">Completed Trips</span>
                            <span class="stat-value"><?= $type === 'rider' ? (int) $user['trips_completed'] : $completedCount ?></span>
                        </div>
                    </div>
                    <?php if ($type === 'rider' && $riderEarnings !== null): ?>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: #ecfdf5; color: #059669;">💰</div>
                            <div class="stat-meta">
                                <span class="stat-label">Total earned</span>
                                <span class="stat-value"><?= e(formatMoney($riderEarnings['total_earned'])) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($type === 'rider'): ?>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: #fef3c7; color: #d97706;">⭐</div>
                            <div class="stat-meta">
                                <span class="stat-label">Average Score</span>
                                <span class="stat-value"><?= number_format((float) ($user['rating_avg'] ?? 5.0), 1) ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="stat-card">
                            <div class="stat-icon" style="background: #f0fdf4; color: #16a34a;">📦</div>
                            <div class="stat-meta">
                                <span class="stat-label">Total Bookings</span>
                                <span class="stat-value"><?= $totalBookingsCount ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 1: BOOKINGS -->
                <?php if ($tab === 'bookings'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title"><?= $type === 'rider' ? 'Incoming Booking Requests & Rides' : 'Your Bookings & Parcel Orders' ?></h2>
                                <p class="dash-card-copy">Manage confirmations, status updates, and peer communication</p>
                            </div>
                        </div>

                        <?php if ($bookings === []): ?>
                            <div class="empty-box" style="padding: 40px 20px;">
                                <span class="empty-icon">📋</span>
                                <h3>No bookings found yet</h3>
                                <p><?= $type === 'rider' ? 'When customers book your routes or send parcel requests, they will appear here.' : 'You have not booked any trips or parcel dispatches yet.' ?></p>
                                <?php if ($type === 'customer'): ?>
                                    <div class="empty-actions">
                                        <a class="button button-primary" href="dashboard.php?type=customer&tab=board">Check the travel board →</a>
                                        <a class="button button-emerald" href="deliveries.php">Send Parcel →</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="booking-list">
                                <?php foreach ($bookings as $b): ?>
                                    <article class="booking-item">
                                        <div class="booking-icon-box">
                                            <?= $b['booking_type'] === 'travel' ? '🚗' : '📦' ?>
                                        </div>

                                        <div class="booking-details">
                                            <div class="booking-head">
                                                <span class="booking-route"><?= e($b['pickup_location']) ?> → <?= e($b['dropoff_location']) ?></span>
                                                <?php if (!empty($b['trip_id'])): ?>
                                                    <?= renderPostedRouteSign($type === 'rider', (string) ($b['rider_name'] ?? '')) ?>
                                                <?php endif; ?>
                                                <?= renderStatusBadge($b['status']) ?>
                                            </div>

                                            <div class="booking-subtext">
                                                <span>📅 <?= formatDateTime($b['scheduled_at']) ?></span>
                                                <span>💰 <strong style="color: var(--primary);"><?= formatMoney($b['fare_amount']) ?></strong></span>
                                                <?php if ($type !== 'rider'): ?>
                                                    <span><?= e($b['vehicle_model'] ?: ucfirst((string) $b['vehicle_type'])) ?><?= !empty($b['plate_number']) ? ' · Plate: ' . e($b['plate_number']) : '' ?> <?= renderVerifiedTag($b) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($type === 'rider' && passengerNameVisibleToRider((string) $b['status'])): ?>
                                                <?= renderPartyName($b['booking_type'] === 'delivery' ? 'Sender' : 'Passenger', (string) $b['customer_name']) ?>
                                            <?php elseif ($type !== 'rider'): ?>
                                                <?= renderPartyName('Rider', (string) $b['rider_name']) ?>
                                            <?php endif; ?>

                                            <?php if (!empty($b['package_desc'])): ?>
                                                <div style="font-size: 12px; color: var(--muted);">
                                                    Detail: <?= e($b['package_desc']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="booking-actions">
                                            <!-- In-App Chat button -->
                                            <a class="button button-sm button-primary" href="messages.php?booking_id=<?= (int) $b['id'] ?>">
                                                💬 Chat
                                            </a>

                                            <?php
                                            $phone = $type === 'rider' ? $b['customer_phone'] : $b['rider_phone'];
                                            ?>
                                            <a class="button button-sm button-secondary" href="tel:<?= e($phone) ?>" title="Call">
                                                📞 Call
                                            </a>

                                            <!-- Rider lifecycle actions -->
                                            <?php if ($type === 'rider'): ?>
                                                <?php if ($b['status'] === 'pending'): ?>
                                                    <form method="post" action="request-action.php" class="dash-action-form">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="confirmed">
                                                        <button class="button button-sm button-emerald" type="submit">✓ Accept</button>
                                                    </form>
                                                    <form method="post" action="request-action.php" class="dash-action-form">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="declined">
                                                        <button class="button button-sm button-danger" type="submit">✕ Decline</button>
                                                    </form>
                                                <?php elseif ($b['status'] === 'confirmed'): ?>
                                                    <form method="post" action="request-action.php" class="dash-action-form">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="in_transit">
                                                        <button class="button button-sm button-primary" type="submit">🚗 Start Trip</button>
                                                    </form>
                                                    <form method="post" action="request-action.php" class="dash-action-form" onsubmit="return confirm('Cancel this accepted booking? The customer will be notified.');">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="cancelled">
                                                        <button class="button button-sm button-danger" type="submit">✕ Cancel booking</button>
                                                    </form>
                                                <?php elseif ($b['status'] === 'in_transit'): ?>
                                                    <form method="post" action="request-action.php" class="dash-action-form">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="completed">
                                                        <button class="button button-sm button-emerald" type="submit">🏁 Mark Completed</button>
                                                    </form>
                                                    <form method="post" action="request-action.php" class="dash-action-form" onsubmit="return confirm('Cancel this trip in progress? The customer will be notified.');">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="cancelled">
                                                        <button class="button button-sm button-danger" type="submit">✕ Cancel trip</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <!-- Customer actions -->
                                                <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?>
                                                    <form method="post" action="request-action.php" class="dash-action-form" onsubmit="return confirm('Cancel this booking? The rider will be notified.');">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="cancelled">
                                                        <button class="button button-sm button-danger" type="submit">Cancel</button>
                                                    </form>
                                                <?php elseif ($b['status'] === 'completed'): ?>
                                                    <a class="button button-sm button-emerald" href="review.php?booking_id=<?= (int) $b['id'] ?>">
                                                        ⭐ Leave Review
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 2 (Rider): MY POSTED TRIPS & PUBLISH NEW ROUTE -->
                <?php if ($tab === 'trips' && $type === 'rider'): ?>
                    <!-- Post New Trip Form -->
                    <div class="dash-card" id="new-trip">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Publish a New Scheduled Journey</h2>
                                <p class="dash-card-copy">Post an upcoming commute or intercity journey to earn from passenger seats and package delivery</p>
                            </div>
                        </div>

                        <form method="post" action="dashboard.php?type=rider">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="post_trip">

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Service Type</label>
                                    <select class="form-control" name="service_type">
                                        <option value="both" selected>🚗📦 Travel & Package Delivery</option>
                                        <option value="travel">🚗 Passenger Travel Only</option>
                                        <option value="delivery">📦 Parcel Delivery Only</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Departure Date & Time</label>
                                    <input class="form-control" name="departure_time" type="datetime-local" required>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Origin City & Address</label>
                                    <div class="city-address-row">
                                        <input class="form-control city-field" name="origin_city" placeholder="e.g. Zaria" value="<?= e($user['city'] ?? 'Zaria') ?>" required>
                                        <input class="form-control" name="origin_address" placeholder="e.g. Sabon Gari Market, Zaria" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Destination City & Address</label>
                                    <div class="city-address-row">
                                        <input class="form-control city-field" name="destination_city" placeholder="e.g. Zaria" value="Zaria" required>
                                        <input class="form-control" name="destination_address" placeholder="e.g. Kwangila, Zaria" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Available Passenger Seats</label>
                                    <input class="form-control" name="available_seats" type="number" min="0" max="15" value="3" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Fare per Passenger Seat (₦)</label>
                                    <input class="form-control" name="price_per_seat" type="number" step="100" min="0" value="2500" required>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Delivery Fee for Parcels (₦)</label>
                                    <input class="form-control" name="delivery_price" type="number" step="100" min="0" value="1800">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Cargo Space Description</label>
                                    <input class="form-control" name="cargo_capacity_desc" placeholder="e.g. Spacious trunk for 2 boxes / backpacks">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Journey Notes / Preferences (Optional)</label>
                                <textarea class="form-control" name="additional_notes" placeholder="e.g. AC available, leaving promptly on time, smooth express route."></textarea>
                            </div>

                            <button class="button button-primary button-lg" type="submit">Publish Scheduled Route →</button>
                        </form>
                    </div>

                    <!-- List of My Posted Trips -->
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <h2 class="dash-card-title">Your Active & Past Routes</h2>
                        </div>

                        <?php if ($myTrips === []): ?>
                            <div class="empty-box" style="padding: 30px;">
                                <p style="margin: 0; color: var(--muted);">You haven't posted any routes yet. Fill in the form above to post your first route!</p>
                            </div>
                        <?php else: ?>
                            <div class="dash-feed">
                                <?php foreach ($myTrips as $t): ?>
                                    <div class="dash-feed-card">
                                        <div>
                                            <div class="card-title-row">
                                                <strong><?= e($t['origin_address']) ?> (<?= e($t['origin_city']) ?>) → <?= e($t['destination_address']) ?> (<?= e($t['destination_city']) ?>)</strong>
                                                <?php if ((int) ($t['accepted_bookings'] ?? 0) > 0): ?>
                                                    <?php
                                                    $acceptedCount = (int) $t['accepted_bookings'];
                                                    $acceptedLabel = $acceptedCount === 1
                                                        ? 'You posted this route. A passenger accepted it.'
                                                        : 'You posted this route. ' . $acceptedCount . ' passengers accepted it.';
                                                    ?>
                                                    <span class="service-chip chip-posted-route"><?= e($acceptedLabel) ?></span>
                                                <?php endif; ?>
                                                <?= renderStatusBadge($t['status']) ?>
                                            </div>
                                            <div class="card-meta">
                                                Departure: <?= formatDateTime($t['departure_time']) ?> · Seats remaining: <strong><?= (int) $t['available_seats'] ?></strong> · Seat Fare: <strong><?= formatMoney($t['price_per_seat']) ?></strong>
                                            </div>
                                        </div>

                                        <?php if ($t['status'] === 'active'): ?>
                                            <form method="post" action="dashboard.php?type=rider" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="cancel_trip">
                                                <input type="hidden" name="trip_id" value="<?= (int) $t['id'] ?>">
                                                <button class="button button-sm button-danger" type="submit">Cancel Route</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'board' && $type === 'customer'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Travel board</h2>
                                <p class="dash-card-copy">Choose a posted ride and pair with that driver. <?= count($travelBoard) ?> open.</p>
                            </div>
                            <a class="button button-sm button-secondary" href="travels.php">Search all travels</a>
                        </div>
                        <?php if ($travelBoard === []): ?>
                            <div class="empty-box" style="padding: 30px;">
                                <h3>No rides on the board yet</h3>
                                <p>When a driver posts a trip, it shows up here so you can choose who to ride with.</p>
                                <a class="button button-primary" href="travels.php#post-request">Post a travel request</a>
                            </div>
                        <?php else: ?>
                            <div class="dash-feed">
                                <?php foreach ($travelBoard as $trip): ?>
                                    <div class="dash-feed-card">
                                        <div>
                                            <div class="card-title-row">
                                                <strong><?= e((string) $trip['origin_address']) ?> (<?= e((string) $trip['origin_city']) ?>) → <?= e((string) $trip['destination_address']) ?> (<?= e((string) $trip['destination_city']) ?>)</strong>
                                            </div>
                                            <?= renderPartyName('Rider', (string) $trip['rider_name']) ?>
                                            <div class="card-meta">
                                                <?= e(formatDateTime((string) $trip['departure_time'])) ?>
                                                · <?= (int) $trip['available_seats'] ?> seat<?= (int) $trip['available_seats'] === 1 ? '' : 's' ?> left
                                                · <strong><?= e(formatMoney($trip['price_per_seat'])) ?></strong> per seat
                                            </div>
                                            <div class="card-meta">
                                                <?= e(ucfirst((string) ($trip['vehicle_type'] ?? 'car'))) ?>
                                                <?php if (trim((string) ($trip['vehicle_model'] ?? '')) !== ''): ?>
                                                    · <?= e((string) $trip['vehicle_model']) ?>
                                                <?php endif; ?>
                                                <?php if (trim((string) ($trip['plate_number'] ?? '')) !== ''): ?>
                                                    · <?= e((string) $trip['plate_number']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <a class="button button-emerald" href="book.php?trip_id=<?= (int) $trip['id'] ?>&type=travel">Pair with this ride</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'nearby' && $type === 'rider'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Nearby short-trip requests</h2>
                                <p class="dash-card-copy">Stay online and share location to get pinged when a customer close by needs a short ride — not a long travel.</p>
                            </div>
                            <span class="status-badge <?= ($user['status'] ?? '') === 'online' ? 'badge-success' : 'badge-muted' ?>">
                                <span class="badge-dot"></span><?= strtoupper((string) ($user['status'] ?? 'offline')) ?>
                            </span>
                        </div>

                        <?php if (($user['status'] ?? '') !== 'online'): ?>
                            <div class="notice notice-error">Switch to Online on your dashboard to receive nearby ride alerts.</div>
                        <?php endif; ?>

                        <div id="request-list">
                            <?php if ($nearbyRequests === []): ?>
                                <p class="zr-notify-empty" style="padding: 8px 0;">No nearby short-trip requests right now. You will get a live alert when a customer close to you wants to go a short distance.</p>
                            <?php else: ?>
                                <?php foreach ($nearbyRequests as $req): ?>
                                    <div class="nearby-match-row">
                                        <div>
                                            <strong><?= e($req['origin']) ?> → <?= e($req['destination']) ?></strong>
                                            <div style="font-size: 13px; color: var(--muted);">
                                                <?= formatMoney($req['budget_amount']) ?>
                                                · <?= (int) $req['passengers_count'] ?> passenger(s)
                                                <?php if (!empty($req['estimated_distance_km'])): ?>
                                                    · <?= number_format((float) $req['estimated_distance_km'], 1) ?> km trip
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="nearby-match-actions">
                                            <span class="nearby-dist">
                                                <?= $req['distance_km'] !== null ? number_format((float) $req['distance_km'], 1) . ' km away' : 'Same city' ?>
                                            </span>
                                            <form method="post" action="request-action.php">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                                <input type="hidden" name="action" value="accept_request">
                                                <button class="button button-sm button-emerald" type="submit">Accept</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'nearby' && $type === 'customer'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Request a nearby ride</h2>
                                <p class="dash-card-copy">Allow location so nearby riders can be matched instantly.</p>
                            </div>
                            <span class="status-badge badge-sky" id="geo-status"><span class="badge-dot"></span>Waiting for GPS</span>
                        </div>

                        <?php if ($openNearbyRequest): ?>
                            <?php
                            $startedUnix = (int) ($openNearbyRequest['search_started_unix'] ?? 0);
                            $serverUnix = (int) ($openNearbyRequest['server_unix'] ?? time());
                            $remaining = max(0, NEARBY_SEARCH_SECONDS - max(0, $serverUnix - $startedUnix));
                            $searchLive = $remaining > 0;
                            $clock = sprintf('%d:%02d', intdiv($remaining, 60), $remaining % 60);
                            ?>
                            <div class="nearby-waiting" id="waiting-box"
                                 data-request-id="<?= (int) $openNearbyRequest['id'] ?>"
                                 data-started="<?= $startedUnix ?>"
                                 data-server-now="<?= $serverUnix ?>"
                                 data-window="<?= (int) NEARBY_SEARCH_SECONDS ?>">
                                <div id="nearby-searching" <?= $searchLive ? '' : 'hidden' ?>>
                                    <div class="nearby-search-visual" aria-hidden="true">
                                        <div class="nearby-spinner"></div>
                                        <div class="nearby-timer" id="nearby-timer"><?= e($clock) ?></div>
                                    </div>
                                    <strong>Looking for a nearby rider…</strong>
                                    <p><?= e($openNearbyRequest['origin']) ?> → <?= e($openNearbyRequest['destination']) ?> · <?= formatMoney($openNearbyRequest['budget_amount']) ?></p>
                                    <p class="nearby-search-note">Searching for 5 minutes. You will be taken to chat when a rider accepts.</p>
                                    <button type="button" class="button button-sm button-danger js-cancel-nearby">Cancel</button>
                                </div>
                                <div id="nearby-expired" <?= $searchLive ? 'hidden' : '' ?>>
                                    <strong>No driver available yet</strong>
                                    <p><?= e($openNearbyRequest['origin']) ?> → <?= e($openNearbyRequest['destination']) ?> · <?= formatMoney($openNearbyRequest['budget_amount']) ?></p>
                                    <p class="nearby-search-note">No rider has picked up this trip. Continue searching for another 5 minutes, or cancel.</p>
                                    <div class="nearby-choice-actions">
                                        <button type="button" class="button button-primary" id="continue-nearby">Continue searching</button>
                                        <button type="button" class="button button-danger js-cancel-nearby">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <form id="nearby-form">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label class="form-label" for="origin">Pickup (where you are)</label>
                                        <input class="form-control" id="origin" name="origin" placeholder="e.g. Sabon Gari Market, Zaria" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="destination">Where you want to go</label>
                                        <input class="form-control" id="destination" name="destination" placeholder="e.g. ABU Main Campus, Samaru" required>
                                    </div>
                                </div>
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label class="form-label" for="distance">Trip distance</label>
                                        <select class="form-control" id="distance" name="estimated_distance_km" required>
                                            <option value="2">Under 2 km</option>
                                            <option value="5" selected>About 2–5 km</option>
                                            <option value="8">About 5–8 km</option>
                                            <option value="12">About 8–12 km (max nearby)</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="passengers">Passengers</label>
                                        <select class="form-control" id="passengers" name="passengers_count">
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="budget">Offer (₦)</label>
                                    <input class="form-control" id="budget" name="budget_amount" type="number" min="300" step="50" value="800" required>
                                </div>
                                <p id="nearby-form-msg" class="notice" hidden></p>
                                <button class="button button-primary button-lg button-full" type="submit">Alert nearby riders →</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="dash-card">
                        <div class="dash-card-header">
                            <h2 class="dash-card-title">Riders close to you</h2>
                        </div>
                        <div id="rider-list">
                            <?php if ($nearbyCustomerRiders === []): ?>
                                <p class="zr-notify-empty" style="padding: 8px 0;">No live GPS matches yet. Allow location — nearby online riders will appear here.</p>
                            <?php else: ?>
                                <?php foreach ($nearbyCustomerRiders as $rider): ?>
                                    <div class="nearby-match-row">
                                        <div>
                                            <strong>Rider: <?= e($rider['full_name']) ?> <?= renderVerifiedTag($rider) ?></strong>
                                            <div style="font-size: 13px; color: var(--muted);">
                                                <?= renderVehicleIcon((string) ($rider['vehicle_type'] ?? 'car')) ?>
                                                <?= e($rider['vehicle_model'] ?: ucfirst((string) ($rider['vehicle_type'] ?? 'car'))) ?>
                                                · <?= renderRatingStars((float) $rider['rating_avg'], (int) $rider['rating_count']) ?>
                                            </div>
                                        </div>
                                        <span class="nearby-dist">
                                            <?= $rider['distance_km'] !== null ? number_format((float) $rider['distance_km'], 1) . ' km' : e((string) ($rider['city'] ?? '')) ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- TAB 3: OPEN REQUESTS MARKETPLACE -->
                <?php if ($tab === 'marketplace'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title"><?= $type === 'rider' ? 'Customer Requests Board (Claim to Earn)' : 'Your Custom Travel & Parcel Requests' ?></h2>
                                <p style="color: var(--muted); font-size: 13px; margin: 0;"><?= $type === 'rider' ? 'Accept open customer packages or passenger rides heading in your direction' : 'Track open requests waiting for drivers' ?></p>
                            </div>
                            <?php if ($type === 'customer'): ?>
                                <a class="button button-sm button-primary" href="travels.php#post-request">➕ Post New Request</a>
                            <?php endif; ?>
                        </div>

                        <?php if ($marketplaceRequests === []): ?>
                            <div class="empty-box" style="padding: 30px;">
                                <p style="margin: 0; color: var(--muted);">No open requests at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="dash-feed">
                                <?php foreach ($marketplaceRequests as $req): ?>
                                    <div class="dash-feed-card">
                                        <div>
                                            <div class="card-title-row">
                                                <span class="service-chip <?= $req['request_type'] === 'travel' ? 'chip-travel' : 'chip-delivery' ?>">
                                                    <?= $req['request_type'] === 'travel' ? '🚗 Passenger Ride' : '📦 Parcel Delivery' ?>
                                                </span>
                                                <?php if (($req['trip_mode'] ?? 'scheduled') === 'nearby'): ?>
                                                    <span class="service-chip chip-both">📍 Nearby now</span>
                                                <?php endif; ?>
                                                <strong style="font-size: 15px;"><?= e($req['origin']) ?> → <?= e($req['destination']) ?></strong>
                                                <?= renderStatusBadge($req['status']) ?>
                                            </div>
                                            <div class="card-meta">
                                                Pickup: <?= e((string) $req['origin']) ?>
                                            </div>
                                            <div class="card-meta">
                                                Drop-off: <?= e((string) $req['destination']) ?>
                                            </div>
                                            <div class="card-meta">
                                                Preferred time: <?= formatDateTime($req['preferred_time']) ?>
                                                <?php if (($req['request_type'] ?? '') === 'travel'): ?>
                                                    · Passengers: <strong><?= (int) ($req['passengers_count'] ?? 1) ?></strong>
                                                <?php endif; ?>
                                                · Budget: <strong style="color: var(--primary);"><?= formatMoney($req['budget_amount']) ?></strong>
                                            </div>
                                            <?php if ($type !== 'rider' && !empty($req['assigned_rider_name'])): ?>
                                                <?= renderPartyName('Rider', (string) $req['assigned_rider_name']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($req['package_details'])): ?>
                                                <div style="font-size: 12px; color: var(--ink); margin-top: 4px;">
                                                    📦 Package: <?= e($req['package_details']) ?> (<?= e(ucfirst($req['package_weight_category'] ?? 'standard')) ?>)
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($type === 'rider' && $req['status'] === 'open'): ?>
                                            <form method="post" action="request-action.php" style="margin: 0;">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                                <input type="hidden" name="action" value="accept_request">
                                                <button class="button button-emerald" type="submit"><?= ($req['request_type'] ?? '') === 'travel' ? '✓ Claim this ride' : '✓ Claim & Deliver' ?> (<?= formatMoney($req['budget_amount']) ?>)</button>
                                            </form>
                                        <?php elseif ($type === 'customer' && $req['status'] === 'open' && ($req['request_type'] ?? '') === 'delivery'): ?>
                                            <?= renderLiveSearchBox($req, 'delivery') ?>
                                        <?php elseif ($type === 'customer' && $req['status'] === 'open'): ?>
                                            <form method="post" action="request-action.php" style="margin: 0;" onsubmit="return confirm('Cancel this request?');">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                                <input type="hidden" name="action" value="cancel_request">
                                                <button class="button button-sm button-danger" type="submit">Cancel request</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($type === 'customer'): ?>
                            <?= renderLiveSearchScript() ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 4 (Rider): VEHICLE & DOCUMENTS -->
                <?php if ($tab === 'vehicle' && $type === 'rider'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Vehicle Information <?= renderVerifiedTag($user) ?></h2>
                                <p style="color: var(--muted); font-size: 13px; margin: 0;">Save the vehicle, then confirm that these details are ready for ZaRide to review.</p>
                            </div>
                        </div>
                        <?php
                        $verifyState = (string) ($user['verification_status'] ?? 'unsubmitted');
                        $submissionGaps = riderSubmissionGaps($user);
                        ?>
                        <?php if ($verifyState === 'approved'): ?>
                            <div class="notice notice-success" role="status">Your vehicle is verified. Customers can see your verification tag, and you can take trips.</div>
                        <?php elseif ($verifyState === 'pending'): ?>
                            <div class="notice notice-success" role="status">Submitted. Your vehicle details are on the admin dashboard and waiting for approval.</div>
                        <?php elseif ($verifyState === 'rejected'): ?>
                            <div class="notice notice-error" role="alert"><?= e((string) riderWorkBlock($user)) ?></div>
                        <?php else: ?>
                            <div class="notice" role="status">Your vehicle is not on the admin dashboard yet. Save the details below, then confirm submission.</div>
                        <?php endif; ?>

                        <form method="post" action="dashboard.php?type=rider" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_vehicle">
                            <!-- Preserve profile fields -->
                            <input type="hidden" name="full_name" value="<?= e($user['full_name']) ?>">
                            <input type="hidden" name="email" value="<?= e($user['email']) ?>">
                            <input type="hidden" name="phone" value="<?= e($user['phone']) ?>">
                            <input type="hidden" name="city" value="<?= e($user['city']) ?>">
                            <input type="hidden" name="whatsapp" value="<?= e($user['whatsapp'] ?? '') ?>">
                            <input type="hidden" name="bio" value="<?= e($user['bio'] ?? '') ?>">
                            <input type="hidden" name="services_offered" value="<?= e($user['services_offered']) ?>">
                            <input type="hidden" name="status" value="<?= e($user['status']) ?>">

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Vehicle Type</label>
                                    <input class="form-control" name="vehicle_type" type="text" maxlength="100" placeholder="e.g. Car, Motorcycle, Scooter" value="<?= e($user['vehicle_type'] ?? '') ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Vehicle Make & Model</label>
                                    <input class="form-control" name="vehicle_model" placeholder="e.g. Toyota Corolla 2019" value="<?= e($user['vehicle_model'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Vehicle Color</label>
                                    <input class="form-control" name="vehicle_color" placeholder="e.g. Silver, Black" value="<?= e($user['vehicle_color'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">License Plate Number</label>
                                    <input class="form-control" name="plate_number" placeholder="e.g. KD-882-AB" value="<?= e($user['plate_number'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Available Passenger Seats</label>
                                    <input class="form-control" name="seats_available" type="number" min="0" max="15" value="<?= (int) ($user['seats_available'] ?? 4) ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Max Cargo Capacity (kg)</label>
                                    <input class="form-control" name="max_cargo_kg" type="number" min="0" max="500" value="<?= (int) ($user['max_cargo_kg'] ?? 20) ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="vehicle_photo">Car / Vehicle Photo</label>
                                <div class="vehicle-photo-upload">
                                    <?= renderVehiclePhoto($user, 'vehicle-photo-preview') ?>
                                    <div>
                                        <input class="form-control" id="vehicle_photo" name="vehicle_photo" type="file" accept="image/jpeg,image/png,image/webp">
                                        <small>Upload a clear JPEG, PNG, or WebP photo of your vehicle (up to 5 MB). Customers will see this on your rider profile.</small>
                                    </div>
                                </div>
                            </div>

                            <button class="button button-primary button-lg" type="submit" style="margin-top: 10px;">Save Vehicle Details →</button>
                        </form>
                        <?php if ($verifyState !== 'approved' && $verifyState !== 'pending'): ?>
                            <form method="post" action="dashboard.php?type=rider" style="margin-top: 16px;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="submit_verification">
                                <button class="button button-emerald button-lg" type="submit" <?= $submissionGaps !== [] ? 'disabled' : '' ?>>
                                    Confirm submission to ZaRide
                                </button>
                                <?php if ($submissionGaps !== []): ?>
                                    <p class="dash-card-copy">Add your <?= e(implode(', ', $submissionGaps)) ?> and save before confirming.</p>
                                <?php else: ?>
                                    <p class="dash-card-copy">This sends the saved vehicle details to the admin dashboard.</p>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 5 (Rider): REVIEWS -->
                <?php if ($tab === 'reviews' && $type === 'rider'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Customer Reviews & Ratings</h2>
                                <p style="color: var(--muted); font-size: 13px; margin: 0;">What passengers and parcel senders say about your service</p>
                            </div>
                            <div style="font-size: 20px; font-weight: 800; color: var(--primary);">
                                <?= number_format((float) ($user['rating_avg'] ?? 5.0), 1) ?> ★ (<?= (int) ($user['rating_count'] ?? 0) ?>)
                            </div>
                        </div>

                        <?php if ($riderReviews === []): ?>
                            <div class="empty-box" style="padding: 30px;">
                                <p style="margin: 0; color: var(--muted);">No reviews received yet. Complete trips to build your community reputation!</p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 14px;">
                                <?php foreach ($riderReviews as $rev): ?>
                                    <div style="border: 1px solid var(--line); border-radius: var(--radius-md); padding: 16px; background: white;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                                            <strong><?= e($rev['customer_name']) ?></strong>
                                            <?= renderRatingStars((float) $rev['rating']) ?>
                                        </div>
                                        <?php if (!empty($rev['comment'])): ?>
                                            <p style="margin: 0; font-size: 14px; color: var(--ink-light); line-height: 1.5;">
                                                "<?= e($rev['comment']) ?>"
                                            </p>
                                        <?php endif; ?>
                                        <small style="color: var(--muted-light); font-size: 11px;"><?= timeAgo($rev['created_at']) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tab === 'earnings' && $type === 'rider' && $riderEarnings !== null): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Earnings from completed rides</h2>
                                <p class="dash-card-copy">This is the full fare of rides marked completed. Admins can see the same total.</p>
                            </div>
                        </div>
                        <div class="stats-grid">
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">Total</span><span class="stat-value"><?= e(formatMoney($riderEarnings['total_earned'])) ?></span></div></div>
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">This month</span><span class="stat-value"><?= e(formatMoney($riderEarnings['month_earned'])) ?></span></div></div>
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">This week</span><span class="stat-value"><?= e(formatMoney($riderEarnings['week_earned'])) ?></span></div></div>
                            <div class="stat-card"><div class="stat-meta"><span class="stat-label">Completed rides</span><span class="stat-value"><?= (int) $riderEarnings['completed_rides'] ?></span></div></div>
                        </div>
                        <p style="margin-top: 14px;">Travel <?= e(formatMoney($riderEarnings['travel_earned'])) ?> · Delivery <?= e(formatMoney($riderEarnings['delivery_earned'])) ?></p>
                    </div>
                <?php endif; ?>

                <!-- TAB: PROFILE SETTINGS -->
                <?php if ($tab === 'profile'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <h2 class="dash-card-title">Personal Profile Details</h2>
                        </div>

                        <form method="post" action="dashboard.php?type=<?= e($type) ?>" <?= $type === 'rider' ? 'enctype="multipart/form-data"' : '' ?>>
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Full Name</label>
                                    <input class="form-control" name="full_name" value="<?= e($user['full_name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Email Address</label>
                                    <input class="form-control" name="email" type="email" value="<?= e($user['email']) ?>" required>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Phone Number</label>
                                    <input class="form-control" name="phone" type="tel" value="<?= e($user['phone']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Operating City</label>
                                    <input class="form-control" name="city" placeholder="e.g. Zaria" value="<?= e($user['city'] ?? 'Zaria') ?>" required>
                                </div>
                            </div>

                            <?php if ($type === 'rider'): ?>
                                <div class="form-group">
                                    <label class="form-label" for="avatar">Profile Picture</label>
                                    <?= renderUserAvatar($user, 'avatar-large') ?>
                                    <input class="form-control" id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp">
                                    <small>Optional. Upload a JPEG, PNG, or WebP image up to 5 MB.</small>
                                </div>

                                <input type="hidden" name="whatsapp" value="<?= e($user['whatsapp'] ?? '') ?>">
                                <div class="form-group">
                                    <label class="form-label">Services Offered</label>
                                    <select class="form-control" name="services_offered">
                                        <option value="both" <?= ($user['services_offered'] ?? '') === 'both' ? 'selected' : '' ?>>Both Travels & Deliveries</option>
                                        <option value="travel" <?= ($user['services_offered'] ?? '') === 'travel' ? 'selected' : '' ?>>Passenger Travels Only</option>
                                        <option value="delivery" <?= ($user['services_offered'] ?? '') === 'delivery' ? 'selected' : '' ?>>Parcel Deliveries Only</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Driver Bio / Intro</label>
                                    <textarea class="form-control" name="bio" placeholder="Tell travelers about your driving experience, routes, and vehicle comfort..."><?= e($user['bio'] ?? '') ?></textarea>
                                </div>

                                <input type="hidden" name="status" value="<?= e($user['status']) ?>">
                                <input type="hidden" name="vehicle_type" value="<?= e($user['vehicle_type'] ?? 'car') ?>">
                                <input type="hidden" name="vehicle_model" value="<?= e($user['vehicle_model'] ?? '') ?>">
                                <input type="hidden" name="vehicle_color" value="<?= e($user['vehicle_color'] ?? '') ?>">
                                <input type="hidden" name="plate_number" value="<?= e($user['plate_number'] ?? '') ?>">
                                <input type="hidden" name="seats_available" value="<?= (int) ($user['seats_available'] ?? 4) ?>">
                                <input type="hidden" name="max_cargo_kg" value="<?= (int) ($user['max_cargo_kg'] ?? 20) ?>">
                            <?php endif; ?>

                            <button class="button button-primary button-lg" type="submit">Save Profile Updates →</button>
                        </form>
                    </div>

                    <div class="dash-card" style="margin-top: 16px;">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Delete account</h2>
                                <p class="dash-card-copy">You can ask for this account to be deleted. It stays active until an admin or the super admin approves the request.</p>
                            </div>
                        </div>
                        <?php if ($deletionRequest): ?>
                            <p>Your request is waiting for review<?= !empty($deletionRequest['reason']) ? ': ' . e((string) $deletionRequest['reason']) : '' ?>.</p>
                            <form method="post" action="dashboard.php?type=<?= e($type) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="cancel_deletion">
                                <button class="button button-secondary" type="submit">Cancel request</button>
                            </form>
                        <?php else: ?>
                            <?php if ($latestDeletion && ($latestDeletion['status'] ?? '') === 'rejected'): ?>
                                <div class="notice notice-error" role="alert">The last request was rejected<?= !empty($latestDeletion['review_note']) ? ': ' . e((string) $latestDeletion['review_note']) : '' ?>.</div>
                            <?php endif; ?>
                            <form method="post" action="dashboard.php?type=<?= e($type) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="request_deletion">
                                <label class="form-label" for="deletion-reason">Reason</label>
                                <textarea class="form-control" id="deletion-reason" name="reason" placeholder="Tell the admin why you want this account deleted" required></textarea>
                                <button class="button button-danger" type="submit" style="margin-top: 12px;">Request deletion</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
    (function () {
        const active = document.querySelector('.dash-app .sidebar-link.active');
        if (active && window.matchMedia('(max-width: 900px)').matches) {
            active.scrollIntoView({ inline: 'center', block: 'nearest' });
        }
    })();
    </script>
    <?php if ($type === 'rider' && $tab === 'nearby'): ?>
    <script>
    (function () {
        const csrf = <?= json_encode(csrfToken()) ?>;
        const list = document.getElementById('request-list');
        if (!list) return;

        function esc(str) {
            return String(str || '').replace(/[&<>"']/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
            });
        }

        function paint(html) {
            if (list.dataset.sig === html) return;
            list.dataset.sig = html;
            list.innerHTML = html;
        }

        function renderRequests(requests) {
            if (!requests.length) {
                paint('<p class="zr-notify-empty" style="padding: 8px 0;">No nearby short-trip requests right now. You will get a live alert when a customer close to you wants to go a short distance.</p>');
                return;
            }
            paint(requests.map(function (req) {
                const trip = req.trip_label ? ' · ' + esc(req.trip_label) : '';
                return '<div class="nearby-match-row"><div><strong>' + esc(req.origin) + ' → ' + esc(req.destination) + '</strong>' +
                    '<div style="font-size: 13px; color: var(--muted);">' + esc(req.budget_label) +
                    ' · ' + esc(req.passengers_count) + ' passenger(s)' + trip + '</div></div>' +
                    '<div class="nearby-match-actions">' +
                    '<span class="nearby-dist">' + esc(req.distance_label) + '</span>' +
                    '<form method="post" action="request-action.php">' +
                    '<input type="hidden" name="csrf_token" value="' + esc(csrf) + '">' +
                    '<input type="hidden" name="request_id" value="' + esc(req.id) + '">' +
                    '<input type="hidden" name="action" value="accept_request">' +
                    '<button class="button button-sm button-emerald" type="submit">Accept</button></form></div></div>';
            }).join(''));
        }

        function tick() {
            fetch('nearby-api?action=nearby_requests')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) return;
                    renderRequests(data.requests || []);
                })
                .catch(function () {});
        }

        setInterval(tick, 8000);
    })();
    </script>
    <?php endif; ?>
    <?php if ($type === 'customer' && $tab === 'nearby'): ?>
    <script>
    (function () {
        const csrf = <?= json_encode(csrfToken()) ?>;
        const geoStatus = document.getElementById('geo-status');
        const form = document.getElementById('nearby-form');
        const waiting = document.getElementById('waiting-box');
        const list = document.getElementById('rider-list');
        let lat = null, lng = null;

        function setGeo(ok, label) {
            if (!geoStatus) return;
            geoStatus.innerHTML = '<span class="badge-dot"></span>' + label;
            geoStatus.className = 'status-badge ' + (ok ? 'badge-success' : 'badge-amber');
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                lat = pos.coords.latitude;
                lng = pos.coords.longitude;
                setGeo(true, 'Location on');
            }, function () {
                setGeo(false, 'Location blocked — same-city matching still works');
            }, { enableHighAccuracy: true, timeout: 8000 });
        } else {
            setGeo(false, 'GPS not available');
        }

        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (window.Notification && Notification.permission === 'default') {
                    Notification.requestPermission();
                }
                const msg = document.getElementById('nearby-form-msg');
                const payload = {
                    action: 'post_nearby',
                    csrf_token: csrf,
                    origin: document.getElementById('origin').value,
                    destination: document.getElementById('destination').value,
                    estimated_distance_km: parseFloat(document.getElementById('distance').value),
                    passengers_count: parseInt(document.getElementById('passengers').value, 10),
                    budget_amount: parseFloat(document.getElementById('budget').value),
                    lat: lat,
                    lng: lng
                };
                fetch('nearby-api', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (!data.ok) {
                        msg.hidden = false;
                        msg.className = 'notice notice-error';
                        msg.textContent = data.error || 'Could not post request.';
                        return;
                    }
                    window.location.reload();
                }).catch(function () {
                    msg.hidden = false;
                    msg.className = 'notice notice-error';
                    msg.textContent = 'Network error. Try again.';
                });
            });
        }

        function cancelNearby() {
            if (!waiting) return;
            fetch('nearby-api', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'cancel_nearby',
                    csrf_token: csrf,
                    request_id: parseInt(waiting.dataset.requestId, 10)
                })
            }).then(function () { window.location.reload(); });
        }

        document.querySelectorAll('.js-cancel-nearby').forEach(function (btn) {
            btn.addEventListener('click', cancelNearby);
        });

        const continueBtn = document.getElementById('continue-nearby');
        if (continueBtn && waiting) {
            continueBtn.addEventListener('click', function () {
                continueBtn.disabled = true;
                continueBtn.textContent = 'Searching again…';
                fetch('nearby-api', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'continue_nearby',
                        csrf_token: csrf,
                        request_id: parseInt(waiting.dataset.requestId, 10)
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (!data || !data.ok) {
                        continueBtn.disabled = false;
                        continueBtn.textContent = 'Continue searching';
                        return;
                    }
                    window.location.reload();
                }).catch(function () {
                    continueBtn.disabled = false;
                    continueBtn.textContent = 'Continue searching';
                });
            });
        }

        if (waiting) {
            const searching = document.getElementById('nearby-searching');
            const expired = document.getElementById('nearby-expired');
            const timerEl = document.getElementById('nearby-timer');
            const windowSeconds = parseInt(waiting.dataset.window, 10) || 300;
            const started = parseInt(waiting.dataset.started, 10) || 0;
            const serverNow = parseInt(waiting.dataset.serverNow, 10) || Math.floor(Date.now() / 1000);
            const skew = (Date.now() / 1000) - serverNow;
            let timerHandle = null;

            function secondsLeft() {
                const now = (Date.now() / 1000) - skew;
                return Math.max(0, Math.ceil(windowSeconds - (now - started)));
            }

            function formatClock(total) {
                const mins = Math.floor(total / 60);
                const secs = total % 60;
                return mins + ':' + (secs < 10 ? '0' : '') + secs;
            }

            function showExpired() {
                if (timerHandle) {
                    clearInterval(timerHandle);
                    timerHandle = null;
                }
                if (searching) searching.hidden = true;
                if (expired) expired.hidden = false;
            }

            function paintTimer() {
                const left = secondsLeft();
                if (timerEl) timerEl.textContent = formatClock(left);
                if (left <= 0) showExpired();
            }

            if (secondsLeft() <= 0) {
                showExpired();
            } else {
                paintTimer();
                timerHandle = setInterval(paintTimer, 250);
            }

            setInterval(function () {
                fetch('nearby-api?action=poll&waiting=1&after_id=0')
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.redirect) {
                            window.location.href = data.redirect;
                        }
                    }).catch(function () {});
            }, 5000);
        }

        function esc(str) {
            return String(str || '').replace(/[&<>"']/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
            });
        }

        function paintRiders(html) {
            if (!list || list.dataset.sig === html) return;
            list.dataset.sig = html;
            list.innerHTML = html;
        }

        function renderRiders(riders) {
            if (!riders.length) {
                paintRiders('<p class="zr-notify-empty" style="padding: 8px 0;">No live GPS matches yet. Allow location — nearby online riders will appear here.</p>');
                return;
            }
            paintRiders(riders.map(function (rider) {
                return '<div class="nearby-match-row"><div><strong>Rider: ' + esc(rider.full_name) + '</strong>' +
                    (rider.verified ? ' <span class="verified-tag" title="Verified" aria-label="Verified"></span>' : '') +
                    '<div style="font-size: 13px; color: var(--muted);">' + esc(rider.vehicle_icon) + ' ' +
                    esc(rider.vehicle_label) + ' · ' + esc(rider.rating_label) + '</div></div>' +
                    '<span class="nearby-dist">' + esc(rider.distance_label) + '</span></div>';
            }).join(''));
        }

        if (list) {
            setInterval(function () {
                fetch('nearby-api?action=nearby_riders')
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) return;
                        renderRiders(data.riders || []);
                    })
                    .catch(function () {});
            }, 8000);
        }
    })();
    </script>
    <?php endif; ?>
    <footer class="site-footer">
        <div class="container footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network.</span>
            <span>Dashboard Console.</span>
        </div>
    </footer>
    <?= renderMobileBottomNav($tab === 'nearby' ? 'nearby' : 'account') ?>
</body>
</html>
