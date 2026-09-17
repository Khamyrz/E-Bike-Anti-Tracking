#!/usr/bin/env php
<?php
/**
 * Cron job to check for expired rentals and handle them
 * Run every minute
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rental-timer.php';

$rentalTimer = new RentalTimer($conn);
$results = $rentalTimer->checkAndProcessRentals();

// Log results
$log = date('Y-m-d H:i:s') . " - Rental check completed: " . 
       "Warnings: {$results['warnings_sent']}, " .
       "Stolen: {$results['stolen_declared']}, " .
       "Returned: {$results['returned_processed']}\n";

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
file_put_contents($logDir . '/rental-check.log', $log, FILE_APPEND);

if ($results['stolen_declared'] > 0) {
    // Log that stolen vehicles were declared
    file_put_contents($logDir . '/stolen-declared.log', 
        date('Y-m-d H:i:s') . " - {$results['stolen_declared']} vehicle(s) declared stolen\n", 
        FILE_APPEND
    );
}