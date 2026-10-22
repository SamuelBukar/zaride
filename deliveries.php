<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$origin = trim($_GET['origin'] ?? '');
$destination = trim($_GET['destination'] ?? '');
$vehicle = trim($_GET['vehicle'] ?? '');

// 1. Fetch active delivery routes posted by riders
$sqlTrips = "SELECT t.*, r.full_name as rider_name, r.phone as rider_phone, r.whatsapp as rider_whatsapp,
                    r.rating_avg, r.rating_count, r.trips_completed, r.vehicle_type, r.vehicle_model,
                    r.plate_number, r.status as rider_status, r.verification_status
             FROM trips t
             JOIN riders r ON t.rider_id = r.id
             WHERE t.service_type IN ('delivery', 'both')
               AND t.status = 'active'
               AND t.departure_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND r.account_status = 'active' AND r.deleted_at IS NULL
               AND r.verification_status = 'approved'";

$paramsTrips = [];
if ($origin !== '') {
    $sqlTrips .= " AND (t.origin_city LIKE ? OR t.origin_address LIKE ?)";
    $paramsTrips[] = "%{$origin}%";
    $paramsTrips[] = "%{$origin}%";
}
if ($destination !== '') {
    $sqlTrips .= " AND (t.destination_city LIKE ? OR t.destination_address LIKE ?)";
    $paramsTrips[] = "%{$destination}%";
    $paramsTrips[] = "%{$destination}%";
}
if ($vehicle !== '') {
    $sqlTrips .= " AND r.vehicle_type = ?";
    $paramsTrips[] = $vehicle;
}
$sqlTrips .= " ORDER BY t.departure_time ASC LIMIT 30";

$deliveryTrips = [];
try {
    $stmt = db()->prepare($sqlTrips);
    $stmt->execute($paramsTrips);
    $deliveryTrips = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Delivery trips query failed: ' . $e->getMessage());
}

// 2. Fetch open delivery requests posted by customers
$openRequests = [];
try {
    $liveSearch = nearbySearchLiveSql('req');
    $reqStmt = db()->query(
        "SELECT req.*, c.full_name as customer_name, c.phone as customer_phone
         FROM requests req
         JOIN customers c ON req.customer_id = c.id
         WHERE req.request_type = 'delivery' AND req.status = 'open'
           AND {$liveSearch}
         ORDER BY req.created_at DESC LIMIT 30"
    );
    $openRequests = $reqStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Open requests query failed: ' . $e->getMessage());
}

