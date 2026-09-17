<?php

session_start();

if(!isset($_SESSION['rider']))
{
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/rider-online.php");
require_once("../includes/rental-timer.php");
require_once("../includes/rental-notifications.php");

$rider_id   = (int)$_SESSION['rider'];
$rider_name = isset($_SESSION['rider_name']) ? $_SESSION['rider_name'] : 'Rider';

rider_set_online($conn, $rider_id, 'login');

// Fetch rider details
$rider = $conn->prepare(
    "SELECT * FROM users WHERE id = ? AND role = 'rider'"
);
$rider->bind_param("i", $rider_id);
$rider->execute();
$rider_data = $rider->get_result()->fetch_assoc();

// Latest SIM800L signal
$location = $conn->prepare(
    "SELECT battery, created_at
     FROM gps_logs
     WHERE rider_id = ?
     ORDER BY id DESC
     LIMIT 1"
);

$location->bind_param("i", $rider_id);
$location->execute();
$loc = $location->get_result()->fetch_assoc();

rider_online_ensure_columns($conn);

$online_row = $conn->query("
    SELECT is_online, last_online_at, online_source
    FROM users
    WHERE id = $rider_id
    LIMIT 1
")->fetch_assoc();

$is_online = rider_is_currently_online($online_row ?: []);
$online_label = rider_online_status_label(array_merge($online_row ?: [], ['is_online' => $is_online ? 1 : 0]));

$current_date = date('l, F j, Y');

// ================================================================
// RENTAL TIMER - AUTO START RENTAL IF NONE EXISTS
// ================================================================
$rentalTimer = new RentalTimer($conn, $rider_id);
$rentalStatus = $rentalTimer->getRentalStatus($rider_id);

// ================================================================
// CRITICAL FIX: AUTO-START RENTAL IF NO ACTIVE SESSION
// ================================================================
if (!$rentalStatus || !$rentalStatus['has_rental']) {
    // Get the rider's ebike_id
    $ebike_id = $rider_data['ebike_id'] ?? '001';
    
    // Start rental session (24 hours)
    $result = $rentalTimer->startRental($rider_id, $ebike_id);
    
    if ($result['success']) {
        // Refresh rental status
        $rentalStatus = $rentalTimer->getRentalStatus($rider_id);
    }
}

// Check if rider is stolen
$is_stolen = $rentalTimer->isRiderStolen($rider_id);

// Get notifications
$notifier = new RentalNotifier($conn);
$unread_notifications = $notifier->getUnreadNotifications($rider_id, 'rider');
$notification_count = $notifier->getNotificationCount($rider_id, 'rider');

// Handle flash messages from return
$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Get rider face preview
function get_face_preview($face_data) {
    if (empty($face_data)) return '';
    if (strpos($face_data, 'data:image') === 0) {
        return $face_data;
    }
    return '';
}

$face_preview = get_face_preview($rider_data['face_data'] ?? '');

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>Rider Dashboard — MotoAdmin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0; 
    padding: 0;
    -webkit-tap-highlight-color: transparent;
}

:root {
    --bg-base:       #0B1120;
    --bg-surface:    #111827;
    --bg-card:       #1E293B;
    --bg-card-hover: #243044;
    --border:        #2D3F55;
    --accent-blue:   #3B82F6;
    --accent-green:  #10B981;
    --accent-amber:  #F59E0B;
    --accent-red:    #EF4444;
    --accent-purple: #8B5CF6;
    --text-primary:  #F1F5F9;
    --text-secondary:#94A3B8;
    --text-muted:    #475569;
    --radius:        12px;
    --safe-bottom:   env(safe-area-inset-bottom, 0px);
}

html, body {
    height: 100%;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    background: var(--bg-base);
    color: var(--text-primary);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
}

body { 
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    padding-bottom: 80px;
}

.flash-message {
    padding: 14px 18px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.flash-message.success {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.25);
    color: #6ee7b7;
}

.flash-message.error {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.25);
    color: #fca5a5;
}

.bottom-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--bg-surface);
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-around;
    align-items: center;
    padding: 8px 0 calc(8px + var(--safe-bottom));
    z-index: 200;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    background: rgba(17, 24, 39, 0.95);
}

.nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 4px 12px;
    text-decoration: none;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 500;
    transition: color 0.2s;
    position: relative;
    min-width: 56px;
}

.nav-item svg {
    width: 24px;
    height: 24px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.nav-item.active {
    color: var(--accent-blue);
}

.nav-item.active::after {
    content: '';
    position: absolute;
    top: -1px;
    left: 50%;
    transform: translateX(-50%);
    width: 20px;
    height: 2px;
    background: var(--accent-blue);
    border-radius: 0 0 2px 2px;
}

.nav-item.logout-item {
    color: var(--accent-red);
}

.nav-item.logout-item:active {
    color: #dc2626;
}

.nav-item .badge {
    position: absolute;
    top: -2px;
    right: 4px;
    background: var(--accent-red);
    color: white;
    font-size: 9px;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 10px;
    min-width: 16px;
    text-align: center;
}

.topbar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    min-height: 60px;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.menu-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--text-secondary);
    padding: 4px;
    cursor: pointer;
}

.topbar-left .brand {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.5px;
    color: var(--text-primary);
}

.topbar-left .brand span {
    color: var(--accent-blue);
}

