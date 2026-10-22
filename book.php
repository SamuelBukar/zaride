<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Handle POST actions first
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        setFlash('error', 'Session verification failed. Please try again.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Action 1: Post open customer request
    if ($action === 'post_request') {
        $customerId = requireAccount('customer');
        $customerAccount = currentAccount();
        if (is_array($customerAccount) && suspensionMessage($customerAccount) !== null) {
            setFlash('error', suspensionMessage($customerAccount));
            header('Location: dashboard.php?type=customer');
            exit;
        }
        $reqType = $_POST['request_type'] ?? 'travel';
        $origin = trim($_POST['origin'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $preferredTime = trim($_POST['preferred_time'] ?? '');
        $budget = (float) ($_POST['budget_amount'] ?? 0);
        $passengers = (int) ($_POST['passengers_count'] ?? 1);
        if ($reqType === 'travel' && ($passengers < 1 || $passengers > 15)) {
            setFlash('error', 'Enter a passenger count from 1 to 15.');
            header('Location: travels.php#post-request');
            exit;
        }
        $passengers = max(1, $passengers);
        $packageDetails = trim($_POST['package_details'] ?? '');
        $weightCategory = $_POST['package_weight_category'] ?? null;
        $recipientName = trim($_POST['recipient_name'] ?? '');
        $recipientPhone = trim($_POST['recipient_phone'] ?? '');

        if ($origin === '' || $destination === '' || $preferredTime === '' || $budget <= 0) {
            setFlash('error', 'Please fill in all required location, time, and budget fields.');
            header('Location: ' . ($reqType === 'delivery' ? 'deliveries.php#send-package' : 'travels.php#post-request'));
            exit;
        }

        try {
            if ($reqType === 'delivery') {
                $previous = db()->prepare(
                    "SELECT id FROM requests
                     WHERE customer_id = ? AND request_type = 'delivery' AND status = 'open'"
                );
                $previous->execute([$customerId]);
                foreach ($previous->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                    deleteTimedSearchRequest((int) $oldId);
                }
            }

            $stmt = db()->prepare(
                $reqType === 'delivery'
                    ? "INSERT INTO requests (customer_id, request_type, origin, destination, preferred_time,
                                              passengers_count, package_details, package_weight_category,
                                              recipient_name, recipient_phone, budget_amount, status, search_started_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW())"
                    : "INSERT INTO requests (customer_id, request_type, origin, destination, preferred_time,
                                              passengers_count, package_details, package_weight_category,
                                              recipient_name, recipient_phone, budget_amount, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')"
            );
            $stmt->execute([
                $customerId,
                $reqType,
                $origin,
                $destination,
                date('Y-m-d H:i:s', strtotime($preferredTime)),
                $passengers,
                $packageDetails ?: null,
                $weightCategory ?: null,
                $recipientName ?: null,
                $recipientPhone ?: null,
                $budget
            ]);

            if ($reqType === 'delivery') {
                $requestId = (int) db()->lastInsertId();
                $posted = db()->prepare("SELECT * FROM requests WHERE id = ?");
                $posted->execute([$requestId]);
                $postedRequest = $posted->fetch();
                if ($postedRequest) {
                    $notified = notifyRidersAboutDelivery($postedRequest);
                    notifyCustomerAboutDeliverySearch($customerId, $postedRequest, $notified);
                }
                setFlash('success', 'Your parcel is posted. We will search for a rider for the next 5 minutes.');
                header('Location: deliveries.php#send-package');
                exit;
            }

            setFlash('success', 'Your request has been published! Online riders will be alerted and can accept it.');
            header('Location: dashboard.php?type=customer');
            exit;
        } catch (PDOException $e) {
            error_log('Failed to post request: ' . $e->getMessage());
            setFlash('error', 'Could not create request. Please try again.');
            header('Location: ' . ($reqType === 'delivery' ? 'deliveries.php' : 'travels.php'));
            exit;
        }
    }

    // Action 2: Confirm trip booking
    if ($action === 'confirm_booking') {
        $customerId = requireAccount('customer');
        $customerAccount = currentAccount();
        if (is_array($customerAccount) && suspensionMessage($customerAccount) !== null) {
            setFlash('error', suspensionMessage($customerAccount));
            header('Location: dashboard.php?type=customer');
            exit;
        }
        $tripId = (int) ($_POST['trip_id'] ?? 0);
        $riderId = (int) ($_POST['rider_id'] ?? 0);
        $bookingType = $_POST['booking_type'] ?? 'travel';
        $seats = max(1, (int) ($_POST['seats_booked'] ?? 1));
        $pickupLoc = trim($_POST['pickup_location'] ?? '');
        $dropoffLoc = trim($_POST['dropoff_location'] ?? '');
        $notes = trim($_POST['customer_notes'] ?? '');
        $packageDesc = trim($_POST['package_desc'] ?? '');

        // Fetch trip details to compute price and verify availability
        $trip = null;
        if ($tripId > 0) {
            $tStmt = db()->prepare("SELECT * FROM trips WHERE id = ? AND status = 'active'");
            $tStmt->execute([$tripId]);
            $trip = $tStmt->fetch();
        }

        if (!$trip && $tripId > 0) {
            setFlash('error', 'This trip is no longer available.');
            header('Location: travels.php');
            exit;
        }

        $riderTargetId = $trip ? (int) $trip['rider_id'] : $riderId;
        $bookedRider = fetchRiderById($riderTargetId);
        if ($bookedRider === null || riderWorkBlock($bookedRider) !== null || ($bookingType === 'travel' && ($bookedRider['verification_status'] ?? '') !== 'approved')) {
            setFlash('error', 'This rider is not available for that booking.');
            header('Location: ' . ($bookingType === 'delivery' ? 'deliveries.php' : 'travels.php'));
            exit;
        }
        $scheduledAt = $trip ? $trip['departure_time'] : date('Y-m-d H:i:s', strtotime('+2 hours'));

        if ($trip && $bookingType === 'travel') {
            if (($_POST['agree_total'] ?? '') !== '1') {
                setFlash('error', 'Agree to the total before requesting this ride.');
                header('Location: book.php?trip_id=' . $tripId . '&type=travel');
                exit;
            }
            $pickupLoc = trim((string) $trip['origin_address'] . (trim((string) ($trip['origin_city'] ?? '')) !== '' ? ', ' . $trip['origin_city'] : ''));
            $dropoffLoc = trim((string) $trip['destination_address'] . (trim((string) ($trip['destination_city'] ?? '')) !== '' ? ', ' . $trip['destination_city'] : ''));
            $notes = '';
        }

        if ($bookingType === 'travel') {
            if ($trip && $seats > $trip['available_seats']) {
                setFlash('error', 'Only ' . $trip['available_seats'] . ' seat(s) are remaining on this trip.');
                header('Location: book.php?trip_id=' . $tripId . '&type=travel');
                exit;
            }
            $fare = $trip ? ((float) $trip['price_per_seat'] * $seats) : 3000.00;
        } else {
            $fare = $trip ? ((float) $trip['delivery_price'] > 0 ? (float) $trip['delivery_price'] : 2000.00) : 2500.00;
        }

        if ($pickupLoc === '' || $dropoffLoc === '') {
            setFlash('error', 'Please provide exact pickup and drop-off points.');
            header('Location: book.php?trip_id=' . $tripId . '&type=' . $bookingType);
            exit;
        }

        try {
            db()->beginTransaction();

            $bStmt = db()->prepare(
                "INSERT INTO bookings (trip_id, customer_id, rider_id, booking_type, seats_booked,
                                       package_desc, pickup_location, dropoff_location, scheduled_at,
                                       fare_amount, status, customer_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
            );
            $bStmt->execute([
                $tripId > 0 ? $tripId : null,
                $customerId,
                $riderTargetId,
                $bookingType,
                $bookingType === 'travel' ? $seats : 0,
                $packageDesc ?: ($trip ? ($bookingType === 'travel' ? "Travel passenger(s)" : "Parcel dispatch") : "Direct booking"),
                $pickupLoc,
                $dropoffLoc,
                $scheduledAt,
                $fare,
                $notes ?: null
            ]);
            $bookingId = (int) db()->lastInsertId();

            // Deduct seats if travel trip
            if ($trip && $bookingType === 'travel') {
                $remSeats = max(0, $trip['available_seats'] - $seats);
                $newStatus = $remSeats === 0 ? 'fully_booked' : 'active';
                $upd = db()->prepare("UPDATE trips SET available_seats = ?, status = ? WHERE id = ?");
                $upd->execute([$remSeats, $newStatus, $tripId]);
            }

            // Insert initial greeting message in in-app chat
            $initMsg = $bookingType === 'travel'
                ? "Hello! I have requested to book {$seats} seat(s) for the journey from {$pickupLoc} to {$dropoffLoc}. Looking forward to connecting."
                : "Hello! I have booked a parcel delivery from {$pickupLoc} to {$dropoffLoc}. Item: " . ($packageDesc ?: 'Package') . ".";

            $mStmt = db()->prepare(
                "INSERT INTO messages (booking_id, sender_type, sender_id, message) VALUES (?, 'customer', ?, ?)"
            );
            $mStmt->execute([$bookingId, $customerId, $initMsg]);

            db()->commit();

            if ($trip && $bookingType === 'travel') {
                createNotification(
                    'rider',
                    $riderTargetId,
                    'posted_route_booked',
                    'Your posted route was accepted',
                    "This post came from you. A passenger accepted your route from {$pickupLoc} to {$dropoffLoc}.",
                    'dashboard.php?type=rider&tab=bookings',
                    'booking',
                    $bookingId
                );
            }

            if ($bookingType === 'delivery') {
                $sender = currentAccount();
                $senderName = is_array($sender) ? (string) ($sender['full_name'] ?? 'A customer') : 'A customer';
                $riderLabel = 'your rider';
                $nameStmt = db()->prepare('SELECT full_name FROM riders WHERE id = ?');
                $nameStmt->execute([$riderTargetId]);
                $foundName = $nameStmt->fetchColumn();
                if (is_string($foundName) && $foundName !== '') {
                    $riderLabel = $foundName;
                }
                createNotification(
                    'rider',
                    $riderTargetId,
                    'delivery_booking',
                    'New parcel delivery',
                    "{$senderName} booked a parcel from {$pickupLoc} to {$dropoffLoc}.",
                    'messages.php?booking_id=' . $bookingId,
                    'booking',
                    $bookingId
                );
                createNotification(
                    'customer',
                    $customerId,
                    'delivery_booking',
                    'Parcel delivery booked',
                    "Your parcel from {$pickupLoc} to {$dropoffLoc} was sent to {$riderLabel}.",
                    'messages.php?booking_id=' . $bookingId,
                    'booking',
                    $bookingId
                );
            }

            setFlash('success', 'Booking requested successfully! You can coordinate directly with your rider in the chat below.');
            header('Location: messages.php?booking_id=' . $bookingId);
            exit;
        } catch (Exception $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Booking failure: ' . $e->getMessage());
            setFlash('error', 'Failed to complete booking: ' . $e->getMessage());
            header('Location: book.php?trip_id=' . $tripId . '&type=' . $bookingType);
            exit;
        }
    }
}

// GET Display logic
$tripId = (int) ($_GET['trip_id'] ?? 0);
$riderId = (int) ($_GET['rider_id'] ?? 0);
$type = $_GET['type'] ?? 'travel';
$type = in_array($type, ['travel', 'delivery'], true) ? $type : 'travel';

$trip = null;
$rider = null;

if ($tripId > 0) {
    $tStmt = db()->prepare(
        "SELECT t.*, r.full_name as rider_name, r.phone as rider_phone, r.whatsapp as rider_whatsapp,
                r.rating_avg, r.rating_count, r.trips_completed, r.vehicle_type, r.vehicle_model,
                r.plate_number, r.status as rider_status, r.verification_status, r.account_status, r.deleted_at
         FROM trips t
         JOIN riders r ON t.rider_id = r.id
         WHERE t.id = ?"
    );
    $tStmt->execute([$tripId]);
    $trip = $tStmt->fetch();
    if ($trip) {
        $riderId = (int) $trip['rider_id'];
    }
}

if ($riderId > 0 && !$trip) {
    $rStmt = db()->prepare("SELECT * FROM riders WHERE id = ?");
    $rStmt->execute([$riderId]);
    $rider = $rStmt->fetch();
}

$currentAcc = currentAccount();
$flash = getFlash();
$listedRider = $trip ?: $rider;
$riderAvailable = !is_array($listedRider)
    || (
        ($listedRider['verification_status'] ?? '') === 'approved'
        && ($listedRider['account_status'] ?? 'active') === 'active'
        && empty($listedRider['deleted_at'])
    );
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | <?= $type === 'travel' ? 'Book Passenger Travel' : 'Book Parcel Delivery' ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body style="background: var(--surface-alt);">
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">P2P Booking</span>
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
            <?php endif; ?>
        </nav>
    </header>

    <main class="container" style="max-width: 820px; padding-top: 36px; padding-bottom: 70px;">
        <?php if ($flash): ?>
            <div class="notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-xl); padding: 36px 32px; box-shadow: var(--shadow-md);">
            <div style="margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid var(--line-subtle);">
                <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary);">
                    <?= $type === 'travel' ? '🚗 Passenger Seat Booking' : '📦 Parcel Dispatch Booking' ?>
                </span>
                <h1 style="font-size: 26px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 6px;">
                    Confirm Your <?= $type === 'travel' ? 'Travel Reservation' : 'Delivery Request' ?>
                </h1>
                <p style="color: var(--muted); font-size: 14px; margin-bottom: 0;">
                    <?= ($trip && $type === 'travel')
                        ? 'This route is fixed. Choose how many seats you need, then agree to the total.'
                        : 'Peer-to-peer direct booking with verified community rider. Direct payment and coordination.' ?>
                </p>
            </div>

            <!-- Trip / Rider Summary Card -->
            <?php if ($trip): ?>
                <div style="background: var(--surface-alt); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 26px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div class="rider-avatar"><?= strtoupper(substr($trip['rider_name'], 0, 1)) ?></div>
                            <div>
                                <strong style="font-size: 16px; color: var(--ink);"><?= e($trip['rider_name']) ?> <?= renderVerifiedTag($trip) ?></strong>
                                <div style="font-size: 12px; color: var(--muted);">
                                    <?= renderRatingStars((float) $trip['rating_avg'], (int) $trip['rating_count']) ?>
                                    · <?= renderVehicleIcon($trip['vehicle_type']) ?> <?= e($trip['vehicle_model'] ?: ucfirst($trip['vehicle_type'])) ?>
                                    <?= !empty($trip['plate_number']) ? '· Plate: ' . e($trip['plate_number']) : '' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="route-timeline" style="margin: 10px 0;">
                        <div class="route-point">
                            <div class="route-time"><?= formatDateTime($trip['departure_time']) ?></div>
                            <div class="route-address"><?= e($trip['origin_address']) ?>, <?= e($trip['origin_city']) ?></div>
                        </div>
                        <div class="route-point destination">
                            <div class="route-time" style="color: #ef4444;">Destination</div>
                            <div class="route-address"><?= e($trip['destination_address']) ?>, <?= e($trip['destination_city']) ?></div>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 12px; border-top: 1px solid var(--line); font-size: 14px;">
                        <span>Unit Rate:</span>
                        <strong style="font-size: 18px; color: var(--primary);">
                            <?= $type === 'travel' ? formatMoney($trip['price_per_seat']) . ' / seat' : formatMoney($trip['delivery_price'] > 0 ? $trip['delivery_price'] : 1500) . ' delivery' ?>
                        </strong>
                    </div>
                </div>
            <?php elseif ($rider): ?>
                <div style="background: var(--surface-alt); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 18px; margin-bottom: 24px; display: flex; align-items: center; gap: 14px;">
                    <div class="rider-avatar"><?= strtoupper(substr($rider['full_name'], 0, 1)) ?></div>
                    <div>
                        <strong style="font-size: 16px;"><?= e($rider['full_name']) ?> <?= renderVerifiedTag($rider) ?></strong>
                        <div style="font-size: 12px; color: var(--muted);">
                            <?= renderRatingStars((float) $rider['rating_avg'], (int) $rider['rating_count']) ?>
                            · <?= renderVehicleIcon($rider['vehicle_type'] ?? 'car') ?>
                            <?= e($rider['vehicle_model'] ?: ucfirst($rider['vehicle_type'] ?? 'car')) ?>
                            <?= !empty($rider['plate_number']) ? '· Plate: ' . e($rider['plate_number']) : '' ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$currentAcc || $currentAcc['account_type'] !== 'customer'): ?>
                <div class="notice notice-error" style="margin-bottom: 24px;">
                    Please <a href="login.php?type=customer" style="font-weight: 700; text-decoration: underline;">sign in as a customer</a> to book this trip.
                </div>
            <?php endif; ?>

            <?php if (!$riderAvailable): ?>
                <div class="notice notice-error">This rider is not verified yet, so bookings are closed.</div>
            <?php else: ?>
            <!-- Booking Form -->
            <form method="post" action="book.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="confirm_booking">
                <input type="hidden" name="trip_id" value="<?= (int) ($trip['id'] ?? 0) ?>">
                <input type="hidden" name="rider_id" value="<?= (int) ($trip['rider_id'] ?? $riderId) ?>">
                <input type="hidden" name="booking_type" value="<?= e($type) ?>">

                <?php if ($trip && $type === 'travel'): ?>
                    <input type="hidden" name="pickup_location" value="<?= e(trim((string) $trip['origin_address'] . (trim((string) ($trip['origin_city'] ?? '')) !== '' ? ', ' . $trip['origin_city'] : ''))) ?>">
                    <input type="hidden" name="dropoff_location" value="<?= e(trim((string) $trip['destination_address'] . (trim((string) ($trip['destination_city'] ?? '')) !== '' ? ', ' . $trip['destination_city'] : ''))) ?>">
                    <div class="form-group">
                        <label class="form-label" for="seats">Seats to book</label>
                        <select class="form-control" id="seats" name="seats_booked" onchange="updateTotalFare()">
                            <?php
                            $maxSeats = max(1, (int) $trip['available_seats']);
                            for ($s = 1; $s <= min($maxSeats, 6); $s++):
                            ?>
                                <option value="<?= $s ?>"><?= $s ?> seat<?= $s > 1 ? 's' : '' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php else: ?>
                <?php if ($type === 'travel'): ?>
                    <div class="form-group">
                        <label class="form-label" for="seats">Seats to Book</label>
                        <select class="form-control" id="seats" name="seats_booked" onchange="updateTotalFare()">
                            <?php 
                            $maxSeats = $trip ? max(1, (int) $trip['available_seats']) : 4;
                            for ($s = 1; $s <= min($maxSeats, 6); $s++): 
                            ?>
                                <option value="<?= $s ?>"><?= $s ?> Seat<?= $s > 1 ? 's' : '' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label" for="pkg-desc">Package Description</label>
                        <input class="form-control" id="pkg-desc" name="package_desc" placeholder="e.g. 1 Medium Box with shoes and clothing" required>
                    </div>
                <?php endif; ?>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="pickup">Pickup Landmark / Address</label>
                        <input class="form-control" id="pickup" name="pickup_location" value="<?= e($trip['origin_address'] ?? '') ?>" placeholder="e.g. Sabon Gari Market, Zaria" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="dropoff">Drop-off Destination</label>
                        <input class="form-control" id="dropoff" name="dropoff_location" value="<?= e($trip['destination_address'] ?? '') ?>" placeholder="e.g. ABU Main Campus, Samaru" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="notes">Notes for Driver (Optional)</label>
                    <textarea class="form-control" id="notes" name="customer_notes" placeholder="e.g. I have 1 small backpack with me; will wait by the main gate"></textarea>
                </div>
                <?php endif; ?>

                <!-- Fare Calculation Box -->
                <div class="fare-summary">
                    <div>
                        <strong><?= ($trip && $type === 'travel') ? 'Total' : 'Total Estimated Fare' ?></strong>
                        <p><?= ($trip && $type === 'travel') ? 'Seat price times the seats you choose' : 'Pay directly to rider upon departure/arrival' ?></p>
                    </div>
                    <div id="total-fare-display" class="fare-summary-total">
                        <?= $type === 'travel' ? formatMoney($trip['price_per_seat'] ?? 2500) : formatMoney($trip['delivery_price'] ?? 2000) ?>
                    </div>
                </div>

                <?php if ($trip && $type === 'travel'): ?>
                    <label class="form-group" style="flex-direction: row; align-items: flex-start; gap: 10px;">
                        <input id="agree-total" name="agree_total" type="checkbox" value="1" required style="margin-top: 3px;">
                        <span>I agree to the total of <strong id="agree-total-amount"><?= e(formatMoney($trip['price_per_seat'])) ?></strong> for this ride.</span>
                    </label>
                <?php endif; ?>

                <button class="button button-primary button-full button-lg" type="submit" <?= (!$currentAcc || $currentAcc['account_type'] !== 'customer') ? 'disabled' : '' ?>>
                    <?= ($trip && $type === 'travel') ? 'Agree and request this ride →' : 'Confirm & Send Booking Request →' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-bottom">
            <span>© <?= date('Y') ?> ZaRide P2P Mobility & Logistics Network.</span>
            <span>Direct P2P Booking Engine.</span>
        </div>
    </footer>

    <?php if ($trip && $type === 'travel'): ?>
    <script>
    const unitPrice = <?= (float) $trip['price_per_seat'] ?>;
    function updateTotalFare() {
        const seats = parseInt(document.getElementById('seats').value, 10) || 1;
        const total = unitPrice * seats;
        const label = '₦' + total.toLocaleString();
        document.getElementById('total-fare-display').innerText = label;
        const agree = document.getElementById('agree-total-amount');
        if (agree) {
            agree.innerText = label;
        }
    }
    </script>
    <?php endif; ?>
    <?= renderMobileBottomNav('travels') ?>
</body>
</html>
