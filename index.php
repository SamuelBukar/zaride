<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Registration form handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($postedToken)) {
        setFlash('error', 'Your session could not be verified. Please try registering again.');
        header('Location: index.php#register');
        exit;
    }

    $accountType = trim((string) ($_POST['account_type'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $city = trim((string) ($_POST['city'] ?? 'Lagos'));
    $password = (string) ($_POST['password'] ?? '');
    $servicesOffered = trim((string) ($_POST['services_offered'] ?? 'both'));
    $vehicleType = trim((string) ($_POST['vehicle_type'] ?? 'car'));

    $errors = [];
    if (!in_array($accountType, ['customer', 'rider'], true)) {
        $errors[] = 'Choose a valid account type.';
    }
    if (preg_match('/\A.{1,120}\z/us', $fullName) !== 1) {
        $errors[] = 'Enter your name (up to 120 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!preg_match('/^[+0-9(). -]{7,25}$/', $phone)) {
        $errors[] = 'Enter a valid phone number (7 to 25 characters).';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Your password must contain at least 8 characters.';
    }
    if ($accountType === 'rider' && preg_match('/\A.{1,100}\z/us', $vehicleType) !== 1) {
        $errors[] = 'Enter a vehicle type (up to 100 characters).';
    }

    if ($errors !== []) {
        setFlash('error', implode(' ', $errors));
        header('Location: index.php#register');
        exit;
    }

    try {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($accountType === 'customer') {
            $statement = db()->prepare(
                'INSERT INTO customers (full_name, email, phone, city, password_hash) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$fullName, $email, $phone, $city, $passwordHash]);
            $accountId = (int) db()->lastInsertId();
        } else {
            $statement = db()->prepare(
                'INSERT INTO riders (full_name, email, phone, city, services_offered, vehicle_type, password_hash, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "online")'
            );
            $statement->execute([$fullName, $email, $phone, $city, $servicesOffered, $vehicleType, $passwordHash]);
            $accountId = (int) db()->lastInsertId();
        }

        session_regenerate_id(true);
        $_SESSION['account_type'] = $accountType;
        $_SESSION['account_id'] = $accountId;
        setFlash('success', 'Welcome to ZaRide! Your account is active. Manage your profile below.');
        header('Location: ' . dashboardUrl($accountType));
        exit;
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            setFlash('error', 'That email address or phone number is already registered for this account type.');
        } else {
            error_log('ZaRide registration failed: ' . $exception->getMessage());
            setFlash('error', 'Registration could not be completed. Check database connection and try again.');
        }
    }

    header('Location: index.php#register');
    exit;
}

// Fetch live active trips for homepage feature
$featuredTrips = [];
try {
    $tripStmt = db()->query(
        "SELECT t.*, r.full_name as rider_name, r.rating_avg, r.rating_count, r.vehicle_type, r.vehicle_model, r.status as rider_status
         FROM trips t
         JOIN riders r ON t.rider_id = r.id
         WHERE t.status = 'active' AND t.departure_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         ORDER BY t.departure_time ASC LIMIT 6"
    );
    $featuredTrips = $tripStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Featured trips query error: ' . $e->getMessage());
}

// Fetch featured online riders
$featuredRiders = [];
try {
    $riderStmt = db()->query(
        "SELECT * FROM riders WHERE status = 'online' ORDER BY rating_avg DESC, trips_completed DESC LIMIT 4"
    );
    $featuredRiders = $riderStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Featured riders query error: ' . $e->getMessage());
}

$currentAcc = currentAccount();
$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | Peer-to-Peer Travels & Parcel Deliveries</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">P2P Mobility & Logistics</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="travels.php">🚗 Find Travels</a>
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
                <a class="button button-sm button-primary" href="#register">Join ZaRide</a>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <!-- Hero Section -->
        <section class="hero">
            <div class="hero-pattern"></div>
            <div class="hero-glow"></div>
            <div class="hero-content">
                <span class="hero-eyebrow">🚀 The Direct Peer-to-Peer Network</span>
                <h1>Travel Together. Send Parcels. <br><span class="gradient-text">Zero Middleman Markup.</span></h1>
                <p class="hero-desc">
                    Connect directly with local drivers and dispatchers. Share rides for affordable commuting, or send packages with trusted peers heading your way.
                </p>

                <!-- Interactive Search Console -->
                <div class="search-console">
                    <div class="console-tabs">
                        <button type="button" class="console-tab active" id="btn-tab-travel" onclick="switchConsoleMode('travel')">
                            🚗 Passenger Travel
                        </button>
                        <button type="button" class="console-tab" id="btn-tab-delivery" onclick="switchConsoleMode('delivery')">
                            📦 Package Delivery
                        </button>
                        <button type="button" class="console-tab" id="btn-tab-riders" onclick="switchConsoleMode('riders')">
                            🛵 Online Riders
                        </button>
                    </div>

                    <!-- Mode 1: Passenger Travel Search -->
                    <form method="get" action="travels.php" class="console-form" id="console-form-travel">
                        <div class="console-input-wrap">
                            <label class="console-label">Departure From</label>
                            <input class="console-field" name="origin" placeholder="City or landmark (e.g. Ikeja, Lagos)" required>
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Destination To</label>
                            <input class="console-field" name="destination" placeholder="Destination (e.g. Victoria Island, Ibadan)" required>
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Date</label>
                            <input class="console-field" type="date" name="date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <button class="button button-primary button-lg" type="submit">
                            Search Rides →
                        </button>
                    </form>

                    <!-- Mode 2: Package Delivery Search -->
                    <form method="get" action="deliveries.php" class="console-form" id="console-form-delivery" style="display: none;">
                        <div class="console-input-wrap">
                            <label class="console-label">Pickup Location</label>
                            <input class="console-field" name="origin" placeholder="Pickup spot (e.g. Alausa, Ikeja)" required>
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Drop-off Destination</label>
                            <input class="console-field" name="destination" placeholder="Drop-off point (e.g. Lekki Phase 1)" required>
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Vehicle Type</label>
                            <select class="console-field" name="vehicle">
                                <option value="">Any Vehicle</option>
                                <option value="motorcycle">🏍️ Motorcycle Dispatch</option>
                                <option value="car">🚗 Car (Trunk Box)</option>
                                <option value="van">🚐 Van / Large Cargo</option>
                            </select>
                        </div>
                        <button class="button button-emerald button-lg" type="submit">
                            Find Delivery →
                        </button>
                    </form>

                    <!-- Mode 3: Online Riders Search -->
                    <form method="get" action="zaride.php" class="console-form rider-mode" id="console-form-riders" style="display: none;">
                        <div class="console-input-wrap">
                            <label class="console-label">City / Region</label>
                            <input class="console-field" name="city" placeholder="City (e.g. Lagos, Abuja, Ibadan)">
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Service Type</label>
                            <select class="console-field" name="service">
                                <option value="">All Services</option>
                                <option value="travel">🚗 Passenger Travels</option>
                                <option value="delivery">📦 Package Delivery</option>
                            </select>
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Availability</label>
                            <select class="console-field" name="status">
                                <option value="online">🟢 Online Now</option>
                                <option value="all">All Riders</option>
                            </select>
                        </div>
                        <button class="button button-dark button-lg" type="submit">
                            Browse Riders →
                        </button>
                    </form>

                    <div class="console-badge-row">
                        <span class="console-stat-pill">✨ <strong>100% Direct P2P</strong> — No platform surcharge</span>
                        <span class="console-stat-pill">🛡️ <strong>Verified Profiles</strong> — License & vehicle plate checks</span>
                        <span class="console-stat-pill">💬 <strong>Instant Chat</strong> — Coordinate on WhatsApp & in-app</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Flash Notice -->
        <div class="container" style="padding-top: 24px;">
            <?php if ($flash): ?>
                <div class="notice notice-<?= e($flash['type']) ?>" role="alert">
                    <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Popular Live Routes Available Now -->
        <section class="section">
            <div class="container">
                <div class="section-header">
                    <span class="section-eyebrow">Active Routes</span>
                    <h2 class="section-title">Departing Soon on ZaRide</h2>
                    <p class="section-subtitle">Real peer journeys happening today. Book a seat or send a package with drivers already on the road.</p>
                </div>

                <?php if ($featuredTrips === []): ?>
                    <div class="empty-box">
                        <span class="empty-icon">🚗</span>
                        <h3>No scheduled trips right now</h3>
                        <p>Be the first driver to publish a route or post your travel request!</p>
                        <a class="button button-primary" href="travels.php#post-request">Post a Travel Request →</a>
                    </div>
                <?php else: ?>
                    <div class="trip-grid">
                        <?php foreach ($featuredTrips as $trip): ?>
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
                                                <div class="rider-meta-name"><?= e($trip['rider_name']) ?></div>
                                                <div class="rider-submeta">
                                                    <?= renderRatingStars((float) $trip['rating_avg'], (int) $trip['rating_count']) ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?= renderServiceBadge($trip['service_type']) ?>
                                    </div>

                                    <div class="route-timeline">
                                        <div class="route-point">
                                            <div class="route-time"><?= formatDateTime($trip['departure_time']) ?></div>
                                            <div class="route-address"><?= e($trip['origin_address']) ?></div>
                                            <div class="route-city"><?= e($trip['origin_city']) ?></div>
                                        </div>
                                        <div class="route-point destination">
                                            <div class="route-time" style="color: #ef4444;">Drop-off</div>
                                            <div class="route-address"><?= e($trip['destination_address']) ?></div>
                                            <div class="route-city"><?= e($trip['destination_city']) ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <div class="trip-pricing-bar">
                                        <div class="price-item">
                                            <span class="price-label"><?= $trip['service_type'] === 'delivery' ? 'Delivery Fee' : 'Per Seat' ?></span>
                                            <span class="price-val">
                                                <?= formatMoney($trip['service_type'] === 'delivery' ? $trip['delivery_price'] : $trip['price_per_seat']) ?>
                                            </span>
                                        </div>
                                        <?php if ($trip['available_seats'] > 0): ?>
                                            <span class="seats-badge">💺 <?= (int) $trip['available_seats'] ?> seats left</span>
                                        <?php else: ?>
                                            <span class="status-badge badge-warning">Parcel Only</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="trip-actions">
                                        <a class="button button-primary button-full" href="book.php?trip_id=<?= (int) $trip['id'] ?>&type=<?= $trip['service_type'] === 'delivery' ? 'delivery' : 'travel' ?>">
                                            <?= $trip['service_type'] === 'delivery' ? 'Send Parcel →' : 'Book Seat →' ?>
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div style="text-align: center; margin-top: 36px;">
                        <a class="button button-secondary button-lg" href="travels.php">
                            Explore All Scheduled Travels & Routes →
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Section: Dual Value Proposition (Travels + Deliveries) -->
        <section class="section section-alt">
            <div class="container">
                <div class="section-header">
                    <span class="section-eyebrow">Two Core Pillars</span>
                    <h2 class="section-title">Built for Travels & Deliveries Alike</h2>
                    <p class="section-subtitle">Whether you have people to transport or parcels to dispatch, ZaRide makes peer mobility seamless.</p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 28px;">
                    <!-- Card 1: Passenger Travel -->
                    <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 34px 28px; box-shadow: var(--shadow-sm);">
                        <div style="width: 54px; height: 54px; border-radius: var(--radius-md); background: #e0f2fe; color: #0284c7; display: grid; place-items: center; font-size: 26px; margin-bottom: 20px;">
                            🚗
                        </div>
                        <h3 style="font-size: 22px; font-weight: 800; margin-bottom: 12px; color: var(--ink);">
                            Passenger Carpooling & Travel
                        </h3>
                        <p style="color: var(--muted); font-size: 15px; line-height: 1.6; margin-bottom: 20px;">
                            Skip crowded bus parks and unpredictable taxi prices. Match with fellow commuters and intercity drivers heading in your direction.
                        </p>
                        <ul style="list-style: none; padding: 0; margin: 0 0 24px; display: flex; flex-direction: column; gap: 10px; font-size: 14px;">
                            <li>✓ <strong>Reserve Guaranteed Seats</strong> in air-conditioned vehicles</li>
                            <li>✓ <strong>Predictable Departure Times</strong> and convenient pickup points</li>
                            <li>✓ <strong>Direct Communication</strong> via WhatsApp & in-app chat</li>
                        </ul>
                        <a class="button button-outline button-full" href="travels.php">Find a Travel Ride →</a>
                    </div>

                    <!-- Card 2: Parcel Deliveries -->
                    <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 34px 28px; box-shadow: var(--shadow-sm);">
                        <div style="width: 54px; height: 54px; border-radius: var(--radius-md); background: #dcfce7; color: #16a34a; display: grid; place-items: center; font-size: 26px; margin-bottom: 20px;">
                            📦
                        </div>
                        <h3 style="font-size: 22px; font-weight: 800; margin-bottom: 12px; color: var(--ink);">
                            Peer Parcel & Courier Delivery
                        </h3>
                        <p style="color: var(--muted); font-size: 15px; line-height: 1.6; margin-bottom: 20px;">
                            Need to dispatch documents, gifts, or merchandise? Harness peer drivers and motorcycles traveling along that exact route.
                        </p>
                        <ul style="list-style: none; padding: 0; margin: 0 0 24px; display: flex; flex-direction: column; gap: 10px; font-size: 14px;">
                            <li>✓ <strong>Same-day Local Motorcycle Dispatch</strong> for urgent parcels</li>
                            <li>✓ <strong>Intercity Trunk Cargo</strong> in cars, SUVs, and vans</li>
                            <li>✓ <strong>Post Your Custom Budget</strong> and let nearby riders accept</li>
                        </ul>
                        <a class="button button-emerald button-full" href="deliveries.php">Send a Package Now →</a>
                    </div>

                    <!-- Card 3: For Drivers & Riders -->
                    <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 34px 28px; box-shadow: var(--shadow-sm);">
                        <div style="width: 54px; height: 54px; border-radius: var(--radius-md); background: #fef3c7; color: #d97706; display: grid; place-items: center; font-size: 26px; margin-bottom: 20px;">
                            💰
                        </div>
                        <h3 style="font-size: 22px; font-weight: 800; margin-bottom: 12px; color: var(--ink);">
                            For Drivers: Monetize Empty Space
                        </h3>
                        <p style="color: var(--muted); font-size: 15px; line-height: 1.6; margin-bottom: 20px;">
                            Already traveling from Lagos to Ibadan or commuting from the Mainland to Island? Turn empty seats and trunk space into income.
                        </p>
                        <ul style="list-style: none; padding: 0; margin: 0 0 24px; display: flex; flex-direction: column; gap: 10px; font-size: 14px;">
                            <li>✓ <strong>Cover 100% of Your Fuel</strong> and vehicle operating costs</li>
                            <li>✓ <strong>Set Your Own Schedule</strong> and choose which requests to accept</li>
                            <li>✓ <strong>Keep Every Naira</strong> — direct peer-to-peer payout</li>
                        </ul>
                        <a class="button button-dark button-full" href="#register">Register as a Driver / Rider →</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Section: How It Works -->
        <section class="section">
            <div class="container">
                <div class="section-header">
                    <span class="section-eyebrow">Seamless Experience</span>
                    <h2 class="section-title">How ZaRide Works</h2>
                    <p class="section-subtitle">Simple, peer-driven, and designed for speed and trust.</p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; text-align: center;">
                    <div style="padding: 24px;">
                        <div style="width: 56px; height: 56px; margin: 0 auto 18px; border-radius: var(--radius-full); background: var(--primary-light); color: var(--primary); font-size: 22px; font-weight: 800; display: grid; place-items: center;">1</div>
                        <h4 style="font-size: 18px; font-weight: 800; margin-bottom: 8px;">Find or Post a Route</h4>
                        <p style="color: var(--muted); font-size: 14px;">Browse active travels and delivery trips, or post an open request stating your desired route and budget.</p>
                    </div>

                    <div style="padding: 24px;">
                        <div style="width: 56px; height: 56px; margin: 0 auto 18px; border-radius: var(--radius-full); background: var(--primary-light); color: var(--primary); font-size: 22px; font-weight: 800; display: grid; place-items: center;">2</div>
                        <h4 style="font-size: 18px; font-weight: 800; margin-bottom: 8px;">Connect Directly</h4>
                        <p style="color: var(--muted); font-size: 14px;">Coordinate pickup landmarks, timing, and package details via in-app chat, direct telephone, or WhatsApp.</p>
                    </div>

                    <div style="padding: 24px;">
                        <div style="width: 56px; height: 56px; margin: 0 auto 18px; border-radius: var(--radius-full); background: var(--primary-light); color: var(--primary); font-size: 22px; font-weight: 800; display: grid; place-items: center;">3</div>
                        <h4 style="font-size: 18px; font-weight: 800; margin-bottom: 8px;">Travel & Rate Your Peer</h4>
                        <p style="color: var(--muted); font-size: 14px;">Travel comfortably or have your parcel delivered safely. Pay agreed fare directly and leave a review to build community trust.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Section: Registration (Dual Role) -->
        <section class="section section-alt" id="register">
            <div class="container" style="max-width: 960px;">
                <div class="section-header">
                    <span class="section-eyebrow">Get Started in Seconds</span>
                    <h2 class="section-title">Create Your ZaRide Account</h2>
                    <p class="section-subtitle">Join thousands of travelers, dispatchers, and vehicle owners across Nigeria.</p>
                </div>

                <div class="trip-grid" style="grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));">
                    <!-- Customer Form -->
                    <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 32px 28px; box-shadow: var(--shadow-sm);">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 18px;">
                            <span style="width: 44px; height: 44px; border-radius: var(--radius-md); background: #dbeafe; color: #1d4ed8; display: grid; place-items: center; font-size: 22px;">👤</span>
                            <div>
                                <span class="hero-eyebrow" style="background: #eff6ff; color: #1d4ed8; margin: 0; padding: 2px 8px;">For Passengers & Senders</span>
                                <h3 style="font-size: 20px; font-weight: 800; margin: 0;">Customer Account</h3>
                            </div>
                        </div>
                        <p style="font-size: 13px; color: var(--muted); margin-bottom: 20px;">Book passenger travels, request express deliveries, and track journeys.</p>

                        <form method="post" action="index.php#register">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="account_type" value="customer">

                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input class="form-control" name="full_name" placeholder="e.g. Amaka Johnson" required maxlength="120">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input class="form-control" name="email" type="email" placeholder="name@example.com" required maxlength="254">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Phone Number</label>
                                <input class="form-control" name="phone" type="tel" placeholder="e.g. 08012345678" required maxlength="25">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Primary City</label>
                                <input class="form-control" name="city" placeholder="e.g. Lagos, Abuja, Ibadan" value="Lagos" required maxlength="100">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Password</label>
                                <input class="form-control" name="password" type="password" minlength="8" placeholder="At least 8 characters" required>
                            </div>

                            <button class="button button-primary button-full button-lg" type="submit" style="margin-top: 10px;">
                                Register as Customer →
                            </button>
                        </form>
                    </div>

                    <!-- Rider / Driver Form -->
                    <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 32px 28px; box-shadow: var(--shadow-sm);">
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 18px;">
                            <span style="width: 44px; height: 44px; border-radius: var(--radius-md); background: #dcfce7; color: #15803d; display: grid; place-items: center; font-size: 22px;">🛵</span>
                            <div>
                                <span class="hero-eyebrow" style="background: #f0fdf4; color: #15803d; margin: 0; padding: 2px 8px;">For Drivers & Dispatchers</span>
                                <h3 style="font-size: 20px; font-weight: 800; margin: 0;">Rider Account</h3>
                            </div>
                        </div>
                        <p style="font-size: 13px; color: var(--muted); margin-bottom: 20px;">Offer seats on your trips, deliver parcels, and earn on your schedule.</p>

                        <form method="post" action="index.php#register">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="account_type" value="rider">

                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input class="form-control" name="full_name" placeholder="e.g. David Adeyemi" required maxlength="120">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input class="form-control" name="email" type="email" placeholder="rider@example.com" required maxlength="254">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Phone Number (Calls & WhatsApp)</label>
                                <input class="form-control" name="phone" type="tel" placeholder="e.g. 08023456789" required maxlength="25">
                            </div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Services Offered</label>
                                    <select class="form-control" name="services_offered">
                                        <option value="both" selected>Travels & Deliveries</option>
                                        <option value="travel">Passenger Travel Only</option>
                                        <option value="delivery">Parcel Delivery Only</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Vehicle Type</label>
                                    <input class="form-control" name="vehicle_type" type="text" maxlength="100" placeholder="e.g. Car, Motorcycle, Scooter" value="car" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Password</label>
                                <input class="form-control" name="password" type="password" minlength="8" placeholder="At least 8 characters" required>
                            </div>

                            <button class="button button-emerald button-full button-lg" type="submit" style="margin-top: 10px;">
                                Register as Rider / Driver →
                            </button>
                        </form>
                    </div>
                </div>

                <p style="text-align: center; margin-top: 24px; color: var(--muted); font-size: 13px;">
                    Already have an account? <a href="login.php" style="color: var(--primary); font-weight: 700;">Sign in to your account</a>
                </p>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="footer-top">
            <div>
                <a class="brand" href="index.php">
                    <span class="brand-mark">Z</span>
                    <div class="brand-text">
                        <span class="brand-title">ZaRide</span>
                        <span class="brand-tag">P2P Network</span>
                    </div>
                </a>
                <p class="footer-brand-desc">
                    Connecting local journeys, passenger carpools, and peer parcel deliveries across Nigeria. Safe, direct, transparent.
                </p>
            </div>
            <div class="footer-col">
                <h4>Mobility</h4>
                <ul class="footer-links">
                    <li><a href="travels.php">Find Passenger Travels</a></li>
                    <li><a href="deliveries.php">Send Parcel / Delivery</a></li>
                    <li><a href="zaride.php">Directory of Riders</a></li>
                    <li><a href="travels.php#post-request">Post Travel Request</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Drivers & Riders</h4>
                <ul class="footer-links">
                    <li><a href="index.php#register">Register as Driver</a></li>
                    <li><a href="dashboard.php?type=rider">Rider Dashboard</a></li>
                    <li><a href="deliveries.php">Claim Delivery Requests</a></li>
                    <li><a href="login.php?type=rider">Driver Sign In</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Safety & Trust</h4>
                <ul class="footer-links">
                    <li><a href="zaride.php">Verified Peer Profiles</a></li>
                    <li><a href="login.php">Secure Authentication</a></li>
                    <li><a href="#">Privacy & Terms</a></li>
                    <li><a href="#">Contact Support</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network. All rights reserved.</span>
            <span>Direct P2P Travel & Courier Platform.</span>
        </div>
    </footer>

    <script>
    function switchConsoleMode(mode) {
        document.getElementById('btn-tab-travel').classList.remove('active');
        document.getElementById('btn-tab-delivery').classList.remove('active');
        document.getElementById('btn-tab-riders').classList.remove('active');

        document.getElementById('console-form-travel').style.display = 'none';
        document.getElementById('console-form-delivery').style.display = 'none';
        document.getElementById('console-form-riders').style.display = 'none';

        if (mode === 'travel') {
            document.getElementById('btn-tab-travel').classList.add('active');
            document.getElementById('console-form-travel').style.display = 'grid';
        } else if (mode === 'delivery') {
            document.getElementById('btn-tab-delivery').classList.add('active');
            document.getElementById('console-form-delivery').style.display = 'grid';
        } else if (mode === 'riders') {
            document.getElementById('btn-tab-riders').classList.add('active');
            document.getElementById('console-form-riders').style.display = 'grid';
        }
    }
    </script>
    <?= renderMobileBottomNav('home') ?>
</body>
</html>