.topbar-left .brand small {
    font-size: 10px;
    font-weight: 500;
    color: var(--text-muted);
    margin-left: 4px;
    letter-spacing: 0.3px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: var(--text-secondary);
    background: var(--bg-card);
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid var(--border);
    white-space: nowrap;
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent-green);
    box-shadow: 0 0 0 2px rgba(16,185,129,0.25);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 2px rgba(16,185,129,0.25); }
    50%       { box-shadow: 0 0 0 5px rgba(16,185,129,0.05); }
}

.main {
    flex: 1;
    padding: 16px 16px 20px;
    max-width: 100%;
}

.welcome-card {
    background: linear-gradient(135deg, var(--bg-card) 0%, var(--bg-surface) 100%);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.welcome-card::before {
    content: '';
    position: absolute;
    right: -40px;
    top: -40px;
    width: 150px;
    height: 150px;
    background: radial-gradient(circle, rgba(59,130,246,0.06) 0%, transparent 70%);
    pointer-events: none;
}

.welcome-text h2 {
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -0.3px;
    margin-bottom: 4px;
}

.welcome-text p {
    font-size: 13px;
    color: var(--text-secondary);
}

.welcome-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    flex-shrink: 0;
    background: rgba(16,185,129,0.12);
    border: 1px solid rgba(16,185,129,0.25);
    color: var(--accent-green);
}

.status-badge.pending {
    background: rgba(245,158,11,0.12);
    border-color: rgba(245,158,11,0.25);
    color: var(--accent-amber);
}

.status-badge::before {
    content: '';
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0 12px;
}

.section-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
}

.section-action {
    font-size: 12px;
    color: var(--accent-blue);
    text-decoration: none;
    font-weight: 500;
}

.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 16px;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 2.5px;
}

.stat-card.blue::before   { background: var(--accent-blue); }
.stat-card.green::before  { background: var(--accent-green); }
.stat-card.purple::before { background: var(--accent-purple); }
.stat-card.amber::before  { background: var(--accent-amber); }

.stat-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
}

.stat-card.blue   .stat-icon { background: rgba(59,130,246,0.1); color: var(--accent-blue); }
.stat-card.green  .stat-icon { background: rgba(16,185,129,0.1);  color: var(--accent-green); }
.stat-card.purple .stat-icon { background: rgba(139,92,246,0.1); color: var(--accent-purple); }
.stat-card.amber  .stat-icon { background: rgba(245,158,11,0.1); color: var(--accent-amber); }

.stat-icon svg {
    width: 14px;
    height: 14px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.stat-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    margin-bottom: 2px;
}

.stat-value {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-primary);
    word-break: break-all;
    line-height: 1.3;
}

.stat-sub {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 3px;
}

.status-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.status-card .status-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: rgba(16,185,129,0.1);
    border: 1px solid rgba(16,185,129,0.2);
}

.status-card .status-icon.offline {
    background: rgba(71, 85, 105, 0.1);
    border-color: rgba(71, 85, 105, 0.2);
}

.status-card .status-icon svg {
    width: 20px;
    height: 20px;
    stroke: var(--accent-green);
    stroke-width: 2;
    fill: none;
}

.status-card .status-icon.offline svg {
    stroke: var(--text-muted);
}

.status-card .status-info {
    flex: 1;
    min-width: 0;
}

.status-card .status-info .label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

.status-card .status-info .value {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    margin-top: 2px;
}

.status-card .status-info .sub {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}

.rental-timer-card {
    background: var(--bg-card);
    border: 2px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.rental-timer-card.stolen {
    border-color: var(--accent-red);
    background: rgba(239, 68, 68, 0.08);
    animation: stolen-pulse 2s infinite;
}

@keyframes stolen-pulse {
    0%, 100% { border-color: var(--accent-red); }
    50% { border-color: rgba(239, 68, 68, 0.3); }
}

.rental-timer-card.grace-period {
    border-color: var(--accent-amber);
    background: rgba(245, 158, 11, 0.08);
    animation: grace-pulse 1s infinite;
}

@keyframes grace-pulse {
    0%, 100% { border-color: var(--accent-amber); }
    50% { border-color: rgba(245, 158, 11, 0.3); }
}

.timer-display {
    text-align: center;
}

.timer-label {
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--text-muted);
    margin-bottom: 8px;
    font-weight: 600;
}

.timer-countdown {
    font-size: 56px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: var(--text-primary);
    font-family: 'Inter', monospace;
    padding: 8px;
    letter-spacing: 2px;
}

.timer-countdown.urgent {
    color: var(--accent-red);
    animation: blink-timer 1s infinite;
}

.timer-countdown.warning {
    color: var(--accent-amber);
}

.timer-countdown.grace {
    color: var(--accent-amber);
    animation: blink-timer 0.8s infinite;
}

.timer-countdown.stolen {
    color: var(--accent-red);
    animation: blink-timer 0.5s infinite;
}

