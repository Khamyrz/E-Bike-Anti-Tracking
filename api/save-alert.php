<?php
// api/save-alert.php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['admin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("../config/database.php");

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

$rider_id   = (int)($data['rider_id'] ?? 0);
$rider_name = $conn->real_escape_string($data['rider_name'] ?? '');
$ebike_id   = $conn->real_escape_string($data['ebike_id'] ?? '');
$latitude   = (float)($data['latitude'] ?? 0);
$longitude  = (float)($data['longitude'] ?? 0);
$is_online  = (int)($data['is_online'] ?? 0);

// ✅ NEW: Alert type support (geofence or vibration)
$alert_type = $conn->real_escape_string($data['alert_type'] ?? 'geofence');
$vibration  = (int)($data['vibration'] ?? 0);

// Validate alert_type
if (!in_array($alert_type, ['geofence', 'vibration'])) {
    $alert_type = 'geofence';
}

// ✅ CREATE TABLE with alert_type and vibration columns
$conn->query("
    CREATE TABLE IF NOT EXISTS map_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rider_id INT NOT NULL,
        rider_name VARCHAR(255) NOT NULL,
        ebike_id VARCHAR(100),
        latitude DECIMAL(10, 6),
        longitude DECIMAL(10, 6),
        is_online TINYINT(1) DEFAULT 0,
        alert_type VARCHAR(20) DEFAULT 'geofence',
        vibration TINYINT(1) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_rider_status (rider_id, status),
        INDEX idx_alert_type (alert_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ✅ Check if columns exist (for existing tables) — auto-migrate
$columns_check = $conn->query("SHOW COLUMNS FROM map_alerts LIKE 'alert_type'");
if ($columns_check && $columns_check->num_rows === 0) {
    $conn->query("ALTER TABLE map_alerts ADD COLUMN alert_type VARCHAR(20) DEFAULT 'geofence' AFTER is_online");
    $conn->query("ALTER TABLE map_alerts ADD COLUMN vibration TINYINT(1) DEFAULT 0 AFTER alert_type");
}

// ✅ VIBRATION COOLDOWN: Check for duplicate vibration alert within last 30 seconds
if ($alert_type === 'vibration') {
    $check = $conn->query("
        SELECT id FROM map_alerts 
        WHERE rider_id = $rider_id 
          AND alert_type = 'vibration'
          AND status = 'active'
          AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
        LIMIT 1
    ");
    
    if ($check && $check->num_rows > 0) {
        echo json_encode([
            'success' => true, 
            'action' => 'skipped',
            'message' => 'Vibration alert cooldown active (30s)'
        ]);
        exit;
    }
}

// ✅ Check if there's an existing active alert for this rider + alert_type
$check = $conn->query("
    SELECT id FROM map_alerts 
    WHERE rider_id = $rider_id 
      AND alert_type = '$alert_type'
      AND status = 'active'
    LIMIT 1
");

if ($check && $check->num_rows > 0) {
    // Update existing alert
    $existing = $check->fetch_assoc();
    $conn->query("
        UPDATE map_alerts 
        SET latitude = $latitude, 
            longitude = $longitude,
            is_online = $is_online,
            vibration = $vibration,
            updated_at = NOW()
        WHERE id = {$existing['id']}
    ");
    echo json_encode([
        'success' => true, 
        'action' => 'updated',
        'id' => $existing['id'],
        'alert_type' => $alert_type
    ]);
} else {
    // Create new alert
    $conn->query("
        INSERT INTO map_alerts 
            (rider_id, rider_name, ebike_id, latitude, longitude, is_online, alert_type, vibration, status)
        VALUES 
            ($rider_id, '$rider_name', '$ebike_id', $latitude, $longitude, $is_online, '$alert_type', $vibration, 'active')
    ");
    echo json_encode([
        'success' => true, 
        'action' => 'created', 
        'id' => $conn->insert_id,
        'alert_type' => $alert_type
    ]);
}
?>