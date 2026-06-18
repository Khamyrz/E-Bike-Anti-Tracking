CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    device_id VARCHAR(32) NOT NULL,
    api_token VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_devices_rider (rider_id),
    UNIQUE KEY uq_devices_device_id (device_id),
    CONSTRAINT fk_devices_rider FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
