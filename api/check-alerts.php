<?php
// api/check-alerts.php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['admin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("../config/database.php");
require_once("../includes/rental-timer.php");
require_once("../includes/global-alerts.php");

$rentalTimer = new RentalTimer($conn);

// Get perimeter alerts
$perimeter_data = getPerimeterAlerts($conn);

// Get rental alerts
$rental_data = getRentalAlerts($conn, $rentalTimer);

// Total alerts
$total_alerts = $perimeter_data['count'] + $rental_data['stolen_count'] + $rental_data['grace_count'];

echo json_encode([
    'success' => true,
    'total_alerts' => $total_alerts,
    'perimeter_alerts' => $perimeter_data['alerts'],
    'perimeter_count' => $perimeter_data['count'],
    'stolen_count' => $rental_data['stolen_count'],
    'grace_count' => $rental_data['grace_count'],
    'timestamp' => date('Y-m-d H:i:s')
]);
?>