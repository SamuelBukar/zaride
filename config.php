<?php
declare(strict_types=1);

startAppSession();

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $proto === 'https';
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function rememberSignedInAccount(string $accountType, int $accountId): void
{
    unset($_SESSION['google_pending'], $_SESSION['google_oauth']);
    $_SESSION['account_type'] = $accountType;
    $_SESSION['account_id'] = $accountId;
    session_regenerate_id(true);
    session_write_close();
}

function db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('ZARIDE_DB_HOST') ?: '127.0.0.1';
    $name = getenv('ZARIDE_DB_NAME') ?: 'Zaride';
    $user = getenv('ZARIDE_DB_USER') ?: 'root';
    $password = getenv('ZARIDE_DB_PASSWORD');
    $password = $password === false ? '' : $password;

    $connection = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    ensureNearbySchema();
    ensureAuthSchema();
    ensureStaffSchema();

    return $connection;
}

const NEARBY_MATCH_RADIUS_KM = 5.0;
const NEARBY_MAX_TRIP_KM = 12.0;
const NEARBY_SEARCH_SECONDS = 300;
const LOCATION_FRESH_MINUTES = 20;

function ensureNearbySchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo = db();
    $alters = [
        "ALTER TABLE customers ADD COLUMN last_lat DECIMAL(10,7) NULL",
        "ALTER TABLE customers ADD COLUMN last_lng DECIMAL(10,7) NULL",
        "ALTER TABLE customers ADD COLUMN last_location_at DATETIME NULL",
        "ALTER TABLE riders ADD COLUMN last_lat DECIMAL(10,7) NULL",
        "ALTER TABLE riders ADD COLUMN last_lng DECIMAL(10,7) NULL",
        "ALTER TABLE riders ADD COLUMN last_location_at DATETIME NULL",
        "ALTER TABLE requests ADD COLUMN trip_mode ENUM('scheduled', 'nearby') NOT NULL DEFAULT 'scheduled'",
        "ALTER TABLE requests ADD COLUMN origin_lat DECIMAL(10,7) NULL",
        "ALTER TABLE requests ADD COLUMN origin_lng DECIMAL(10,7) NULL",
        "ALTER TABLE requests ADD COLUMN dest_lat DECIMAL(10,7) NULL",
        "ALTER TABLE requests ADD COLUMN dest_lng DECIMAL(10,7) NULL",
        "ALTER TABLE requests ADD COLUMN estimated_distance_km DECIMAL(6,2) NULL",
        "ALTER TABLE requests ADD COLUMN search_started_at DATETIME NULL",
        "ALTER TABLE bookings ADD COLUMN customer_chat_at DATETIME NULL",
        "ALTER TABLE bookings ADD COLUMN rider_chat_at DATETIME NULL",
    ];
    foreach ($alters as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Column already exists
        }
    }

    try {
        $pdo->exec(
            "UPDATE requests
             SET search_started_at = created_at
             WHERE trip_mode = 'nearby' AND search_started_at IS NULL"
        );
    } catch (PDOException $e) {
        // Column may not exist yet on this engine
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                recipient_type ENUM('customer', 'rider') NOT NULL,
                recipient_id BIGINT UNSIGNED NOT NULL,
                notification_type VARCHAR(40) NOT NULL,
                title VARCHAR(160) NOT NULL,
                body VARCHAR(400) NOT NULL,
                url VARCHAR(255) NULL,
                related_type VARCHAR(40) NULL,
                related_id BIGINT UNSIGNED NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_notifications_recipient (recipient_type, recipient_id, is_read, created_at),
                KEY idx_notifications_related (notification_type, related_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (PDOException $e) {
        error_log('Could not create notifications table: ' . $e->getMessage());
    }

    foreach ([
        "CREATE INDEX idx_riders_online_location ON riders (status, last_location_at)",
        "CREATE INDEX idx_messages_booking_id ON messages (booking_id, id)",
    ] as $indexSql) {
        try {
            $pdo->exec($indexSql);
        } catch (PDOException $e) {
            // Index already exists
        }
    }
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function isLocationFresh(?string $datetime): bool
{
    if ($datetime === null || $datetime === '') {
        return false;
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) <= (LOCATION_FRESH_MINUTES * 60);
}

function saveAccountLocation(string $accountType, int $accountId, float $lat, float $lng): void
{
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return;
    }
    $table = $accountType === 'rider' ? 'riders' : 'customers';
    $stmt = db()->prepare(
        "UPDATE {$table} SET last_lat = ?, last_lng = ?, last_location_at = NOW() WHERE id = ?"
    );
    $stmt->execute([round($lat, 7), round($lng, 7), $accountId]);
}

function createNotification(
    string $recipientType,
    int $recipientId,
    string $type,
    string $title,
    string $body,
    ?string $url = null,
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    try {
        $repeatableByItem = !in_array($type, ['nearby_riders', 'nearby_request'], true);
        if (!$repeatableByItem || $relatedId !== null) {
            $sql = "SELECT id FROM notifications
                    WHERE recipient_type = ? AND recipient_id = ? AND notification_type = ? AND is_read = 0";
            $params = [$recipientType, $recipientId, $type];
            if ($repeatableByItem) {
                $sql .= " AND related_id = ?";
                $params[] = $relatedId;
            }
            $sql .= " LIMIT 1";
            $exists = db()->prepare($sql);
            $exists->execute($params);
            if ($exists->fetch()) {
                return;
            }
        }

        $stmt = db()->prepare(
            "INSERT INTO notifications
                (recipient_type, recipient_id, notification_type, title, body, url, related_type, related_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $recipientType,
            $recipientId,
            $type,
            $title,
            $body,
            $url,
            $relatedType,
            $relatedId,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to create notification: ' . $e->getMessage());
    }
}

function unreadNotificationsCount(int $accountId, string $accountType): int
{
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(id) FROM notifications
             WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0"
        );
        $stmt->execute([$accountType, $accountId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function fetchAccountNotifications(int $accountId, string $accountType, int $afterId = 0, int $limit = 20, bool $onlyNewer = false): array
{
    try {
        $limit = max(1, min(50, $limit));
        if ($onlyNewer) {
            $stmt = db()->prepare(
                "SELECT * FROM notifications
                 WHERE recipient_type = ? AND recipient_id = ? AND id > ? AND is_read = 0
                 ORDER BY id ASC
                 LIMIT {$limit}"
            );
            $stmt->execute([$accountType, $accountId, max(0, $afterId)]);
            return $stmt->fetchAll();
        }

        $stmt = db()->prepare(
            "SELECT * FROM notifications
             WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0
             ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([$accountType, $accountId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function nearbyRidersForPoint(float $lat, float $lng, ?string $city = null, float $radiusKm = NEARBY_MATCH_RADIUS_KM): array
{
    $riders = [];
    try {
        $minutes = (int) LOCATION_FRESH_MINUTES;
        $sql = "SELECT id, full_name, phone, whatsapp, city, vehicle_type, vehicle_model, plate_number,
                       rating_avg, rating_count, status, verification_status, last_lat, last_lng, last_location_at, services_offered
                FROM riders
                WHERE status = 'online'
                  AND " . listedRiderSql() . "
                  AND (
                    (last_lat IS NOT NULL AND last_lng IS NOT NULL
                     AND last_location_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE))";
        if ($city !== null && $city !== '') {
            $sql .= " OR city = ?)";
            $stmt = db()->prepare($sql);
            $stmt->execute([$city]);
        } else {
            $sql .= ")";
            $stmt = db()->prepare($sql);
            $stmt->execute();
        }
        $riders = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $matched = [];
    foreach ($riders as $rider) {
        $distance = null;
        $hasPoint = !($lat == 0.0 && $lng == 0.0);
        $hasCoords = $rider['last_lat'] !== null && $rider['last_lng'] !== null && isLocationFresh($rider['last_location_at']);
        if ($hasPoint && $hasCoords) {
            $distance = haversineKm($lat, $lng, (float) $rider['last_lat'], (float) $rider['last_lng']);
            if ($distance > $radiusKm) {
                continue;
            }
        } elseif ($city !== null && strcasecmp((string) $rider['city'], $city) !== 0) {
            continue;
        } elseif ($city === null) {
            continue;
        }

        $rider['distance_km'] = $distance !== null ? round($distance, 2) : null;
        $matched[] = $rider;
    }

    usort($matched, static function ($a, $b) {
        if ($a['distance_km'] === null && $b['distance_km'] === null) {
            return 0;
        }
        if ($a['distance_km'] === null) {
            return 1;
        }
        if ($b['distance_km'] === null) {
            return -1;
        }
        return $a['distance_km'] <=> $b['distance_km'];
    });

    return $matched;
}

function nearbySearchLiveSql(string $alias = 'req'): string
{
    $seconds = (int) NEARBY_SEARCH_SECONDS;
    return "(({$alias}.trip_mode <> 'nearby' AND {$alias}.request_type <> 'delivery') OR COALESCE({$alias}.search_started_at, {$alias}.created_at) >= DATE_SUB(NOW(), INTERVAL {$seconds} SECOND))";
}

function isTimedSearchRequest(array $request): bool
{
    return ($request['trip_mode'] ?? '') === 'nearby' || ($request['request_type'] ?? '') === 'delivery';
}

function deleteRequestNotifications(int $requestId): void
{
    try {
        $stmt = db()->prepare(
            "DELETE FROM notifications WHERE related_type = 'request' AND related_id = ?"
        );
        $stmt->execute([$requestId]);
    } catch (PDOException $e) {
        error_log('Could not delete request notifications: ' . $e->getMessage());
    }
}

function deleteTimedSearchRequest(int $requestId): void
{
    deleteRequestNotifications($requestId);
    try {
        $stmt = db()->prepare(
            "DELETE FROM requests WHERE id = ? AND (trip_mode = 'nearby' OR request_type = 'delivery')"
        );
        $stmt->execute([$requestId]);
    } catch (PDOException $e) {
        error_log('Could not delete search request: ' . $e->getMessage());
    }
}

function deleteNearbyRequest(int $requestId): void
{
    deleteTimedSearchRequest($requestId);
}

function nearbyOpenRequestsForRider(array $rider): array
{
    $requests = [];
    try {
        $live = nearbySearchLiveSql('req');
        $stmt = db()->query(
            "SELECT req.*, c.full_name as customer_name, c.phone as customer_phone, c.city as customer_city
             FROM requests req
             JOIN customers c ON req.customer_id = c.id
             WHERE req.status = 'open' AND req.trip_mode = 'nearby'
               AND {$live}
             ORDER BY req.created_at DESC
             LIMIT 40"
        );
        $requests = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $riderLat = $rider['last_lat'] !== null ? (float) $rider['last_lat'] : null;
    $riderLng = $rider['last_lng'] !== null ? (float) $rider['last_lng'] : null;
    $riderFresh = isLocationFresh($rider['last_location_at'] ?? null);
    $riderCity = $rider['city'] ?? null;

    $matched = [];
    foreach ($requests as $req) {
        $distance = null;
        $hasPickup = $req['origin_lat'] !== null && $req['origin_lng'] !== null;
        if ($hasPickup && $riderLat !== null && $riderLng !== null && $riderFresh) {
            $distance = haversineKm($riderLat, $riderLng, (float) $req['origin_lat'], (float) $req['origin_lng']);
            if ($distance > NEARBY_MATCH_RADIUS_KM) {
                continue;
            }
        } elseif ($riderCity !== null && strcasecmp((string) ($req['customer_city'] ?? ''), (string) $riderCity) !== 0) {
            continue;
        }

        $tripKm = $req['estimated_distance_km'] !== null ? (float) $req['estimated_distance_km'] : null;
        if ($tripKm !== null && $tripKm > NEARBY_MAX_TRIP_KM) {
            continue;
        }

        $req['distance_km'] = $distance !== null ? round($distance, 2) : null;
        $matched[] = $req;
    }

    usort($matched, static function ($a, $b) {
        return ($a['distance_km'] ?? 999) <=> ($b['distance_km'] ?? 999);
    });

    return $matched;
}

function liveOpenDeliveries(): array
{
    try {
        $live = nearbySearchLiveSql('req');
        $stmt = db()->query(
            "SELECT req.id, req.origin, req.destination
             FROM requests req
             WHERE req.request_type = 'delivery' AND req.status = 'open'
               AND {$live}
             ORDER BY req.id DESC
             LIMIT 20"
        );
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function notifyRiderAboutOpenDeliveries(int $riderId): void
{
    if ($riderId <= 0) {
        return;
    }
    foreach (liveOpenDeliveries() as $request) {
        $origin = (string) ($request['origin'] ?? 'the pickup');
        $destination = (string) ($request['destination'] ?? 'the drop-off');
        createNotification(
            'rider',
            $riderId,
            'delivery_request',
            'Parcel delivery request',
            "A customer wants a parcel taken from {$origin} to {$destination}.",
            'deliveries.php',
            'request',
            (int) $request['id']
        );
    }
}

function notifyRidersAboutDelivery(array $request): int
{
    try {
        $riders = db()->query("SELECT id FROM riders WHERE status = 'online' AND " . listedRiderSql() . " LIMIT 40")->fetchAll();
    } catch (PDOException $e) {
        return 0;
    }

    $origin = (string) ($request['origin'] ?? 'the pickup');
    $destination = (string) ($request['destination'] ?? 'the drop-off');
    $requestId = (int) ($request['id'] ?? 0);
    $notified = 0;
    foreach ($riders as $rider) {
        createNotification(
            'rider',
            (int) $rider['id'],
            'delivery_request',
            'Parcel delivery request',
            "A customer wants a parcel taken from {$origin} to {$destination}.",
            'deliveries.php',
            'request',
            $requestId
        );
        $notified++;
    }

    return $notified;
}

function notifyCustomerAboutDeliverySearch(int $customerId, array $request, int $riderCount): void
{
    $origin = (string) ($request['origin'] ?? 'the pickup');
    $destination = (string) ($request['destination'] ?? 'the drop-off');
    $requestId = (int) ($request['id'] ?? 0);
    $body = $riderCount > 0
        ? 'We alerted ' . $riderCount . ' rider' . ($riderCount === 1 ? '' : 's') . " about your parcel from {$origin} to {$destination}."
        : "Your parcel from {$origin} to {$destination} is posted. Riders will be alerted as soon as they come online.";
    createNotification(
        'customer',
        $customerId,
        'delivery_search',
        $riderCount > 0 ? 'Riders alerted about your parcel' : 'Your parcel is posted',
        $body,
        'deliveries.php',
        'request',
        $requestId
    );
}

function renderLiveSearchBox(array $request, string $kind): string
{
    $id = (int) ($request['id'] ?? 0);
    $startedUnix = (int) ($request['search_started_unix'] ?? 0);
    $serverUnix = (int) ($request['server_unix'] ?? time());
    if ($startedUnix <= 0) {
        $startedUnix = strtotime((string) ($request['search_started_at'] ?? $request['created_at'] ?? '')) ?: $serverUnix;
    }
    $window = (int) NEARBY_SEARCH_SECONDS;
    $remaining = max(0, $window - max(0, $serverUnix - $startedUnix));
    $searchLive = $remaining > 0;
    $clock = sprintf('%d:%02d', intdiv($remaining, 60), $remaining % 60);
    $isDelivery = $kind === 'delivery';
    $title = $isDelivery ? 'Looking for a rider for your parcel…' : 'Looking for a nearby rider…';
    $expiredNote = $isDelivery
        ? 'No rider has picked up this delivery. Continue searching for another 5 minutes, or cancel.'
        : 'No rider has picked up this trip. Continue searching for another 5 minutes, or cancel.';
    $cancelAction = $isDelivery ? 'cancel_delivery' : 'cancel_nearby';
    $continueAction = $isDelivery ? 'continue_delivery' : 'continue_nearby';
    $poll = $isDelivery ? 'delivery' : '1';
    $route = e((string) ($request['origin'] ?? '')) . ' → ' . e((string) ($request['destination'] ?? ''))
        . ' · ' . formatMoney($request['budget_amount'] ?? 0);
    $csrf = e(csrfToken());
    $searchingHidden = $searchLive ? '' : ' hidden';
    $expiredHidden = $searchLive ? ' hidden' : '';

    return <<<HTML
<div class="nearby-waiting live-search" id="live-search-{$id}"
     data-request-id="{$id}"
     data-started="{$startedUnix}"
     data-server-now="{$serverUnix}"
     data-window="{$window}"
     data-csrf="{$csrf}"
     data-cancel-action="{$cancelAction}"
     data-continue-action="{$continueAction}"
     data-poll="{$poll}">
    <div class="live-search-active"{$searchingHidden}>
        <div class="nearby-search-visual" aria-hidden="true">
            <div class="nearby-spinner"></div>
            <div class="nearby-timer live-search-timer">{$clock}</div>
        </div>
        <strong>{$title}</strong>
        <p>{$route}</p>
        <p class="nearby-search-note">Searching for 5 minutes. You will be taken to chat when a rider accepts.</p>
        <button type="button" class="button button-sm button-danger js-cancel-search">Cancel</button>
    </div>
    <div class="live-search-expired"{$expiredHidden}>
        <strong>No driver available yet</strong>
        <p>{$route}</p>
        <p class="nearby-search-note">{$expiredNote}</p>
        <div class="nearby-choice-actions">
            <button type="button" class="button button-primary js-continue-search">Continue searching</button>
            <button type="button" class="button button-danger js-cancel-search">Cancel</button>
        </div>
    </div>
</div>
HTML;
}

function renderLiveSearchScript(): string
{
    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    return <<<'HTML'
<script>
(function () {
    function postSearch(action, csrf, requestId) {
        return fetch('nearby-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, csrf_token: csrf, request_id: requestId })
        }).then(function (r) { return r.json(); });
    }

    document.querySelectorAll('.live-search').forEach(function (box) {
        const requestId = parseInt(box.dataset.requestId, 10);
        const csrf = box.dataset.csrf || '';
        const windowSeconds = parseInt(box.dataset.window, 10) || 300;
        const started = parseInt(box.dataset.started, 10) || 0;
        const serverNow = parseInt(box.dataset.serverNow, 10) || Math.floor(Date.now() / 1000);
        const skew = (Date.now() / 1000) - serverNow;
        const searching = box.querySelector('.live-search-active');
        const expired = box.querySelector('.live-search-expired');
        const timerEl = box.querySelector('.live-search-timer');
        let timerHandle = null;

        function secondsLeft() {
            const now = (Date.now() / 1000) - skew;
            return Math.max(0, Math.ceil(windowSeconds - (now - started)));
        }

        function formatClock(total) {
            const mins = Math.floor(total / 60);
            const secs = total % 60;
            return mins + ':' + (secs < 10 ? '0' : '') + secs;
        }

        function showExpired() {
            if (timerHandle) {
                clearInterval(timerHandle);
                timerHandle = null;
            }
            if (searching) searching.hidden = true;
            if (expired) expired.hidden = false;
        }

        function paintTimer() {
            const left = secondsLeft();
            if (timerEl) timerEl.textContent = formatClock(left);
            if (left <= 0) showExpired();
        }

        if (secondsLeft() <= 0) {
            showExpired();
        } else {
            paintTimer();
            timerHandle = setInterval(paintTimer, 250);
        }

        box.querySelectorAll('.js-cancel-search').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.disabled = true;
                postSearch(box.dataset.cancelAction, csrf, requestId)
                    .then(function () { window.location.reload(); })
                    .catch(function () { window.location.reload(); });
            });
        });

        const continueBtn = box.querySelector('.js-continue-search');
        if (continueBtn) {
            continueBtn.addEventListener('click', function () {
                continueBtn.disabled = true;
                continueBtn.textContent = 'Searching again…';
                postSearch(box.dataset.continueAction, csrf, requestId).then(function (data) {
                    if (!data || !data.ok) {
                        continueBtn.disabled = false;
                        continueBtn.textContent = 'Continue searching';
                        return;
                    }
                    window.location.reload();
                }).catch(function () {
                    continueBtn.disabled = false;
                    continueBtn.textContent = 'Continue searching';
                });
            });
        }

        setInterval(function () {
            const waiting = encodeURIComponent(box.dataset.poll || '1');
            fetch('nearby-api?action=poll&waiting=' + waiting + '&after_id=0')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.redirect) window.location.href = data.redirect;
                }).catch(function () {});
        }, 5000);
    });
})();
</script>
HTML;
}

function notifyNearbyRidersAboutRequest(array $request, ?string $customerCity = null): int
{
    $lat = $request['origin_lat'] !== null ? (float) $request['origin_lat'] : null;
    $lng = $request['origin_lng'] !== null ? (float) $request['origin_lng'] : null;
    if ($lat === null || $lng === null) {
        $riders = [];
        try {
            $stmt = db()->prepare(
                "SELECT id, full_name, city FROM riders WHERE status = 'online' AND " . listedRiderSql() . " AND city = ?"
            );
            $stmt->execute([$customerCity ?: 'Lagos']);
            $riders = $stmt->fetchAll();
        } catch (PDOException $e) {
            return 0;
        }
    } else {
        $riders = nearbyRidersForPoint($lat, $lng, $customerCity);
    }

    $origin = $request['origin'] ?? 'nearby';
    $destination = $request['destination'] ?? 'a nearby destination';
    $requestId = (int) $request['id'];
    $notified = 0;

    foreach ($riders as $rider) {
        $distLabel = isset($rider['distance_km']) && $rider['distance_km'] !== null
            ? number_format((float) $rider['distance_km'], 1) . ' km away'
            : 'in your area';
        createNotification(
            'rider',
            (int) $rider['id'],
            'nearby_request',
            'Short ride nearby',
            "A customer {$distLabel} wants to go from {$origin} to {$destination} now.",
            'nearby.php',
            'request',
            $requestId
        );
        $notified++;
    }

    return $notified;
}

function notifyCustomerAboutNearbyRiders(int $customerId, array $riders, ?int $requestId = null): void
{
    if ($riders === []) {
        return;
    }
    $closest = $riders[0];
    $count = count($riders);
    $dist = $closest['distance_km'] !== null
        ? number_format((float) $closest['distance_km'], 1) . ' km away'
        : 'in your city';
    $name = $closest['full_name'] ?? 'A rider';
    createNotification(
        'customer',
        $customerId,
        'nearby_riders',
        $count === 1 ? 'A rider is nearby' : $count . ' riders are nearby',
        "{$name} is {$dist} and available for a short trip.",
        'nearby.php',
        $requestId ? 'request' : 'nearby',
        $requestId ?? (int) $closest['id']
    );
}

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    if (!is_string($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function currentAccountType(): ?string
{
    $type = $_SESSION['account_type'] ?? null;
    return in_array($type, ['customer', 'rider'], true) ? $type : null;
}

function currentAccountId(): ?int
{
    $id = $_SESSION['account_id'] ?? null;
    if (is_int($id) && $id > 0) {
        return $id;
    }
    if ((is_string($id) || is_float($id)) && ctype_digit((string) $id) && (int) $id > 0) {
        return (int) $id;
    }
    return null;
}

function currentAccount(): ?array
{
    static $cachedAccount = null;
    if ($cachedAccount !== null) {
        return $cachedAccount;
    }

    $id = currentAccountId();
    $type = currentAccountType();
    if ($id === null || $type === null) {
        return null;
    }

    $table = $type === 'rider' ? 'riders' : 'customers';
    try {
        $stmt = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $row['account_type'] = $type;
            $cachedAccount = $row;
            return $cachedAccount;
        }
    } catch (PDOException $e) {
        error_log('currentAccount query failed: ' . $e->getMessage());
    }

    return null;
}

function dashboardUrl(?string $accountType = null): string
{
    if ($accountType === null) {
        $accountType = currentAccountType();
    }

    return $accountType === 'rider' || $accountType === 'customer'
        ? 'dashboard.php?type=' . $accountType
        : 'login.php';
}

function requireAccount(string $accountType): int
{
    $id = currentAccountId();
    $currentType = currentAccountType();

    if ($id === null || $currentType === null) {
        setFlash('error', 'Please sign in to access that page.');
        header('Location: login.php?type=' . rawurlencode($accountType));
        exit;
    }

    if ($currentType !== $accountType) {
        header('Location: ' . dashboardUrl($currentType));
        exit;
    }

    $account = currentAccount();
    $block = is_array($account) ? accountSignInBlock($account) : null;
    if ($block !== null) {
        unset($_SESSION['account_type'], $_SESSION['account_id']);
        setFlash('error', $block);
        header('Location: login.php?type=' . rawurlencode($accountType));
        exit;
    }

    return $id;
}

function formatMoney(float|int|string $amount): string
{
    $val = (float) $amount;
    return '₦' . number_format($val, 0);
}

function renderPartyName(string $label, string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    return '<div class="ride-names"><div class="ride-name-line"><span>' . e($label) . '</span><strong>' . e($name) . '</strong></div></div>';
}

function passengerNameVisibleToRider(string $status): bool
{
    return in_array($status, ['confirmed', 'in_transit', 'completed'], true);
}

function formatDateTime(?string $datetime): string
{
    if (empty($datetime)) {
        return '—';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return (string) $datetime;
    }
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $tripDate = date('Y-m-d', $ts);
    $time = date('g:i A', $ts);

    if ($tripDate === $today) {
        return 'Today at ' . $time;
    }
    if ($tripDate === $tomorrow) {
        return 'Tomorrow at ' . $time;
    }
    return date('M j, Y · g:i A', $ts);
}

function timeAgo(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) return $datetime;
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function renderStatusBadge(string $status): string
{
    $cleanStatus = strtolower(trim($status));
    $classes = [
        'online' => 'badge-success',
        'active' => 'badge-success',
        'confirmed' => 'badge-info',
        'in_transit' => 'badge-warning pulse',
        'in_progress' => 'badge-warning pulse',
        'completed' => 'badge-emerald',
        'pending' => 'badge-amber',
        'open' => 'badge-sky',
        'offline' => 'badge-muted',
        'declined' => 'badge-danger',
        'cancelled' => 'badge-danger',
    ];
    $labels = [
        'online' => 'Online',
        'offline' => 'Offline',
        'active' => 'Active Route',
        'confirmed' => 'Confirmed',
        'in_transit' => 'In Transit',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'pending' => 'Pending Confirmation',
        'open' => 'Open Request',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
    ];

    $cls = $classes[$cleanStatus] ?? 'badge-default';
    $label = $labels[$cleanStatus] ?? ucfirst(str_replace('_', ' ', $cleanStatus));

    return '<span class="status-badge ' . e($cls) . '"><span class="badge-dot"></span>' . e($label) . '</span>';
}

function renderPostedRouteSign(bool $forRider, string $riderName = ''): string
{
    if ($forRider) {
        $label = 'This post came from you';
    } else {
        $name = trim($riderName);
        $label = $name !== '' ? 'This post came from ' . $name : 'This post came from the rider';
    }

    return '<span class="service-chip chip-posted-route">' . e($label) . '</span>';
}

function renderServiceBadge(string $serviceType): string
{
    $type = strtolower(trim($serviceType));
    if ($type === 'travel') {
        return '<span class="service-chip chip-travel" title="Passenger Travel"><span class="chip-icon">🚗</span> Travel</span>';
    }
    if ($type === 'delivery') {
        return '<span class="service-chip chip-delivery" title="Package Delivery"><span class="chip-icon">📦</span> Delivery</span>';
    }
    return '<span class="service-chip chip-both" title="Passenger Travel & Parcel Delivery"><span class="chip-icon">🚗📦</span> Travel & Delivery</span>';
}

function renderVehicleIcon(string $vehicleType): string
{
    return match (strtolower($vehicleType)) {
        'motorcycle' => '🏍️',
        'scooter' => '🛵',
        'bicycle' => '🚲',
        'suv' => '🚙',
        'van' => '🚐',
        'car' => '🚗',
        default => '🚘',
    };
}

function renderRatingStars(float $rating, int $count = 0): string
{
    $starsHtml = '<span class="rating-stars" aria-label="' . number_format($rating, 1) . ' out of 5 stars">';
    $rounded = round($rating * 2) / 2;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rounded) {
            $starsHtml .= '<span class="star-filled">★</span>';
        } elseif ($i - 0.5 == $rounded) {
            $starsHtml .= '<span class="star-half">★</span>';
        } else {
            $starsHtml .= '<span class="star-empty">☆</span>';
        }
    }
    $starsHtml .= '</span>';
    $starsHtml .= '<strong class="rating-value">' . number_format($rating, 1) . '</strong>';
    if ($count > 0) {
        $starsHtml .= '<span class="rating-count">(' . $count . ')</span>';
    }
    return '<span class="rating-badge">' . $starsHtml . '</span>';
}

function chatPresenceColumn(string $accountType): ?string
{
    if ($accountType === 'customer') {
        return 'customer_chat_at';
    }
    if ($accountType === 'rider') {
        return 'rider_chat_at';
    }
    return null;
}

function touchChatPresence(int $bookingId, string $accountType): void
{
    $column = chatPresenceColumn($accountType);
    if ($column === null || $bookingId <= 0) {
        return;
    }
    try {
        db()->prepare("UPDATE bookings SET {$column} = NOW() WHERE id = ?")->execute([$bookingId]);
    } catch (PDOException $e) {
        error_log('Could not record chat presence: ' . $e->getMessage());
    }
}

function recipientIsInChat(int $bookingId, string $recipientType): bool
{
    $column = chatPresenceColumn($recipientType);
    if ($column === null || $bookingId <= 0) {
        return false;
    }
    try {
        $stmt = db()->prepare(
            "SELECT {$column} IS NOT NULL AND {$column} >= DATE_SUB(NOW(), INTERVAL 20 SECOND)
             FROM bookings WHERE id = ?"
        );
        $stmt->execute([$bookingId]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function clearOpenChatNotifications(int $bookingId, string $accountType, int $accountId): void
{
    if ($bookingId <= 0 || $accountId <= 0) {
        return;
    }
    try {
        $stmt = db()->prepare(
            "UPDATE notifications SET is_read = 1
             WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0
               AND notification_type = 'chat_message' AND related_id = ?"
        );
        $stmt->execute([$accountType, $accountId, $bookingId]);
    } catch (PDOException $e) {
        error_log('Could not clear open chat notifications: ' . $e->getMessage());
    }
}

function unreadMessagesCount(int $accountId, string $accountType): int
{
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(m.id) FROM messages m
             JOIN bookings b ON m.booking_id = b.id
             WHERE m.is_read = 0 AND m.sender_type != ?
             AND (b.customer_id = ? OR b.rider_id = ?)"
        );
        $stmt->execute([$accountType, $accountId, $accountId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function renderMobileBottomNav(string $activePage = ''): string
{
    $currentAcc = currentAccount();
    $unread = $currentAcc ? unreadMessagesCount((int) $currentAcc['id'], $currentAcc['account_type']) : 0;
    $dashUrl = $currentAcc ? dashboardUrl() : 'login.php';
    $accountLabel = $currentAcc ? 'Profile' : 'Sign In';

    $items = [
        ['id' => 'home', 'url' => 'index.php', 'label' => 'Home', 'icon' => '🏠'],
        ['id' => 'nearby', 'url' => ($currentAcc && in_array(($currentAcc['account_type'] ?? ''), ['rider', 'customer'], true)) ? 'dashboard.php?type=' . $currentAcc['account_type'] . '&tab=nearby' : 'nearby.php', 'label' => 'Nearby', 'icon' => '📍'],
        ['id' => 'travels', 'url' => 'travels.php', 'label' => 'Travels', 'icon' => '🚗'],
        ['id' => 'deliveries', 'url' => 'deliveries.php', 'label' => 'Deliveries', 'icon' => '📦'],
        ['id' => 'account', 'url' => $dashUrl, 'label' => $accountLabel, 'icon' => '👤', 'badge' => $unread],
    ];

    $html = '<nav class="mobile-bottom-nav" aria-label="Mobile Navigation">';
    foreach ($items as $item) {
        $isActive = ($activePage === $item['id']) ? ' active' : '';
        $html .= '<a href="' . e($item['url']) . '" class="bottom-nav-item' . $isActive . '">';
        $html .= '<span class="bottom-nav-icon-wrap">';
        $html .= '<span class="bottom-nav-icon">' . $item['icon'] . '</span>';
        if (!empty($item['badge']) && $item['badge'] > 0) {
            $html .= '<span class="bottom-nav-badge">' . (int) $item['badge'] . '</span>';
        }
        $html .= '</span>';
        $html .= '<span class="bottom-nav-label">' . e($item['label']) . '</span>';
        $html .= '</a>';
    }
    $html .= '</nav>';
    $html .= renderRealtimeNotifyWidget();

    return $html;
}

function renderRealtimeNotifyWidget(): string
{
    $acc = currentAccount();
    if (!$acc) {
        return '';
    }

    $csrf = e(csrfToken());
    $type = e($acc['account_type']);

    return <<<HTML
<div id="zr-notify-root" data-csrf="{$csrf}" data-role="{$type}">
    <button type="button" class="zr-notify-bell" id="zr-notify-bell" aria-label="Notifications">
        🔔
        <span class="zr-notify-count" id="zr-notify-count" hidden>0</span>
    </button>
    <div class="zr-notify-panel" id="zr-notify-panel" hidden>
        <div class="zr-notify-panel-head">
            <strong>Live alerts</strong>
        </div>
        <div class="zr-notify-list" id="zr-notify-list">
            <p class="zr-notify-empty">No unread alerts.</p>
        </div>
    </div>
    <div class="zr-toast-stack" id="zr-toast-stack"></div>
</div>
<script>
(function () {
    const root = document.getElementById('zr-notify-root');
    if (!root) return;
    const csrf = root.dataset.csrf;
    const bell = document.getElementById('zr-notify-bell');
    const panel = document.getElementById('zr-notify-panel');
    const countEl = document.getElementById('zr-notify-count');
    const listEl = document.getElementById('zr-notify-list');
    const toasts = document.getElementById('zr-toast-stack');
    let lastId = 0;
    let seenIds = new Set();
    let pendingRead = new Set();
    let primed = false;
    let lastPing = 0;

    function onChatPage() {
        return /\/messages(\.php)?\/?$/i.test(location.pathname);
    }

    function currentChatBookingId() {
        if (!onChatPage()) return 0;
        return Number(new URLSearchParams(location.search).get('booking_id') || 0);
    }

    function isOpenChatMessage(n) {
        const bookingId = currentChatBookingId();
        if (!bookingId || String(n.notification_type || '') !== 'chat_message') return false;
        if (Number(n.related_id) === bookingId) return true;
        const match = String(n.url || '').match(/[?&]booking_id=(\d+)/);
        return !!(match && Number(match[1]) === bookingId);
    }

    bell.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
    });
    document.addEventListener('click', function (ev) {
        if (!root.contains(ev.target)) panel.hidden = true;
    });

    function markRead(id) {
        if (!id) return Promise.resolve();
        return fetch('nearby-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_read', csrf_token: csrf, id: id }),
            keepalive: true
        }).catch(function () {});
    }

    function renderCount(unread) {
        if (unread > 0) {
            countEl.hidden = false;
            countEl.textContent = String(unread);
        } else {
            countEl.hidden = true;
            countEl.textContent = '0';
        }
    }

    function renderItems(items) {
        const unreadItems = items.filter(function (n) {
            const id = Number(n.id);
            if (isOpenChatMessage(n)) {
                if (id && !pendingRead.has(id)) {
                    pendingRead.add(id);
                    markRead(id);
                }
                return false;
            }
            return Number(n.is_read) === 0 && !pendingRead.has(id);
        });
        if (!unreadItems.length) {
            listEl.innerHTML = '<p class="zr-notify-empty">No unread alerts.</p>';
        } else {
            listEl.innerHTML = unreadItems.map(function (n) {
                return '<a class="zr-notify-item unread" data-id="' + Number(n.id) + '" href="' + escapeHtml(n.url || 'nearby.php') + '">' +
                    '<strong>' + escapeHtml(n.title) + '</strong>' +
                    '<span>' + escapeHtml(n.body) + '</span>' +
                    '<small>' + escapeHtml(n.created_label || '') + '</small></a>';
            }).join('');
        }
        renderCount(unreadItems.length);
    }

    listEl.addEventListener('click', function (ev) {
        const item = ev.target.closest('a.zr-notify-item');
        if (!item) return;
        ev.preventDefault();
        const id = Number(item.dataset.id || 0);
        const href = item.getAttribute('href') || 'nearby.php';
        if (id) pendingRead.add(id);
        item.remove();
        const remaining = listEl.querySelectorAll('a.zr-notify-item').length;
        if (!remaining) {
            listEl.innerHTML = '<p class="zr-notify-empty">No unread alerts.</p>';
        }
        renderCount(remaining);
        markRead(id).finally(function () {
            window.location.href = href;
        });
    });

    function toast(n) {
        if (isOpenChatMessage(n)) return;
        if (onChatPage() && String(n.notification_type || '') === 'chat_message') return;
        const id = Number(n.id);
        if (!id || seenIds.has(id)) return;
        seenIds.add(id);
        const el = document.createElement('a');
        el.className = 'zr-toast';
        el.href = n.url || 'nearby.php';
        el.innerHTML = '<strong>' + escapeHtml(n.title) + '</strong><span>' + escapeHtml(n.body) + '</span>';
        el.addEventListener('click', function (ev) {
            ev.preventDefault();
            const href = el.getAttribute('href') || 'nearby.php';
            markRead(id).finally(function () { window.location.href = href; });
        });
        toasts.appendChild(el);
        setTimeout(function () { el.remove(); }, 1000);
        if (window.Notification && Notification.permission === 'granted') {
            try { new Notification(n.title, { body: n.body }); } catch (e) {}
        }
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function poll() {
        fetch('nearby-api?action=poll&after_id=' + lastId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                renderItems(data.notifications || []);
                if (!primed) {
                    primed = true;
                    (data.notifications || []).forEach(function (n) { seenIds.add(Number(n.id)); });
                    if (data.latest_id) lastId = Math.max(lastId, Number(data.latest_id));
                } else if (!onChatPage()) {
                    (data.fresh || []).forEach(toast);
                    if (data.latest_id) lastId = Math.max(lastId, Number(data.latest_id));
                }
                if (data.redirect && !onChatPage()) window.location.href = data.redirect;
            })
            .catch(function () {});
    }

    function sendLocation(pos) {
        const now = Date.now();
        if (now - lastPing < 20000) return;
        lastPing = now;
        fetch('nearby-api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'ping',
                csrf_token: csrf,
                lat: pos.coords.latitude,
                lng: pos.coords.longitude
            })
        }).then(poll).catch(function () {});
    }

    function askNotifyPermission() {
        if (window.Notification && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    bell.addEventListener('click', askNotifyPermission);

    function startGeo() {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(sendLocation, function () {}, { enableHighAccuracy: true, timeout: 8000 });
        navigator.geolocation.watchPosition(sendLocation, function () {}, { enableHighAccuracy: true, maximumAge: 15000 });
    }

    poll();
    setInterval(poll, 8000);
    startGeo();
})();
</script>
HTML;
}


function updateRiderRatingStats(int $riderId): void
{
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(id) as total_reviews, COALESCE(AVG(rating), 5.0) as avg_rating
             FROM reviews WHERE rider_id = ?"
        );
        $stmt->execute([$riderId]);
        $stats = $stmt->fetch();

        $tripsStmt = db()->prepare(
            "SELECT COUNT(id) FROM bookings WHERE rider_id = ? AND status = 'completed'"
        );
        $tripsStmt->execute([$riderId]);
        $completedTrips = (int) $tripsStmt->fetchColumn();

        $update = db()->prepare(
            "UPDATE riders
             SET rating_avg = ?, rating_count = ?, trips_completed = ?
             WHERE id = ?"
        );
        $update->execute([
            round((float) ($stats['avg_rating'] ?? 5.0), 2),
            (int) ($stats['total_reviews'] ?? 0),
            $completedTrips,
            $riderId
        ]);
    } catch (PDOException $e) {
        error_log('Failed to update rider stats: ' . $e->getMessage());
    }
}

function privateUploadDirectory(): string
{
    $configured = getenv('ZARIDE_UPLOAD_DIR');
    $directory = $configured !== false && $configured !== ''
        ? $configured
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zaride-private-uploads';

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private upload directory could not be created.');
    }

    return $directory;
}

function storePrivateImage(array $upload): string
{
    if (!isset($upload['error'], $upload['tmp_name'], $upload['size'])
        || !is_int($upload['error'])
        || !is_int($upload['size'])
        || !is_string($upload['tmp_name'])) {
        throw new RuntimeException('The uploaded image was not received correctly.');
    }

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $message = $upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Each image must be no larger than 5 MB.'
            : 'An image could not be uploaded. Please try again.';
        throw new RuntimeException($message);
    }

    if ($upload['size'] < 1 || $upload['size'] > 5 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Each image must be a valid upload no larger than 5 MB.');
    }

    $image = @getimagesize($upload['tmp_name']);
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        throw new RuntimeException('Image validation is unavailable on this server.');
    }

    $mimeType = finfo_file($fileInfo, $upload['tmp_name']);
    finfo_close($fileInfo);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_array($image)
        || !isset($image[0], $image[1], $image['mime'])
        || $image[0] < 1
        || $image[1] < 1
        || $image[0] * $image[1] > 20000000
        || !is_string($mimeType)
        || !isset($extensions[$mimeType])
        || $image['mime'] !== $mimeType) {
        throw new RuntimeException('Upload a valid JPEG, PNG, or WebP image.');
    }

    $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mimeType];
    $path = privateUploadDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $path)) {
        throw new RuntimeException('The uploaded image could not be saved.');
    }

    return $filename;
}

