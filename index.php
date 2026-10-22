<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Fetch live active trips for homepage feature
$featuredTrips = [];
try {
    $tripStmt = db()->query(
        "SELECT t.*, r.full_name as rider_name, r.rating_avg, r.rating_count, r.vehicle_type, r.vehicle_model, r.status as rider_status, r.verification_status
         FROM trips t
         JOIN riders r ON t.rider_id = r.id
         WHERE t.status = 'active' AND t.departure_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
           AND r.account_status = 'active' AND r.deleted_at IS NULL
           AND r.verification_status = 'approved'
         ORDER BY t.departure_time ASC LIMIT 6"
    );
    $featuredTrips = $tripStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Featured trips query error: ' . $e->getMessage());
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
            <a class="nav-link" href="nearby.php">📍 Nearby</a>
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
                <a class="button button-sm button-primary" href="register.php">Join ZaRide</a>
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
                <h1>Travel Together. Send Parcels.</h1>
                <p class="hero-desc">
                    Connect directly with local drivers and dispatchers. Share rides for affordable commuting, or send packages with trusted peers heading your way.
                </p>
                <div class="hero-actions">
                    <a class="button button-emerald button-lg" href="nearby.php">📍 Nearby ride now (short trips)</a>
                </div>

                <!-- Interactive Search Console -->
                <div class="search-console">
                    <div class="console-tabs">
                        <button type="button" class="console-tab active" id="btn-tab-travel" onclick="switchConsoleMode('travel')">
                            <span class="console-tab-icon" aria-hidden="true">🚗</span>
                            <span class="console-tab-label">Passenger Travel</span>
                        </button>
                        <button type="button" class="console-tab" id="btn-tab-delivery" onclick="switchConsoleMode('delivery')">
                            <span class="console-tab-icon" aria-hidden="true">📦</span>
                            <span class="console-tab-label">Package Delivery</span>
                        </button>
                        <button type="button" class="console-tab" id="btn-tab-riders" onclick="switchConsoleMode('riders')">
                            <span class="console-tab-icon" aria-hidden="true">🛵</span>
                            <span class="console-tab-label">Online Riders</span>
                        </button>
                    </div>

                    <!-- Mode 1: Passenger Travel Search -->
                    <form method="get" action="travels.php" class="console-form" id="console-form-travel">
                        <div class="console-input-wrap">
                            <label class="console-label">Departure From</label>
                            <input class="console-field" name="origin" placeholder="City or landmark (e.g. Sabon Gari, Zaria)" required>
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Destination To</label>
                            <input class="console-field" name="destination" placeholder="Destination (e.g. ABU Samaru, Zaria)" required>
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
                            <input class="console-field" name="origin" placeholder="Pickup spot (e.g. Tudun Wada, Zaria)" required>
                        </div>
                        <div class="console-input-wrap">
                            <label class="console-label">Drop-off Destination</label>
                            <input class="console-field" name="destination" placeholder="Drop-off point (e.g. Kwangila, Zaria)" required>
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
                            <input class="console-field" name="city" placeholder="City (e.g. Zaria, Kaduna)">
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
                        <span class="console-stat-pill">💬 <strong>Instant Chat</strong> — Coordinate in the ZaRide chat</span>
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
                                                <div class="rider-meta-name"><?= e($trip['rider_name']) ?> <?= renderVerifiedTag($trip) ?></div>
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

        <!-- Section: How It Works -->
        <section class="section">
            <div class="container">
                <div class="section-header">
                    <span class="section-eyebrow">Seamless Experience</span>
                    <h2 class="section-title">How ZaRide Works</h2>
                    <p class="section-subtitle">Simple, peer-driven, and designed for speed and trust.</p>
                </div>

                <div class="steps-grid">
                    <div style="padding: 24px;">
                        <div style="width: 56px; height: 56px; margin: 0 auto 18px; border-radius: var(--radius-full); background: var(--primary-light); color: var(--primary); font-size: 22px; font-weight: 800; display: grid; place-items: center;">1</div>
                        <h4 style="font-size: 18px; font-weight: 800; margin-bottom: 8px;">Find or Post a Route</h4>
                        <p style="color: var(--muted); font-size: 14px;">Browse active travels and delivery trips, or post an open request stating your desired route and budget.</p>
                    </div>

                    <div style="padding: 24px;">
                        <div style="width: 56px; height: 56px; margin: 0 auto 18px; border-radius: var(--radius-full); background: var(--primary-light); color: var(--primary); font-size: 22px; font-weight: 800; display: grid; place-items: center;">2</div>
                        <h4 style="font-size: 18px; font-weight: 800; margin-bottom: 8px;">Connect Directly</h4>
                        <p style="color: var(--muted); font-size: 14px;">Coordinate pickup landmarks, timing, and package details in the in-app chat (or by phone call).</p>
                    </div>

                    <div style="padding: 24px;">
                        <div style="width: 56px; height: 56px; margin: 0 auto 18px; border-radius: var(--radius-full); background: var(--primary-light); color: var(--primary); font-size: 22px; font-weight: 800; display: grid; place-items: center;">3</div>
                        <h4 style="font-size: 18px; font-weight: 800; margin-bottom: 8px;">Travel & Rate Your Peer</h4>
                        <p style="color: var(--muted); font-size: 14px;">Travel comfortably or have your parcel delivered safely. Pay agreed fare directly and leave a review to build community trust.</p>
                    </div>
                </div>
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
        </div>
        <div class="footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network. All rights reserved.</span>
            <span>Direct P2P Travel & Courier Platform.</span>
        </div>
    </footer>

    <script>
    if (window.location.hash === '#register') {
        window.location.replace('register.php');
    }
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
