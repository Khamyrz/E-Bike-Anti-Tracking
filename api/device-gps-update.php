<?php

require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/rider-online.php";

header('Content-Type: application/json');

$device_id = isset($_POST['device_id']) ? trim($_POST['device_id']) : '';
$token = isset($_POST['token']) ? trim($_POST['token']) : '';

if ($device_id === '' || $token === '') {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "device_id and token are required"]);
    exit;
}

$stmt = $conn->prepare("
    SELECT d.rider_id, u.status
    FROM devices d
    INNER JOIN users u ON u.id = d.rider_id
    WHERE d.device_id = ? AND d.api_token = ?
    LIMIT 1
");
$stmt->bind_param('ss', $device_id, $token);
$stmt->execute();
$device = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$device) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Invalid device credentials"]);
    exit;
}

if ($device['status'] !== 'approved') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Rider is not approved"]);
    exit;
}

$speed = isset($_POST['speed']) ? (string)$_POST['speed'] : '0';
$battery = isset($_POST['battery']) ? (string)$_POST['battery'] : '100';
$rider_id = (int)$device['rider_id'];

rider_set_online($conn, $rider_id, 'sim800l');

$stmt = $conn->prepare(
    "INSERT INTO gps_logs (rider_id, latitude, longitude, speed, battery) VALUES (?, NULL, NULL, ?, ?)"
);
$stmt->bind_param('iss', $rider_id, $speed, $battery);
$stmt->execute();
$stmt->close();

echo json_encode([
    "status" => "success",
    "rider_id" => $rider_id,
    "online" => true,
]);
