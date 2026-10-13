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
            r.full_name as rider_name, r.phone as rider_phone, r.whatsapp as rider_whatsapp,
            r.vehicle_type, r.vehicle_model, r.plate_number
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

$otherName = $isCustomer ? $booking['rider_name'] : $booking['customer_name'];
$otherRole = $isCustomer ? 'Driver / Courier' : 'Passenger / Sender';
$otherPhone = $isCustomer ? $booking['rider_phone'] : $booking['customer_phone'];
$otherWhatsapp = $isCustomer ? $booking['rider_whatsapp'] : null;

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

// Mark messages from other party as read
$updRead = db()->prepare(
    "UPDATE messages SET is_read = 1 WHERE booking_id = ? AND sender_type != ?"
);
$updRead->execute([$bookingId, $accountType]);

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
        <div style="background: white; border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 18px 22px; margin-bottom: 16px; box-shadow: var(--shadow-sm); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                    <span class="hero-eyebrow" style="background: var(--primary-subtle); color: var(--primary); margin: 0; padding: 3px 10px;">
                        Booking #<?= (int) $booking['id'] ?> · <?= ucfirst($booking['booking_type']) ?>
                    </span>
                    <?= renderStatusBadge($booking['status']) ?>
                </div>
                <div style="font-size: 15px; font-weight: 700; color: var(--ink);">
                    📍 <?= e($booking['pickup_location']) ?> → 🎯 <?= e($booking['dropoff_location']) ?>
                </div>
                <div style="font-size: 12px; color: var(--muted);">
                    Scheduled: <?= formatDateTime($booking['scheduled_at']) ?> · Total Fare: <strong style="color: var(--primary);"><?= formatMoney($booking['fare_amount']) ?></strong>
                </div>
            </div>

            <div style="display: flex; gap: 8px;">
                <a class="button button-sm button-secondary" href="tel:<?= e($otherPhone) ?>">
                    📞 Call <?= e($otherPhone) ?>
                </a>
                <?php if (!empty($otherWhatsapp)): ?>
                    <a class="button button-sm button-whatsapp" href="https://wa.me/<?= e(preg_replace('/\D+/', '', $otherWhatsapp)) ?>?text=<?= rawurlencode("Hello {$otherName}, this is about our ZaRide booking #{$booking['id']}.") ?>" target="_blank" rel="noopener noreferrer">
                        WhatsApp
                    </a>
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
                            <?= e($otherName) ?>
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
                    <div style="text-align: center; color: var(--muted); margin: auto;">
                        <span style="font-size: 32px; display: block; margin-bottom: 6px;">💬</span>
                        <p style="margin: 0; font-size: 13px;">No messages yet. Say hello or coordinate your pickup location below!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php 
                        $isMine = ($msg['sender_type'] === $accountType && (int) $msg['sender_id'] === $accountId);
                        ?>
                        <div class="chat-bubble-wrap <?= $isMine ? 'mine' : 'theirs' ?>">
                            <div class="chat-bubble">
                                <?= nl2br(e($msg['message'])) ?>
                            </div>
                            <span class="chat-time">
                                <?= date('g:i A', strtotime($msg['created_at'])) ?>
                                <?= $isMine ? ($msg['is_read'] ? '· Read ✓✓' : '· Sent ✓') : '' ?>
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
                <form method="post" action="messages.php?booking_id=<?= $bookingId ?>" class="chat-input-form" id="chat-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input class="chat-input" id="message-input" name="message" placeholder="Type a message or tap a quick chip above..." autocomplete="off" required>
                    <button class="button button-primary" type="submit">Send →</button>
                </form>
            </div>
        </div>
    </main>

    <script>
    const container = document.getElementById('chat-messages-container');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }

    function insertTemplate(text) {
        const input = document.getElementById('message-input');
        input.value = text;
        input.focus();
    }
    </script>
    <?= renderMobileBottomNav('account') ?>
</body>
</html>