@keyframes blink-timer {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

.timer-status {
    margin: 12px 0;
    font-size: 16px;
    font-weight: 600;
}

.status-active {
    color: var(--accent-green);
}

.status-grace {
    color: var(--accent-amber);
    animation: pulse-warning 1s infinite;
}

.status-expired {
    color: var(--accent-red);
}

.status-stolen {
    color: var(--accent-red);
    font-weight: 700;
    font-size: 20px;
    animation: pulse-warning 0.8s infinite;
}

.status-warning {
    color: var(--accent-amber);
}

.status-urgent {
    color: var(--accent-red);
    animation: pulse-warning 1.5s infinite;
}

@keyframes pulse-warning {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.timer-details {
    display: flex;
    justify-content: center;
    gap: 24px;
    font-size: 13px;
    color: var(--text-muted);
    margin-top: 14px;
    flex-wrap: wrap;
    padding: 12px;
    background: var(--bg-surface);
    border-radius: var(--radius-sm);
}

.timer-details span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.timer-details .label {
    color: var(--text-muted);
}

.timer-details .value {
    color: var(--text-primary);
    font-weight: 500;
}

.timer-progress {
    width: 100%;
    height: 6px;
    background: var(--bg-surface);
    border-radius: 3px;
    margin-top: 16px;
    overflow: hidden;
}

.timer-progress-bar {
    height: 100%;
    border-radius: 3px;
    transition: width 1s linear;
    background: var(--accent-green);
}

.timer-progress-bar.warning {
    background: var(--accent-amber);
}

.timer-progress-bar.danger {
    background: var(--accent-red);
    animation: progress-pulse 1s infinite;
}

@keyframes progress-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.stolen-alert {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px;
    background: rgba(239, 68, 68, 0.1);
    border-radius: var(--radius-sm);
    border: 2px solid rgba(239, 68, 68, 0.3);
}

.stolen-icon {
    font-size: 48px;
    animation: blink 1s infinite;
}

@keyframes blink {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.3; transform: scale(0.9); }
}

.stolen-message h3 {
    color: var(--accent-red);
    margin-bottom: 4px;
    font-size: 18px;
}

.stolen-message p {
    color: var(--text-secondary);
    font-size: 14px;
}

.stolen-warning {
    color: var(--accent-red) !important;
    font-weight: 600;
}

.grace-period-warning {
    margin-top: 16px;
    padding: 16px;
    background: rgba(245, 158, 11, 0.1);
    border-radius: var(--radius-sm);
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.alert {
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    font-size: 14px;
}

.alert-danger {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #fca5a5;
}

.alert-stolen {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.5);
    color: #fca5a5;
    font-weight: 600;
}

.no-rental {
    text-align: center;
    padding: 30px 20px;
    color: var(--text-muted);
}

.no-rental .sub-text {
    font-size: 13px;
    margin-top: 6px;
}

.btn-return {
    display: inline-block;
    margin-top: 16px;
    padding: 14px 32px;
    border-radius: var(--radius-sm);
    border: none;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.2s;
    text-decoration: none;
    width: 100%;
    background: var(--accent-green);
    color: #fff;
}

.btn-return:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.btn-return:active {
    transform: scale(0.97);
}

.btn-return.danger {
    background: var(--accent-red);
    animation: return-pulse 1.5s infinite;
}

@keyframes return-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    50% { box-shadow: 0 0 20px rgba(239, 68, 68, 0.2); }
}

.btn-return.danger:hover {
    opacity: 0.9;
}

.btn-return:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.notifications-list {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    margin-bottom: 20px;
}

.notification-item {
    display: flex;
    gap: 12px;
    padding: 12px;
    border-bottom: 1px solid var(--border);
    transition: background 0.15s;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item.stolen_declared {
    background: rgba(239, 68, 68, 0.05);
}

.notification-item.grace_period_warning {
    background: rgba(245, 158, 11, 0.05);
}

.notification-item.rental_started {
    background: rgba(59, 130, 246, 0.05);
}

.notification-item.returned {
    background: rgba(16, 185, 129, 0.05);
}

.notification-icon {
    font-size: 20px;
    flex-shrink: 0;
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 2px;
}

.notification-message {
    font-size: 13px;
    color: var(--text-secondary);
}

.notification-time {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 4px;
}

.no-notifications {
    text-align: center;
    padding: 20px;
    color: var(--text-muted);
}

.notification-badge {
    display: inline-block;
    background: var(--accent-red);
    color: white;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    min-width: 20px;
    text-align: center;
}

.actions-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 8px;
}

.action-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    transition: background 0.15s, border-color 0.15s, transform 0.15s;
    color: var(--text-primary);
    touch-action: manipulation;
}

.action-card:active {
    transform: scale(0.97);
    background: var(--bg-card-hover);
}

.action-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.action-card.blue   .action-icon { background: rgba(59,130,246,0.1); color: var(--accent-blue); }
.action-card.green  .action-icon { background: rgba(16,185,129,0.1); color: var(--accent-green); }
.action-card.purple .action-icon { background: rgba(139,92,246,0.1); color: var(--accent-purple); }
.action-card.amber  .action-icon { background: rgba(245,158,11,0.1); color: var(--accent-amber); }
.action-card.red    .action-icon { background: rgba(239,68,68,0.1); color: var(--accent-red); }

.action-icon svg {
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.action-card .title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
}

.action-card .desc {
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1.3;
}

.avatar-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-blue), var(--accent-purple));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: white;
    flex-shrink: 0;
}

.avatar-face {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid var(--border);
    flex-shrink: 0;
}

.drawer-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 300;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

.drawer-overlay.active {
    display: block;
}

