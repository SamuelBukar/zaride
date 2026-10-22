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
    denyRiderWork($riderId, 'dashboard.php?type=rider');
    $requestId = (int) ($_POST['request_id'] ?? 0);

    $rStmt = db()->prepare("SELECT * FROM requests WHERE id = ? AND status = 'open'");
    $rStmt->execute([$requestId]);
    $request = $rStmt->fetch();

    if (!$request) {
        setFlash('error', 'This request is no longer open or was already claimed.');
        header('Location: deliveries.php');
        exit;
    }

    if (isTimedSearchRequest($request)) {
        $live = db()->prepare(
            "SELECT id FROM requests WHERE id = ? AND " . nearbySearchLiveSql('requests')
        );
        $live->execute([$requestId]);
        if (!$live->fetch()) {
            $isDelivery = ($request['request_type'] ?? '') === 'delivery';
            setFlash('error', $isDelivery
                ? 'This delivery search has ended. The customer is no longer looking.'
                : 'This nearby search has ended. The customer is no longer looking.');
            header('Location: ' . ($isDelivery ? 'deliveries.php' : 'nearby.php'));
            exit;
        }
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
        saveAdminTripHistory($bookingId);

        // 3. Insert greeting message
        $msg = "Hello! I have accepted your " . ($request['request_type'] === 'travel' ? 'travel ride' : 'delivery parcel') . " request. I am en route or preparing for pickup.";
        $insMsg = db()->prepare("INSERT INTO messages (booking_id, sender_type, sender_id, message) VALUES (?, 'rider', ?, ?)");
        $insMsg->execute([$bookingId, $riderId, $msg]);

        db()->commit();

        deleteRequestNotifications($requestId);
        $riderName = (currentAccount()['full_name'] ?? 'A rider');
        $acceptedDelivery = ($request['request_type'] ?? '') === 'delivery';
        createNotification(
            'customer',
            (int) $request['customer_id'],
            'request_accepted',
            $acceptedDelivery ? 'A rider accepted your delivery' : 'A nearby rider accepted',
            $acceptedDelivery
                ? "{$riderName} accepted your parcel from {$request['origin']} to {$request['destination']}. Open chat to coordinate pickup."
                : "{$riderName} accepted your trip from {$request['origin']} to {$request['destination']}. Open chat to coordinate pickup.",
            'messages.php?booking_id=' . $bookingId,
            'booking',
            $bookingId
        );

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

    if ($isCustomer && $newStatus !== 'cancelled') {
        setFlash('error', 'Customers can only cancel a booking.');
        header('Location: dashboard.php?type=customer');
        exit;
    }

    $cancellable = ['pending', 'confirmed', 'in_transit'];
    if ($newStatus === 'cancelled' && !in_array($booking['status'], $cancellable, true)) {
        setFlash('error', 'This booking can no longer be cancelled.');
        header('Location: ' . dashboardUrl());
        exit;
    }

    if ($isCustomer && $newStatus === 'cancelled' && !in_array($booking['status'], ['pending', 'confirmed'], true)) {
        setFlash('error', 'This trip has already started and cannot be cancelled from here.');
        header('Location: dashboard.php?type=customer');
        exit;
    }

    try {
        $upd = db()->prepare("UPDATE bookings SET status = ? WHERE id = ?");
        $upd->execute([$newStatus, $bookingId]);

        if (in_array($newStatus, ['confirmed', 'in_transit', 'completed'], true)) {
            saveAdminTripHistory($bookingId);
        } elseif ($newStatus === 'cancelled') {
            saveAdminTripHistory($bookingId, 'cancelled', (string) $booking['status']);
        }

        if ($newStatus === 'completed') {
            updateRiderRatingStats((int) $booking['rider_id']);
        }

        if ($newStatus === 'cancelled') {
            $nearStmt = db()->prepare(
                "SELECT id FROM requests
                 WHERE customer_id = ? AND trip_mode = 'nearby'
                   AND origin = ? AND destination = ?
                   AND preferred_time = ?
                   AND status IN ('open', 'accepted', 'in_transit')
                 ORDER BY id DESC LIMIT 1"
            );
            $nearStmt->execute([
                $booking['customer_id'],
                $booking['pickup_location'],
                $booking['dropoff_location'],
                $booking['scheduled_at'],
            ]);
            $nearbyRequestId = (int) $nearStmt->fetchColumn();
            if ($nearbyRequestId > 0 || ($booking['booking_type'] ?? '') === 'delivery') {
                if (($booking['booking_type'] ?? '') === 'delivery' && $nearbyRequestId < 1) {
                    $deliveryStmt = db()->prepare(
                        "SELECT id FROM requests
                         WHERE customer_id = ? AND request_type = 'delivery'
                           AND origin = ? AND destination = ?
                           AND preferred_time = ?
                           AND status IN ('open', 'accepted', 'in_transit')
                         ORDER BY id DESC LIMIT 1"
                    );
                    $deliveryStmt->execute([
                        $booking['customer_id'],
                        $booking['pickup_location'],
                        $booking['dropoff_location'],
                        $booking['scheduled_at'],
                    ]);
                    $nearbyRequestId = (int) $deliveryStmt->fetchColumn();
                }
                $recipientType = $isRider ? 'customer' : 'rider';
                $recipientId = $isRider ? (int) $booking['customer_id'] : (int) $booking['rider_id'];
                $who = $isRider ? 'The rider' : 'The customer';
                createNotification(
                    $recipientType,
                    $recipientId,
                    'booking_cancelled',
                    'Booking cancelled',
                    "{$who} cancelled the trip from {$booking['pickup_location']} to {$booking['dropoff_location']}.",
                    dashboardUrl($recipientType),
                    'booking',
                    $bookingId
                );
                if ($nearbyRequestId > 0) {
                    deleteTimedSearchRequest($nearbyRequestId);
                }
                $dropBooking = db()->prepare("DELETE FROM bookings WHERE id = ?");
                $dropBooking->execute([$bookingId]);

                setFlash('success', $isRider
                    ? 'Booking cancelled. The customer has been notified.'
                    : 'Booking cancelled. The rider has been notified.');
                header('Location: ' . dashboardUrl());
                exit;
            }

            if (!empty($booking['trip_id']) && $booking['booking_type'] === 'travel' && (int) $booking['seats_booked'] > 0) {
                $seatStmt = db()->prepare(
                    "UPDATE trips
                     SET available_seats = available_seats + ?,
                         status = IF(status = 'fully_booked', 'active', status)
                     WHERE id = ? AND status IN ('active', 'fully_booked')"
                );
                $seatStmt->execute([(int) $booking['seats_booked'], (int) $booking['trip_id']]);
            }

            $reqStmt = db()->prepare(
                "UPDATE requests
                 SET status = 'cancelled', assigned_rider_id = NULL
                 WHERE customer_id = ? AND assigned_rider_id = ?
                   AND origin = ? AND destination = ?
                   AND status IN ('accepted', 'in_transit')"
            );
            $reqStmt->execute([
                $booking['customer_id'],
                $booking['rider_id'],
                $booking['pickup_location'],
                $booking['dropoff_location'],
            ]);

            $recipientType = $isRider ? 'customer' : 'rider';
            $recipientId = $isRider ? (int) $booking['customer_id'] : (int) $booking['rider_id'];
            $who = $isRider ? 'The rider' : 'The customer';
            createNotification(
                $recipientType,
                $recipientId,
                'booking_cancelled',
                'Booking cancelled',
                "{$who} cancelled the trip from {$booking['pickup_location']} to {$booking['dropoff_location']}.",
                dashboardUrl($recipientType),
                'booking',
                $bookingId
            );
        }

        $statusLabels = [
            'confirmed' => 'Booking confirmed!',
            'in_transit' => 'Trip started (In Transit). Drive safely!',
            'completed' => 'Trip marked as completed!',
            'declined' => 'Booking declined.',
            'cancelled' => $isRider
                ? 'Booking cancelled. The customer has been notified.'
                : 'Booking cancelled. The rider has been notified.'
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

// Action 3: Customer cancels an open request from the dashboard
if ($action === 'cancel_request') {
    $customerId = requireAccount('customer');
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $stmt = db()->prepare(
        "SELECT * FROM requests WHERE id = ? AND customer_id = ? AND status = 'open'"
    );
    $stmt->execute([$requestId, $customerId]);
    $request = $stmt->fetch();
    if (!$request) {
        setFlash('error', 'That request is no longer open.');
        header('Location: dashboard.php?type=customer&tab=marketplace');
        exit;
    }

    if (isTimedSearchRequest($request)) {
        deleteTimedSearchRequest($requestId);
        setFlash('success', ($request['request_type'] ?? '') === 'delivery'
            ? 'Delivery request cancelled. It was not saved in your history.'
            : 'Nearby request cancelled.');
    } else {
        $upd = db()->prepare("UPDATE requests SET status = 'cancelled' WHERE id = ? AND customer_id = ?");
        $upd->execute([$requestId, $customerId]);
        setFlash('success', 'Request cancelled.');
    }

    header('Location: dashboard.php?type=customer&tab=marketplace');
    exit;
}

// Action 4: Quick toggle rider online/offline
if ($action === 'toggle_rider_status') {
    $riderId = requireAccount('rider');
    $currentStatus = $_POST['current_status'] ?? 'offline';
    $nextStatus = $currentStatus === 'online' ? 'offline' : 'online';
    if ($nextStatus === 'online') {
        denyRiderWork($riderId, 'dashboard.php?type=rider');
    }

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
