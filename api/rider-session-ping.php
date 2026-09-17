<?php



require __DIR__ . "/../config/database.php";

require_once __DIR__ . "/../includes/rider-online.php";



header('Content-Type: application/json');



session_start();



if (!isset($_SESSION['rider'])) {

    http_response_code(401);

    echo json_encode(["status" => "error", "message" => "Unauthorized"]);

    exit;

}



$rider_id = (int)$_SESSION['rider'];

rider_set_online($conn, $rider_id, 'session');



echo json_encode(["status" => "success"]);

