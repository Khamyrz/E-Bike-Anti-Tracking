<?php

session_start();

require __DIR__ . "/../config/database.php";

header('Content-Type: application/json');

http_response_code(403);
echo json_encode([
    "status" => "error",
    "message" => "Browser GPS is disabled. Location must come from the ESP32 device via device-gps-update.php."
]);