.drawer {
    position: fixed;
    top: 0;
    left: -280px;
    width: 280px;
    height: 100%;
    background: var(--bg-surface);
    z-index: 301;
    transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    padding: 20px;
    overflow-y: auto;
    border-right: 1px solid var(--border);
}

.drawer.active {
    left: 0;
}

.drawer-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
}

.drawer-close {
    background: none;
    border: none;
    color: var(--text-secondary);
    font-size: 24px;
    cursor: pointer;
    padding: 4px;
}

.drawer-user {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--bg-card);
    border-radius: var(--radius);
    margin-bottom: 20px;
}

.drawer-user .info .name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.drawer-user .info .role {
    font-size: 12px;
    color: var(--text-muted);
}

.drawer-nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.drawer-nav .nav-item-drawer {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 500;
    transition: background 0.15s, color 0.15s;
}

.drawer-nav .nav-item-drawer:active {
    background: var(--bg-card);
}

.drawer-nav .nav-item-drawer.active {
    background: rgba(59,130,246,0.1);
    color: var(--accent-blue);
}

.drawer-nav .nav-item-drawer.logout {
    color: var(--accent-red);
    margin-top: 8px;
    border-top: 1px solid var(--border);
    padding-top: 16px;
}

.drawer-nav .nav-item-drawer.logout:active {
    background: rgba(239,68,68,0.1);
}

.drawer-nav .nav-item-drawer svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
    flex-shrink: 0;
}

.drawer-divider {
    height: 1px;
    background: var(--border);
    margin: 12px 0;
}

.drawer-logout {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
    margin-top: 8px;
    transition: background 0.15s, color 0.15s;
}

.drawer-logout:active {
    background: rgba(239,68,68,0.1);
    color: var(--accent-red);
}

.drawer-logout svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.sidebar {
    display: none;
}

@media (min-width: 768px) {
    body {
        padding-bottom: 0;
    }
    
    .bottom-nav {
        display: none;
    }
    
    .sidebar {
        display: flex;
        flex-direction: column;
        width: 220px;
        background: var(--bg-surface);
        border-right: 1px solid var(--border);
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 100;
        padding: 20px 12px;
        overflow-y: auto;
    }
    
    .sidebar-logo {
        padding: 0 8px 20px;
        border-bottom: 1px solid var(--border);
        margin-bottom: 16px;
    }
    
    .sidebar-logo .brand {
        font-size: 20px;
        font-weight: 800;
        letter-spacing: -0.5px;
        color: var(--text-primary);
    }
    
    .sidebar-logo .brand span {
        color: var(--accent-blue);
    }
    
    .sidebar-logo .sub {
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 2px;
    }
    
    .sidebar-nav {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    
    .sidebar-nav .nav-item-drawer {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        text-decoration: none;
        color: var(--text-secondary);
        font-size: 13px;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
    }
    
    .sidebar-nav .nav-item-drawer:hover {
        background: var(--bg-card);
        color: var(--text-primary);
    }
    
    .sidebar-nav .nav-item-drawer.active {
        background: rgba(59,130,246,0.1);
        color: var(--accent-blue);
    }
    
    .sidebar-nav .nav-item-drawer.logout {
        color: var(--accent-red);
        margin-top: 8px;
        border-top: 1px solid var(--border);
        padding-top: 16px;
    }
    
    .sidebar-nav .nav-item-drawer.logout:hover {
        background: rgba(239,68,68,0.1);
    }
    
    .sidebar-nav .nav-item-drawer svg {
        width: 18px;
        height: 18px;
        stroke: currentColor;
        stroke-width: 2;
        fill: none;
        flex-shrink: 0;
    }
    
    .sidebar-nav .nav-section {
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: var(--text-muted);
        padding: 12px 8px 4px;
    }
    
    .sidebar-footer {
        border-top: 1px solid var(--border);
        padding-top: 16px;
        margin-top: 8px;
    }
    
    .sidebar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        border-radius: 8px;
        margin-bottom: 8px;
    }
    
    .sidebar-user .info .name {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-primary);
    }
    
    .sidebar-user .info .role {
        font-size: 11px;
        color: var(--text-muted);
    }
    
    .sidebar-logout {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        border-radius: 8px;
        text-decoration: none;
        color: var(--accent-red);
        font-size: 13px;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
    }
    
    .sidebar-logout:hover {
        background: rgba(239,68,68,0.1);
    }
    
    .sidebar-logout svg {
        width: 16px;
        height: 16px;
        stroke: currentColor;
        stroke-width: 2;
        fill: none;
    }
    
    .main {
        margin-left: 220px;
        padding: 20px 24px 30px;
        max-width: calc(100% - 220px);
    }
    
    .topbar {
        padding: 16px 24px;
    }
    
    .menu-toggle {
        display: none !important;
    }
    
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }
    
    .actions-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }
    
    .drawer-overlay, .drawer {
        display: none !important;
    }

    .timer-countdown {
        font-size: 72px;
    }
}

@media (min-width: 1024px) {
    .sidebar {
        width: 260px;
        padding: 24px 16px;
    }
    
    .main {
        margin-left: 260px;
        padding: 24px 32px 30px;
        max-width: calc(100% - 260px);
    }
    
    .topbar {
        padding: 16px 32px;
    }
    
    .stats-grid {
        gap: 16px;
    }
    
    .actions-grid {
        gap: 16px;
    }
}

