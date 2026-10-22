<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonOut(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$accountId = currentAccountId();
$accountType = currentAccountType();
if ($accountId === null || $accountType === null) {
    jsonOut(['ok' => false, 'error' => 'Please sign in.'], 401);
}

$raw = file_get_contents('php://input');
$jsonBody = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($jsonBody)) {
    $jsonBody = [];
}

$action = (string) ($_GET['action'] ?? $jsonBody['action'] ?? $_POST['action'] ?? 'poll');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($jsonBody['csrf_token'] ?? $_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        jsonOut(['ok' => false, 'error' => 'Session verification failed.'], 403);
    }
}

if ($action === 'poll') {
    if ($accountType === 'rider') {
        notifyRiderAboutOpenDeliveries($accountId);
    }
    $afterId = (int) ($_GET['after_id'] ?? 0);
    $decorate = static function (array $note): array {
        $note['created_label'] = timeAgo((string) $note['created_at']);
        $note['is_read'] = (int) $note['is_read'];
        $note['id'] = (int) $note['id'];
        return $note;
    };
    $notes = array_map($decorate, fetchAccountNotifications($accountId, $accountType, 0, 50));
    $freshSource = $afterId > 0
        ? fetchAccountNotifications($accountId, $accountType, $afterId, 20, true)
        : $notes;
    $fresh = [];
    $latestId = 0;
    foreach ($notes as $note) {
        $latestId = max($latestId, (int) $note['id']);
    }
    foreach ($freshSource as $note) {
        $note = $decorate($note);
        if ((int) $note['id'] > $afterId && (int) $note['is_read'] === 0) {
            $fresh[] = $note;
            $latestId = max($latestId, (int) $note['id']);
        }
    }

    $redirect = null;
    $waiting = (string) ($_GET['waiting'] ?? '');
    if ($accountType === 'customer' && $waiting !== '') {
        try {
            $modeSql = $waiting === 'delivery'
                ? "r.request_type = 'delivery'"
                : "r.trip_mode = 'nearby'";
            $accStmt = db()->prepare(
                "SELECT r.id as request_id, b.id as booking_id
                 FROM requests r
                 JOIN bookings b ON b.customer_id = r.customer_id AND b.rider_id = r.assigned_rider_id
                 WHERE r.customer_id = ? AND {$modeSql} AND r.status = 'accepted'
                   AND b.created_at >= DATE_SUB(NOW(), INTERVAL 20 MINUTE)
                 ORDER BY b.id DESC LIMIT 1"
            );
            $accStmt->execute([$accountId]);
            $accepted = $accStmt->fetch();
            if ($accepted) {
                $redirect = 'messages.php?booking_id=' . (int) $accepted['booking_id'];
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    jsonOut([
        'ok' => true,
        'notifications' => $notes,
        'fresh' => $fresh,
        'unread' => unreadNotificationsCount($accountId, $accountType),
        'latest_id' => $latestId,
        'redirect' => $redirect,
    ]);
}

if ($action === 'ping') {
    $lat = (float) ($jsonBody['lat'] ?? $_POST['lat'] ?? 0);
    $lng = (float) ($jsonBody['lng'] ?? $_POST['lng'] ?? 0);
    if ($lat === 0.0 && $lng === 0.0) {
        jsonOut(['ok' => false, 'error' => 'Location missing.']);
    }

    saveAccountLocation($accountType, $accountId, $lat, $lng);
    $acc = currentAccount();
    $city = is_array($acc) ? (string) ($acc['city'] ?? '') : '';

    if ($accountType === 'rider') {
        $rider = $acc ?? [];
        $rider['last_lat'] = $lat;
        $rider['last_lng'] = $lng;
        $rider['last_location_at'] = date('Y-m-d H:i:s');
        $matches = nearbyOpenRequestsForRider($rider);
        foreach ($matches as $req) {
            $distLabel = $req['distance_km'] !== null
                ? number_format((float) $req['distance_km'], 1) . ' km away'
                : 'in your area';
            createNotification(
                'rider',
                $accountId,
                'nearby_request',
                'Short ride nearby',
                "A customer {$distLabel} wants to go from {$req['origin']} to {$req['destination']} now.",
                'nearby.php',
                'request',
                (int) $req['id']
            );
        }
        notifyRiderAboutOpenDeliveries($accountId);
        jsonOut(['ok' => true, 'matches' => count($matches)]);
    }

    $riders = nearbyRidersForPoint($lat, $lng, $city !== '' ? $city : null);
    try {
        $openStmt = db()->prepare(
            "SELECT * FROM requests
             WHERE customer_id = ? AND trip_mode = 'nearby' AND status = 'open'
             ORDER BY id DESC LIMIT 1"
        );
        $openStmt->execute([$accountId]);
        $open = $openStmt->fetch();
        if ($open) {
            $upd = db()->prepare(
                "UPDATE requests SET origin_lat = ?, origin_lng = ? WHERE id = ?"
            );
            $upd->execute([round($lat, 7), round($lng, 7), $open['id']]);
            $open['origin_lat'] = $lat;
            $open['origin_lng'] = $lng;
            notifyNearbyRidersAboutRequest($open, $city !== '' ? $city : null);
        } elseif ($riders !== []) {
            notifyCustomerAboutNearbyRiders($accountId, $riders);
        }
    } catch (PDOException $e) {
        error_log('Nearby ping customer update failed: ' . $e->getMessage());
    }

    jsonOut(['ok' => true, 'nearby_riders' => count($riders)]);
}

if ($action === 'nearby_riders') {
    $acc = currentAccount() ?? [];
    $lat = isset($acc['last_lat']) ? (float) $acc['last_lat'] : 0.0;
    $lng = isset($acc['last_lng']) ? (float) $acc['last_lng'] : 0.0;
    $city = (string) ($acc['city'] ?? '');
    $riders = nearbyRidersForPoint($lat, $lng, $city !== '' ? $city : null);

    $safe = array_map(static function (array $r): array {
        $vehicle = (string) ($r['vehicle_model'] ?: ucfirst((string) ($r['vehicle_type'] ?? 'car')));
        return [
            'id' => (int) $r['id'],
            'full_name' => $r['full_name'],
            'vehicle_type' => $r['vehicle_type'],
            'vehicle_model' => $r['vehicle_model'],
            'vehicle_icon' => renderVehicleIcon((string) ($r['vehicle_type'] ?? 'car')),
            'vehicle_label' => $vehicle,
            'rating_label' => number_format((float) $r['rating_avg'], 1) . ' ★ (' . (int) $r['rating_count'] . ')',
            'rating_avg' => (float) $r['rating_avg'],
            'rating_count' => (int) $r['rating_count'],
            'distance_km' => $r['distance_km'],
            'distance_label' => $r['distance_km'] !== null
                ? number_format((float) $r['distance_km'], 1) . ' km'
                : (string) ($r['city'] ?? ''),
            'city' => $r['city'],
            'verified' => ($r['verification_status'] ?? '') === 'approved',
        ];
    }, $riders);

    jsonOut(['ok' => true, 'riders' => $safe]);
}

if ($action === 'nearby_requests' && $accountType === 'rider') {
    $acc = currentAccount() ?? [];
    $matches = nearbyOpenRequestsForRider($acc);
    $safe = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'origin' => $r['origin'],
            'destination' => $r['destination'],
            'budget_amount' => (float) $r['budget_amount'],
            'budget_label' => formatMoney($r['budget_amount']),
            'passengers_count' => (int) $r['passengers_count'],
            'preferred_time' => $r['preferred_time'],
            'distance_km' => $r['distance_km'],
            'distance_label' => $r['distance_km'] !== null
                ? number_format((float) $r['distance_km'], 1) . ' km away'
                : 'Same city',
            'estimated_distance_km' => $r['estimated_distance_km'] !== null ? (float) $r['estimated_distance_km'] : null,
            'trip_label' => $r['estimated_distance_km'] !== null
                ? number_format((float) $r['estimated_distance_km'], 1) . ' km trip'
                : '',
        ];
    }, $matches);
    jsonOut(['ok' => true, 'requests' => $safe]);
}