function deletePrivateImage(?string $filename): void
{
    if ($filename === null || !preg_match('/\A[a-f0-9]{48}\.(jpg|png|webp)\z/', $filename)) {
        return;
    }

    $configured = getenv('ZARIDE_UPLOAD_DIR');
    $directory = $configured !== false && $configured !== ''
        ? $configured
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zaride-private-uploads';
    if (!is_dir($directory)) {
        return;
    }

    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path) && !unlink($path)) {
        error_log('ZaRide could not remove replaced private image: ' . $filename);
    }
}

function publicAvatarDirectory(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Avatar upload directory could not be created.');
    }
    return $dir;
}

function storeAvatarImage(array $upload): string
{
    if (!isset($upload['error'], $upload['tmp_name'], $upload['size'])
        || !is_int($upload['error'])
        || !is_int($upload['size'])
        || !is_string($upload['tmp_name'])) {
        throw new RuntimeException('The uploaded profile picture was not received correctly.');
    }

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $message = $upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Profile picture must be no larger than 5 MB.'
            : 'Profile picture could not be uploaded. Please try again.';
        throw new RuntimeException($message);
    }

    if ($upload['size'] < 1 || $upload['size'] > 5 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Profile picture must be a valid image under 5 MB.');
    }

    $image = @getimagesize($upload['tmp_name']);
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        throw new RuntimeException('Image validation is unavailable.');
    }
    $mimeType = finfo_file($fileInfo, $upload['tmp_name']);
    finfo_close($fileInfo);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!is_array($image)
        || !isset($extensions[$mimeType])
        || $image['mime'] !== $mimeType) {
        throw new RuntimeException('Please upload a valid JPEG, PNG, or WebP photo.');
    }

    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mimeType];
    $destination = publicAvatarDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save avatar file.');
    }

    return $filename;
}

