<?php
/**
 * Test Connection - Verify ESP32 data flow
 * Access: http://localhost/ebike-tracker/api/test-connection.php
 */

require __DIR__ . "/../config/database.php";

header('Content-Type: application/json');

$tests = [];

// Test 1: Database connection
$tests['database'] = $conn ? 'Connected' : 'Failed';

// Test 2: Check tables exist
$tables = ['users', 'devices', 'gps_logs', 'geofence_zones'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    $tests['tables'][$table] = $result->num_rows > 0 ? 'Exists' : 'Missing';
}

// Test 3: Check columns
$columns = [
    'gps_logs' => ['latitude', 'longitude', 'speed', 'battery', 'vibration'],
    'users' => ['is_online', 'last_online_at', 'online_source'],
    'devices' => ['device_id', 'api_token', 'rider_id']
];

foreach ($columns as $table => $cols) {
    foreach ($cols as $col) {
        $result = $conn->query("SHOW COLUMNS FROM $table LIKE '$col'");
        $tests['columns'][$table][$col] = $result->num_rows > 0 ? 'OK' : 'Missing';
    }
}

// Test 4: Check for test device
$result = $conn->query("SELECT * FROM devices WHERE device_id = 'EBIKE0001'");
$tests['test_device'] = $result->num_rows > 0 ? 'Found' : 'Not found';

// Test 5: Check recent GPS data
$result = $conn->query("SELECT COUNT(*) as count FROM gps_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$recent = $result->fetch_assoc();
$tests['recent_gps_logs'] = $recent['count'] . ' entries in last hour';

// Test 6: PHP extensions
$tests['php_extensions'] = [
    'mysqli' => extension_loaded('mysqli') ? 'Loaded' : 'Missing',
    'json' => extension_loaded('json') ? 'Loaded' : 'Missing',
    'curl' => extension_loaded('curl') ? 'Loaded' : 'Missing'
];

// Test 7: File permissions
$logDir = __DIR__ . '/../logs';
$tests['logs_directory'] = [
    'exists' => file_exists($logDir) ? 'Yes' : 'No',
    'writable' => is_writable($logDir) ? 'Yes' : 'No'
];

// Overall status
$allPassed = true;
foreach ($tests as $category => $test) {
    if (is_array($test)) {
        foreach ($test as $key => $value) {
            if ($value === 'Missing' || $value === 'Failed' || $value === 'Not found' || $value === 'No') {
                $allPassed = false;
            }
        }
    } elseif ($test === 'Failed' || $test === 'Not found') {
        $allPassed = false;
    }
}

$tests['overall_status'] = $allPassed ? 'All checks passed ✅' : 'Some checks failed ❌';

echo json_encode($tests, JSON_PRETTY_PRINT);