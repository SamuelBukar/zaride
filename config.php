<?php
declare(strict_types=1);

session_start();

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

    return $connection;
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
    return is_int($id) && $id > 0 ? $id : null;
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

    return $id;
}

function formatMoney(float|int|string $amount): string
{
    $val = (float) $amount;
    return '₦' . number_format($val, 0);
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
        ['id' => 'travels', 'url' => 'travels.php', 'label' => 'Travels', 'icon' => '🚗'],
        ['id' => 'deliveries', 'url' => 'deliveries.php', 'label' => 'Deliveries', 'icon' => '📦'],
        ['id' => 'riders', 'url' => 'zaride.php', 'label' => 'Riders', 'icon' => '👥'],
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

    return $html;
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

function renderUserAvatar(?array $user, string $sizeClass = ''): string
{
    $name = $user['full_name'] ?? 'User';
    $initial = strtoupper(mb_substr($name, 0, 1));
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