@media (max-width: 380px) {
    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .actions-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    
    .stat-card {
        padding: 12px 14px;
    }
    
    .action-card {
        padding: 14px;
    }
    
    .welcome-text h2 {
        font-size: 17px;
    }
    
    .topbar-left .brand {
        font-size: 16px;
    }
    
    .timer-countdown {
        font-size: 32px;
    }
}

@media (hover: none) {
    .action-card:hover {
        transform: none;
        background: var(--bg-card);
    }
    
    .action-card:active {
        transform: scale(0.96);
        background: var(--bg-card-hover);
    }
}

.hidden {
    display: none !important;
}

.text-truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
</head>
<body data-rider-id="<?php echo $id; ?>">

<!-- ── Mobile Drawer ──────────────────────────── -->
<div class="drawer-overlay" id="drawerOverlay"></div>

<div class="drawer" id="drawer">
    <div class="drawer-header">
        <div class="sidebar-logo">
            <div class="brand">Moto<span>Admin</span></div>
            <div class="sub">Fleet Management</div>
        </div>
        <button class="drawer-close" id="drawerClose" aria-label="Close menu">✕</button>
    </div>
    
    <div class="drawer-user">
        <?php if ($face_preview): ?>
            <img class="avatar-face" src="<?php echo htmlspecialchars($face_preview); ?>" alt="Face">
        <?php else: ?>
            <div class="avatar-small">
                <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
            </div>
        <?php endif; ?>
        <div class="info">
            <div class="name"><?php echo htmlspecialchars($rider_name); ?></div>
            <div class="role">Rider</div>
        </div>
    </div>
    
    <nav class="drawer-nav">
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);padding:4px 12px 8px;">Overview</div>
        
        <a href="dashboard.php" class="nav-item-drawer active">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);padding:12px 12px 4px;margin-top:4px;">My Bike</div>
        
        <a href="map.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
            Track Bike
        </a>
        
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);padding:12px 12px 4px;margin-top:4px;">Account</div>
        
        <a href="profile.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Profile
        </a>
        
        <a href="../auth/logout.php" class="nav-item-drawer logout">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </nav>
</div>

<!-- ── Top Bar ─────────────────────────────────── -->
<header class="topbar">
    <div class="topbar-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
            <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none">
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
        </button>
        <div class="brand">
            Moto<span>Admin</span>
            <small>Rider</small>
        </div>
    </div>
    <div class="topbar-right">
        <div class="status-indicator">
            <span class="status-dot"></span>
            <span>Online</span>
        </div>
        <?php if ($face_preview): ?>
            <img class="avatar-face" src="<?php echo htmlspecialchars($face_preview); ?>" alt="Face">
        <?php else: ?>
            <div class="avatar-small" id="avatarDesktop">
                <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
            </div>
        <?php endif; ?>
    </div>
</header>

<!-- ── Desktop Sidebar ─────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="brand">Moto<span>Admin</span></div>
        <div class="sub">Fleet Management</div>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">Overview</div>
        
        <a href="dashboard.php" class="nav-item-drawer active">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        
        <div class="nav-section" style="margin-top:12px;">My Bike</div>
        
        <a href="map.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
            Track Bike
        </a>
        
        <div class="nav-section" style="margin-top:12px;">Account</div>
        
        <a href="profile.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            Profile
        </a>
        
        <a href="../auth/logout.php" class="nav-item-drawer logout">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Sign Out
        </a>
    </nav>
    
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <?php if ($face_preview): ?>
                <img class="avatar-face" src="<?php echo htmlspecialchars($face_preview); ?>" alt="Face">
            <?php else: ?>
                <div class="avatar-small">
                    <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
                </div>
            <?php endif; ?>
            <div class="info">
                <div class="name"><?php echo htmlspecialchars($rider_name); ?></div>
                <div class="role">Rider</div>
            </div>
        </div>
    </div>
</aside>

