<?php
/**
 * Rider Online Status Management
 * UPDATED: 30 seconds threshold para sa real-time
 * Online = May data within 30 seconds
 * Offline = Walang data within 30 seconds
 */

// ✅ 30 SECONDS - Para sa real-time online/offline
define('RIDER_ONLINE_STALE_SECONDS', 30);

function rider_online_ensure_columns($conn)
{
    static $done = false;
    if ($done) return;

    $columns = [
        'is_online'      => "TINYINT(1) NOT NULL DEFAULT 0",
        'last_online_at' => "DATETIME NULL DEFAULT NULL",
        'online_source'  => "VARCHAR(20) NULL DEFAULT NULL",
    ];

    foreach ($columns as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN `$column` $definition");
        }
    }
    
    $done = true;
}

function rider_set_online($conn, $rider_id, $source = 'esp32')
{
    rider_online_ensure_columns($conn);

    $rider_id = (int)$rider_id;
    $valid_sources = ['login', 'sim800l', 'esp32', 'session', 'manual', 'browser'];
    $source = in_array($source, $valid_sources, true) ? $source : 'esp32';

    $stmt = $conn->prepare("
        UPDATE users
        SET is_online = 1,
            last_online_at = NOW(),
            online_source = ?
        WHERE id = ?
          AND role = 'rider'
          AND status = 'approved'
    ");
    
    if (!$stmt) return false;
    
    $stmt->bind_param('si', $source, $rider_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function rider_set_offline($conn, $rider_id)
{
    rider_online_ensure_columns($conn);

    $rider_id = (int)$rider_id;
    $stmt = $conn->prepare("
        UPDATE users
        SET is_online = 0,
            online_source = NULL
        WHERE id = ?
          AND role = 'rider'
    ");
    
    if (!$stmt) return false;
    
    $stmt->bind_param('i', $rider_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

function rider_is_currently_online($row)
{
    if (empty($row) || !is_array($row)) return false;
    if (empty($row['is_online'])) return false;
    if (empty($row['last_online_at'])) return false;

    $last = strtotime((string)$row['last_online_at']);
    if ($last === false) return false;

    $age = time() - $last;
    return $age <= RIDER_ONLINE_STALE_SECONDS;  // ✅ 30 seconds
}

function rider_online_status_label(array $row)
{
    if (!rider_is_currently_online($row)) {
        return 'Offline';
    }

    $source = $row['online_source'] ?? '';
    
    switch ($source) {
        case 'esp32':
            return 'Online (GPS)';
        case 'sim800l':
            return 'Online (Cellular)';
        case 'login':
            return 'Online (App)';
        default:
            return 'Online';
    }
}

function rider_auto_offline_cleanup($conn)
{
    rider_online_ensure_columns($conn);
    
    $stmt = $conn->prepare("
        UPDATE users
        SET is_online = 0,
            online_source = NULL
        WHERE is_online = 1
          AND last_online_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
          AND role = 'rider'
    ");
    
    if (!$stmt) return 0;
    
    $timeout = RIDER_ONLINE_STALE_SECONDS;  // ✅ 30 seconds
    $stmt->bind_param('i', $timeout);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    return $affected;
}

/**
 * Get all online riders with ESP32 source
 * 
 * @param mysqli $conn Database connection
 * @return array List of online riders
 */
function rider_get_online_riders($conn)
{
    rider_online_ensure_columns($conn);
    
    // Auto-cleanup first
    rider_auto_offline_cleanup($conn);
    
    $query = "
        SELECT 
            id,
            fullname,
            ebike_id,
            is_online,
            last_online_at,
            online_source,
            TIMESTAMPDIFF(SECOND, last_online_at, NOW()) AS online_age_seconds
        FROM users
        WHERE role = 'rider'
          AND status = 'approved'
          AND is_online = 1
          AND last_online_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ORDER BY last_online_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("rider_get_online_riders prepare failed: " . $conn->error);
        return [];
    }
    
    $timeout = RIDER_ONLINE_STALE_SECONDS;
    $stmt->bind_param('i', $timeout);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $riders = [];
    while ($row = $result->fetch_assoc()) {
        $row['status_label'] = rider_online_status_label($row);
        $row['source_emoji'] = rider_online_source_emoji($row);
        $row['last_active'] = rider_last_active_ago($row);
        $riders[] = $row;
    }
    
    $stmt->close();
    
    return $riders;
}

/**
 * Count online riders by source
 * 
 * @param mysqli $conn Database connection
 * @return array Count by source
 */
function rider_count_online_by_source($conn)
{
    rider_online_ensure_columns($conn);
    
    // Auto-cleanup first
    rider_auto_offline_cleanup($conn);
    
    $query = "
        SELECT 
            COALESCE(online_source, 'unknown') AS source,
            COUNT(*) AS total
        FROM users
        WHERE role = 'rider'
          AND status = 'approved'
          AND is_online = 1
          AND last_online_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        GROUP BY online_source
    ";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("rider_count_online_by_source prepare failed: " . $conn->error);
        return [];
    }
    
    $timeout = RIDER_ONLINE_STALE_SECONDS;
    $stmt->bind_param('i', $timeout);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $counts = [];
    while ($row = $result->fetch_assoc()) {
        $counts[$row['source']] = (int)$row['total'];
    }
    
    $stmt->close();
    
    return $counts;
}

/**
 * Check if ESP32 device is actively sending data
 * 
 * @param mysqli $conn Database connection
 * @param int $rider_id Rider ID
 * @return bool True if ESP32 is active
 */
function rider_is_esp32_active($conn, $rider_id)
{
    rider_online_ensure_columns($conn);
    
    $rider_id = (int)$rider_id;
    
    $query = "
        SELECT 
            is_online,
            online_source,
            last_online_at,
            TIMESTAMPDIFF(SECOND, last_online_at, NOW()) AS age_seconds
        FROM users
        WHERE id = ?
          AND role = 'rider'
          AND is_online = 1
          AND online_source = 'esp32'
        LIMIT 1
    ";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param('i', $rider_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if (!$row) {
        return false;
    }
    
    $age = (int)$row['age_seconds'];
    
    return $age <= RIDER_ONLINE_STALE_SECONDS;
}

/**
 * Force refresh online status for all riders
 * 
 * @param mysqli $conn Database connection
 * @return int Number of riders affected
 */
function rider_refresh_all_status($conn)
{
    rider_online_ensure_columns($conn);
    
    // Set stale riders offline
    $offlineCount = rider_auto_offline_cleanup($conn);
    
    // Get fresh online count
    $onlineCount = count(rider_get_online_riders($conn));
    
    error_log("Status refresh: $onlineCount online, $offlineCount set offline");
    
    return [
        'online' => $onlineCount,
        'offline' => $offlineCount
    ];
}