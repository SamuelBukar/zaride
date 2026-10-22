<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$currentAcc = currentAccount();
$serviceFilter = trim($_GET['service'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'online');
$cityFilter = trim($_GET['city'] ?? '');
$vehicleFilter = trim($_GET['vehicle'] ?? '');

$sql = "SELECT r.*,
               (SELECT COUNT(t.id) FROM trips t WHERE t.rider_id = r.id AND t.status = 'active') as active_trips_count
        FROM riders r WHERE " . listedRiderSql('r');
$params = [];

if ($statusFilter === 'online') {
    $sql .= " AND r.status = 'online'";
}

if ($serviceFilter === 'travel') {
    $sql .= " AND r.services_offered IN ('travel', 'both')";
} elseif ($serviceFilter === 'delivery') {
    $sql .= " AND r.services_offered IN ('delivery', 'both')";
}

if ($cityFilter !== '') {
    $sql .= " AND r.city LIKE ?";
    $params[] = "%{$cityFilter}%";
}

if ($vehicleFilter !== '') {
    $sql .= " AND r.vehicle_type = ?";
    $params[] = $vehicleFilter;
}

$sql .= " ORDER BY r.status DESC, r.rating_avg DESC, r.trips_completed DESC LIMIT 50";

$riders = [];
$databaseError = false;

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $riders = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Rider directory failed: ' . $e->getMessage());
    $databaseError = true;
}

