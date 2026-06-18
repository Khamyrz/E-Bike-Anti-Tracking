<?php

session_start();

if (isset($_SESSION['rider'])) {
    require __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/rider-online.php';
    rider_set_offline($conn, (int)$_SESSION['rider']);
}

session_destroy();

header("Location: ../index.php");
exit;
