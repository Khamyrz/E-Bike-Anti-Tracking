<?php
/**
 * Zone Settings Management
 * Stores and retrieves geofence and zone configuration
 */

/**
 * Ensure zone_settings table exists
 */
function zone_ensure_table($conn)
{
    $table_exists = $conn->query("SHOW TABLES LIKE 'zone_settings'");
    if ($table_exists->num_rows === 0) {
        $sql = "CREATE TABLE IF NOT EXISTS zone_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) UNIQUE NOT NULL,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $conn->query($sql);
    }
}

/**
 * Get a setting value
 */
function zone_get_setting($conn, $key, $default = '')
{
    zone_ensure_table($conn);
    
    $stmt = $conn->prepare("SELECT setting_value FROM zone_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row ? $row['setting_value'] : $default;
}

/**
 * Set a setting value
 */
function zone_set_setting($conn, $key, $value)
{
    zone_ensure_table($conn);
    
    $stmt = $conn->prepare("
        INSERT INTO zone_settings (setting_key, setting_value) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    
    if (!$stmt) {
        error_log("Failed to prepare zone_set_setting: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param('ss', $key, $value);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Load all zone settings
 */
function zone_load_settings($conn)
{
    zone_ensure_table($conn);
    
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM zone_settings");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return $settings;
}

/**
 * Get perimeter points from settings
 */
function zone_get_perimeter($conn)
{
    $json = zone_get_setting($conn, 'perimeter_points', '');
    if ($json === '' || $json === null) {
        return [];
    }
    
    $points = json_decode($json, true);
    return is_array($points) ? $points : [];
}