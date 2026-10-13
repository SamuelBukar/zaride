<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . dashboardUrl());
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($token)) {
    setFlash('error', 'Session verification failed. Please try again.');
    header('Location: ' . dashboardUrl());
    exit;
}

$action = $_POST['action'] ?? '';
$accountId = currentAccountId();
$accountType = currentAccountType();

if ($accountId === null || $accountType === null) {
    setFlash('error', 'Please sign in.');
    header('Location: login.php');
    exit;
}

// Action 1: Rider accepts an open customer request
if ($action === 'accept_request') {
    $riderId = requireAccount('rider');
    $requestId = (int) ($_POST['request_id'] ?? 0);

    $rStmt = db()->prepare("SELECT * FROM requests WHERE id = ? AND status = 'open'");
    $rStmt->execute([$requestId]);
    $request = $rStmt->fetch();

    if (!$request) {
        setFlash('error', 'This request is no longer open or was already claimed.');
        header('Location: deliveries.php');
        exit;
    }

    try {
        db()->beginTransaction();

        // 1. Update request
        $updReq = db()->prepare("UPDATE requests SET status = 'accepted', assigned_rider_id = ? WHERE id = ?");
        $updReq->execute([$riderId, $requestId]);

        // 2. Create booking
        $insBook = db()->prepare(
            "INSERT INTO bookings (customer_id, rider_id, booking_type, package_desc, pickup_location,
                                   dropoff_location, scheduled_at, fare_amount, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')"
        );
        $insBook->execute([
            $request['customer_id'],
            $riderId,
            $request['request_type'],
            $request['package_details'] ?: ($request['request_type'] === 'travel' ? 'Passenger Ride' : 'Parcel Delivery'),
            $request['origin'],
            $request['destination'],
            $request['preferred_time'],
            $request['budget_amount']
        ]);
        $bookingId = (int) db()->lastInsertId();

        // 3. Insert greeting message
        $msg = "Hello! I have accepted your " . ($request['request_type'] === 'travel' ? 'travel ride' : 'delivery parcel') . " request. I am en route or preparing for pickup.";
        $insMsg = db()->prepare("INSERT INTO messages (booking_id, sender_type, sender_id, message) VALUES (?, 'rider', ?, ?)");
        $insMsg->execute([$bookingId, $riderId, $msg]);

        db()->commit();

        setFlash('success', 'You have accepted this request! Booking confirmed. Coordinate with customer below:');
        header('Location: messages.php?booking_id=' . $bookingId);
        exit;
    } catch (Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Failed to accept request: ' . $e->getMessage());
        setFlash('error', 'Could not accept request. Please try again.');
        header('Location: deliveries.php');
        exit;
    }
}

// Action 2: Update booking status (Accept, Start, Complete, Decline, Cancel)
if ($action === 'update_booking_status') {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    $allowedStatuses = ['confirmed', 'in_transit', 'completed', 'declined', 'cancelled'];

    if (!in_array($newStatus, $allowedStatuses, true)) {
        setFlash('error', 'Invalid status update.');
        header('Location: ' . dashboardUrl());
        exit;
    }

    $bStmt = db()->prepare("SELECT * FROM bookings WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch();

    if (!$booking) {
        setFlash('error', 'Booking not found.');
        header('Location: ' . dashboardUrl());
        exit;
    }

    $isRider = ($accountType === 'rider' && $accountId === (int) $booking['rider_id']);
    $isCustomer = ($accountType === 'customer' && $accountId === (int) $booking['customer_id']);

    if (!$isRider && !$isCustomer) {
        setFlash('error', 'Unauthorized action.');
        header('Location: ' . dashboardUrl());
        exit;
    }

    // Customer can only cancel pending booking
    if ($isCustomer && $newStatus !== 'cancelled') {
        setFlash('error', 'Customers can only cancel a pending booking.');
        header('Location: dashboard.php?type=customer');
        exit;
    }

    try {
        $upd = db()->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $upd->execute([$newStatus, $bookingId]);

        // If completed by rider, update trips_completed counter
        if ($newStatus === 'completed') {
            updateRiderRatingStats((int) $booking['rider_id']);
        }

        $statusLabels = [
            'confirmed' => 'Booking confirmed!',
            'in_transit' => 'Trip started (In Transit). Drive safely!',
            'completed' => 'Trip marked as completed!',
            'declined' => 'Booking declined.',
            'cancelled' => 'Booking cancelled.'
        ];

        setFlash('success', $statusLabels[$newStatus] ?? 'Booking status updated.');
        header('Location: ' . dashboardUrl());
        exit;
    } catch (PDOException $e) {
        error_log('Status update error: ' . $e->getMessage());
        setFlash('error', 'Failed to update status. Please try again.');
        header('Location: ' . dashboardUrl());
        exit;
    }
}

// Action 3: Quick toggle rider online/offline
if ($action === 'toggle_rider_status') {
    $riderId = requireAccount('rider');
    $currentStatus = $_POST['current_status'] ?? 'offline';
    $nextStatus = $currentStatus === 'online' ? 'offline' : 'online';

    try {
        $stmt = db()->prepare("UPDATE riders SET status = ? WHERE id = ?");
        $stmt->execute([$nextStatus, $riderId]);
        setFlash('success', 'Your status is now ' . strtoupper($nextStatus) . '. ' . ($nextStatus === 'online' ? 'Customers can now view your contact details!' : 'You will not appear as available.'));
    } catch (PDOException $e) {
        setFlash('error', 'Failed to update status.');
    }

    header('Location: dashboard.php?type=rider');
    exit;
}

header('Location: ' . dashboardUrl());
exit;
