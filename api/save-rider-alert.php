<?php
// api/save-rider-alert.php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['rider'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("../config/database.php");

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

$rider_id = (int)($_SESSION['rider'] ?? 0);
$status = $conn->real_escape_string($data['status'] ?? 'warning');
$distance = $data['distance'] ?? null;
$latitude = (float)($data['latitude'] ?? 0);
$longitude = (float)($data['longitude'] ?? 0);

// Gumawa ng table kung wala pa
$conn->query("
    CREATE TABLE IF NOT EXISTS rider_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rider_id INT NOT NULL,
        status VARCHAR(20) DEFAULT 'warning',
        distance_to_boundary DECIMAL(10, 2),
        latitude DECIMAL(10, 6),
        longitude DECIMAL(10, 6),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rider_id (rider_id),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Anti-spam: Check kung may existing alert sa loob ng 5 minuto
$check = $conn->query("
    SELECT id FROM rider_alerts 
    WHERE rider_id = $rider_id 
    AND status = '$status'
    AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    LIMIT 1
");

if ($check && $check->num_rows > 0) {
    echo json_encode(['success' => true, 'action' => 'skipped']);
    exit;
}

$distance_sql = $distance ? $distance : 'NULL';
$insert = $conn->query("
    INSERT INTO rider_alerts (rider_id, status, distance_to_boundary, latitude, longitude)
    VALUES ($rider_id, '$status', $distance_sql, $latitude, $longitude)
");

if ($insert) {
    echo json_encode([
        'success' => true,
        'action' => 'created',
        'id' => $conn->insert_id
    ]);
} else {
    echo json_encode(['error' => 'Failed to save: ' . $conn->error]);
}
?>