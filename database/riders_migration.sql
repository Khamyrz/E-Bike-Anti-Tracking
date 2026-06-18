-- Rider profile fields for admin-managed registration
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS ebike_id VARCHAR(10) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS face_data LONGTEXT NULL AFTER ebike_id;

-- Unique E-Bike ID per rider (MySQL 8+ IF NOT EXISTS on index may need manual check)
-- Run separately if index already exists:
-- ALTER TABLE users ADD UNIQUE KEY uq_users_ebike_id (ebike_id);
