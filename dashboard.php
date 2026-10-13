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

        if (preg_match('/\A.{1,100}\z/us', $vehicleType) !== 1) {
            setFlash('error', 'Enter a vehicle type (up to 100 characters).');
            header('Location: dashboard.php?type=rider&tab=' . ($action === 'update_vehicle' ? 'vehicle' : 'profile'));
            exit;
        }

        try {
            $profileQuery = db()->prepare('SELECT avatar_path FROM riders WHERE id = ?');
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

            $stmt = db()->prepare(
                "UPDATE riders
                 SET full_name = ?, email = ?, phone = ?, city = ?, whatsapp = ?, bio = ?,
                     services_offered = ?, status = ?, vehicle_type = ?, vehicle_model = ?,
                     vehicle_color = ?, plate_number = ?, seats_available = ?, max_cargo_kg = ?,
                     avatar_path = ?
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
                $accountId
            ]);

            if ($newAvatar !== null && !empty($oldProfile['avatar_path'])) {
                deleteAvatarImage($oldProfile['avatar_path']);
            }
            setFlash('success', 'Rider profile and vehicle details updated.');
        } catch (Exception $e) {
            if ($newAvatar !== null) {
                deleteAvatarImage($newAvatar);
            }
            setFlash('error', $e->getMessage());
        }
        header('Location: dashboard.php?type=rider&tab=' . ($action === 'update_vehicle' ? 'vehicle' : 'profile'));
        exit;
    }
}

// Fetch current user record
$userStmt = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
$userStmt->execute([$accountId]);
$user = $userStmt->fetch();

if (!$user) {
    $_SESSION = [];
    session_regenerate_id(true);
    setFlash('error', 'Account not found. Please sign in again.');
    header('Location: login.php?type=' . $type);
    exit;
}

$tab = $_GET['tab'] ?? 'bookings';
$flash = getFlash();

