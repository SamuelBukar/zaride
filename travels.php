<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$origin = trim($_GET['origin'] ?? '');
$destination = trim($_GET['destination'] ?? '');
$date = trim($_GET['date'] ?? '');
$vehicle = trim($_GET['vehicle'] ?? '');
$minSeats = (int) ($_GET['seats'] ?? 1);

// Build SQL query for travel trips
$sql = "SELECT t.*, r.full_name as rider_name, r.phone as rider_phone, r.whatsapp as rider_whatsapp,
               r.rating_avg, r.rating_count, r.trips_completed, r.vehicle_type, r.vehicle_model,
               r.vehicle_color, r.plate_number, r.status as rider_status, r.verification_status
        FROM trips t
        JOIN riders r ON t.rider_id = r.id
        WHERE t.service_type IN ('travel', 'both')
          AND t.status = 'active'
          AND t.available_seats >= ?
          AND t.departure_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
          AND r.account_status = 'active' AND r.deleted_at IS NULL
          AND r.verification_status = 'approved'";

$params = [$minSeats];

if ($origin !== '') {
    $sql .= " AND (t.origin_city LIKE ? OR t.origin_address LIKE ?)";
    $params[] = "%{$origin}%";
    $params[] = "%{$origin}%";
}

if ($destination !== '') {
    $sql .= " AND (t.destination_city LIKE ? OR t.destination_address LIKE ?)";
    $params[] = "%{$destination}%";
    $params[] = "%{$destination}%";
}

if ($date !== '') {
    $sql .= " AND DATE(t.departure_time) = ?";
    $params[] = $date;
}

if ($vehicle !== '') {
    $sql .= " AND r.vehicle_type = ?";
    $params[] = $vehicle;
}

$sql .= " ORDER BY t.departure_time ASC LIMIT 50";

$trips = [];
$errorMsg = null;
try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $trips = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Travel trips query failed: ' . $e->getMessage());
    $errorMsg = 'Could not load trips at this moment.';
}

