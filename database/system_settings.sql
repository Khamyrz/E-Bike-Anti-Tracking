-- Tracks when the daily 11:00 PM rider reset last ran
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
