<?php
declare(strict_types=1);

function ensureStaffSchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('super_admin', 'admin') NOT NULL,
            status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_staff_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS deletion_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_type ENUM('customer', 'rider') NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(400) NULL,
            status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
            review_note VARCHAR(400) NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_deletion_status (status, created_at),
            KEY idx_deletion_account (account_type, account_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    addColumnIfMissing(
        'riders',
        'verification_status',
        "ALTER TABLE riders ADD COLUMN verification_status ENUM('unsubmitted', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved'"
    );
    $verificationType = columnType('riders', 'verification_status');
    if (!str_contains($verificationType, 'unsubmitted')) {
        $pdo->exec(
            "ALTER TABLE riders MODIFY COLUMN verification_status ENUM('unsubmitted', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'unsubmitted'"
        );
    }

    addColumnIfMissing(
        'riders',
        'account_status',
        "ALTER TABLE riders ADD COLUMN account_status ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active'"
    );
    addColumnIfMissing('riders', 'suspension_reason', 'ALTER TABLE riders ADD COLUMN suspension_reason VARCHAR(255) NULL');
    addColumnIfMissing('riders', 'verification_note', 'ALTER TABLE riders ADD COLUMN verification_note VARCHAR(400) NULL');
    addColumnIfMissing('riders', 'verified_at', 'ALTER TABLE riders ADD COLUMN verified_at DATETIME NULL');
    addColumnIfMissing('riders', 'verified_by', 'ALTER TABLE riders ADD COLUMN verified_by BIGINT UNSIGNED NULL');
    addColumnIfMissing('riders', 'deleted_at', 'ALTER TABLE riders ADD COLUMN deleted_at DATETIME NULL');

    addColumnIfMissing(
        'customers',
        'account_status',
        "ALTER TABLE customers ADD COLUMN account_status ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active'"
    );
    addColumnIfMissing('customers', 'suspension_reason', 'ALTER TABLE customers ADD COLUMN suspension_reason VARCHAR(255) NULL');
    addColumnIfMissing('customers', 'deleted_at', 'ALTER TABLE customers ADD COLUMN deleted_at DATETIME NULL');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_trip_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            rider_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            driver_name VARCHAR(120) NOT NULL,
            driver_phone VARCHAR(25) NULL,
            passenger_name VARCHAR(120) NOT NULL,
            passenger_phone VARCHAR(25) NULL,
            vehicle_label VARCHAR(160) NULL,
            plate_number VARCHAR(40) NULL,
            booking_type ENUM('travel', 'delivery') NOT NULL DEFAULT 'travel',
            seats_booked INT UNSIGNED NOT NULL DEFAULT 1,
            package_desc VARCHAR(255) NULL,
            pickup_location VARCHAR(255) NOT NULL,
            dropoff_location VARCHAR(255) NOT NULL,
            scheduled_at DATETIME NOT NULL,
            fare_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('confirmed', 'in_transit', 'completed', 'cancelled') NOT NULL,
            confirmed_at DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_trip_booking (booking_id),
            KEY idx_admin_trip_rider (rider_id, confirmed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    backfillAdminTripHistory();

    ensureSuperAdminSeed();
}

function addColumnIfMissing(string $table, string $column, string $sql): void
{
    if (columnDefault($table, $column) !== null || columnExists($table, $column)) {
        return;
    }
    try {
        db()->exec($sql);
    } catch (PDOException $e) {
        // Column already exists.
    }
}

function columnExists(string $table, string $column): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnType(string $table, string $column): string
{
    $stmt = db()->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $value = $stmt->fetchColumn();
    return $value === false ? '' : strtolower((string) $value);
}

function columnDefault(string $table, string $column): ?string
{
    $stmt = db()->prepare(
        'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return null;
    }
    return strtolower(trim((string) $value, "'"));
}

function ensureSuperAdminSeed(): void
{
    $email = 'bukarsamuelisaac@gmail.com';
    $existing = db()->prepare('SELECT id FROM staff WHERE email = ? LIMIT 1');
    $existing->execute([$email]);
    if ($existing->fetch()) {
        return;
    }

    $hash = '$2y$10$xgw3xG798Fq.JeSRY5rYb.DDV6iny6zEUKC27AfuA2nK0remMXfg2';
    db()->prepare(
        "INSERT INTO staff (full_name, email, password_hash, role, status) VALUES (?, ?, ?, 'super_admin', 'active')"
    )->execute(['Super Admin', $email, $hash]);
}

function listedRiderSql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return "{$prefix}account_status = 'active'
        AND {$prefix}deleted_at IS NULL
        AND {$prefix}verification_status = 'approved'";
}

