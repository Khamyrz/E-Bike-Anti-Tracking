<?php
session_start();

if (!isset($_SESSION['admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/rental-notifications.php';

$data = json_decode(file_get_contents('php://input'), true);
$rider_id = $data['rider_id'] ?? 0;
$type = $data['type'] ?? 'warning';

if (!$rider_id) {
    echo json_encode(['success' => false, 'message' => 'Rider ID required']);
    exit;
}

$notifier = new RentalNotifier($conn);

if ($type === 'stolen') {
    $message = "🚨 URGENT: Your vehicle has been declared stolen. Contact support immediately.";
    $title = "🚨 STOLEN VEHICLE - Immediate Action Required";
} else {
    $message = "⚠️ REMINDER: Please return your E-Bike immediately. Your rental has expired.";
    $title = "⚠️ Rental Expired - Return Required";
}

$result = $notifier->notifyRider($rider_id, 'manual_alert', $title, $message);

echo json_encode(['success' => $result, 'message' => $result ? 'Alert sent successfully' : 'Failed to send alert']);