if ($action === 'post_nearby') {
    if ($accountType !== 'customer') {
        jsonOut(['ok' => false, 'error' => 'Sign in as a customer to request a nearby ride.'], 403);
    }

    $origin = trim((string) ($jsonBody['origin'] ?? ''));
    $destination = trim((string) ($jsonBody['destination'] ?? ''));
    $budget = (float) ($jsonBody['budget_amount'] ?? 0);
    $passengers = max(1, (int) ($jsonBody['passengers_count'] ?? 1));
    $estimatedKm = (float) ($jsonBody['estimated_distance_km'] ?? 0);
    $lat = (float) ($jsonBody['lat'] ?? 0);
    $lng = (float) ($jsonBody['lng'] ?? 0);

    if ($origin === '' || $destination === '' || $budget <= 0) {
        jsonOut(['ok' => false, 'error' => 'Enter pickup, destination, and a budget.']);
    }
    if ($estimatedKm <= 0 || $estimatedKm > NEARBY_MAX_TRIP_KM) {
        jsonOut([
            'ok' => false,
            'error' => 'Nearby rides are for short trips up to ' . (int) NEARBY_MAX_TRIP_KM . ' km. Use Travels for longer journeys.',
        ]);
    }

    if ($lat !== 0.0 || $lng !== 0.0) {
        saveAccountLocation('customer', $accountId, $lat, $lng);
    }

    try {
        $previous = db()->prepare(
            "SELECT id FROM requests
             WHERE customer_id = ? AND trip_mode = 'nearby' AND status = 'open'"
        );
        $previous->execute([$accountId]);
        foreach ($previous->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
            deleteNearbyRequest((int) $oldId);
        }

        $stmt = db()->prepare(
            "INSERT INTO requests (
                customer_id, request_type, origin, destination, preferred_time,
                passengers_count, budget_amount, status, trip_mode,
                origin_lat, origin_lng, estimated_distance_km, search_started_at
             ) VALUES (?, 'travel', ?, ?, NOW(), ?, ?, 'open', 'nearby', ?, ?, ?, NOW())"
        );
        $originLat = ($lat !== 0.0 || $lng !== 0.0) ? round($lat, 7) : null;
        $originLng = ($lat !== 0.0 || $lng !== 0.0) ? round($lng, 7) : null;
        $stmt->execute([
            $accountId,
            $origin,
            $destination,
            $passengers,
            $budget,
            $originLat,
            $originLng,
            $estimatedKm,
        ]);
        $requestId = (int) db()->lastInsertId();
        $acc = currentAccount() ?? [];
        $request = [
            'id' => $requestId,
            'origin' => $origin,
            'destination' => $destination,
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
        ];
        $notified = notifyNearbyRidersAboutRequest($request, (string) ($acc['city'] ?? ''));
        jsonOut([
            'ok' => true,
            'request_id' => $requestId,
            'notified' => $notified,
            'message' => $notified > 0
                ? "Alerted {$notified} nearby rider(s). Stay on this page — you will be notified when one accepts."
                : 'Request posted. Nearby riders will be alerted as soon as they come online in your area.',
        ]);
    } catch (PDOException $e) {
        error_log('Nearby request failed: ' . $e->getMessage());
        jsonOut(['ok' => false, 'error' => 'Could not post nearby request.'], 500);
    }
}

