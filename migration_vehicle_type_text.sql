USE Zaride;

ALTER TABLE riders
    MODIFY COLUMN vehicle_type VARCHAR(100) NULL DEFAULT 'car';
