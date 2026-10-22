<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$accountId = currentAccountId();
$accountType = currentAccountType();

if ($accountId === null || $accountType === null) {
    setFlash('error', 'Please sign in to view your messages.');
    header('Location: login.php');
    exit;
}

$bookingId = (int) ($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) {
    setFlash('error', 'Booking ID is required.');
    header('Location: ' . dashboardUrl());
    exit;
}

// Fetch booking & authorization check
$bStmt = db()->prepare(
    "SELECT b.*,
            c.full_name as customer_name, c.phone as customer_phone,
            r.full_name as rider_name, r.phone as rider_phone,
            r.vehicle_type, r.vehicle_model, r.plate_number, r.verification_status
     FROM bookings b
     JOIN customers c ON b.customer_id = c.id
     JOIN riders r ON b.rider_id = r.id
     WHERE b.id = ?"
);
$bStmt->execute([$bookingId]);
$booking = $bStmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found.');
    header('Location: ' . dashboardUrl());
    exit;
}

$isCustomer = ($accountType === 'customer' && $accountId === (int) $booking['customer_id']);
$isRider = ($accountType === 'rider' && $accountId === (int) $booking['rider_id']);

if (!$isCustomer && !$isRider) {
    setFlash('error', 'You are not authorized to view this conversation.');
    header('Location: ' . dashboardUrl());
    exit;
}

$showPassengerName = $isRider && passengerNameVisibleToRider((string) $booking['status']);
$otherName = $isCustomer
    ? (string) $booking['rider_name']
    : ($showPassengerName ? (string) $booking['customer_name'] : 'Passenger');
$otherRole = $isCustomer ? 'Rider' : ($booking['booking_type'] === 'delivery' ? 'Sender' : 'Passenger');
$otherPhone = $isCustomer ? $booking['rider_phone'] : $booking['customer_phone'];

// Handle sending a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }

    $text = trim($_POST['message'] ?? '');
    if ($text !== '') {
        $insStmt = db()->prepare(
            "INSERT INTO messages (booking_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)"
        );
        $insStmt->execute([$bookingId, $accountType, $accountId, $text]);
    }

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => true]);
        exit;
    }

    header('Location: messages.php?booking_id=' . $bookingId);
    exit;
}

// Mark messages from other party as read. This chat is open, so its alerts are already seen.
$updRead = db()->prepare(
    "UPDATE messages SET is_read = 1 WHERE booking_id = ? AND sender_type != ?"
);
$updRead->execute([$bookingId, $accountType]);
touchChatPresence($bookingId, $accountType);
clearOpenChatNotifications($bookingId, $accountType, $accountId);

// Fetch all messages
$mStmt = db()->prepare(
    "SELECT * FROM messages WHERE booking_id = ? ORDER BY created_at ASC"
);
$mStmt->execute([$bookingId]);
$messages = $mStmt->fetchAll();

