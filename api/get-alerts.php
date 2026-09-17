<?php
// api/get-alerts.php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['admin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("../config/database.php");

// Check kung may table
$check = $conn->query("SHOW TABLES LIKE 'map_alerts'");
if (!$check || $check->num_rows === 0) {
    echo json_encode(['success' => true, 'alerts' => [], 'count' => 0]);
    exit;
}

// ✅ OPTIONAL FILTERS
$limit = (int)($_GET['limit'] ?? 100);
$alert_type = $_GET['type'] ?? null;  // 'geofence' | 'vibration' | null (all)
$rider_id = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : null;

// Build WHERE clause
$where = ["status = 'active'"];

if ($alert_type && in_array($alert_type, ['geofence', 'vibration'])) {
    $where[] = "alert_type = '" . $conn->real_escape_string($alert_type) . "'";
}

if ($rider_id) {
    $where[] = "rider_id = $rider_id";
}

$where_sql = "WHERE " . implode(" AND ", $where);

// ✅ Auto-migrate: check kung may alert_type / vibration columns
$columns = $conn->query("SHOW COLUMNS FROM map_alerts LIKE 'alert_type'");
$has_alert_type = ($columns && $columns->num_rows > 0);

$columns_vib = $conn->query("SHOW COLUMNS FROM map_alerts LIKE 'vibration'");
$has_vibration = ($columns_vib && $columns_vib->num_rows > 0);

// Build SELECT with fallback kung wala pa ang columns
$select_fields = "*";
if (!$has_alert_type) {
    $select_fields = "*, 'geofence' AS alert_type";
}
if (!$has_vibration) {
    $select_fields = str_replace("'geofence' AS alert_type", "'geofence' AS alert_type, 0 AS vibration", $select_fields);
}

$query = $conn->query("
    SELECT $select_fields FROM map_alerts 
    $where_sql
    ORDER BY created_at DESC
    LIMIT $limit
");

$alerts = [];
$count = 0;
$vibration_count = 0;
$geofence_count = 0;

if ($query && $query->num_rows > 0) {
    while ($row = $query->fetch_assoc()) {
        // ✅ Cast types para consistent JSON output
        $row['id'] = (int)$row['id'];
        $row['rider_id'] = (int)$row['rider_id'];
        $row['latitude'] = (float)$row['latitude'];
        $row['longitude'] = (float)$row['longitude'];
        $row['is_online'] = (int)$row['is_online'];
        $row['vibration'] = (int)($row['vibration'] ?? 0);
        
        // ✅ Normalize alert_type
        if (!isset($row['alert_type']) || empty($row['alert_type'])) {
            $row['alert_type'] = $row['vibration'] == 1 ? 'vibration' : 'geofence';
        }
        
        // ✅ Add readable type label
        $row['type_label'] = $row['alert_type'] === 'vibration' 
            ? '⚠️ Vibration' 
            : '🚨 Geofence';
        
        // ✅ Count per type
        if ($row['alert_type'] === 'vibration') {
            $vibration_count++;
        } else {
            $geofence_count++;
        }
        
        $alerts[] = $row;
        $count++;
    }
}

echo json_encode([
    'success' => true,
    'alerts' => $alerts,
    'count' => $count,
    'summary' => [
        'total' => $count,
        'vibration' => $vibration_count,
        'geofence' => $geofence_count
    ]
]);
?>