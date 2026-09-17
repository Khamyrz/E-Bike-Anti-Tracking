<?php
/**
 * Simulate ESP32 sending GPS data
 * Access: http://localhost/ebike-tracker/api/test-esp32-send.php
 */

header('Content-Type: application/json');

// Configuration
$apiUrl = 'http://localhost/ebike-tracker/api/device-gps-update.php';
$deviceId = 'EBIKE0001';
$apiToken = 'your_device_api_token';

// Simulate GPS data (Bantayan Island coordinates)
$testData = [
    'device_id' => $deviceId,
    'token' => $apiToken,
    'lat' => 11.1865 + (rand(-100, 100) / 10000), // Random variation
    'lng' => 123.7245 + (rand(-100, 100) / 10000), // Random variation
    'speed' => rand(10, 50) . '.' . rand(0, 9),
    'battery' => rand(60, 100),
    'vibration' => rand(0, 1)
];

// Send POST request
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($testData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo json_encode([
    'test_data_sent' => $testData,
    'http_code' => $httpCode,
    'response' => json_decode($response, true),
    'curl_error' => $error ?: null
], JSON_PRETTY_PRINT);