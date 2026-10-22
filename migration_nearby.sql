

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS last_lat DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS last_lng DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS last_location_at DATETIME NULL;

ALTER TABLE riders
    ADD COLUMN IF NOT EXISTS last_lat DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS last_lng DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS last_location_at DATETIME NULL;

ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS trip_mode ENUM('scheduled', 'nearby') NOT NULL DEFAULT 'scheduled',
    ADD COLUMN IF NOT EXISTS origin_lat DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS origin_lng DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS dest_lat DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS dest_lng DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS estimated_distance_km DECIMAL(6,2) NULL,
    ADD COLUMN IF NOT EXISTS search_started_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