function accountSignInBlock(array $account): ?string
{
    if (!empty($account['deleted_at']) || ($account['account_status'] ?? '') === 'deleted') {
        return 'This account has been deleted.';
    }
    return null;
}

function suspensionMessage(array $account): ?string
{
    if (($account['account_status'] ?? 'active') !== 'suspended') {
        return null;
    }
    $reason = trim((string) ($account['suspension_reason'] ?? ''));
    return $reason !== ''
        ? 'This account is suspended. ' . $reason
        : 'This account is suspended.';
}

function riderOffersTravel(array $rider): bool
{
    return in_array((string) ($rider['services_offered'] ?? 'both'), ['travel', 'both'], true);
}

function riderSubmissionGaps(array $rider): array
{
    $gaps = [];
    if (trim((string) ($rider['vehicle_type'] ?? '')) === '') {
        $gaps[] = 'vehicle type';
    }
    if (trim((string) ($rider['vehicle_model'] ?? '')) === '') {
        $gaps[] = 'vehicle model';
    }
    if (trim((string) ($rider['plate_number'] ?? '')) === '') {
        $gaps[] = 'plate number';
    }
    if (trim((string) ($rider['vehicle_photo_path'] ?? '')) === '') {
        $gaps[] = 'vehicle photo';
    }
    return $gaps;
}

function riderIsVerified(array $rider): bool
{
    return ($rider['verification_status'] ?? '') === 'approved';
}

function renderVerifiedTag(array $rider): string
{
    if (!riderIsVerified($rider)) {
        return '';
    }
    return '<span class="verified-tag" title="Verified" aria-label="Verified"></span>';
}

function riderWorkBlock(array $rider): ?string
{
    $accountBlock = accountSignInBlock($rider) ?? suspensionMessage($rider);
    if ($accountBlock !== null) {
        return $accountBlock;
    }
    if (riderIsVerified($rider)) {
        return null;
    }
    $status = (string) ($rider['verification_status'] ?? 'unsubmitted');
    if ($status === 'rejected') {
        $note = trim((string) ($rider['verification_note'] ?? ''));
        return $note !== ''
            ? 'Your vehicle was not approved. ' . $note
            : 'Your vehicle was not approved. Update the details and submit them again.';
    }
    if ($status === 'pending') {
        return 'Your vehicle details are with the admin team. You can take trips after they approve you.';
    }
    return 'Save your vehicle details, then confirm submission. You can take trips after an admin verifies you.';
}