$currentAcc = currentAccount();
$flash = getFlash();
$openDelivery = null;
if ($currentAcc && ($currentAcc['account_type'] ?? '') === 'customer') {
    try {
        $ownStmt = db()->prepare(
            "SELECT *,
                    UNIX_TIMESTAMP(COALESCE(search_started_at, created_at)) AS search_started_unix,
                    UNIX_TIMESTAMP(NOW()) AS server_unix
             FROM requests
             WHERE customer_id = ? AND request_type = 'delivery' AND status = 'open'
             ORDER BY id DESC LIMIT 1"
        );
        $ownStmt->execute([(int) $currentAcc['id']]);
        $openDelivery = $ownStmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log('Open delivery lookup failed: ' . $e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | P2P Parcel & Package Deliveries</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">P2P Logistics</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="travels.php">🚗 Find Travels</a>
            <a class="nav-link active" href="deliveries.php">📦 Deliveries</a>
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
        <!-- Page Hero -->
        <section style="background: linear-gradient(135deg, #093b37 0%, #065f46 100%); color: white; padding: 48px 0 38px;">
            <div class="container">
                <span class="hero-eyebrow" style="background: rgba(255,255,255,0.15); color: #a7f3d0;">Peer-to-Peer Parcel Dispatch</span>
                <h1 style="font-size: clamp(28px, 4vw, 42px); font-weight: 800; margin-bottom: 12px; letter-spacing: -1px;">
                    Send Packages with Verified Peer Riders
                </h1>
                <p style="color: #d1fae5; max-width: 650px; font-size: 16px; margin-bottom: 0;">
                    From urgent document dispatch on motorcycles to trunk-capacity parcel transit in cars and vans. Direct peer rates without high corporate logistics markups.
                </p>
            </div>
        </section>

        <div class="container" style="padding-top: 28px; padding-bottom: 70px;">
            <?php if ($flash): ?>
                <div class="notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <!-- Filter Console -->
            <form method="get" action="deliveries.php" class="filter-bar">
                <div class="filter-group" style="flex: 1;">
                    <input class="console-field" style="height: 40px; min-width: 180px;" type="text" name="origin" placeholder="Pickup (e.g. Sabon Gari, Zaria)" value="<?= e($origin) ?>">
                    <input class="console-field" style="height: 40px; min-width: 180px;" type="text" name="destination" placeholder="Drop-off (e.g. Samaru, Zaria)" value="<?= e($destination) ?>">
                    <select class="filter-select" name="vehicle">
                        <option value="">Vehicle: Any</option>
                        <option value="motorcycle" <?= $vehicle === 'motorcycle' ? 'selected' : '' ?>>🏍️ Motorcycle (Express Dispatch)</option>
                        <option value="car" <?= $vehicle === 'car' ? 'selected' : '' ?>>🚗 Car (Trunk Box)</option>
                        <option value="van" <?= $vehicle === 'van' ? 'selected' : '' ?>>🚐 Van / Large Cargo</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button class="button button-sm button-primary" type="submit">Filter Deliveries</button>
                    <?php if ($origin || $destination || $vehicle): ?>
                        <a class="button button-sm button-secondary" href="deliveries.php">Clear</a>
                    <?php endif; ?>
                    <a class="button button-sm button-emerald" href="#send-package">📦 Send a Package Now</a>
                </div>
            </form>

            <!-- SECTION 1: Active Delivery Trips by Riders -->
            <div style="margin-bottom: 48px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px;">
                    <div>
                        <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary); margin-bottom: 4px;">En Route & Scheduled</span>
                        <h2 style="font-size: 22px; font-weight: 800; margin: 0;">Riders Heading Your Way with Cargo Space</h2>
                    </div>
                </div>

                <?php if ($deliveryTrips === []): ?>
                    <div class="empty-box" style="padding: 40px 20px;">
                        <span class="empty-icon">📦🛵</span>
                        <h3>No direct delivery trips listed for this route right now</h3>
                        <p>Post a Delivery Request below so online dispatch riders in your area can pick it up!</p>
                        <a class="button button-primary" href="#send-package">Post a Delivery Request →</a>
                    </div>
                <?php else: ?>
                    <div class="delivery-grid">
                        <?php foreach ($deliveryTrips as $trip): ?>
                            <article class="trip-card">
                                <div>
                                    <div class="trip-topline">
                                        <div class="trip-rider">
                                            <div class="rider-avatar">
                                                <?= strtoupper(substr($trip['rider_name'], 0, 1)) ?>
                                                <?php if ($trip['rider_status'] === 'online'): ?>
                                                    <span class="online-dot" title="Rider is Online"></span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="rider-meta-name"><?= e($trip['rider_name']) ?> <?= renderVerifiedTag($trip) ?></div>
                                                <div class="rider-submeta">
                                                    <?= renderRatingStars((float) $trip['rating_avg'], (int) $trip['rating_count']) ?>
                                                    <span>· <?= (int) $trip['trips_completed'] ?> deliveries</span>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="service-chip chip-delivery">
                                            <?= renderVehicleIcon($trip['vehicle_type']) ?> <?= e(ucfirst($trip['vehicle_type'])) ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($trip['vehicle_model']) || !empty($trip['plate_number'])): ?>
                                        <div style="font-size: 12px; color: var(--muted); margin: -4px 0 10px;">
                                            <?= !empty($trip['vehicle_model']) ? '🚘 ' . e($trip['vehicle_model']) : '' ?>
                                            <?= !empty($trip['plate_number']) ? (!empty($trip['vehicle_model']) ? ' · ' : '') . 'Plate: ' . e($trip['plate_number']) : '' ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="route-timeline">
                                        <div class="route-point">
                                            <div class="route-time"><?= formatDateTime($trip['departure_time']) ?></div>
                                            <div class="route-address"><?= e($trip['origin_address']) ?></div>
                                            <div class="route-city"><?= e($trip['origin_city']) ?></div>
                                        </div>
                                        <div class="route-point destination">
                                            <div class="route-time" style="color: #ef4444;">Drop-off Zone</div>
                                            <div class="route-address"><?= e($trip['destination_address']) ?></div>
                                            <div class="route-city"><?= e($trip['destination_city']) ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($trip['cargo_capacity_desc'])): ?>
                                        <div style="font-size: 13px; color: var(--ink); margin: 10px 0; background: var(--surface-alt); padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--line);">
                                            📦 <strong>Cargo Space:</strong> <?= e($trip['cargo_capacity_desc']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <div class="trip-pricing-bar">
                                        <div class="price-item">
                                            <span class="price-label">Delivery Fee</span>
                                            <span class="price-val"><?= formatMoney($trip['delivery_price'] > 0 ? $trip['delivery_price'] : 1500) ?></span>
                                        </div>
                                        <span class="status-badge badge-success">Ready for Parcel</span>
                                    </div>

                                    <div class="trip-actions">
                                        <a class="button button-primary button-full" href="book.php?trip_id=<?= (int) $trip['id'] ?>&type=delivery">
                                            Send Parcel with this Rider →
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SECTION 2: Open Customer Delivery Requests -->
            <div style="margin-bottom: 48px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px;">
                    <div>
                        <span class="hero-eyebrow" style="background: #fef3c7; color: #b45309; margin-bottom: 4px;">Live Request Feed</span>
                        <h2 style="font-size: 22px; font-weight: 800; margin: 0;">Parcels Waiting for Riders / Dispatchers</h2>
                    </div>
                    <?php if ($currentAcc && $currentAcc['account_type'] === 'rider'): ?>
                        <span class="status-badge badge-info">💡 As a Rider, you can accept these requests!</span>
                    <?php endif; ?>
                </div>

                <?php if ($openRequests === []): ?>
                    <div class="empty-box" style="padding: 30px;">
                        <p style="margin: 0; color: var(--muted);">All customer packages have been accepted! Check back soon or post a new package below.</p>
                    </div>
                <?php else: ?>
                    <div class="request-grid">
                        <?php foreach ($openRequests as $req): ?>
                            <div class="request-card">
                                <div>
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                                        <span class="status-badge badge-amber"><span class="badge-dot"></span>Open Package</span>
                                        <span style="font-size: 16px; font-weight: 800; color: var(--primary);"><?= formatMoney($req['budget_amount']) ?></span>
                                    </div>

                                    <div style="font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 6px;">
                                        📦 <?= e($req['package_details'] ?: 'Package Delivery') ?>
                                    </div>
                                    <div style="font-size: 12px; color: var(--muted); margin-bottom: 12px;">
                                        Size: <strong style="color: var(--ink);"><?= e(ucfirst($req['package_weight_category'] ?? 'standard')) ?></strong> · Posted <?= timeAgo($req['created_at']) ?>
                                    </div>

                                    <div style="font-size: 13px; line-height: 1.4; color: var(--ink-light); margin-bottom: 12px;">
                                        <div>📍 <strong>Pickup:</strong> <?= e($req['origin']) ?></div>
                                        <div>🎯 <strong>Drop-off:</strong> <?= e($req['destination']) ?></div>
                                        <?php if (!empty($req['recipient_name'])): ?>
                                            <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">Recipient: <?= e($req['recipient_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line-subtle);">
                                    <?php if ($currentAcc && $currentAcc['account_type'] === 'rider'): ?>
                                        <form method="post" action="request-action.php">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                            <input type="hidden" name="action" value="accept_request">
                                            <button class="button button-emerald button-full" type="submit">
                                                ✓ Accept & Deliver This (<?= formatMoney($req['budget_amount']) ?>)
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; color: var(--muted);">
                                            <span>Sender: <?= e($req['customer_name']) ?></span>
                                            <span class="status-badge badge-sky">Awaiting Driver</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SECTION 3: Post a Delivery Request Form -->
            <section id="send-package" style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 36px; box-shadow: var(--shadow-sm);">
                <div style="max-width: 640px; margin: 0 auto; text-align: center; margin-bottom: 28px;">
                    <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary);">Quick Dispatch Form</span>
                    <h2 style="font-size: 26px; font-weight: 800; margin-bottom: 8px;">Need to Send a Parcel?</h2>
                    <p style="color: var(--muted); font-size: 15px;">Fill in your package and location details. Online riders and travelers on your route will be alerted to claim your delivery.</p>
                </div>

                <?php if ($openDelivery): ?>
                    <div style="max-width: 720px; margin: 0 auto;">
                        <?= renderLiveSearchBox($openDelivery, 'delivery') ?>
                        <?= renderLiveSearchScript() ?>
                    </div>
                <?php else: ?>
                <form method="post" action="book.php" style="max-width: 720px; margin: 0 auto;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="post_request">
                    <input type="hidden" name="request_type" value="delivery">

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="del-origin">Pickup Location & Landmark</label>
                            <input class="form-control" id="del-origin" name="origin" placeholder="e.g. 14 Sokoto Road, Sabon Gari, Zaria" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="del-destination">Drop-off Destination</label>
                            <input class="form-control" id="del-destination" name="destination" placeholder="e.g. 25 Kofar Doka Road, Zaria City" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="del-details">What are you sending? (Package Description)</label>
                        <input class="form-control" id="del-details" name="package_details" placeholder="e.g. Sealed corporate documents, clothing bag, electronics box" required>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="del-weight">Package Weight / Category</label>
                            <select class="form-control" id="del-weight" name="package_weight_category" required>
                                <option value="small">Small (Envelope, Documents, Keys, Phone) · under 2kg</option>
                                <option value="medium" selected>Medium (Standard Box, Shoebox, Bag) · 2kg to 10kg</option>
                                <option value="large">Large (Multiple Boxes, Suitcase) · 10kg to 25kg</option>
                                <option value="heavy">Heavy / Bulky (Cargo, Appliances) · 25kg+</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="del-budget">Offered Delivery Budget (₦)</label>
                            <input class="form-control" id="del-budget" name="budget_amount" type="number" step="100" min="500" placeholder="e.g. 2500" required>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="del-recipient-name">Recipient Name</label>
                            <input class="form-control" id="del-recipient-name" name="recipient_name" placeholder="Name of person receiving" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="del-recipient-phone">Recipient Phone Number</label>
                            <input class="form-control" id="del-recipient-phone" name="recipient_phone" type="tel" placeholder="e.g. 08012345678" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="del-time">Preferred Pickup Time</label>
                        <input class="form-control" id="del-time" name="preferred_time" type="datetime-local" required>
                    </div>

                    <div style="text-align: center; margin-top: 14px;">
                        <?php if ($currentAcc && $currentAcc['account_type'] === 'customer'): ?>
                            <button class="button button-emerald button-lg" type="submit">Post Delivery Request →</button>
                        <?php elseif ($currentAcc && $currentAcc['account_type'] === 'rider'): ?>
                            <div class="notice notice-error" style="display: inline-block;">You are signed in as a Rider. Sign in as a Customer to post delivery requests.</div>
                        <?php else: ?>
                            <a class="button button-emerald button-lg" href="login.php?type=customer">Sign in as Customer to Post Package →</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network.</span>
            <span>Local and Intercity Parcel Dispatch.</span>
        </div>
    </footer>
    <?= renderMobileBottomNav('deliveries') ?>
</body>
</html>