// Fetch bookings for this user
$bookings = [];
try {
    if ($type === 'rider') {
        $bStmt = db()->prepare(
            "SELECT b.*, c.full_name as customer_name, c.phone as customer_phone
             FROM bookings b
             JOIN customers c ON b.customer_id = c.id
             WHERE b.rider_id = ?
             ORDER BY b.created_at DESC LIMIT 50"
        );
        $bStmt->execute([$accountId]);
    } else {
        $bStmt = db()->prepare(
            "SELECT b.*, r.full_name as rider_name, r.phone as rider_phone, r.whatsapp as rider_whatsapp,
                    r.vehicle_type, r.vehicle_model, r.plate_number, r.rating_avg
             FROM bookings b
             JOIN riders r ON b.rider_id = r.id
             WHERE b.customer_id = ?
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
        $tStmt = db()->prepare("SELECT * FROM trips WHERE rider_id = ? ORDER BY departure_time DESC LIMIT 30");
        $tStmt->execute([$accountId]);
        $myTrips = $tStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('My trips query failed: ' . $e->getMessage());
    }
}

// Fetch customer open requests (or rider marketplace open requests)
$marketplaceRequests = [];
if ($type === 'rider') {
    try {
        $mStmt = db()->query("SELECT req.*, c.full_name as customer_name, c.phone as customer_phone FROM requests req JOIN customers c ON req.customer_id = c.id WHERE req.status = 'open' ORDER BY req.created_at DESC LIMIT 20");
        $marketplaceRequests = $mStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Marketplace requests error: ' . $e->getMessage());
    }
} else {
    try {
        $cReqStmt = db()->prepare("SELECT * FROM requests WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20");
        $cReqStmt->execute([$accountId]);
        $marketplaceRequests = $cReqStmt->fetchAll();
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
    <link rel="stylesheet" href="styles.css">
</head>
<body style="background: var(--surface-alt);">
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag"><?= $type === 'rider' ? 'Rider Console' : 'Customer Console' ?></span>
            </div>
        </a>
        <nav class="site-nav">
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
            <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 18px 24px; margin-top: 24px; box-shadow: var(--shadow-sm); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <span class="status-badge <?= $user['status'] === 'online' ? 'badge-success' : 'badge-muted' ?>" style="font-size: 13px; padding: 6px 14px;">
                        <span class="badge-dot"></span>You are currently <?= strtoupper($user['status']) ?>
                    </span>
                    <span style="font-size: 13px; color: var(--muted);">
                        <?= $user['status'] === 'online' ? '✓ Visible to customers for trips & parcel requests' : 'Offline — not receiving new requests' ?>
                    </span>
                </div>
                <form method="post" action="request-action.php" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle_rider_status">
                    <input type="hidden" name="current_status" value="<?= e($user['status']) ?>">
                    <button class="button button-sm <?= $user['status'] === 'online' ? 'button-secondary' : 'button-emerald' ?>" type="submit">
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
                    <h3><?= e($user['full_name']) ?></h3>
                    <p><?= e($user['email']) ?></p>
                    <span class="role-tag <?= e($type) ?>"><?= ucfirst($type) ?> Account</span>

                    <?php if ($type === 'rider'): ?>
                        <div style="margin-top: 12px;">
                            <?= renderRatingStars((float) ($user['rating_avg'] ?? 5.0), (int) ($user['rating_count'] ?? 0)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <nav class="sidebar-nav">
                    <a class="sidebar-link <?= $tab === 'bookings' ? 'active' : '' ?>" href="dashboard.php?type=<?= e($type) ?>&tab=bookings">
                        <span><?= $type === 'rider' ? '📋 Booking Requests' : '🎫 My Bookings' ?></span>
                        <?php if ($activeBookingsCount > 0): ?>
                            <span class="nav-badge"><?= $activeBookingsCount ?></span>
                        <?php endif; ?>
                    </a>

                    <?php if ($type === 'rider'): ?>
                        <a class="sidebar-link <?= $tab === 'trips' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=trips">
                            <span>🚗 My Posted Routes</span>
                            <span style="font-size: 12px; color: var(--muted);"><?= count($myTrips) ?></span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'marketplace' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=marketplace">
                            <span>📦 Open Customer Board</span>
                            <span style="font-size: 11px; background: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: var(--radius-full); font-weight: 700;">Claim</span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'vehicle' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=vehicle">
                            <span>🚘 Vehicle Details</span>
                        </a>
                        <a class="sidebar-link <?= $tab === 'reviews' ? 'active' : '' ?>" href="dashboard.php?type=rider&tab=reviews">
                            <span>⭐ Reviews & Rating</span>
                            <span style="font-size: 12px; color: var(--muted);"><?= count($riderReviews) ?></span>
                        </a>
                    <?php else: ?>
                        <a class="sidebar-link <?= $tab === 'marketplace' ? 'active' : '' ?>" href="dashboard.php?type=customer&tab=marketplace">
                            <span>📢 My Custom Requests</span>
                            <span style="font-size: 12px; color: var(--muted);"><?= count($marketplaceRequests) ?></span>
                        </a>
                    <?php endif; ?>

                    <a class="sidebar-link <?= $tab === 'profile' ? 'active' : '' ?>" href="dashboard.php?type=<?= e($type) ?>&tab=profile">
                        <span>⚙️ Profile Settings</span>
                    </a>
                </nav>

                <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--line-subtle); display: flex; flex-direction: column; gap: 8px;">
                    <?php if ($type === 'rider'): ?>
                        <a class="button button-sm button-primary button-full" href="dashboard.php?type=rider&tab=trips#new-trip">
                            ➕ Post New Route
                        </a>
                    <?php else: ?>
                        <a class="button button-sm button-primary button-full" href="travels.php">
                            🚗 Find a Ride
                        </a>
                        <a class="button button-sm button-emerald button-full" href="deliveries.php#send-package">
                            📦 Send a Package
                        </a>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- Main Content Area -->
            <section class="dashboard-content">
                <?php if ($flash): ?>
                    <div class="notice notice-<?= e($flash['type']) ?>" role="alert">
                        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= e($flash['message']) ?>
                    </div>
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
                                <p style="color: var(--muted); font-size: 13px; margin: 0;">Manage confirmations, status updates, and peer communication</p>
                            </div>
                        </div>

                        <?php if ($bookings === []): ?>
                            <div class="empty-box" style="padding: 40px 20px;">
                                <span class="empty-icon">📋</span>
                                <h3>No bookings found yet</h3>
                                <p><?= $type === 'rider' ? 'When customers book your routes or send parcel requests, they will appear here.' : 'You have not booked any trips or parcel dispatches yet.' ?></p>
                                <?php if ($type === 'customer'): ?>
                                    <div style="display: flex; gap: 10px; justify-content: center;">
                                        <a class="button button-primary" href="travels.php">Find Travels →</a>
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
                                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                <span class="booking-route"><?= e($b['pickup_location']) ?> → <?= e($b['dropoff_location']) ?></span>
                                                <?= renderStatusBadge($b['status']) ?>
                                            </div>

                                            <div class="booking-subtext">
                                                <span>📅 <?= formatDateTime($b['scheduled_at']) ?></span>
                                                <span>💰 <strong style="color: var(--primary);"><?= formatMoney($b['fare_amount']) ?></strong></span>
                                                <?php if ($type === 'rider'): ?>
                                                    <span>👤 Customer: <strong><?= e($b['customer_name']) ?></strong></span>
                                                <?php else: ?>
                                                    <span>🛵 Driver: <strong><?= e($b['rider_name']) ?></strong> (<?= e($b['vehicle_model'] ?: ucfirst($b['vehicle_type'])) ?><?= !empty($b['plate_number']) ? ' · Plate: ' . e($b['plate_number']) : '' ?>)</span>
                                                <?php endif; ?>
                                            </div>

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

                                            <!-- Direct contact buttons -->
                                            <?php 
                                            $phone = $type === 'rider' ? $b['customer_phone'] : $b['rider_phone'];
                                            $whatsapp = $type === 'customer' ? ($b['rider_whatsapp'] ?? null) : null;
                                            ?>
                                            <a class="button button-sm button-secondary" href="tel:<?= e($phone) ?>" title="Call">
                                                📞 Call
                                            </a>
                                            <?php if (!empty($whatsapp)): ?>
                                                <a class="button button-sm button-whatsapp" href="https://wa.me/<?= e(preg_replace('/\D+/', '', $whatsapp)) ?>" target="_blank" rel="noopener noreferrer">
                                                    WhatsApp
                                                </a>
                                            <?php endif; ?>

                                            <!-- Rider lifecycle actions -->
                                            <?php if ($type === 'rider'): ?>
                                                <?php if ($b['status'] === 'pending'): ?>
                                                    <form method="post" action="request-action.php" style="margin: 0; display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="confirmed">
                                                        <button class="button button-sm button-emerald" type="submit">✓ Accept</button>
                                                    </form>
                                                    <form method="post" action="request-action.php" style="margin: 0; display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="declined">
                                                        <button class="button button-sm button-danger" type="submit">✕ Decline</button>
                                                    </form>
                                                <?php elseif ($b['status'] === 'confirmed'): ?>
                                                    <form method="post" action="request-action.php" style="margin: 0; display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="in_transit">
                                                        <button class="button button-sm button-primary" type="submit">🚗 Start Trip</button>
                                                    </form>
                                                <?php elseif ($b['status'] === 'in_transit'): ?>
                                                    <form method="post" action="request-action.php" style="margin: 0; display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                        <input type="hidden" name="action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="hidden" name="status" value="completed">
                                                        <button class="button button-sm button-emerald" type="submit">🏁 Mark Completed</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <!-- Customer actions -->
                                                <?php if ($b['status'] === 'pending'): ?>
                                                    <form method="post" action="request-action.php" style="margin: 0; display: inline;">
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
                                <p style="color: var(--muted); font-size: 13px; margin: 0;">Post an upcoming commute or intercity journey to earn from passenger seats and package delivery</p>
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
                                    <div style="display: flex; gap: 8px;">
                                        <input class="form-control" style="width: 130px;" name="origin_city" placeholder="City" value="<?= e($user['city'] ?? 'Lagos') ?>" required>
                                        <input class="form-control" name="origin_address" placeholder="Pickup spot / Landmark" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Destination City & Address</label>
                                    <div style="display: flex; gap: 8px;">
                                        <input class="form-control" style="width: 130px;" name="destination_city" placeholder="City" value="Lagos" required>
                                        <input class="form-control" name="destination_address" placeholder="Drop-off spot / Landmark" required>
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
                            <div style="display: flex; flex-direction: column; gap: 14px;">
                                <?php foreach ($myTrips as $t): ?>
                                    <div style="border: 1px solid var(--line); border-radius: var(--radius-md); padding: 16px; background: white; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                        <div>
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                                <strong><?= e($t['origin_address']) ?> (<?= e($t['origin_city']) ?>) → <?= e($t['destination_address']) ?> (<?= e($t['destination_city']) ?>)</strong>
                                                <?= renderStatusBadge($t['status']) ?>
                                            </div>
                                            <div style="font-size: 13px; color: var(--muted);">
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
                            <div style="display: flex; flex-direction: column; gap: 14px;">
                                <?php foreach ($marketplaceRequests as $req): ?>
                                    <div style="border: 1px solid var(--line); border-radius: var(--radius-md); padding: 18px; background: white; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                                        <div>
                                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                                                <span class="service-chip <?= $req['request_type'] === 'travel' ? 'chip-travel' : 'chip-delivery' ?>">
                                                    <?= $req['request_type'] === 'travel' ? '🚗 Passenger Ride' : '📦 Parcel Delivery' ?>
                                                </span>
                                                <strong style="font-size: 15px;"><?= e($req['origin']) ?> → <?= e($req['destination']) ?></strong>
                                                <?= renderStatusBadge($req['status']) ?>
                                            </div>
                                            <div style="font-size: 13px; color: var(--muted);">
                                                Preferred Time: <?= formatDateTime($req['preferred_time']) ?> · Budget: <strong style="color: var(--primary);"><?= formatMoney($req['budget_amount']) ?></strong>
                                                <?php if ($type === 'rider' && !empty($req['customer_name'])): ?>
                                                    · Customer: <strong><?= e($req['customer_name']) ?></strong>
                                                <?php endif; ?>
                                            </div>
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
                                                <button class="button button-emerald" type="submit">✓ Claim & Deliver (<?= formatMoney($req['budget_amount']) ?>)</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- TAB 4 (Rider): VEHICLE & DOCUMENTS -->
                <?php if ($tab === 'vehicle' && $type === 'rider'): ?>
                    <div class="dash-card">
                        <div class="dash-card-header">
                            <div>
                                <h2 class="dash-card-title">Vehicle Information</h2>
                                <p style="color: var(--muted); font-size: 13px; margin: 0;">Keep your vehicle details and license plate number up to date for passenger confidence</p>
                            </div>
                        </div>

                        <form method="post" action="dashboard.php?type=rider">
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
                                    <input class="form-control" name="plate_number" placeholder="e.g. LAG-882-AB" value="<?= e($user['plate_number'] ?? '') ?>">
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

                            <button class="button button-primary button-lg" type="submit" style="margin-top: 10px;">Save Vehicle Details →</button>
                        </form>
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
                                    <input class="form-control" name="city" value="<?= e($user['city'] ?? 'Lagos') ?>" required>
                                </div>
                            </div>

                            <?php if ($type === 'rider'): ?>
                                <div class="form-group">
                                    <label class="form-label" for="avatar">Profile Picture</label>
                                    <?= renderUserAvatar($user, 'avatar-large') ?>
                                    <input class="form-control" id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp">
                                    <small>Optional. Upload a JPEG, PNG, or WebP image up to 5 MB.</small>
                                </div>

                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label class="form-label">WhatsApp Number</label>
                                        <input class="form-control" name="whatsapp" type="tel" value="<?= e($user['whatsapp'] ?? '') ?>" placeholder="For direct customer coordination">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Services Offered</label>
                                        <select class="form-control" name="services_offered">
                                            <option value="both" <?= ($user['services_offered'] ?? '') === 'both' ? 'selected' : '' ?>>Both Travels & Deliveries</option>
                                            <option value="travel" <?= ($user['services_offered'] ?? '') === 'travel' ? 'selected' : '' ?>>Passenger Travels Only</option>
                                            <option value="delivery" <?= ($user['services_offered'] ?? '') === 'delivery' ? 'selected' : '' ?>>Parcel Deliveries Only</option>
                                        </select>
                                    </div>
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
                <?php endif; ?>
            </section>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network.</span>
            <span>Dashboard Console.</span>
        </div>
    </footer>
    <?= renderMobileBottomNav('account') ?>
</body>
</html>