function deleteAvatarImage(?string $filename): void
{
    if ($filename === null || !preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $filename)) {
        return;
    }
    $path = publicAvatarDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

function publicVehicleDirectory(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'vehicles';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Vehicle photo directory could not be created.');
    }
    return $dir;
}

function storeVehiclePhoto(array $upload): string
{
    if (!isset($upload['error'], $upload['tmp_name'], $upload['size'])
        || !is_int($upload['error'])
        || !is_int($upload['size'])
        || !is_string($upload['tmp_name'])) {
        throw new RuntimeException('The vehicle photo was not received correctly.');
    }

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $message = $upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Vehicle photo must be no larger than 5 MB.'
            : 'Vehicle photo could not be uploaded. Please try again.';
        throw new RuntimeException($message);
    }

    if ($upload['size'] < 1 || $upload['size'] > 5 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Vehicle photo must be a valid image under 5 MB.');
    }

    $image = @getimagesize($upload['tmp_name']);
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        throw new RuntimeException('Image validation is unavailable.');
    }
    $mimeType = finfo_file($fileInfo, $upload['tmp_name']);
    finfo_close($fileInfo);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!is_array($image)
        || !isset($extensions[$mimeType])
        || $image['mime'] !== $mimeType) {
        throw new RuntimeException('Please upload a valid JPEG, PNG, or WebP vehicle photo.');
    }

    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mimeType];
    $destination = publicVehicleDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save vehicle photo.');
    }

    return $filename;
}

