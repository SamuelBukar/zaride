<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$accountType = currentAccountType();
$accountId = currentAccountId();
if (($accountType === 'rider' || $accountType === 'customer') && $accountId !== null) {
    header('Location: dashboard.php?type=' . $accountType . '&tab=nearby');
    exit;
}
$currentAcc = currentAccount();
$flash = getFlash();

$openRequest = null;
$nearbyRiders = [];
$nearbyRequests = [];

if ($currentAcc) {
    if ($accountType === 'customer') {
        $stmt = db()->prepare(
            "SELECT *,
                    UNIX_TIMESTAMP(COALESCE(search_started_at, created_at)) AS search_started_unix,
                    UNIX_TIMESTAMP(NOW()) AS server_unix
             FROM requests
             WHERE customer_id = ? AND trip_mode = 'nearby' AND status = 'open'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$accountId]);
        $openRequest = $stmt->fetch() ?: null;

        $lat = $currentAcc['last_lat'] !== null ? (float) $currentAcc['last_lat'] : 0.0;
        $lng = $currentAcc['last_lng'] !== null ? (float) $currentAcc['last_lng'] : 0.0;
        $nearbyRiders = nearbyRidersForPoint($lat, $lng, (string) ($currentAcc['city'] ?? ''));
    } elseif ($accountType === 'rider') {
        $nearbyRequests = nearbyOpenRequestsForRider($currentAcc);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | Nearby Short Rides</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body style="background: var(--surface-alt);">
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">Nearby Now</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link active" href="nearby.php">📍 Nearby</a>
            <a class="nav-link" href="travels.php">🚗 Travels</a>
            <a class="nav-link" href="deliveries.php">📦 Deliveries</a>
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

    <main class="container" style="max-width: 860px; padding-top: 32px; padding-bottom: 80px;">
        <?php if ($flash): ?>
            <div class="notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div class="nearby-hero">
            <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary);">📍 Short trips only · up to <?= (int) NEARBY_MAX_TRIP_KM ?> km</span>
            <h1>Ride now with people close to you</h1>
            <p>
                This is for local hops — not intercity travel. Customers request a nearby ride,
                and online riders within about <?= (int) NEARBY_MATCH_RADIUS_KM ?> km get a live alert.
            </p>
        </div>

        <?php if (!$currentAcc): ?>
            <div class="dash-card" style="text-align: center;">
                <p style="color: var(--muted);">Sign in to share your location and get real-time nearby alerts.</p>
                <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                    <a class="button button-primary" href="login.php?type=customer">Customer sign in</a>
                    <a class="button button-emerald" href="login.php?type=rider">Rider sign in</a>
                </div>
            </div>
        <?php elseif ($accountType === 'customer'): ?>
            <div class="dash-card">
                <div class="dash-card-header">
                    <div>
                        <h2 class="dash-card-title">Request a nearby ride</h2>
                        <p style="color: var(--muted); font-size: 13px; margin: 0;">Allow location so nearby riders can be matched instantly.</p>
                    </div>
                    <span class="status-badge badge-sky" id="geo-status"><span class="badge-dot"></span>Waiting for GPS</span>
                </div>

                <?php if ($openRequest): ?>
                    <?php
                    $startedUnix = (int) ($openRequest['search_started_unix'] ?? 0);
                    $serverUnix = (int) ($openRequest['server_unix'] ?? time());
                    $remaining = max(0, NEARBY_SEARCH_SECONDS - max(0, $serverUnix - $startedUnix));
                    $searchLive = $remaining > 0;
                    $clock = sprintf('%d:%02d', intdiv($remaining, 60), $remaining % 60);
                    ?>
                    <div class="nearby-waiting" id="waiting-box"
                         data-request-id="<?= (int) $openRequest['id'] ?>"
                         data-started="<?= $startedUnix ?>"
                         data-server-now="<?= $serverUnix ?>"
                         data-window="<?= (int) NEARBY_SEARCH_SECONDS ?>">
                        <div id="nearby-searching" <?= $searchLive ? '' : 'hidden' ?>>
                            <div class="nearby-search-visual" aria-hidden="true">
                                <div class="nearby-spinner"></div>
                                <div class="nearby-timer" id="nearby-timer"><?= e($clock) ?></div>
                            </div>
                            <strong>Looking for a nearby rider…</strong>
                            <p><?= e($openRequest['origin']) ?> → <?= e($openRequest['destination']) ?> · <?= formatMoney($openRequest['budget_amount']) ?></p>
                            <p class="nearby-search-note">Searching for 5 minutes. You will be taken to chat when a rider accepts.</p>
                            <button type="button" class="button button-sm button-danger js-cancel-nearby">Cancel</button>
                        </div>
                        <div id="nearby-expired" <?= $searchLive ? 'hidden' : '' ?>>
                            <strong>No driver available yet</strong>
                            <p><?= e($openRequest['origin']) ?> → <?= e($openRequest['destination']) ?> · <?= formatMoney($openRequest['budget_amount']) ?></p>
                            <p class="nearby-search-note">No rider has picked up this trip. Continue searching for another 5 minutes, or cancel.</p>
                            <div class="nearby-choice-actions">
                                <button type="button" class="button button-primary" id="continue-nearby">Continue searching</button>
                                <button type="button" class="button button-danger js-cancel-nearby">Cancel</button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <form id="nearby-form">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label" for="origin">Pickup (where you are)</label>
                                <input class="form-control" id="origin" name="origin" placeholder="e.g. Sabon Gari Market, Zaria" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="destination">Where you want to go</label>
                                <input class="form-control" id="destination" name="destination" placeholder="e.g. ABU Main Campus, Samaru" required>
                            </div>
                        </div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label" for="distance">Trip distance</label>
                                <select class="form-control" id="distance" name="estimated_distance_km" required>
                                    <option value="2">Under 2 km</option>
                                    <option value="5" selected>About 2–5 km</option>
                                    <option value="8">About 5–8 km</option>
                                    <option value="12">About 8–12 km (max nearby)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="passengers">Passengers</label>
                                <select class="form-control" id="passengers" name="passengers_count">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="budget">Offer (₦)</label>
                            <input class="form-control" id="budget" name="budget_amount" type="number" min="300" step="50" value="800" required>
                        </div>
                        <p id="nearby-form-msg" class="notice" hidden></p>
                        <button class="button button-primary button-lg button-full" type="submit">Alert nearby riders →</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="dash-card">
                <div class="dash-card-header">
                    <h2 class="dash-card-title">Riders close to you</h2>
                </div>
                <div id="rider-list">
                    <?php if ($nearbyRiders === []): ?>
                        <p class="zr-notify-empty" style="padding: 8px 0;">No live GPS matches yet. Allow location — nearby online riders will appear here.</p>
                    <?php else: ?>
                        <?php foreach ($nearbyRiders as $rider): ?>
                            <div class="nearby-match-row">
                                <div>
                                    <strong>Rider: <?= e($rider['full_name']) ?> <?= renderVerifiedTag($rider) ?></strong>
                                    <div style="font-size: 13px; color: var(--muted);">
                                        <?= renderVehicleIcon((string) ($rider['vehicle_type'] ?? 'car')) ?>
                                        <?= e($rider['vehicle_model'] ?: ucfirst((string) ($rider['vehicle_type'] ?? 'car'))) ?>
                                        · <?= renderRatingStars((float) $rider['rating_avg'], (int) $rider['rating_count']) ?>
                                    </div>
                                </div>
                                <span class="nearby-dist">
                                    <?= $rider['distance_km'] !== null ? number_format((float) $rider['distance_km'], 1) . ' km' : e($rider['city']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="dash-card">
                <div class="dash-card-header">
                    <div>
                        <h2 class="dash-card-title">Nearby short-trip requests</h2>
                        <p style="color: var(--muted); font-size: 13px; margin: 0;">Stay online and share location to get pinged when a customer close by needs a short ride — not a long travel.</p>
                    </div>
                    <span class="status-badge <?= ($currentAcc['status'] ?? '') === 'online' ? 'badge-success' : 'badge-muted' ?>">
                        <span class="badge-dot"></span><?= strtoupper((string) ($currentAcc['status'] ?? 'offline')) ?>
                    </span>
                </div>

                <?php if (($currentAcc['status'] ?? '') !== 'online'): ?>
                    <div class="notice notice-error">Switch to Online on your dashboard to receive nearby ride alerts.</div>
                <?php endif; ?>

                <div id="request-list">
                    <?php if ($nearbyRequests === []): ?>
                        <p class="zr-notify-empty" style="padding: 8px 0;">No nearby short-trip requests right now. You will get a live alert when a customer close to you wants to go a short distance.</p>
                    <?php else: ?>
                        <?php foreach ($nearbyRequests as $req): ?>
                            <div class="nearby-match-row">
                                <div>
                                    <strong><?= e($req['origin']) ?> → <?= e($req['destination']) ?></strong>
                                    <div style="font-size: 13px; color: var(--muted);">
                                        <?= formatMoney($req['budget_amount']) ?>
                                        · <?= (int) $req['passengers_count'] ?> passenger(s)
                                        <?php if (!empty($req['estimated_distance_km'])): ?>
                                            · <?= number_format((float) $req['estimated_distance_km'], 1) ?> km trip
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="nearby-dist">
                                        <?= $req['distance_km'] !== null ? number_format((float) $req['distance_km'], 1) . ' km away' : 'Same city' ?>
                                    </span>
                                    <form method="post" action="request-action.php" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                        <input type="hidden" name="action" value="accept_request">
                                        <button class="button button-sm button-emerald" type="submit">Accept</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php if ($currentAcc && $accountType === 'customer'): ?>
    <script>
    (function () {
        const csrf = <?= json_encode(csrfToken()) ?>;
        const geoStatus = document.getElementById('geo-status');
        const form = document.getElementById('nearby-form');
        const waiting = document.getElementById('waiting-box');
        let lat = null, lng = null;

        function setGeo(ok, label) {
            if (!geoStatus) return;
            geoStatus.innerHTML = '<span class="badge-dot"></span>' + label;
            geoStatus.className = 'status-badge ' + (ok ? 'badge-success' : 'badge-amber');
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function (pos) {
                lat = pos.coords.latitude;
                lng = pos.coords.longitude;
                setGeo(true, 'Location on');
            }, function () {
                setGeo(false, 'Location blocked — same-city matching still works');
            }, { enableHighAccuracy: true, timeout: 8000 });
        } else {
            setGeo(false, 'GPS not available');
        }

        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (window.Notification && Notification.permission === 'default') {
                    Notification.requestPermission();
                }
                const msg = document.getElementById('nearby-form-msg');
                const payload = {
                    action: 'post_nearby',
                    csrf_token: csrf,
                    origin: document.getElementById('origin').value,
                    destination: document.getElementById('destination').value,
                    estimated_distance_km: parseFloat(document.getElementById('distance').value),
                    passengers_count: parseInt(document.getElementById('passengers').value, 10),
                    budget_amount: parseFloat(document.getElementById('budget').value),
                    lat: lat,
                    lng: lng
                };
                fetch('nearby-api', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (!data.ok) {
                        msg.hidden = false;
                        msg.className = 'notice notice-error';
                        msg.textContent = data.error || 'Could not post request.';
                        return;
                    }
                    window.location.reload();
                }).catch(function () {
                    msg.hidden = false;
                    msg.className = 'notice notice-error';
                    msg.textContent = 'Network error. Try again.';
                });
            });
        }

        function cancelNearby() {
            if (!waiting) return;
            fetch('nearby-api', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'cancel_nearby',
                    csrf_token: csrf,
                    request_id: parseInt(waiting.dataset.requestId, 10)
                })
            }).then(function () { window.location.reload(); });
        }

        document.querySelectorAll('.js-cancel-nearby').forEach(function (btn) {
            btn.addEventListener('click', cancelNearby);
        });

        const continueBtn = document.getElementById('continue-nearby');
        if (continueBtn && waiting) {
            continueBtn.addEventListener('click', function () {
                continueBtn.disabled = true;
                continueBtn.textContent = 'Searching again…';
                fetch('nearby-api', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'continue_nearby',
                        csrf_token: csrf,
                        request_id: parseInt(waiting.dataset.requestId, 10)
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
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

        if (waiting) {
            const searching = document.getElementById('nearby-searching');
            const expired = document.getElementById('nearby-expired');
            const timerEl = document.getElementById('nearby-timer');
            const windowSeconds = parseInt(waiting.dataset.window, 10) || 300;
            const started = parseInt(waiting.dataset.started, 10) || 0;
            const serverNow = parseInt(waiting.dataset.serverNow, 10) || Math.floor(Date.now() / 1000);
            const skew = (Date.now() / 1000) - serverNow;
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

            setInterval(function () {
                fetch('nearby-api?action=poll&waiting=1&after_id=0')
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.redirect) {
                            window.location.href = data.redirect;
                        }
                    }).catch(function () {});
            }, 5000);
        }
    })();
    </script>
    <?php endif; ?>

    <?php if ($currentAcc): ?>
    <script>
    (function () {
        const role = <?= json_encode($accountType) ?>;
        const csrf = <?= json_encode(csrfToken()) ?>;
        const list = document.getElementById(role === 'rider' ? 'request-list' : 'rider-list');
        if (!list) return;

        function esc(str) {
            return String(str || '').replace(/[&<>"']/g, function (ch) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
            });
        }

        function paint(html) {
            if (list.dataset.sig === html) return;
            list.dataset.sig = html;
            list.innerHTML = html;
        }

        function renderRiders(riders) {
            if (!riders.length) {
                paint('<p class="zr-notify-empty" style="padding: 8px 0;">No live GPS matches yet. Allow location — nearby online riders will appear here.</p>');
                return;
            }
            paint(riders.map(function (rider) {
                return '<div class="nearby-match-row"><div><strong>Rider: ' + esc(rider.full_name) + '</strong>' +
                    (rider.verified ? ' <span class="verified-tag" title="Verified" aria-label="Verified"></span>' : '') +
                    '<div style="font-size: 13px; color: var(--muted);">' + esc(rider.vehicle_icon) + ' ' +
                    esc(rider.vehicle_label) + ' · ' + esc(rider.rating_label) + '</div></div>' +
                    '<span class="nearby-dist">' + esc(rider.distance_label) + '</span></div>';
            }).join(''));
        }

        function renderRequests(requests) {
            if (!requests.length) {
                paint('<p class="zr-notify-empty" style="padding: 8px 0;">No nearby short-trip requests right now. You will get a live alert when a customer close to you wants to go a short distance.</p>');
                return;
            }
            paint(requests.map(function (req) {
                const trip = req.trip_label ? ' · ' + esc(req.trip_label) : '';
                return '<div class="nearby-match-row"><div><strong>' + esc(req.origin) + ' → ' + esc(req.destination) + '</strong>' +
                    '<div style="font-size: 13px; color: var(--muted);">' + esc(req.budget_label) +
                    ' · ' + esc(req.passengers_count) + ' passenger(s)' + trip + '</div></div>' +
                    '<div style="display: flex; align-items: center; gap: 10px;">' +
                    '<span class="nearby-dist">' + esc(req.distance_label) + '</span>' +
                    '<form method="post" action="request-action.php" style="margin: 0;">' +
                    '<input type="hidden" name="csrf_token" value="' + esc(csrf) + '">' +
                    '<input type="hidden" name="request_id" value="' + esc(req.id) + '">' +
                    '<input type="hidden" name="action" value="accept_request">' +
                    '<button class="button button-sm button-emerald" type="submit">Accept</button></form></div></div>';
            }).join(''));
        }

        function tick() {
            const action = role === 'rider' ? 'nearby_requests' : 'nearby_riders';
            fetch('nearby-api?action=' + action)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) return;
                    if (role === 'rider') renderRequests(data.requests || []);
                    else renderRiders(data.riders || []);
                })
                .catch(function () {});
        }

        setInterval(tick, 8000);
    })();
    </script>
    <?php endif; ?>

    <?= renderMobileBottomNav('nearby') ?>
</body>
</html>