<!-- ── Main Content ───────────────────────────── -->
<main class="main">

    <div class="welcome-card">
        <div class="welcome-top">
            <div class="welcome-text">
                <h2>👋 Welcome back, <?php echo htmlspecialchars(explode(' ', $rider_name)[0]); ?></h2>
                <p><?php echo $current_date; ?></p>
            </div>
            <?php
            $status = $rider_data['status'] ?? 'pending';
            echo "<span class=\"status-badge " . ($status === 'approved' ? '' : 'pending') . "\">" . ucfirst($status) . "</span>";
            ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash-message <?php echo strpos($flash, '✅') !== false ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($flash); ?>
        </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- RENTAL TIMER SECTION                                             -->
    <!-- ================================================================ -->
    <div class="section-header">
        <span class="section-label">⏱️ E-Bike Rental Timer (24 Hours)</span>
    </div>

    <div class="rental-timer-card <?php echo $is_stolen ? 'stolen' : ($rentalStatus && $rentalStatus['is_grace_period'] ? 'grace-period' : '') ?>">
        <?php if ($is_stolen): ?>
            <div class="stolen-alert">
                <div class="stolen-icon">🚨</div>
                <div class="stolen-message">
                    <h3>VEHICLE DECLARED AS STOLEN</h3>
                    <p>You have failed to return the E-Bike within the 24-hour period.</p>
                    <p class="stolen-warning">This is a serious matter. Please contact support immediately.</p>
                    <button class="btn-return danger" onclick="contactSupport()" style="width:auto;padding:10px 24px;margin-top:10px;">Contact Support Now</button>
                </div>
            </div>
        <?php elseif ($rentalStatus && $rentalStatus['has_rental']): ?>
            <div class="timer-display">
                <div class="timer-label">Time Remaining (24 Hours Total)</div>
                <div class="timer-countdown <?php 
                    if ($rentalStatus['is_grace_period']) echo 'grace';
                    elseif ($rentalStatus['is_expired']) echo 'stolen';
                    elseif ($rentalStatus['time_remaining_seconds'] < 3600) echo 'urgent';
                    elseif ($rentalStatus['time_remaining_seconds'] < 7200) echo 'warning';
                ?>" id="rental-timer">
                    <?php echo $rentalStatus['time_remaining_formatted']; ?>
                </div>
                
                <?php 
                    $total_seconds = 24 * 60 * 60;
                    $remaining = $rentalStatus['time_remaining_seconds'];
                    $progress = ($remaining / $total_seconds) * 100;
                    $progress_class = '';
                    if ($rentalStatus['is_grace_period'] || $rentalStatus['is_expired']) {
                        $progress_class = 'danger';
                    } elseif ($remaining < 3600) {
                        $progress_class = 'danger';
                    } elseif ($remaining < 7200) {
                        $progress_class = 'warning';
                    }
                ?>
                <div class="timer-progress">
                    <div class="timer-progress-bar <?php echo $progress_class; ?>" 
                         id="timer-progress-bar" 
                         style="width: <?php echo max(0, $progress); ?>%;">
                    </div>
                </div>

                <div class="timer-status" id="rental-status">
                    <?php if ($rentalStatus['is_grace_period']): ?>
                        <span class="status-grace">⚠️ GRACE PERIOD - <?php echo $rentalTimer->grace_period_minutes; ?> MINUTES REMAINING</span>
                    <?php elseif ($rentalStatus['is_expired']): ?>
                        <span class="status-expired">⏰ EXPIRED</span>
                    <?php elseif ($rentalStatus['time_remaining_seconds'] < 3600): ?>
                        <span class="status-urgent">⚠️ URGENT - Less than 1 hour remaining</span>
                    <?php elseif ($rentalStatus['time_remaining_seconds'] < 7200): ?>
                        <span class="status-warning">⚠️ Less than 2 hours remaining</span>
                    <?php else: ?>
                        <span class="status-active">✅ Active</span>
                    <?php endif; ?>
                </div>
                
                <div class="timer-details">
                    <span>
                        <span class="label">🟢 Started:</span>
                        <span class="value"><?php echo date('M j, g:i A', strtotime($rentalStatus['start_time'])); ?></span>
                    </span>
                    <span>
                        <span class="label">🔴 Ends (24h):</span>
                        <span class="value"><?php echo date('M j, g:i A', strtotime($rentalStatus['end_time'])); ?></span>
                    </span>
                    <span>
                        <span class="label">🚲 E-Bike ID:</span>
                        <span class="value"><?php echo htmlspecialchars($rentalStatus['ebike_id']); ?></span>
                    </span>
                </div>
                
                <?php if ($rentalStatus['is_grace_period']): ?>
                    <div class="grace-period-warning" id="rental-warning">
                        <div class="alert alert-danger">
                            <strong>⚠️ URGENT:</strong> Your 24-hour rental period has ended. 
                            You have <strong><?php echo $rentalTimer->grace_period_minutes; ?> minutes</strong> to return the E-Bike. 
                            If you fail to return it, the vehicle will be declared as stolen.
                        </div>
                    </div>
                <?php endif; ?>

                <button class="btn-return <?php echo $rentalStatus['is_grace_period'] ? 'danger' : ''; ?>" id="returnBtn" onclick="returnEbike()">
                    <?php echo $rentalStatus['is_grace_period'] ? '⚠️ Return E-Bike Now!' : '🔑 Return E-Bike'; ?>
                </button>
            </div>
        <?php else: ?>
            <div class="no-rental">
                <p>No active rental session</p>
                <p class="sub-text">Your E-Bike rental will appear here when you start a session</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="section-header">
        <span class="section-label">Account Details</span>
    </div>

    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </div>
            <div class="stat-label">Rider</div>
            <div class="stat-value text-truncate"><?php echo htmlspecialchars(explode(' ', $rider_name)[0] ?? '—'); ?></div>
        </div>

        <div class="stat-card green">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </div>
            <div class="stat-label">Email</div>
            <div class="stat-value text-truncate"><?php echo htmlspecialchars($rider_data['email'] ?? '—'); ?></div>
        </div>

        <div class="stat-card purple">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 11.51 18a19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </div>
            <div class="stat-label">Phone</div>
            <div class="stat-value"><?php echo htmlspecialchars($rider_data['phone'] ?? '—'); ?></div>
        </div>

        <div class="stat-card amber">
            <div class="stat-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="stat-label">Status</div>
            <div class="stat-value"><?php echo ucfirst($rider_data['status'] ?? 'pending'); ?></div>
        </div>
    </div>

    <div class="section-header">
        <span class="section-label">SIM800L Status</span>
    </div>

    <div class="status-card">
        <div class="status-icon <?php echo $is_online ? '' : 'offline'; ?>">
            <svg viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="2"/>
                <path d="M12 2a10 10 0 0 1 10 10"/>
                <path d="M12 6a6 6 0 0 1 6 6"/>
                <path d="M12 10a2 2 0 0 1 2 2"/>
            </svg>
        </div>
        <div class="status-info">
            <div class="label">Connection</div>
            <div class="value"><?php echo htmlspecialchars($online_label); ?></div>
            <?php if($loc && !empty($loc['battery'])): ?>
            <div class="sub">
                Battery <?php echo htmlspecialchars($loc['battery']); ?>%
                <?php if(!empty($loc['created_at'])): ?>
                · <?php echo htmlspecialchars(date('h:i A', strtotime($loc['created_at']))); ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- NOTIFICATIONS SECTION                                            -->
    <!-- ================================================================ -->
    <div class="section-header">
        <span class="section-label">🔔 Notifications</span>
        <?php if ($notification_count > 0): ?>
            <span class="notification-badge"><?php echo $notification_count; ?></span>
        <?php endif; ?>
    </div>

    <div class="notifications-list" id="notifications">
        <?php if ($unread_notifications && $unread_notifications->num_rows > 0): ?>
            <?php while ($notif = $unread_notifications->fetch_assoc()): ?>
                <div class="notification-item <?php echo $notif['type']; ?>">
                    <div class="notification-icon">
                        <?php if ($notif['type'] === 'stolen_declared'): ?>
                            🚨
                        <?php elseif ($notif['type'] === 'grace_period_warning'): ?>
                            ⚠️
                        <?php elseif ($notif['type'] === 'rental_started'): ?>
                            🚀
                        <?php elseif ($notif['type'] === 'returned'): ?>
                            ✅
                        <?php else: ?>
                            ℹ️
                        <?php endif; ?>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                        <div class="notification-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                        <div class="notification-time"><?php echo date('M j, g:i A', strtotime($notif['created_at'])); ?></div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-notifications">
                <p>✅ No new notifications</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="section-header">
        <span class="section-label">Quick Actions</span>
    </div>

    <div class="actions-grid">
        <a href="map.php" class="action-card green">
            <div class="action-icon">
                <svg viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
            </div>
            <div>
                <div class="title">Track Bike</div>
                <div class="desc">View location & status</div>
            </div>
        </a>

        <a href="profile.php" class="action-card blue">
            <div class="action-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </div>
            <div>
                <div class="title">My Profile</div>
                <div class="desc">View & edit details</div>
            </div>
        </a>
    </div>

