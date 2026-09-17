<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/rental-timer.php';

$rentalTimer = new RentalTimer($conn);

// Get stolen vehicles
$stolen = $rentalTimer->getStolenRentals();
$stolen_vehicles = [];
while ($row = $stolen->fetch_assoc()) {
    $stolen_vehicles[] = $row;
}

// Get grace period alerts
$active = $rentalTimer->getActiveRentals();
$grace_period_alerts = [];
while ($rental = $active->fetch_assoc()) {
    $status = $rentalTimer->getRentalStatus($rental['rider_id']);
    if ($status && $status['is_grace_period']) {
        $grace_period_alerts[] = array_merge($rental, $status);
    }
}

echo json_encode([
    'stolen_vehicles' => $stolen_vehicles,
    'grace_period_alerts' => $grace_period_alerts
]);