<?php
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/rental-timer.php';

$rider_id = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;

if (!$rider_id) {
    echo json_encode(['error' => 'Rider ID required']);
    exit;
}

$rentalTimer = new RentalTimer($conn, $rider_id);
$status = $rentalTimer->getRentalStatus($rider_id);

echo json_encode($status);