</main>

<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item active">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span>Home</span>
    </a>
    
    <a href="map.php" class="nav-item">
        <svg viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
        <span>Map</span>
    </a>
    
    <a href="profile.php" class="nav-item">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>Profile</span>
    </a>
    
    <a href="../auth/logout.php" class="nav-item logout-item">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Logout</span>
    </a>
</nav>

<script>
const menuToggle = document.getElementById('menuToggle');
const drawer = document.getElementById('drawer');
const drawerOverlay = document.getElementById('drawerOverlay');
const drawerClose = document.getElementById('drawerClose');

function openDrawer() {
    drawer.classList.add('active');
    drawerOverlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDrawer() {
    drawer.classList.remove('active');
    drawerOverlay.classList.remove('active');
    document.body.style.overflow = '';
}

if (menuToggle) {
    menuToggle.addEventListener('click', openDrawer);
}

if (drawerClose) {
    drawerClose.addEventListener('click', closeDrawer);
}

if (drawerOverlay) {
    drawerOverlay.addEventListener('click', closeDrawer);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDrawer();
});

window.contactSupport = function() {
    alert('Please contact support at: support@motoadmin.com or call +63 900 000 0000');
};

// ================================================================
// RETURN E-BIKE FUNCTIONALITY (AJAX)
// ================================================================
window.returnEbike = function() {
    const btn = document.getElementById('returnBtn');
    if (!btn) return;

    if (!confirm('⚠️ Are you sure you want to return this E-Bike?\n\nYour rental will end, you will be logged out, and your account will be permanently removed. You must register again with the administrator to log in.')) {
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Processing...';

    fetch('../api/return-ebike.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            rider_id: <?php echo $rider_id; ?>
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('✅ ' + data.message, 'success');
            setTimeout(function() {
                window.location.href = data.redirect || '../index.php?returned=1';
            }, 2000);
        } else {
            showNotification('❌ ' + data.message, 'error');
            btn.disabled = false;
            btn.textContent = '🔑 Return E-Bike';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('❌ Error processing request. Please try again.', 'error');
        btn.disabled = false;
        btn.textContent = '🔑 Return E-Bike';
    });
};

function showNotification(message, type) {
    const existing = document.querySelectorAll('.flash-notification');
    existing.forEach(el => el.remove());

    const flash = document.createElement('div');
    flash.className = 'flash-notification';
    flash.textContent = message;
    flash.style.cssText = `
        position: fixed;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 999;
        max-width: 90%;
        padding: 14px 20px;
        border-radius: var(--radius-sm);
        font-weight: 500;
        background: ${type === 'success' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)'};
        border: 1px solid ${type === 'success' ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)'};
        color: ${type === 'success' ? '#6ee7b7' : '#fca5a5'};
        text-align: center;
        animation: fadeInUp 0.3s ease;
    `;
    
    document.body.appendChild(flash);
    setTimeout(function() { 
        flash.style.opacity = '0';
        flash.style.transition = 'opacity 0.3s';
        setTimeout(function() { flash.remove(); }, 300);
    }, 5000);
}