function deleteVehiclePhoto(?string $filename): void
{
    if ($filename === null || !preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $filename)) {
        return;
    }
    $path = publicVehicleDirectory() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}

function vehiclePhotoUrl(?string $filename): ?string
{
    if ($filename === null || $filename === '') {
        return null;
    }
    $path = __DIR__ . '/uploads/vehicles/' . $filename;
    if (!is_file($path)) {
        return null;
    }
    return 'uploads/vehicles/' . $filename;
}

function renderVehiclePhoto(?array $rider, string $sizeClass = ''): string
{
    $url = vehiclePhotoUrl($rider['vehicle_photo_path'] ?? null);
    $label = trim((string) (($rider['vehicle_model'] ?? '') ?: ($rider['vehicle_type'] ?? 'Vehicle')));
    if ($url === null) {
        return '<div class="vehicle-photo-empty ' . e($sizeClass) . '">'
            . '<span>' . renderVehicleIcon((string) ($rider['vehicle_type'] ?? 'car')) . '</span>'
            . '<small>No car photo yet</small></div>';
    }
    return '<img src="' . e($url) . '" alt="' . e($label) . '" class="vehicle-photo ' . e($sizeClass) . '">';
}

function renderUserAvatar(?array $user, string $sizeClass = ''): string
{
    $name = $user['full_name'] ?? 'User';
    $initialChar = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
    $initial = strtoupper($initialChar);
    $avatarPath = $user['avatar_path'] ?? null;

    if (!empty($avatarPath) && is_file(__DIR__ . '/uploads/avatars/' . $avatarPath)) {
        return '<img src="uploads/avatars/' . e($avatarPath) . '" alt="' . e($name) . '" class="avatar-photo ' . e($sizeClass) . '">';
    }

    return '<span class="avatar-fallback ' . e($sizeClass) . '">' . e($initial) . '</span>';
}

function renderPlateBadge(?string $plateNumber): string
{
    if (empty($plateNumber)) {
        return '';
    }
    return '<span class="license-plate-badge" title="Vehicle License Plate"><span class="plate-flag">🇳🇬</span><span class="plate-tag">NG</span><span class="plate-num">' . e(strtoupper($plateNumber)) . '</span></span>';
}

require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/auth.php';

