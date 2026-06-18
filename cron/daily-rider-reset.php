<?php

/**
 * Run via Windows Task Scheduler every day at 11:00 PM.
 * Example command:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\CAPSTONE\ebike-tracker\cron\daily-rider-reset.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/daily-rider-reset.php';

$now      = new DateTime('now', new DateTimeZone(RIDER_RESET_TIMEZONE));
$last_run = rider_reset_get_last_run($conn);

if(!rider_reset_is_due($now, $last_run))
{
    echo '[' . $now->format('Y-m-d H:i:s') . "] Rider reset not due yet.\n";
    exit(0);
}

$result = run_daily_rider_reset($conn);
echo '[' . $result['reset_at'] . '] ' . $result['message'] . "\n";