const style = document.createElement('style');
style.textContent = `
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateX(-50%) translateY(10px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
`;
document.head.appendChild(style);

// ================================================================
// RENTAL TIMER REAL-TIME UPDATE - 24 HOURS FIXED
// ================================================================
<?php if ($rentalStatus && $rentalStatus['has_rental'] && !$is_stolen): ?>
(function() {
    const timerElement = document.getElementById('rental-timer');
    const statusElement = document.getElementById('rental-status');
    const progressBar = document.getElementById('timer-progress-bar');
    const warningElement = document.getElementById('rental-warning');
    const returnBtn = document.getElementById('returnBtn');
    const endTimestamp = <?php echo (int)($rentalStatus['end_timestamp'] ?? 0); ?>;
    const totalSeconds = <?php echo (int)($rentalStatus['total_duration_seconds'] ?? (24 * 60 * 60)); ?>;
    const gracePeriodMinutes = <?php echo $rentalTimer->grace_period_minutes; ?>;
    let isGracePeriod = <?php echo $rentalStatus['is_grace_period'] ? 'true' : 'false'; ?>;

    function formatTime(seconds) {
        if (seconds < 0) seconds = 0;
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        return String(hours).padStart(2, '0') + ':' + 
               String(minutes).padStart(2, '0') + ':' + 
               String(secs).padStart(2, '0');
    }

    function updateTimer() {
        const now = Math.floor(Date.now() / 1000);
        const end = endTimestamp;
        let remaining = end - now;

        if (timerElement) {
            timerElement.textContent = formatTime(remaining);
            
            timerElement.className = 'timer-countdown';
            if (remaining <= 0) {
                const graceEnd = end + (gracePeriodMinutes * 60);
                if (now <= graceEnd && !isGracePeriod) {
                    timerElement.classList.add('grace');
                } else if (now > graceEnd) {
                    timerElement.classList.add('stolen');
                } else {
                    timerElement.classList.add('urgent');
                }
            } else if (remaining < 3600) {
                timerElement.classList.add('urgent');
            } else if (remaining < 7200) {
                timerElement.classList.add('warning');
            }
        }

        if (progressBar) {
            // FIXED: Using 24 hours for progress calculation
            const progress = Math.max(0, Math.min(100, (remaining / totalSeconds) * 100));
            progressBar.style.width = progress + '%';
            
            progressBar.className = 'timer-progress-bar';
            if (remaining <= 0 || remaining < 3600) {
                progressBar.classList.add('danger');
            } else if (remaining < 7200) {
                progressBar.classList.add('warning');
            }
        }

        if (statusElement) {
            if (remaining <= 0) {
                const graceEnd = end + (gracePeriodMinutes * 60);
                if (now <= graceEnd && !isGracePeriod) {
                    isGracePeriod = true;
                    statusElement.innerHTML = '<span class="status-grace">⚠️ GRACE PERIOD - ' + gracePeriodMinutes + ' MINUTES REMAINING</span>';
                    if (warningElement) {
                        warningElement.innerHTML = `
                            <div class="alert alert-danger">
                                <strong>⚠️ URGENT:</strong> Your 24-hour rental period has ended. 
                                You have <strong>${gracePeriodMinutes} minutes</strong> to return the E-Bike. 
                                If you fail to return it, the vehicle will be declared as stolen.
                            </div>
                        `;
                        warningElement.style.display = 'block';
                    }
                    if (returnBtn) {
                        returnBtn.className = 'btn-return danger';
                        returnBtn.textContent = '⚠️ Return E-Bike Now!';
                    }
                } else if (now > graceEnd) {
                    statusElement.innerHTML = '<span class="status-stolen">🚨 STOLEN</span>';
                    setTimeout(function() { location.reload(); }, 3000);
                } else {
                    statusElement.innerHTML = '<span class="status-expired">⏰ EXPIRED</span>';
                }
            } else if (remaining < 3600) {
                statusElement.innerHTML = '<span class="status-urgent">⚠️ URGENT - Less than 1 hour remaining</span>';
            } else if (remaining < 7200) {
                statusElement.innerHTML = '<span class="status-warning">⚠️ Less than 2 hours remaining</span>';
            } else {
                statusElement.innerHTML = '<span class="status-active">✅ Active</span>';
            }
        }
    }

    updateTimer();
    setInterval(updateTimer, 1000);
})();
<?php endif; ?>

<?php if (isset($_GET['returned']) && $_GET['returned'] == 1): ?>
showNotification('✅ E-Bike returned successfully!', 'success');
if (window.history && window.history.replaceState) {
    window.history.replaceState({}, document.title, window.location.pathname);
}
<?php endif; ?>

window.RIDER_ID = <?php echo $rider_id; ?>;
</script>

<?php if(file_exists("../assets/js/rider-session-ping.js")): ?>
<script src="../assets/js/rider-session-ping.js"></script>
<?php endif; ?>

<!-- Rider Global Alert System -->
<script src="../assets/js/rider-global-alert.js"></script>
</body>
</html>