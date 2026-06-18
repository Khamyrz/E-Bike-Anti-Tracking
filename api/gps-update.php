<?php

session_start();

require __DIR__ . "/../config/database.php";

header('Content-Type: application/json');

$rider_id = isset($_POST['rider_id']) ? (int)$_POST['rider_id'] : 0;
$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : 0;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : 0;

if (!isset($_SESSION['rider']) || (int)$_SESSION['rider'] !== $rider_id) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

if ($rider_id <= 0 || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid coordinates"]);
    exit;
}

$speed = isset($_POST['speed']) ? (string)$_POST['speed'] : '0';
$battery = isset($_POST['battery']) ? (string)$_POST['battery'] : '100';

$stmt = $conn->prepare(
    "INSERT INTO gps_logs (rider_id, latitude, longitude, speed, battery) VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param('iddss', $rider_id, $lat, $lng, $speed, $battery);
$stmt->execute();
$stmt->close();

echo json_encode(["status" => "success"]);
