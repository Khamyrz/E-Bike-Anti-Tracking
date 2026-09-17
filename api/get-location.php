<?php
/**
 * Get Single Rider Location
 * Endpoint: /ebike-gps/api/get-location.php
 * UPDATED: Fixed duplicate session_start, added satellites, real-time check
 */

session_start();  // ✅ ISA LANG dapat ito

require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/rider-online.php";
require_once __DIR__ . "/../includes/geofence.php";

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Ensure required columns
function ensureGpsLogsColumns($conn) {
    $columns = [
        'vibration' => "ALTER TABLE gps_logs ADD COLUMN vibration TINYINT(1) NULL DEFAULT 0",
        'satellites' => "ALTER TABLE gps_logs ADD COLUMN satellites INT NULL DEFAULT 0"
    ];
    
    foreach ($columns as $column => $alterQuery) {
        $check = $conn->query("SHOW COLUMNS FROM gps_logs LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            try {
                $conn->query($alterQuery);
            } catch (Exception $e) {
                // Column might already exist
            }
        }
    }
}

ensureGpsLogsColumns($conn);

$rider_id = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;

if (isset($_SESSION['rider'])) {
    $rider_id = (int)$_SESSION['rider'];
}

if ($rider_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid rider"]);
    exit;
}

rider_online_ensure_columns($conn);

// Get rider info
$stmt = $conn->prepare("
    SELECT id, fullname, ebike_id, is_online, last_online_at, online_source
    FROM users
    WHERE id = ? AND role = 'rider' AND status = 'approved'
    LIMIT 1
");
$stmt->bind_param('i', $rider_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Rider not found"]);
    exit;
}

// Get latest GPS data
$gps_stmt = $conn->prepare("
    SELECT latitude, longitude, battery, speed, vibration, satellites, created_at
    FROM gps_logs
    WHERE rider_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$gps_stmt->bind_param('i', $rider_id);
$gps_stmt->execute();
$gps_data = $gps_stmt->get_result()->fetch_assoc();
$gps_stmt->close();

$battery = null;
$last_signal = null;
$latitude = null;
$longitude = null;
$speed = 0;
$vibration = false;
$satellites = 0;

if ($gps_data) {
    $battery = $gps_data['battery'];
    $last_signal = $gps_data['created_at'];
    $latitude = $gps_data['latitude'] !== null ? (float)$gps_data['latitude'] : null;
    $longitude = $gps_data['longitude'] !== null ? (float)$gps_data['longitude'] : null;
    $speed = $gps_data['speed'] !== null ? (float)$gps_data['speed'] : 0;
    $vibration = isset($gps_data['vibration']) ? (bool)$gps_data['vibration'] : false;
    $satellites = isset($gps_data['satellites']) ? (int)$gps_data['satellites'] : 0;
}

// ✅ CHECK ONLINE STATUS gamit ang rider-online.php (180 seconds threshold)
$online = rider_is_currently_online($row);

// ✅ CHECK SIGNAL AGE (180 seconds threshold)
$signalAge = 999;
if ($last_signal) {
    $lastTimestamp = strtotime($last_signal);
    if ($lastTimestamp !== false) {
        $signalAge = time() - $lastTimestamp;
    }
}
$hasFreshSignal = $signalAge < 180;  // ✅ 180 seconds (3 minutes)

// ✅ FINAL ONLINE STATUS - Dapat consistent sa get-all-locations.php
$online = $online && $hasFreshSignal;

// ✅ Check kung may valid location
$hasLocation = ($latitude !== null && $longitude !== null && 
                $latitude != 0 && $longitude != 0 && 
                $hasFreshSignal);

// Process geofence
$geofence = ['active' => false, 'status' => 'none', 'inside' => true];
if ($hasLocation) {
    try {
        $geofence = geofence_evaluate($conn, $latitude, $longitude);
    } catch (Exception $e) {
        error_log("Geofence error: " . $e->getMessage());
    }
}

// Build response
echo json_encode([
    'id'                => (int)$row['id'],
    'fullname'          => $row['fullname'],
    'ebike_id'          => $row['ebike_id'],
    'is_online'         => $online,
    'online_source'     => $online ? ($row['online_source'] ?? null) : null,
    'last_online_at'    => $row['last_online_at'],
    'battery'           => $battery,
    'last_signal'       => $last_signal,
    'signal_age_seconds'=> (int)$signalAge,
    'is_fresh'          => $hasFreshSignal,
    'status_label'      => $online ? 'Online (GPS)' : 'Offline',
    
    // GPS Data
    'lat'               => $hasLocation ? $latitude : null,
    'lng'               => $hasLocation ? $longitude : null,
    'speed'             => $speed,
    'vibration'         => $vibration,
    'satellites'        => $satellites,
    'has_location'      => $hasLocation,
    'location_source'   => $hasLocation ? 'esp32' : null,
    'geofence'          => $geofence
]);