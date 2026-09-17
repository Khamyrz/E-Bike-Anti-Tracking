<?php
/**
 * Database Configuration
 * E-Bike Tracker System
 */

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'ebike_tracker';
$db_port = 3306;

// Disable automatic error reporting
mysqli_report(MYSQLI_REPORT_OFF);

// Initialize MySQL connection
$conn = mysqli_init();
if (!$conn) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Could not initialize MySQL connection'
    ]));
}

// Set connection timeout
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

// Connect to database
$conn->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

// Check connection
if ($conn->connect_errno) {
    http_response_code(503);
    
    // Check if it's an AJAX/API request
    if (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
        strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
    ) {
        header('Content-Type: application/json');
        die(json_encode([
            'status' => 'error',
            'message' => 'Database connection failed. Please start MySQL in XAMPP Control Panel.',
            'error_code' => $conn->connect_errno
        ]));
    }
    
    // HTML error page for browser requests
    die(
        '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Database Unavailable - E-Bike Tracker</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #0B1120;
                    color: #F1F5F9;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    padding: 20px;
                }
                .container {
                    max-width: 480px;
                    background: #111827;
                    border: 1px solid #2D3F55;
                    border-radius: 12px;
                    padding: 32px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                }
                h1 {
                    font-size: 20px;
                    margin-bottom: 16px;
                    color: #EF4444;
                }
                .error-code {
                    background: #1E293B;
                    color: #94A3B8;
                    padding: 8px 12px;
                    border-radius: 6px;
                    font-family: monospace;
                    font-size: 13px;
                    margin-bottom: 16px;
                }
                p {
                    font-size: 14px;
                    color: #94A3B8;
                    line-height: 1.6;
                    margin-bottom: 12px;
                }
                .steps {
                    background: #1E293B;
                    border-radius: 8px;
                    padding: 16px;
                    margin: 16px 0;
                }
                .steps ol {
                    margin-left: 20px;
                    color: #94A3B8;
                    font-size: 13px;
                    line-height: 1.8;
                }
                .steps strong {
                    color: #F59E0B;
                }
                .refresh-btn {
                    display: inline-block;
                    background: #3B82F6;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 8px;
                    text-decoration: none;
                    font-size: 14px;
                    font-weight: 500;
                    transition: background 0.2s;
                }
                .refresh-btn:hover {
                    background: #2563EB;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>🔌 Database Connection Failed</h1>
                <div class="error-code">Error Code: ' . htmlspecialchars($conn->connect_errno) . '</div>
                <p>Unable to connect to the MySQL database. This usually happens when MySQL is not running or the database doesn\'t exist.</p>
                
                <div class="steps">
                    <p style="font-weight:600;color:#F1F5F9;">Quick Fix Steps:</p>
                    <ol>
                        <li>Open <strong>XAMPP Control Panel</strong></li>
                        <li>Click <strong>Start</strong> next to MySQL</li>
                        <li>Wait for the green light</li>
                        <li>Click <strong>Admin</strong> next to MySQL (opens phpMyAdmin)</li>
                        <li>Verify database <strong>' . htmlspecialchars($db_name) . '</strong> exists</li>
                        <li>If not, run the SQL setup script</li>
                    </ol>
                </div>
                
                <p style="font-size:12px;color:#475569;">
                    Host: ' . htmlspecialchars($db_host) . ':' . $db_port . '<br>
                    Database: ' . htmlspecialchars($db_name) . '
                </p>
                
                <a href="javascript:location.reload()" class="refresh-btn">🔄 Refresh Page</a>
            </div>
        </body>
        </html>'
    );
}

// Set UTF-8 charset
$conn->set_charset("utf8mb4");

// Set timezone
$conn->query("SET time_zone = '+08:00'");

/**
 * Helper function to check if a table exists
 */
function table_exists($conn, $table_name) {
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'");
    return $result && $result->num_rows > 0;
}

/**
 * Helper function to check if a column exists
 */
function column_exists($conn, $table_name, $column_name) {
    $result = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table_name) . "` LIKE '" . $conn->real_escape_string($column_name) . "'");
    return $result && $result->num_rows > 0;
}

/**
 * Helper function to safely add a column if it doesn't exist
 */
function ensure_column($conn, $table, $column, $definition) {
    if (!column_exists($conn, $table, $column)) {
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
        return $conn->query($sql);
    }
    return true;
}

/**
 * Initialize required database structure
 */
function initialize_database_structure($conn) {
    // Users table columns
    ensure_column($conn, 'users', 'is_online', "TINYINT(1) NOT NULL DEFAULT 0");
    ensure_column($conn, 'users', 'last_online_at', "DATETIME NULL DEFAULT NULL");
    ensure_column($conn, 'users', 'online_source', "VARCHAR(20) NULL DEFAULT NULL");
    ensure_column($conn, 'users', 'ebike_id', "VARCHAR(50) NULL DEFAULT NULL");
    
    // GPS logs columns
    ensure_column($conn, 'gps_logs', 'vibration', "TINYINT(1) NULL DEFAULT 0");
    ensure_column($conn, 'gps_logs', 'battery', "VARCHAR(10) NULL DEFAULT '100'");
    ensure_column($conn, 'gps_logs', 'speed', "VARCHAR(20) NULL DEFAULT '0'");
    
    // Devices table
    ensure_column($conn, 'devices', 'rider_id', "INT NULL DEFAULT NULL");
    ensure_column($conn, 'devices', 'api_token', "VARCHAR(100) NOT NULL");
    ensure_column($conn, 'devices', 'device_id', "VARCHAR(50) UNIQUE NOT NULL");
}

// Auto-initialize
initialize_database_structure($conn);

?>