<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/zone-settings.php';

session_start();

$is_admin = isset($_SESSION['admin']);
$is_rider = isset($_SESSION['rider']);

if (!$is_admin && !$is_rider) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$points = zone_get_perimeter($conn);

echo json_encode([
    'active' => count($points) >= 3,
    'points' => $points,
    'point_count' => count($points),
    'warning_meters' => 150,
]);
