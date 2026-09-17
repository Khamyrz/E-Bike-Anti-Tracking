<?php
/**
 * Device GPS Update Endpoint
 * Receives GPS data from ESP32 devices with GPS NEO-6M
 * AUTO-SETS RIDER ONLINE when device sends data
 * Endpoint: /ebike-gps/api/device-gps-update.php
 * 
 * Methods: POST (recommended), GET (for testing)
 * UPDATED: Consistent 180 seconds threshold, better logging
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/device-gps-error.log');

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/rider-online.php";
require_once __DIR__ . "/../includes/geofence.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Accept both POST and GET methods
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "message" => "Only POST and GET methods are allowed"
    ]);
    exit();
}

/**
 * Ensure required columns exist in gps_logs table
 */
function ensureGpsLogsColumns($conn) {
    $requiredColumns = [
        'vibration' => "ALTER TABLE gps_logs ADD COLUMN vibration TINYINT(1) NULL DEFAULT 0",
        'battery' => "ALTER TABLE gps_logs ADD COLUMN battery VARCHAR(10) NULL DEFAULT '100'",
        'speed' => "ALTER TABLE gps_logs MODIFY COLUMN speed VARCHAR(20) NULL DEFAULT '0'",
        'satellites' => "ALTER TABLE gps_logs ADD COLUMN satellites INT NULL DEFAULT 0",
        'hdop' => "ALTER TABLE gps_logs ADD COLUMN hdop FLOAT NULL DEFAULT NULL"
    ];
    
    foreach ($requiredColumns as $column => $alterQuery) {
        $check = $conn->query("SHOW COLUMNS FROM gps_logs LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            try {
                $conn->query($alterQuery);
                error_log("Added column '$column' to gps_logs table");
            } catch (Exception $e) {
                error_log("Failed to add column '$column': " . $e->getMessage());
            }
        }
    }
}

/**
 * Ensure devices table exists and has required columns
 */
function ensureDevicesTable($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'devices'");
    if ($tableCheck && $tableCheck->num_rows === 0) {
        $createTable = "
            CREATE TABLE devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                device_id VARCHAR(32) NOT NULL UNIQUE,
                api_token VARCHAR(64) NOT NULL,
                rider_id INT NOT NULL,
                device_type VARCHAR(50) DEFAULT 'esp32',
                is_active TINYINT(1) DEFAULT 1,
                last_seen_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rider_id (rider_id),
                FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        try {
            $conn->query($createTable);
            error_log("Created devices table");
        } catch (Exception $e) {
            error_log("Failed to create devices table: " . $e->getMessage());
        }
    } else {
        $columns = [
            'device_type' => "ALTER TABLE devices ADD COLUMN device_type VARCHAR(50) DEFAULT 'esp32' AFTER api_token",
            'is_active' => "ALTER TABLE devices ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER device_type",
            'last_seen_at' => "ALTER TABLE devices ADD COLUMN last_seen_at DATETIME NULL AFTER is_active"
        ];
        
        foreach ($columns as $column => $alterQuery) {
            $check = $conn->query("SHOW COLUMNS FROM devices LIKE '$column'");
            if ($check && $check->num_rows === 0) {
                try {
                    $conn->query($alterQuery);
                    error_log("Added column '$column' to devices table");
                } catch (Exception $e) {
                    error_log("Failed to add column '$column': " . $e->getMessage());
                }
            }
        }
    }
}

// Ensure database structure
ensureGpsLogsColumns($conn);
ensureDevicesTable($conn);

// Get request data based on method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $device_id = isset($_POST['device_id']) ? trim($_POST['device_id']) : '';
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
    $speed = isset($_POST['speed']) ? (string)$_POST['speed'] : '0';
    $battery = isset($_POST['battery']) ? (string)$_POST['battery'] : '100';
    $vibration = isset($_POST['vibration']) ? (int)$_POST['vibration'] : 0;
    $lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
    $satellites = isset($_POST['satellites']) ? (int)$_POST['satellites'] : 0;
    $hdop = isset($_POST['hdop']) ? (float)$_POST['hdop'] : null;
} else {
    $device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';
    $speed = isset($_GET['speed']) ? (string)$_GET['speed'] : '0';
    $battery = isset($_GET['battery']) ? (string)$_GET['battery'] : '100';
    $vibration = isset($_GET['vibration']) ? (int)$_GET['vibration'] : 0;
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    $satellites = isset($_GET['satellites']) ? (int)$_GET['satellites'] : 0;
    $hdop = isset($_GET['hdop']) ? (float)$_GET['hdop'] : null;
}

// Log incoming request
$requestMethod = $_SERVER['REQUEST_METHOD'];
error_log(date('Y-m-d H:i:s') . " - Method: $requestMethod - Device: $device_id - IP: " . $_SERVER['REMOTE_ADDR']);

