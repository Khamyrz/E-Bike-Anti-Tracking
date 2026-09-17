<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/rental-timer.php';

// Check if rider is logged in
if (!isset($_SESSION['rider'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$rider_id = $_SESSION['rider'];
$session_id = $data['session_id'] ?? null;

$rentalTimer = new RentalTimer($conn, $rider_id);
$result = $rentalTimer->returnEbike($rider_id, $session_id);

if (!empty($result['success']) && !empty($result['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();

    $result['redirect'] = '../index.php?returned=1';
}

echo json_encode($result);