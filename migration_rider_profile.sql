USE Zaride;

ALTER TABLE riders
    ADD COLUMN whatsapp VARCHAR(25) NULL AFTER status,
    ADD COLUMN vehicle_type VARCHAR(100) NULL DEFAULT 'car' AFTER whatsapp,
    ADD COLUMN vehicle_photo_path VARCHAR(255) NULL AFTER vehicle_type,
    ADD COLUMN plate_number VARCHAR(32) NULL AFTER vehicle_photo_path,
    ADD COLUMN plate_photo_path VARCHAR(255) NULL AFTER plate_number;