if ($action === 'cancel_delivery') {
    if ($accountType !== 'customer') {
        jsonOut(['ok' => false, 'error' => 'Unauthorized.'], 403);
    }
    $requestId = (int) ($jsonBody['request_id'] ?? 0);
    $stmt = db()->prepare(
        "SELECT id FROM requests
         WHERE id = ? AND customer_id = ? AND request_type = 'delivery' AND status = 'open'"
    );
    $stmt->execute([$requestId, $accountId]);
    if (!$stmt->fetch()) {
        jsonOut(['ok' => false, 'error' => 'That delivery request is no longer open.']);
    }
    deleteTimedSearchRequest($requestId);
    jsonOut(['ok' => true]);
}

if ($action === 'continue_delivery') {
    if ($accountType !== 'customer') {
        jsonOut(['ok' => false, 'error' => 'Unauthorized.'], 403);
    }
    $requestId = (int) ($jsonBody['request_id'] ?? 0);
    $upd = db()->prepare(
        "UPDATE requests SET search_started_at = NOW()
         WHERE id = ? AND customer_id = ? AND request_type = 'delivery' AND status = 'open'"
    );
    $upd->execute([$requestId, $accountId]);
    if ($upd->rowCount() < 1) {
        jsonOut(['ok' => false, 'error' => 'That delivery request is no longer open.']);
    }
    $reqStmt = db()->prepare("SELECT * FROM requests WHERE id = ?");
    $reqStmt->execute([$requestId]);
    $request = $reqStmt->fetch();
    if (!$request) {
        jsonOut(['ok' => false, 'error' => 'That delivery request is no longer open.']);
    }
    deleteRequestNotifications($requestId);
    $notified = notifyRidersAboutDelivery($request);
    notifyCustomerAboutDeliverySearch($accountId, $request, $notified);
    jsonOut(['ok' => true]);
}