// Validate required fields
if ($device_id === '' || $token === '') {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "device_id and token are required"
    ]);
    exit();
}

// Authenticate device
$stmt = $conn->prepare("
    SELECT d.rider_id, u.status, u.fullname, u.ebike_id
    FROM devices d
    INNER JOIN users u ON u.id = d.rider_id
    WHERE d.device_id = ? AND d.api_token = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $conn->error
    ]);
    exit();
}

$stmt->bind_param('ss', $device_id, $token);
$stmt->execute();
$result = $stmt->get_result();
$device = $result->fetch_assoc();
$stmt->close();

if (!$device) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid device credentials"
    ]);
    exit();
}

if ($device['status'] !== 'approved') {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Rider account is not approved"
    ]);
    exit();
}

$rider_id = (int)$device['rider_id'];

// ✅ AUTO-SET RIDER ONLINE (before inserting GPS data)
rider_set_online($conn, $rider_id, 'esp32');

// Update device last_seen_at
$updateDevice = $conn->prepare("UPDATE devices SET last_seen_at = NOW(), is_active = 1 WHERE device_id = ?");
$updateDevice->bind_param('s', $device_id);
$updateDevice->execute();
$updateDevice->close();

// Validate GPS coordinates
$has_coords = ($lat !== null && $lng !== null && 
               $lat != 0.0 && $lng != 0.0 &&
               $lat >= -90 && $lat <= 90 && 
               $lng >= -180 && $lng <= 180);

// Log GPS data
error_log("GPS - Rider: $rider_id - Lat: $lat - Lng: $lng - Sats: $satellites - Valid: " . ($has_coords ? 'yes' : 'no'));

// Check if hdop column exists
$hdopCheck = $conn->query("SHOW COLUMNS FROM gps_logs LIKE 'hdop'");
if ($hdopCheck && $hdopCheck->num_rows === 0) {
    $conn->query("ALTER TABLE gps_logs ADD COLUMN hdop FLOAT NULL DEFAULT NULL");
}

// Insert GPS log
if ($has_coords) {
    $stmt = $conn->prepare(
        "INSERT INTO gps_logs (rider_id, latitude, longitude, speed, battery, vibration, satellites, hdop) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iddssiii', $rider_id, $lat, $lng, $speed, $battery, $vibration, $satellites, $hdop);
} else {
    // Still save data even without GPS fix
    $stmt = $conn->prepare(
        "INSERT INTO gps_logs (rider_id, speed, battery, vibration, satellites) 
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issii', $rider_id, $speed, $battery, $vibration, $satellites);
}

if (!$stmt->execute()) {
    error_log("Insert failed for rider $rider_id: " . $stmt->error);
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to save GPS data"
    ]);
    $stmt->close();
    exit();
}
// Sa device-gps-update.php, idagdag:
$vibration = isset($_GET['vibration']) ? intval($_GET['vibration']) : 0;

// Sa INSERT/UPDATE query:
$stmt = $conn->prepare("
    INSERT INTO gps_locations (device_id, lat, lng, speed, battery, vibration, satellites, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        lat = VALUES(lat),
        lng = VALUES(lng),
        speed = VALUES(speed),
        battery = VALUES(battery),
        vibration = VALUES(vibration),
        satellites = VALUES(satellites),
        created_at = NOW()
");
$stmt->bind_param("sdddiis", $device_id, $lat, $lng, $speed, $battery, $vibration, $satellites);

$insert_id = $stmt->insert_id;
$stmt->close();

// Process geofence if coordinates are valid
$geofence = ['active' => false, 'status' => 'none', 'inside' => true];
if ($has_coords) {
    try {
        $geofence = geofence_process_location($conn, $rider_id, $lat, $lng, $device['fullname'] ?? '');
    } catch (Exception $e) {
        error_log("Geofence error: " . $e->getMessage());
    }
}

// Success response
$response = [
    "status" => "success",
    "message" => $has_coords ? "GPS data received - Rider is ONLINE" : "Data received - Rider is ONLINE",
    "rider_id" => $rider_id,
    "rider_name" => $device['fullname'] ?? '',
    "ebike_id" => $device['ebike_id'] ?? null,
    "online" => true,
    "gps_saved" => $has_coords,
    "gps_valid" => $has_coords,
    "satellites" => $satellites,
    "log_id" => $insert_id,
    "coordinates" => $has_coords ? [
        "lat" => $lat,
        "lng" => $lng,
        "speed" => $speed,
        "battery" => $battery
    ] : null,
    "vibration" => $vibration ? true : false,
    "geofence" => $geofence,
    "timestamp" => date('Y-m-d H:i:s')
];

error_log("Success - Rider $rider_id - Log ID: $insert_id - Online: YES at " . date('Y-m-d H:i:s'));
echo json_encode($response, JSON_PRETTY_PRINT);