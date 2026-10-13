CREATE DATABASE IF NOT EXISTS Zaride
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE Zaride;

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(25) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL DEFAULT 'Lagos',
    avatar_color VARCHAR(20) NOT NULL DEFAULT '#059669',
    avatar_path VARCHAR(255) NULL,
    status ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_email (email),
    UNIQUE KEY uq_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS riders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(25) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
    whatsapp VARCHAR(25) NULL,
    city VARCHAR(100) NOT NULL DEFAULT 'Lagos',
    bio TEXT NULL,
    avatar_path VARCHAR(255) NULL,
    services_offered ENUM('both', 'travel', 'delivery') NOT NULL DEFAULT 'both',
    vehicle_type VARCHAR(100) NULL DEFAULT 'car',
    vehicle_model VARCHAR(100) NULL,
    vehicle_color VARCHAR(50) NULL,
    plate_number VARCHAR(32) NULL,
    seats_available INT UNSIGNED NOT NULL DEFAULT 4,
    max_cargo_kg INT UNSIGNED NOT NULL DEFAULT 20,
    vehicle_photo_path VARCHAR(255) NULL,
    plate_photo_path VARCHAR(255) NULL,
    rating_avg DECIMAL(3,2) NOT NULL DEFAULT 5.00,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    trips_completed INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_riders_email (email),
    UNIQUE KEY uq_riders_phone (phone),
    KEY idx_riders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rider_id BIGINT UNSIGNED NOT NULL,
    service_type ENUM('travel', 'delivery', 'both') NOT NULL DEFAULT 'both',
    origin_city VARCHAR(100) NOT NULL,
    origin_address VARCHAR(255) NOT NULL,
    destination_city VARCHAR(100) NOT NULL,
    destination_address VARCHAR(255) NOT NULL,
    departure_time DATETIME NOT NULL,
    available_seats INT UNSIGNED NOT NULL DEFAULT 3,
    price_per_seat DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    cargo_capacity_desc VARCHAR(255) NULL,
    additional_notes TEXT NULL,
    status ENUM('active', 'fully_booked', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trips_cities (origin_city, destination_city),
    KEY idx_trips_departure (departure_time),
    KEY idx_trips_status (status),
    CONSTRAINT fk_trips_rider FOREIGN KEY (rider_id) REFERENCES riders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    request_type ENUM('travel', 'delivery') NOT NULL DEFAULT 'travel',
    origin VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    preferred_time DATETIME NOT NULL,
    passengers_count INT UNSIGNED NOT NULL DEFAULT 1,
    package_details TEXT NULL,
    package_weight_category ENUM('small', 'medium', 'large', 'heavy') NULL,
    recipient_name VARCHAR(120) NULL,
    recipient_phone VARCHAR(25) NULL,
    budget_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('open', 'accepted', 'in_transit', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
    assigned_rider_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_requests_status (status),
    CONSTRAINT fk_requests_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_rider FOREIGN KEY (assigned_rider_id) REFERENCES riders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trip_id BIGINT UNSIGNED NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NOT NULL,
    booking_type ENUM('travel', 'delivery') NOT NULL DEFAULT 'travel',
    seats_booked INT UNSIGNED NOT NULL DEFAULT 1,
    package_desc VARCHAR(255) NULL,
    pickup_location VARCHAR(255) NOT NULL,
    dropoff_location VARCHAR(255) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    fare_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'confirmed', 'in_transit', 'completed', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
    customer_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bookings_status (status),
    CONSTRAINT fk_bookings_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE SET NULL,
    CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_rider FOREIGN KEY (rider_id) REFERENCES riders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('customer', 'rider') NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_booking (booking_id),
    CONSTRAINT fk_messages_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    reviewer_type ENUM('customer', 'rider') NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reviews_rider (rider_id),
    CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_rider FOREIGN KEY (rider_id) REFERENCES riders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