$currentAcc = currentAccount();
$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | Find Passenger Travels & Carpools</title>
    <link rel="stylesheet" href="styles.css?v=<?= (int) @filemtime(__DIR__ . '/styles.css') ?>">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">P2P Mobility</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="nearby.php">📍 Nearby</a>
            <a class="nav-link active" href="travels.php">🚗 Find Travels</a>
            <a class="nav-link" href="deliveries.php">📦 Deliveries</a>
            <a class="nav-link" href="zaride.php">👥 Riders</a>
            <?php if ($currentAcc): ?>
                <a class="user-menu-pill" href="<?= e(dashboardUrl()) ?>">
                    <span class="user-avatar-mini"><?= strtoupper(substr($currentAcc['full_name'], 0, 1)) ?></span>
                    <span style="font-weight: 700; font-size: 13px;"><?= e(explode(' ', $currentAcc['full_name'])[0]) ?></span>
                    <span class="role-tag <?= e($currentAcc['account_type']) ?>"><?= ucfirst($currentAcc['account_type']) ?></span>
                </a>
            <?php else: ?>
                <a class="nav-link" href="login.php">Sign In</a>
                <a class="button button-sm button-primary" href="register.php">Join ZaRide</a>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <!-- Page Hero Header -->
        <section style="background: linear-gradient(135deg, #093b37 0%, #0f766e 100%); color: white; padding: 48px 0 38px;">
            <div class="container">
                <span class="hero-eyebrow" style="background: rgba(255,255,255,0.15); color: #a7f3d0;">Peer-to-Peer Carpooling</span>
                <h1 style="font-size: clamp(28px, 4vw, 42px); font-weight: 800; margin-bottom: 12px; letter-spacing: -1px;">
                    Find Rides & Share Travels
                </h1>
                <p style="color: #d1fae5; max-width: 650px; font-size: 16px; margin-bottom: 16px;">
                    Book seats directly with verified drivers heading your way. Enjoy lower fares, direct communication, and zero middleman markups.
                </p>
                <a class="button button-sm" href="nearby.php" style="background: #ecfdf5; color: #065f46;">
                    Going a short distance nearby? Get live rider alerts →
                </a>
            </div>
        </section>

        <div class="container" style="padding-top: 28px; padding-bottom: 70px;">
            <?php if ($flash): ?>
                <div class="notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <!-- Filter Console -->
            <form method="get" action="travels.php" class="filter-bar">
                <div class="filter-group" style="flex: 1;">
                    <input class="console-field" style="height: 40px; min-width: 170px;" type="text" name="origin" placeholder="From (e.g. Tudun Wada, Zaria)" value="<?= e($origin) ?>">
                    <input class="console-field" style="height: 40px; min-width: 170px;" type="text" name="destination" placeholder="To (e.g. Kongo Campus, Zaria)" value="<?= e($destination) ?>">
                    <input class="console-field" style="height: 40px; width: 140px;" type="date" name="date" value="<?= e($date) ?>">
                    <select class="filter-select" name="vehicle">
                        <option value="">Vehicle: All</option>
                        <option value="car" <?= $vehicle === 'car' ? 'selected' : '' ?>>Cars</option>
                        <option value="suv" <?= $vehicle === 'suv' ? 'selected' : '' ?>>SUVs</option>
                        <option value="van" <?= $vehicle === 'van' ? 'selected' : '' ?>>Vans / Minibuses</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button class="button button-sm button-primary" type="submit">Filter Rides</button>
                    <?php if ($origin || $destination || $date || $vehicle): ?>
                        <a class="button button-sm button-secondary" href="travels.php">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                <h2 style="font-size: 20px; font-weight: 800; margin: 0;">
                    <?= count($trips) ?> <?= count($trips) === 1 ? 'Scheduled Trip' : 'Scheduled Trips' ?> Available
                </h2>
                <a class="button button-sm button-secondary" href="#post-request">
                    ➕ Can't find a ride? Post a request
                </a>
            </div>

            <!-- Trips Grid -->
            <?php if ($trips === []): ?>
                <div class="empty-box">
                    <span class="empty-icon">🚗💨</span>
                    <h3>No scheduled rides match your route right now</h3>
                    <p>Drivers publish new journeys regularly. You can post a custom travel request so drivers heading your way can contact or accept you directly!</p>
                    <a class="button button-primary" href="#post-request">Post a Travel Request Now →</a>
                </div>
            <?php else: ?>
                <div class="trip-grid">
                    <?php foreach ($trips as $trip): ?>
                        <article class="trip-card">
                            <div>
                                <div class="trip-topline">
                                    <div class="trip-rider">
                                        <div class="rider-avatar">
                                            <?= strtoupper(substr($trip['rider_name'], 0, 1)) ?>
                                            <?php if ($trip['rider_status'] === 'online'): ?>
                                                <span class="online-dot" title="Driver is Online"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="rider-meta-name"><?= e($trip['rider_name']) ?> <?= renderVerifiedTag($trip) ?></div>
                                            <div class="rider-submeta">
                                                <?= renderRatingStars((float) $trip['rating_avg'], (int) $trip['rating_count']) ?>
                                                <span>· <?= (int) $trip['trips_completed'] ?> trips</span>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="service-chip chip-travel">
                                        <?= renderVehicleIcon($trip['vehicle_type']) ?> <?= e(ucfirst($trip['vehicle_type'])) ?>
                                    </span>
                                </div>

                                <!-- Route Details -->
                                <div class="route-timeline">
                                    <div class="route-point">
                                        <div class="route-time"><?= formatDateTime($trip['departure_time']) ?></div>
                                        <div class="route-address"><?= e($trip['origin_address']) ?></div>
                                        <div class="route-city"><?= e($trip['origin_city']) ?></div>
                                    </div>
                                    <div class="route-point destination">
                                        <div class="route-time" style="color: #ef4444;">Destination Drop-off</div>
                                        <div class="route-address"><?= e($trip['destination_address']) ?></div>
                                        <div class="route-city"><?= e($trip['destination_city']) ?></div>
                                    </div>
                                </div>

                                <?php if (!empty($trip['additional_notes'])): ?>
                                    <p style="font-size: 13px; color: var(--muted); margin: 8px 0; background: var(--surface-alt); padding: 8px 12px; border-radius: var(--radius-sm);">
                                        💬 <?= e($trip['additional_notes']) ?>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($trip['vehicle_model']) || !empty($trip['plate_number'])): ?>
                                    <div style="font-size: 12px; color: var(--muted); margin-bottom: 10px;">
                                        🚘 <?= e($trip['vehicle_model']) ?> <?= !empty($trip['plate_number']) ? '· Plate: ' . e($trip['plate_number']) : '' ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <div class="trip-pricing-bar">
                                    <div class="price-item">
                                        <span class="price-label">Seat Fare</span>
                                        <span class="price-val"><?= formatMoney($trip['price_per_seat']) ?></span>
                                    </div>
                                    <span class="seats-badge">
                                        💺 <?= (int) $trip['available_seats'] ?> <?= (int) $trip['available_seats'] === 1 ? 'seat left' : 'seats left' ?>
                                    </span>
                                </div>

                                <div class="trip-actions">
                                    <a class="button button-primary button-full" href="book.php?trip_id=<?= (int) $trip['id'] ?>&type=travel">
                                        Book Seat →
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Post Travel Request Section -->
            <section id="post-request" style="margin-top: 60px; background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 36px; box-shadow: var(--shadow-sm);">
                <div style="max-width: 640px; margin: 0 auto; text-align: center; margin-bottom: 28px;">
                    <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary);">Passenger Board</span>
                    <h2 style="font-size: 26px; font-weight: 800; margin-bottom: 8px;">Need a ride that isn't listed?</h2>
                    <p style="color: var(--muted); font-size: 15px;">Post your travel requirement. Drivers looking for passengers on your route will be able to review and accept it directly.</p>
                </div>

                <form method="post" action="book.php" style="max-width: 700px; margin: 0 auto;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="post_request">
                    <input type="hidden" name="request_type" value="travel">

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="req-origin">Your Pickup Location</label>
                            <input class="form-control" id="req-origin" name="origin" placeholder="e.g. Sabon Gari, Zaria" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="req-destination">Your Destination</label>
                            <input class="form-control" id="req-destination" name="destination" placeholder="e.g. ABU Samaru, Zaria" required>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="req-time">Preferred Departure Time</label>
                            <input class="form-control" id="req-time" name="preferred_time" type="datetime-local" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="req-passengers">Passengers</label>
                            <input class="form-control" id="req-passengers" name="passengers_count" type="number" min="1" max="15" step="1" value="1" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="req-budget">Your Budget (₦)</label>
                        <input class="form-control" id="req-budget" name="budget_amount" type="number" step="100" min="500" placeholder="e.g. 2500" required>
                    </div>

                    <div style="text-align: center; margin-top: 10px;">
                        <?php if ($currentAcc && $currentAcc['account_type'] === 'customer'): ?>
                            <button class="button button-primary button-lg" type="submit">Post Travel Request →</button>
                        <?php elseif ($currentAcc && $currentAcc['account_type'] === 'rider'): ?>
                            <div class="notice notice-error" style="display: inline-block;">You are signed in as a Rider. Please sign in as a Customer to post travel requests.</div>
                        <?php else: ?>
                            <a class="button button-primary button-lg" href="login.php?type=customer">Sign in as Customer to Post Request →</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network.</span>
            <span>Direct P2P Carpooling & Rides.</span>
        </div>
    </footer>
    <?= renderMobileBottomNav('travels') ?>
</body>
</html>
