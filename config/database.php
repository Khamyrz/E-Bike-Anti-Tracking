<?php

$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'ebike_tracker';
$db_port = 3306;

mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_init();
if (!$conn) {
    die('Database Error: Could not initialize MySQL connection.');
}

$conn->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

if ($conn->connect_errno) {
    http_response_code(503);
    die(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Database Unavailable</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0B1120;color:#F1F5F9;display:flex;'
        . 'align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}'
        . '.box{max-width:420px;background:#111827;border:1px solid #2D3F55;border-radius:12px;padding:28px;}'
        . 'h1{font-size:18px;margin:0 0 12px;}p{font-size:14px;color:#94A3B8;line-height:1.6;margin:0 0 8px;}'
        . 'code{background:#1E293B;padding:2px 6px;border-radius:4px;font-size:13px;}</style></head><body>'
        . '<div class="box"><h1>Database connection failed</h1>'
        . '<p>MySQL is not running or refused the connection. Open the XAMPP Control Panel and start '
        . '<strong>MySQL</strong>, then refresh this page.</p>'
        . '<p>If MySQL is already running, verify the database <code>' . htmlspecialchars($db_name) . '</code> '
        . 'exists in phpMyAdmin.</p></div></body></html>'
    );
}

?>