if ($action === 'cancel_nearby') {
    if ($accountType !== 'customer') {
        jsonOut(['ok' => false, 'error' => 'Unauthorized.'], 403);
    }
    $requestId = (int) ($jsonBody['request_id'] ?? 0);
    $stmt = db()->prepare(
        "SELECT id FROM requests
         WHERE id = ? AND customer_id = ? AND trip_mode = 'nearby' AND status = 'open'"
    );
    $stmt->execute([$requestId, $accountId]);
    if (!$stmt->fetch()) {
        jsonOut(['ok' => false, 'error' => 'That nearby request is no longer open.']);
    }
    deleteNearbyRequest($requestId);
    jsonOut(['ok' => true]);
}

if ($action === 'continue_nearby') {
    if ($accountType !== 'customer') {
        jsonOut(['ok' => false, 'error' => 'Unauthorized.'], 403);
    }
    $requestId = (int) ($jsonBody['request_id'] ?? 0);
    $upd = db()->prepare(
        "UPDATE requests SET search_started_at = NOW()
         WHERE id = ? AND customer_id = ? AND trip_mode = 'nearby' AND status = 'open'"
    );
    $upd->execute([$requestId, $accountId]);
    if ($upd->rowCount() < 1) {
        jsonOut(['ok' => false, 'error' => 'That nearby request is no longer open.']);
    }

    $reqStmt = db()->prepare("SELECT * FROM requests WHERE id = ?");
    $reqStmt->execute([$requestId]);
    $request = $reqStmt->fetch();
    if (!$request) {
        jsonOut(['ok' => false, 'error' => 'That nearby request is no longer open.']);
    }

    deleteRequestNotifications($requestId);
    $acc = currentAccount() ?? [];
    $notified = notifyNearbyRidersAboutRequest($request, (string) ($acc['city'] ?? ''));
    jsonOut(['ok' => true, 'notified' => $notified]);
}

if ($action === 'mark_read') {
    $all = !empty($jsonBody['all']);
    $id = (int) ($jsonBody['id'] ?? 0);
    if ($all) {
        $stmt = db()->prepare(
            "UPDATE notifications SET is_read = 1 WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0"
        );
        $stmt->execute([$accountType, $accountId]);
    } elseif ($id > 0) {
        $stmt = db()->prepare(
            "UPDATE notifications SET is_read = 1
             WHERE id = ? AND recipient_type = ? AND recipient_id = ?"
        );
        $stmt->execute([$id, $accountType, $accountId]);
    }
    jsonOut(['ok' => true, 'unread' => unreadNotificationsCount($accountId, $accountType)]);
}

jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