function fetchRiderById(int $riderId): ?array
{
    $stmt = db()->prepare('SELECT * FROM riders WHERE id = ?');
    $stmt->execute([$riderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function denyRiderWork(int $riderId, string $redirect): void
{
    $rider = fetchRiderById($riderId);
    if ($rider === null) {
        return;
    }
    $block = riderWorkBlock($rider);
    if ($block === null) {
        return;
    }
    setFlash('error', $block);
    header('Location: ' . $redirect);
    exit;
}

function syncRiderAvailability(int $riderId): void
{
    $rider = fetchRiderById($riderId);
    if ($rider === null || ($rider['status'] ?? '') !== 'online') {
        return;
    }
    if (riderWorkBlock($rider) !== null) {
        db()->prepare("UPDATE riders SET status = 'offline' WHERE id = ?")->execute([$riderId]);
    }
}

function riderEarnings(int $riderId): array
{
    $stmt = db()->prepare(
        "SELECT
            COALESCE(SUM(fare_amount), 0) AS total_earned,
            COUNT(*) AS completed_rides,
            COALESCE(SUM(CASE WHEN booking_type = 'travel' THEN fare_amount ELSE 0 END), 0) AS travel_earned,
            COALESCE(SUM(CASE WHEN booking_type = 'delivery' THEN fare_amount ELSE 0 END), 0) AS delivery_earned,
            COALESCE(SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN fare_amount ELSE 0 END), 0) AS week_earned,
            COALESCE(SUM(CASE WHEN updated_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN fare_amount ELSE 0 END), 0) AS month_earned
         FROM bookings
         WHERE rider_id = ? AND status = 'completed'"
    );
    $stmt->execute([$riderId]);
    $row = $stmt->fetch() ?: [];
    return [
        'total_earned' => (float) ($row['total_earned'] ?? 0),
        'completed_rides' => (int) ($row['completed_rides'] ?? 0),
        'travel_earned' => (float) ($row['travel_earned'] ?? 0),
        'delivery_earned' => (float) ($row['delivery_earned'] ?? 0),
        'week_earned' => (float) ($row['week_earned'] ?? 0),
        'month_earned' => (float) ($row['month_earned'] ?? 0),
    ];
}

function pendingDeletionRequest(string $accountType, int $accountId): ?array
{
    $stmt = db()->prepare(
        "SELECT * FROM deletion_requests
         WHERE account_type = ? AND account_id = ? AND status = 'pending'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$accountType, $accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function latestDeletionRequest(string $accountType, int $accountId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM deletion_requests WHERE account_type = ? AND account_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$accountType, $accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function requestAccountDeletion(string $accountType, int $accountId, string $reason): string
{
    if (pendingDeletionRequest($accountType, $accountId)) {
        return 'A deletion request is already waiting for review.';
    }
    $cleanReason = trim($reason);
    if ($cleanReason === '') {
        $cleanReason = 'No reason given';
    }
    if (function_exists('mb_substr')) {
        $cleanReason = mb_substr($cleanReason, 0, 400);
    } else {
        $cleanReason = substr($cleanReason, 0, 400);
    }
    db()->prepare(
        'INSERT INTO deletion_requests (account_type, account_id, reason, status) VALUES (?, ?, ?, "pending")'
    )->execute([$accountType, $accountId, $cleanReason]);
    return '';
}

function cancelAccountDeletion(string $accountType, int $accountId): void
{
    db()->prepare(
        "UPDATE deletion_requests
         SET status = 'cancelled', reviewed_at = NOW()
         WHERE account_type = ? AND account_id = ? AND status = 'pending'"
    )->execute([$accountType, $accountId]);
}

function accountOpenWorkMessage(string $accountType, int $accountId): ?string
{
    if ($accountType === 'rider') {
        $bookings = db()->prepare(
            "SELECT COUNT(*) FROM bookings WHERE rider_id = ? AND status IN ('pending', 'confirmed', 'in_transit')"
        );
        $bookings->execute([$accountId]);
        if ((int) $bookings->fetchColumn() > 0) {
            return 'This rider still has a ride that is not finished.';
        }
        $trips = db()->prepare(
            "SELECT COUNT(*) FROM trips WHERE rider_id = ? AND status = 'in_progress'"
        );
        $trips->execute([$accountId]);
        if ((int) $trips->fetchColumn() > 0) {
            return 'This rider still has a trip in progress.';
        }
        return null;
    }

    $bookings = db()->prepare(
        "SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND status IN ('pending', 'confirmed', 'in_transit')"
    );
    $bookings->execute([$accountId]);
    if ((int) $bookings->fetchColumn() > 0) {
        return 'This customer still has a ride that is not finished.';
    }
    $requests = db()->prepare(
        "SELECT COUNT(*) FROM requests WHERE customer_id = ? AND status IN ('accepted', 'in_transit')"
    );
    $requests->execute([$accountId]);
    if ((int) $requests->fetchColumn() > 0) {
        return 'This customer still has a request that is not finished.';
    }
    return null;
}

function removePersonAccount(string $accountType, int $accountId): string
{
    if (!in_array($accountType, ['customer', 'rider'], true) || $accountId <= 0) {
        return 'That account could not be found.';
    }

    $openWork = accountOpenWorkMessage($accountType, $accountId);
    if ($openWork !== null) {
        return $openWork;
    }

    $table = $accountType === 'rider' ? 'riders' : 'customers';
    $person = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
    $person->execute([$accountId]);
    $row = $person->fetch();
    if (!$row) {
        return 'That account could not be found.';
    }
    if (!empty($row['deleted_at']) || ($row['account_status'] ?? '') === 'deleted') {
        return 'That account has already been deleted.';
    }

    if ($accountType === 'rider') {
        db()->prepare(
            "UPDATE trips SET status = 'cancelled'
             WHERE rider_id = ? AND status IN ('active', 'fully_booked')"
        )->execute([$accountId]);
        db()->prepare(
            "UPDATE requests SET status = 'cancelled', assigned_rider_id = NULL
             WHERE assigned_rider_id = ? AND status = 'open'"
        )->execute([$accountId]);
        if (!empty($row['avatar_path'])) {
            deleteAvatarImage((string) $row['avatar_path']);
        }
        if (!empty($row['vehicle_photo_path'])) {
            deleteVehiclePhoto((string) $row['vehicle_photo_path']);
        }
        db()->prepare(
            "UPDATE riders
             SET full_name = 'Removed account',
                 email = ?,
                 phone = ?,
                 password_hash = ?,
                 google_sub = NULL,
                 whatsapp = NULL,
                 bio = NULL,
                 avatar_path = NULL,
                 vehicle_photo_path = NULL,
                 plate_photo_path = NULL,
                 plate_number = NULL,
                 status = 'offline',
                 account_status = 'deleted',
                 deleted_at = NOW()
             WHERE id = ?"
        )->execute([
            'removed-rider-' . $accountId . '@zaride.invalid',
            'removed-r-' . $accountId,
            password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            $accountId,
        ]);
    } else {
        db()->prepare(
            "UPDATE requests SET status = 'cancelled'
             WHERE customer_id = ? AND status = 'open'"
        )->execute([$accountId]);
        if (!empty($row['avatar_path'])) {
            deleteAvatarImage((string) $row['avatar_path']);
        }
        db()->prepare(
            "UPDATE customers
             SET full_name = 'Removed account',
                 email = ?,
                 phone = ?,
                 password_hash = ?,
                 google_sub = NULL,
                 avatar_path = NULL,
                 account_status = 'deleted',
                 status = 'offline',
                 deleted_at = NOW()
             WHERE id = ?"
        )->execute([
            'removed-customer-' . $accountId . '@zaride.invalid',
            'removed-c-' . $accountId,
            password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            $accountId,
        ]);
    }

    return '';
}

function approveAccountDeletion(int $requestId, int $staffId, string $note): string
{
    $stmt = db()->prepare("SELECT * FROM deletion_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return 'That deletion request is no longer waiting.';
    }

    $error = removePersonAccount((string) $request['account_type'], (int) $request['account_id']);
    if ($error !== '') {
        return $error;
    }

    $cleanNote = trim($note);
    db()->prepare(
        "UPDATE deletion_requests
         SET status = 'approved', review_note = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE id = ?"
    )->execute([$cleanNote !== '' ? $cleanNote : null, $staffId, $requestId]);

    return '';
}

function currentStaff(): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    $id = $_SESSION['staff_id'] ?? null;
    if (!is_numeric($id) || (int) $id <= 0) {
        return null;
    }

    try {
        $stmt = db()->prepare('SELECT * FROM staff WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }

    if (!$row || ($row['status'] ?? '') !== 'active') {
        unset($_SESSION['staff_id']);
        return null;
    }

    $cached = $row;
    return $cached;
}

function requireStaff(): array
{
    $staff = currentStaff();
    if ($staff === null) {
        setFlash('error', 'Sign in to the staff page to continue.');
        header('Location: gate-9f3k.php');
        exit;
    }
    return $staff;
}

function staffIsSuper(array $staff): bool
{
    return ($staff['role'] ?? '') === 'super_admin';
}

function rememberStaff(int $staffId): void
{
    $_SESSION['staff_id'] = $staffId;
    session_regenerate_id(true);
}

function saveAdminTripHistory(int $bookingId, ?string $forcedStatus = null, ?string $previousStatus = null): void
{
    $confirmedStates = ['confirmed', 'in_transit', 'completed'];
    if ($forcedStatus === 'cancelled' && !in_array((string) $previousStatus, $confirmedStates, true)) {
        $exists = db()->prepare('SELECT id FROM admin_trip_history WHERE booking_id = ?');
        $exists->execute([$bookingId]);
        if (!$exists->fetch()) {
            return;
        }
    }

    $stmt = db()->prepare(
        "SELECT b.*, r.full_name AS driver_name, r.phone AS driver_phone,
                r.vehicle_type, r.vehicle_model, r.plate_number,
                c.full_name AS passenger_name, c.phone AS passenger_phone
         FROM bookings b
         JOIN riders r ON r.id = b.rider_id
         JOIN customers c ON c.id = b.customer_id
         WHERE b.id = ?"
    );
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        return;
    }

    $status = $forcedStatus ?? (string) $booking['status'];
    if (!in_array($status, ['confirmed', 'in_transit', 'completed', 'cancelled'], true)) {
        return;
    }
    if ($status !== 'cancelled' && !in_array($status, $confirmedStates, true)) {
        return;
    }

    $vehicle = trim((string) ($booking['vehicle_model'] ?: $booking['vehicle_type']));
    db()->prepare(
        "INSERT INTO admin_trip_history (
            booking_id, rider_id, customer_id, driver_name, driver_phone,
            passenger_name, passenger_phone, vehicle_label, plate_number,
            booking_type, seats_booked, package_desc, pickup_location,
            dropoff_location, scheduled_at, fare_amount, status, confirmed_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            driver_name = VALUES(driver_name),
            driver_phone = VALUES(driver_phone),
            passenger_name = VALUES(passenger_name),
            passenger_phone = VALUES(passenger_phone),
            vehicle_label = VALUES(vehicle_label),
            plate_number = VALUES(plate_number),
            seats_booked = VALUES(seats_booked),
            package_desc = VALUES(package_desc),
            pickup_location = VALUES(pickup_location),
            dropoff_location = VALUES(dropoff_location),
            scheduled_at = VALUES(scheduled_at),
            fare_amount = VALUES(fare_amount),
            status = VALUES(status)"
    )->execute([
        $bookingId,
        (int) $booking['rider_id'],
        (int) $booking['customer_id'],
        (string) $booking['driver_name'],
        (string) $booking['driver_phone'],
        (string) $booking['passenger_name'],
        (string) $booking['passenger_phone'],
        $vehicle !== '' ? $vehicle : null,
        (string) ($booking['plate_number'] ?? ''),
        (string) $booking['booking_type'],
        (int) $booking['seats_booked'],
        $booking['package_desc'],
        (string) $booking['pickup_location'],
        (string) $booking['dropoff_location'],
        (string) $booking['scheduled_at'],
        $booking['fare_amount'],
        $status,
    ]);
}

function backfillAdminTripHistory(): void
{
    db()->exec(
        "INSERT INTO admin_trip_history (
            booking_id, rider_id, customer_id, driver_name, driver_phone,
            passenger_name, passenger_phone, vehicle_label, plate_number,
            booking_type, seats_booked, package_desc, pickup_location,
            dropoff_location, scheduled_at, fare_amount, status, confirmed_at
         )
         SELECT b.id, b.rider_id, b.customer_id, r.full_name, r.phone,
                c.full_name, c.phone,
                NULLIF(COALESCE(NULLIF(r.vehicle_model, ''), r.vehicle_type, ''), ''),
                r.plate_number,
                b.booking_type, b.seats_booked, b.package_desc, b.pickup_location,
                b.dropoff_location, b.scheduled_at, b.fare_amount, b.status,
                COALESCE(b.updated_at, b.created_at, NOW())
         FROM bookings b
         JOIN riders r ON r.id = b.rider_id
         JOIN customers c ON c.id = b.customer_id
         WHERE b.status IN ('confirmed', 'in_transit', 'completed')
         ON DUPLICATE KEY UPDATE
            status = IF(admin_trip_history.status = 'cancelled', admin_trip_history.status, VALUES(status)),
            fare_amount = VALUES(fare_amount)"
    );
}
