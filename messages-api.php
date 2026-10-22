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

function messagePreview(string $text): string
{
    $preview = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if (function_exists('mb_strlen') && mb_strlen($preview) > 160) {
        return mb_substr($preview, 0, 157) . '...';
    }
    if (strlen($preview) > 160) {
        return substr($preview, 0, 157) . '...';
    }
    return $preview;
}

function formatChatMessage(array $row, string $accountType, int $accountId): array
{
    $mine = $row['sender_type'] === $accountType && (int) $row['sender_id'] === $accountId;
    return [
        'id' => (int) $row['id'],
        'mine' => $mine,
        'text' => (string) $row['message'],
        'time' => date('g:i A', strtotime((string) $row['created_at'])),
        'read' => (int) $row['is_read'] === 1,
    ];
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

$action = (string) ($_GET['action'] ?? $jsonBody['action'] ?? $_POST['action'] ?? '');
$bookingId = (int) ($_GET['booking_id'] ?? $jsonBody['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($bookingId <= 0) {
    jsonOut(['ok' => false, 'error' => 'Booking is required.'], 400);
}

$bStmt = db()->prepare(
    "SELECT b.id, b.customer_id, b.rider_id, b.status, b.booking_type
     FROM bookings b
     WHERE b.id = ?"
);
$bStmt->execute([$bookingId]);
$booking = $bStmt->fetch();

if (!$booking) {
    jsonOut(['ok' => false, 'error' => 'Booking not found.'], 404);
}

$isCustomer = $accountType === 'customer' && $accountId === (int) $booking['customer_id'];
$isRider = $accountType === 'rider' && $accountId === (int) $booking['rider_id'];
if (!$isCustomer && !$isRider) {
    jsonOut(['ok' => false, 'error' => 'You are not part of this conversation.'], 403);
}

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $afterId = max(0, (int) ($_GET['after_id'] ?? 0));

    $mark = db()->prepare(
        "UPDATE messages SET is_read = 1 WHERE booking_id = ? AND sender_type != ? AND is_read = 0"
    );
    $mark->execute([$bookingId, $accountType]);
    touchChatPresence($bookingId, $accountType);
    clearOpenChatNotifications($bookingId, $accountType, $accountId);

    $mStmt = db()->prepare(
        "SELECT id, sender_type, sender_id, message, is_read, created_at
         FROM messages
         WHERE booking_id = ? AND id > ?
         ORDER BY id ASC
         LIMIT 50"
    );
    $mStmt->execute([$bookingId, $afterId]);
    $rows = $mStmt->fetchAll();

    $readStmt = db()->prepare(
        "SELECT COALESCE(MAX(id), 0) FROM messages
         WHERE booking_id = ? AND sender_type = ? AND sender_id = ? AND is_read = 1"
    );
    $readStmt->execute([$bookingId, $accountType, $accountId]);

    $latestId = $afterId;
    $messages = [];
    foreach ($rows as $row) {
        $formatted = formatChatMessage($row, $accountType, $accountId);
        $latestId = max($latestId, $formatted['id']);
        $messages[] = $formatted;
    }

    jsonOut([
        'ok' => true,
        'messages' => $messages,
        'latest_id' => $latestId,
        'read_up_to' => (int) $readStmt->fetchColumn(),
    ]);
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($jsonBody['csrf_token'] ?? $_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        jsonOut(['ok' => false, 'error' => 'Session verification failed.'], 403);
    }

    $text = trim((string) ($jsonBody['message'] ?? $_POST['message'] ?? ''));
    if ($text === '') {
        jsonOut(['ok' => false, 'error' => 'Message is empty.'], 400);
    }
    if (function_exists('mb_strlen') ? mb_strlen($text) > 1000 : strlen($text) > 1000) {
        jsonOut(['ok' => false, 'error' => 'Message is too long.'], 400);
    }

    $ins = db()->prepare(
        "INSERT INTO messages (booking_id, sender_type, sender_id, message) VALUES (?, ?, ?, ?)"
    );
    $ins->execute([$bookingId, $accountType, $accountId, $text]);
    $messageId = (int) db()->lastInsertId();

    $rowStmt = db()->prepare(
        "SELECT id, sender_type, sender_id, message, is_read, created_at FROM messages WHERE id = ?"
    );
    $rowStmt->execute([$messageId]);
    $row = $rowStmt->fetch();

    $sender = currentAccount();
    $senderName = is_array($sender) ? (string) ($sender['full_name'] ?? 'Someone') : 'Someone';
    $recipientType = $isCustomer ? 'rider' : 'customer';
    if ($recipientType === 'rider' && !passengerNameVisibleToRider((string) ($booking['status'] ?? ''))) {
        $senderName = ($booking['booking_type'] ?? '') === 'delivery' ? 'A sender' : 'A passenger';
    }
    $recipientId = $isCustomer ? (int) $booking['rider_id'] : (int) $booking['customer_id'];
    if (!recipientIsInChat($bookingId, $recipientType)) {
        createNotification(
            $recipientType,
            $recipientId,
            'chat_message',
            'New message from ' . $senderName,
            messagePreview($text),
            'messages.php?booking_id=' . $bookingId,
            'booking',
            $bookingId
        );
    }

    jsonOut([
        'ok' => true,
        'message' => $row ? formatChatMessage($row, $accountType, $accountId) : null,
    ]);
}

jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
