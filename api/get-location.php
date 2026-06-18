<?php

require __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/rider-online.php";

header('Content-Type: application/json');

session_start();

$rider_id = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;

if (isset($_SESSION['rider'])) {
    $rider_id = (int)$_SESSION['rider'];
}

if ($rider_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid rider"]);
    exit;
}

rider_online_ensure_columns($conn);

$stmt = $conn->prepare("
    SELECT id, fullname, ebike_id, is_online, last_online_at, online_source
    FROM users
    WHERE id = ? AND role = 'rider' AND status = 'approved'
    LIMIT 1
");
$stmt->bind_param('i', $rider_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Rider not found"]);
    exit;
}

$battery = null;
$last_signal = null;
$battery_stmt = $conn->prepare("
    SELECT battery, created_at
    FROM gps_logs
    WHERE rider_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$battery_stmt->bind_param('i', $rider_id);
$battery_stmt->execute();
$signal = $battery_stmt->get_result()->fetch_assoc();
$battery_stmt->close();

if ($signal) {
    $battery = $signal['battery'];
    $last_signal = $signal['created_at'];
}

$online = rider_is_currently_online($row);

echo json_encode([
    'id'             => (int)$row['id'],
    'fullname'       => $row['fullname'],
    'ebike_id'       => $row['ebike_id'],
    'is_online'      => $online,
    'online_source'  => $online ? ($row['online_source'] ?? null) : null,
    'last_online_at' => $row['last_online_at'],
    'battery'        => $battery,
    'last_signal'    => $last_signal,
    'status_label'   => rider_online_status_label(array_merge($row, ['is_online' => $online ? 1 : 0])),
]);