$currentAcc = currentAccount();
$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ZaRide | In-App P2P Chat with <?= e($otherName) ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body style="background: var(--surface-alt);">
    <header class="site-header">
        <a class="brand" href="index.php">
            <span class="brand-mark">Z</span>
            <div class="brand-text">
                <span class="brand-title">ZaRide</span>
                <span class="brand-tag">P2P Messenger</span>
            </div>
        </a>
        <nav class="site-nav">
            <a class="nav-link" href="dashboard.php?type=<?= e($accountType) ?>">← My Dashboard</a>
            <div class="user-menu-pill">
                <span class="user-avatar-mini"><?= strtoupper(substr($currentAcc['full_name'], 0, 1)) ?></span>
                <span style="font-weight: 700; font-size: 13px;"><?= e(explode(' ', $currentAcc['full_name'])[0]) ?></span>
                <span class="role-tag <?= e($accountType) ?>"><?= ucfirst($accountType) ?></span>
            </div>
        </nav>
    </header>

    <main class="container" style="max-width: 860px; padding-top: 24px; padding-bottom: 50px;">
        <?php if ($flash): ?>
            <div class="notice notice-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <!-- Booking Context Card -->
        <div class="booking-context">
            <div>
                <div class="booking-context-tags">
                    <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary); margin: 0; padding: 3px 10px;">
                        Booking #<?= (int) $booking['id'] ?> · <?= ucfirst($booking['booking_type']) ?>
                    </span>
                    <?php if (!empty($booking['trip_id'])): ?>
                        <?= renderPostedRouteSign($isRider, (string) ($booking['rider_name'] ?? '')) ?>
                    <?php endif; ?>
                    <?= renderStatusBadge($booking['status']) ?>
                </div>
                <div class="booking-context-route">
                    📍 <?= e($booking['pickup_location']) ?> → 🎯 <?= e($booking['dropoff_location']) ?>
                </div>
                <div style="font-size: 12px; color: var(--muted);">
                    Scheduled: <?= formatDateTime($booking['scheduled_at']) ?> · Total Fare: <strong style="color: var(--primary);"><?= formatMoney($booking['fare_amount']) ?></strong>
                </div>
            </div>

            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a class="button button-sm button-secondary" href="tel:<?= e($otherPhone) ?>">
                    📞 Call <?= e($otherPhone) ?>
                </a>
                <?php if ($isRider && in_array($booking['status'], ['pending', 'confirmed', 'in_transit'], true)): ?>
                    <form method="post" action="request-action.php" style="margin: 0;" onsubmit="return confirm('Cancel this booking? The customer will be notified.');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="update_booking_status">
                        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button class="button button-sm button-danger" type="submit">✕ Cancel booking</button>
                    </form>
                <?php elseif ($isCustomer && in_array($booking['status'], ['pending', 'confirmed'], true)): ?>
                    <form method="post" action="request-action.php" style="margin: 0;" onsubmit="return confirm('Cancel this booking? The rider will be notified.');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="update_booking_status">
                        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                        <input type="hidden" name="status" value="cancelled">
                        <button class="button button-sm button-danger" type="submit">✕ Cancel booking</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chat Window Component -->
        <div class="chat-window">
            <div class="chat-header">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="rider-avatar" style="width: 38px; height: 38px; font-size: 15px;">
                        <?= strtoupper(substr($otherName, 0, 1)) ?>
                    </div>
                    <div>
                        <div style="font-weight: 800; font-size: 15px; color: var(--ink); line-height: 1.2;">
                            <?= e($otherName) ?> <?= $isCustomer ? renderVerifiedTag($booking) : '' ?>
                        </div>
                        <div style="font-size: 12px; color: var(--muted);">
                            <?= e($otherRole) ?> <?= $isCustomer && !empty($booking['vehicle_model']) ? '· 🚘 ' . e($booking['vehicle_model']) : '' ?>
                        </div>
                    </div>
                </div>
                <span class="status-badge badge-success"><span class="badge-dot"></span>Active Chat</span>
            </div>

            <!-- Messages Stream -->
            <div class="chat-messages" id="chat-messages-container">
                <?php if ($messages === []): ?>
                    <div id="chat-empty" style="text-align: center; color: var(--muted); margin: auto;">
                        <span style="font-size: 32px; display: block; margin-bottom: 6px;">💬</span>
                        <p style="margin: 0; font-size: 13px;">No messages yet. Say hello or coordinate your pickup location below!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php
                        $isMine = ($msg['sender_type'] === $accountType && (int) $msg['sender_id'] === $accountId);
                        ?>
                        <div class="chat-bubble-wrap <?= $isMine ? 'mine' : 'theirs' ?>" data-id="<?= (int) $msg['id'] ?>">
                            <div class="chat-bubble"><?= e($msg['message']) ?></div>
                            <span class="chat-time">
                                <?= date('g:i A', strtotime($msg['created_at'])) ?>
                                <?= $isMine ? ((int) $msg['is_read'] === 1 ? ' · Read ✓✓' : ' · Sent ✓') : '' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Quick Template Coordinate Chips -->
            <div class="chat-chips">
                <?php if ($accountType === 'rider'): ?>
                    <button type="button" class="chat-chip" onclick="insertTemplate('I have arrived at the pickup spot.')">📍 Arrived at pickup</button>
                    <button type="button" class="chat-chip" onclick="insertTemplate('Starting the trip now.')">🚗 Starting trip now</button>
                    <button type="button" class="chat-chip" onclick="insertTemplate('Running 5-10 minutes behind schedule due to traffic.')">⏳ Slight traffic delay</button>
                    <button type="button" class="chat-chip" onclick="insertTemplate('Arrived at destination safely!')">🏁 Arrived safely</button>
                <?php else: ?>
                    <button type="button" class="chat-chip" onclick="insertTemplate('I am waiting at the pickup spot.')">📍 Waiting at pickup</button>
                    <button type="button" class="chat-chip" onclick="insertTemplate('Package is packed and ready for handover.')">📦 Parcel ready</button>
                    <button type="button" class="chat-chip" onclick="insertTemplate('Please call me when you reach the junction.')">📞 Call on arrival</button>
                    <button type="button" class="chat-chip" onclick="insertTemplate('Thank you so much!')">👍 Thank you</button>
                <?php endif; ?>
            </div>

            <!-- Chat Footer Input -->
            <div class="chat-footer">
                <form method="post" action="messages?booking_id=<?= $bookingId ?>" class="chat-input-form" id="chat-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input class="chat-input" id="message-input" name="message" placeholder="Type a message or tap a quick chip above..." autocomplete="off" required>
                    <button class="button button-primary" type="submit">Send →</button>
                </form>
            </div>
        </div>
    </main>

    <script>
    const container = document.getElementById('chat-messages-container');
    const chatForm = document.getElementById('chat-form');
    const messageInput = document.getElementById('message-input');
    const bookingId = <?= (int) $bookingId ?>;
    const csrf = <?= json_encode(csrfToken()) ?>;
    let latestId = <?= $messages === [] ? 0 : (int) $messages[count($messages) - 1]['id'] ?>;
    let pollDelay = 2500;
    let pollTimer = null;
    let sending = false;

    if (container) {
        container.scrollTop = container.scrollHeight;
    }

    function insertTemplate(text) {
        messageInput.value = text;
        messageInput.focus();
    }

    function appendMessage(msg) {
        if (!container || !msg || !msg.id) return;
        if (container.querySelector('[data-id="' + msg.id + '"]')) {
            latestId = Math.max(latestId, msg.id);
            return;
        }
        const empty = document.getElementById('chat-empty');
        if (empty) empty.remove();

        const wrap = document.createElement('div');
        wrap.className = 'chat-bubble-wrap ' + (msg.mine ? 'mine' : 'theirs');
        wrap.dataset.id = String(msg.id);

        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble';
        bubble.textContent = msg.text || '';

        const time = document.createElement('span');
        time.className = 'chat-time';
        let label = msg.time || '';
        if (msg.mine) label += msg.read ? ' · Read ✓✓' : ' · Sent ✓';
        time.textContent = label;

        wrap.appendChild(bubble);
        wrap.appendChild(time);
        container.appendChild(wrap);
        latestId = Math.max(latestId, msg.id);

        const distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
        if (msg.mine || distanceFromBottom < 140) {
            container.scrollTop = container.scrollHeight;
        }
    }

    function markRead(upTo) {
        if (!upTo) return;
        container.querySelectorAll('.chat-bubble-wrap.mine').forEach(function (el) {
            const id = parseInt(el.dataset.id || '0', 10);
            if (id > upTo) return;
            const time = el.querySelector('.chat-time');
            if (time && time.textContent.indexOf('Read') === -1) {
                time.textContent = time.textContent.replace('· Sent ✓', '· Read ✓✓');
            }
        });
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(pollMessages, pollDelay);
    }

    function pollMessages() {
        if (document.hidden) {
            schedulePoll();
            return;
        }
        fetch('messages-api?action=poll&booking_id=' + bookingId + '&after_id=' + latestId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    pollDelay = 8000;
                    schedulePoll();
                    return;
                }
                pollDelay = 2500;
                (data.messages || []).forEach(appendMessage);
                if (data.latest_id) latestId = Math.max(latestId, data.latest_id);
                markRead(data.read_up_to || 0);
                schedulePoll();
            })
            .catch(function () {
                pollDelay = 8000;
                schedulePoll();
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            pollDelay = 2500;
            clearTimeout(pollTimer);
            pollMessages();
        }
    });

    if (chatForm) {
        chatForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            const text = messageInput.value.trim();
            if (text === '' || sending) return;
            sending = true;
            messageInput.disabled = true;
            fetch('messages-api?booking_id=' + bookingId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'send',
                    csrf_token: csrf,
                    booking_id: bookingId,
                    message: text
                })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data || !data.ok) {
                    window.alert((data && data.error) || 'Could not send message.');
                    return;
                }
                messageInput.value = '';
                if (data.message) appendMessage(data.message);
            }).catch(function () {
                window.alert('Network error. Try again.');
            }).finally(function () {
                sending = false;
                messageInput.disabled = false;
                messageInput.focus();
            });
        });
    }

    pollMessages();
    </script>
    <?= renderMobileBottomNav('account') ?>
</body>
</html>
