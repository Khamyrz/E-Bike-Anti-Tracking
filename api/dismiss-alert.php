<?php
// api/dismiss-alert.php
session_start();
header('Content-Type: application/json');

if(!isset($_SESSION['admin'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include("../config/database.php");

$data = json_decode(file_get_contents('php://input'), true);
$alert_id = (int)($data['alert_id'] ?? 0);

if ($alert_id > 0) {
    $conn->query("UPDATE map_alerts SET status = 'dismissed' WHERE id = $alert_id");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Invalid ID']);
}
?>