$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | Verified Peer Riders & Drivers Directory</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">Peer Network</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="travels.php">🚗 Find Travels</a>
            <a class="nav-link" href="deliveries.php">📦 Deliveries</a>
            <a class="nav-link active" href="zaride.php">👥 Riders</a>
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
        <section style="background: linear-gradient(135deg, #093b37 0%, #0f766e 100%); color: white; padding: 48px 0 38px;">
            <div class="container">
                <span class="hero-eyebrow" style="background: rgba(255,255,255,0.15); color: #a7f3d0;">Verified Community Directory</span>
                <h1 style="font-size: clamp(28px, 4vw, 42px); font-weight: 800; margin-bottom: 12px; letter-spacing: -1px;">
                    Peer Drivers & Dispatch Riders
                </h1>
                <p style="color: #d1fae5; max-width: 650px; font-size: 16px; margin-bottom: 0;">
                    Connect directly with verified local riders for travels and parcel deliveries. View ratings, vehicle specs, and active trips.
                </p>
            </div>
        </section>

        <div class="container" style="padding-top: 28px; padding-bottom: 70px;">
            <?php if ($flash): ?>
                <div class="notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <!-- Filter Bar -->
            <form method="get" action="zaride.php" class="filter-bar">
                <div class="filter-group" style="flex: 1;">
                    <select class="filter-select" name="status">
                        <option value="online" <?= $statusFilter === 'online' ? 'selected' : '' ?>>🟢 Online Now Only</option>
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Registered Riders</option>
                    </select>

                    <select class="filter-select" name="service">
                        <option value="">Services: All</option>
                        <option value="travel" <?= $serviceFilter === 'travel' ? 'selected' : '' ?>>🚗 Passenger Travels</option>
                        <option value="delivery" <?= $serviceFilter === 'delivery' ? 'selected' : '' ?>>📦 Parcel Delivery</option>
                    </select>

                    <input class="console-field" style="height: 38px; width: 170px;" type="text" name="city" placeholder="City (e.g. Zaria, Kaduna)" value="<?= e($cityFilter) ?>">

                    <select class="filter-select" name="vehicle">
                        <option value="">Vehicle: All</option>
                        <option value="car" <?= $vehicleFilter === 'car' ? 'selected' : '' ?>>🚗 Cars</option>
                        <option value="motorcycle" <?= $vehicleFilter === 'motorcycle' ? 'selected' : '' ?>>🏍️ Motorcycles</option>
                        <option value="suv" <?= $vehicleFilter === 'suv' ? 'selected' : '' ?>>🚙 SUVs</option>
                        <option value="van" <?= $vehicleFilter === 'van' ? 'selected' : '' ?>>🚐 Vans</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button class="button button-sm button-primary" type="submit">Filter Riders</button>
                    <?php if ($serviceFilter || $cityFilter || $vehicleFilter || $statusFilter !== 'online'): ?>
                        <a class="button button-sm button-secondary" href="zaride.php">Reset</a>
                    <?php endif; ?>
                </div>
            </form>

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 22px;">
                <h2 style="font-size: 20px; font-weight: 800; margin: 0;">
                    <?= count($riders) ?> <?= count($riders) === 1 ? 'Rider Available' : 'Riders Available' ?>
                </h2>
                <a class="button button-sm button-secondary" href="register.php?type=rider">Become a Rider →</a>
            </div>

            <?php if ($databaseError): ?>
                <div class="notice notice-error">Could not load the rider directory at this moment.</div>
            <?php elseif ($riders === []): ?>
                <div class="empty-box">
                    <span class="empty-icon">👥</span>
                    <h3>No riders matched your filters</h3>
                    <p>Try switching to "All Registered Riders" or clearing your city/service filter.</p>
                    <a class="button button-primary" href="zaride.php?status=all">View All Riders →</a>
                </div>
            <?php else: ?>
                <div class="rider-grid">
                    <?php foreach ($riders as $rider): ?>
                        <article class="trip-card">
                            <div>
                                <div class="trip-topline">
                                    <div class="trip-rider">
                                        <div class="rider-avatar">
                                            <?= strtoupper(substr($rider['full_name'], 0, 1)) ?>
                                            <?php if ($rider['status'] === 'online'): ?>
                                                <span class="online-dot" title="Online now"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="rider-meta-name"><?= e($rider['full_name']) ?> <?= renderVerifiedTag($rider) ?></div>
                                            <div class="rider-submeta">
                                                <?= renderRatingStars((float) $rider['rating_avg'], (int) $rider['rating_count']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span class="status-badge <?= $rider['status'] === 'online' ? 'badge-success' : 'badge-muted' ?>">
                                        <span class="badge-dot"></span><?= ucfirst($rider['status']) ?>
                                    </span>
                                </div>

                                <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px;">
                                    <?= renderServiceBadge($rider['services_offered'] ?? 'both') ?>
                                    <span class="service-chip" style="background: var(--surface-alt); color: var(--muted); border: 1px solid var(--line);">
                                        📍 <?= e($rider['city'] ?: 'Lagos') ?>
                                    </span>
                                </div>

                                <?php if (!empty($rider['bio'])): ?>
                                    <p style="font-size: 13px; color: var(--muted); line-height: 1.5; margin-bottom: 14px;">
                                        <?= e($rider['bio']) ?>
                                    </p>
                                <?php endif; ?>

                                <!-- Vehicle & Capacity Box -->
                                <div style="background: var(--surface-alt); border-radius: var(--radius-md); padding: 12px 14px; font-size: 13px; color: var(--ink); margin-bottom: 16px; border: 1px solid var(--line-subtle);">
                                    <?php $vehicleUrl = vehiclePhotoUrl($rider['vehicle_photo_path'] ?? null); ?>
                                    <?php if ($vehicleUrl !== null): ?>
                                        <img src="<?= e($vehicleUrl) ?>" alt="<?= e($rider['vehicle_model'] ?: 'Vehicle') ?>" class="vehicle-photo vehicle-photo-card">
                                    <?php endif; ?>
                                    <div style="font-weight: 700; margin-bottom: 4px;">
                                        <?= renderVehicleIcon($rider['vehicle_type'] ?? 'car') ?>
                                        <?= e($rider['vehicle_model'] ?: ucfirst($rider['vehicle_type'] ?? 'car')) ?>
                                        <?= !empty($rider['plate_number']) ? '· Plate: ' . e($rider['plate_number']) : '' ?>
                                    </div>
                                    <div class="spec-row">
                                        <span>💺 <?= (int) ($rider['seats_available'] ?? 4) ?> seats</span>
                                        <span>📦 <?= (int) ($rider['max_cargo_kg'] ?? 20) ?>kg cargo</span>
                                        <span>🏁 <?= (int) ($rider['trips_completed'] ?? 0) ?> trips</span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <!-- Actions -->
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <a class="button button-primary button-full" href="book.php?rider_id=<?= (int) $rider['id'] ?>&type=travel">
                                        Request Booking with Rider →
                                    </a>

                                    <div style="display: flex; gap: 8px;">
                                        <?php if ($currentAcc): ?>
                                            <a class="button button-sm button-secondary" style="flex: 1;" href="tel:<?= e($rider['phone']) ?>">
                                                📞 Call
                                            </a>
                                        <?php else: ?>
                                            <a class="button button-sm button-secondary button-full" href="login.php?type=customer">
                                                🔒 Sign in to book or call
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network.</span>
            <span>Direct P2P Directory.</span>
        </div>
    </footer>
    <?= renderMobileBottomNav('riders') ?>
</body>
</html>
