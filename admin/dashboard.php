<?php

session_start();

if(!isset($_SESSION['admin']))
{
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/rental-timer.php");
require_once("../includes/rental-notifications.php");

// ================================================================
// INITIALIZE OBJECTS (ISANG BESES LANG)
// ================================================================
$rentalTimer = new RentalTimer($conn);
$notifier = new RentalNotifier($conn);

$riders = $conn->query(
    "SELECT COUNT(*) total FROM users WHERE role='rider'"
)->fetch_assoc()['total'];

$admin_name = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Administrator';
$current_date = date('l, F j, Y');
$admin_id = $_SESSION['admin'];

// ================================================================
// RENTAL ALERTS
// ================================================================
$admin_notifications = $notifier->getUnreadNotifications($admin_id, 'admin');
$admin_notification_count = $notifier->getNotificationCount($admin_id, 'admin');

$stolen_vehicles = $rentalTimer->getStolenRentals();
$stolen_count = $stolen_vehicles->num_rows;

// ================================================================
// ACTIVE RENTALS
// ================================================================
$active_rentals = $conn->query("
    SELECT rs.*, u.fullname, u.email, u.phone, u.face_data, u.ebike_id as user_ebike_id
    FROM rental_sessions rs
    JOIN users u ON u.id = rs.rider_id
    WHERE rs.status = 'active'
    ORDER BY rs.end_time ASC
");

$active_rentals_list = [];
$grace_period_rentals = [];

function get_face_preview_admin($face_data) {
    if (empty($face_data)) return '';
    if (strpos($face_data, 'data:image') === 0) {
        return $face_data;
    }
    return '';
}

while ($rental = $active_rentals->fetch_assoc()) {
    $status = $rentalTimer->getRentalStatus($rental['rider_id']);
    if ($status) {
        $rental['timer'] = $status;
        $rental['face_preview'] = get_face_preview_admin($rental['face_data'] ?? '');
        $active_rentals_list[] = $rental;
        if ($status['is_grace_period']) {
            $grace_period_rentals[] = $rental;
        }
    }
}
$grace_count = count($grace_period_rentals);
$active_count = count($active_rentals_list);

// ================================================================
// MAP PERIMETER ALERTS - MULA SA DATABASE (map_alerts table)
// ================================================================
$map_alerts = [];
$map_alert_count = 0;

// Check kung may map_alerts table
$check_alerts_table = $conn->query("SHOW TABLES LIKE 'map_alerts'");
if ($check_alerts_table && $check_alerts_table->num_rows > 0) {
    $alerts_query = $conn->query("
        SELECT * FROM map_alerts 
        WHERE status = 'active'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    
    if ($alerts_query && $alerts_query->num_rows > 0) {
        while ($alert = $alerts_query->fetch_assoc()) {
            $map_alerts[] = $alert;
            $map_alert_count++;
        }
    }
}

// Store in session for modal navigation
$_SESSION['active_rentals_list'] = $active_rentals_list;
$_SESSION['map_alerts_list'] = $map_alerts;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Admin Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root {
    --bg-primary: #0a0f1e;
    --bg-secondary: #111827;
    --bg-card: #1a2332;
    --bg-card-hover: #1f2a3d;
    --bg-input: #0f1629;
    --bg-surface: #0d1424;
    --border-color: #2a3a52;
    --text-primary: #f0f4ff;
    --text-secondary: #94a3b8;
    --text-muted: #4a5a72;
    --accent-blue: #3b82f6;
    --accent-blue-light: #60a5fa;
    --accent-green: #10b981;
    --accent-red: #ef4444;
    --accent-orange: #f59e0b;
    --accent-purple: #8b5cf6;
    --shadow: 0 8px 32px rgba(0,0,0,0.4);
    --radius: 12px;
    --radius-sm: 8px;
    --sidebar-width: 250px;
    --header-height: 60px;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg-primary);
    color: var(--text-primary);
    min-height: 100vh;
    overflow-x: hidden;
    display: flex;
}

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--sidebar-width);
    background: var(--bg-secondary);
    border-right: 1px solid var(--border-color);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-y: auto;
}

.sidebar-brand {
    padding: 24px 20px 16px;
    border-bottom: 1px solid var(--border-color);
}

.sidebar-brand .logo {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.5px;
    color: var(--text-primary);
}

.sidebar-brand .logo span {
    color: var(--accent-blue);
}

.sidebar-brand .sub {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 2px;
}

.sidebar-nav {
    flex: 1;
    padding: 16px 12px;
}

.nav-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--text-muted);
    padding: 12px 8px 8px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
    position: relative;
}

.nav-link:hover {
    background: var(--bg-card);
    color: var(--text-primary);
}

.nav-link.active {
    background: rgba(59, 130, 246, 0.12);
    color: var(--accent-blue);
}

.nav-link.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 24px;
    background: var(--accent-blue);
    border-radius: 0 3px 3px 0;
}

.nav-link svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    opacity: 0.7;
}

.nav-link .badge {
    margin-left: auto;
    background: var(--accent-orange);
    color: #0B1120;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    line-height: 1.4;
}

.nav-link .badge.danger {
    background: var(--accent-red);
    color: #fff;
}

.sidebar-footer {
    padding: 16px 12px;
    border-top: 1px solid var(--border-color);
}

.sidebar-user {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    border-radius: var(--radius-sm);
}

.sidebar-user .avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-blue), #6366f1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
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

.logout-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s;
    margin-top: 4px;
}

.logout-btn:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--accent-red);
}

.logout-btn svg {
    width: 16px;
    height: 16px;
}

.sidebar-toggle {
    display: none;
    position: fixed;
    top: 12px;
    left: 12px;
    z-index: 900;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    padding: 10px;
    cursor: pointer;
    color: var(--text-primary);
    transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}

.sidebar-toggle:hover {
    background: var(--bg-card);
}

.sidebar-toggle svg {
    width: 22px;
    height: 22px;
    display: block;
}

.sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 999;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.sidebar-overlay.open {
    opacity: 1;
}

.sidebar-close {
    display: none;
    position: absolute;
    top: 16px;
    right: 16px;
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 4px;
}

.sidebar-close svg {
    width: 24px;
    height: 24px;
}

.main {
    margin-left: var(--sidebar-width);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.header {
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
    padding: 0 28px;
    height: var(--header-height);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
}

.header-left h1 {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.3px;
}

.header-left .subtitle {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 1px;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.status-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 20px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    font-size: 12px;
    color: var(--text-secondary);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent-green);
    animation: pulse-dot 2s infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.8); }
}

.content {
    padding: 28px;
    flex: 1;
}

.section-header {
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.section-header h2 {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
}

.section-header .badge-count {
    font-size: 11px;
    padding: 3px 12px;
    border-radius: 20px;
    font-weight: 600;
}

.badge-count.danger {
    background: rgba(239, 68, 68, 0.15);
    color: var(--accent-red);
}

.badge-count.warning {
    background: rgba(245, 158, 11, 0.15);
    color: var(--accent-orange);
}

.badge-count.info {
    background: rgba(59, 130, 246, 0.15);
    color: var(--accent-blue);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 22px 24px;
    position: relative;
    overflow: hidden;
    transition: all 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    border-color: var(--accent-blue);
}

.stat-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
}

.stat-card.amber::before { background: var(--accent-orange); }
.stat-card.blue::before  { background: var(--accent-blue); }
.stat-card.green::before { background: var(--accent-green); }
.stat-card.red::before   { background: var(--accent-red); }

.stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
}

.stat-card.amber .stat-icon { background: rgba(245, 158, 11, 0.12); color: var(--accent-orange); }
.stat-card.blue .stat-icon  { background: rgba(59, 130, 246, 0.12); color: var(--accent-blue); }
.stat-card.green .stat-icon { background: rgba(16, 185, 129, 0.12); color: var(--accent-green); }
.stat-card.red .stat-icon   { background: rgba(239, 68, 68, 0.12); color: var(--accent-red); }

.stat-icon svg {
    width: 20px;
    height: 20px;
}

.stat-value {
    font-size: 36px;
    font-weight: 700;
    line-height: 1;
    letter-spacing: -1px;
    font-variant-numeric: tabular-nums;
    margin-bottom: 6px;
}

.stat-card.amber .stat-value { color: var(--accent-orange); }
.stat-card.blue .stat-value  { color: var(--accent-blue); }
.stat-card.green .stat-value { color: var(--accent-green); }
.stat-card.red .stat-value   { color: var(--accent-red); }

.stat-label {
    font-size: 13px;
    color: var(--text-secondary);
    font-weight: 500;
}

.stat-sublabel {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 3px;
}

/* Rider Cards */
.rider-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.rider-card-admin {
    background: var(--bg-card);
    border: 2px solid var(--border-color);
    border-radius: var(--radius);
    padding: 20px;
    transition: all 0.3s ease;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.rider-card-admin:hover {
    transform: translateY(-3px);
    border-color: var(--accent-blue);
    box-shadow: var(--shadow);
}

.rider-card-admin.grace {
    border-color: var(--accent-orange);
    animation: card-grace-pulse 1.5s infinite;
}

.rider-card-admin.stolen {
    border-color: var(--accent-red);
    animation: card-stolen-pulse 1s infinite;
}

.rider-card-admin.urgent {
    border-color: var(--accent-red);
}

@keyframes card-grace-pulse {
    0%, 100% { border-color: var(--accent-orange); box-shadow: 0 0 10px rgba(245,158,11,0.1); }
    50% { border-color: rgba(245,158,11,0.4); box-shadow: 0 0 20px rgba(245,158,11,0.2); }
}

@keyframes card-stolen-pulse {
    0%, 100% { border-color: var(--accent-red); box-shadow: 0 0 10px rgba(239,68,68,0.1); }
    50% { border-color: rgba(239,68,68,0.4); box-shadow: 0 0 25px rgba(239,68,68,0.3); }
}

.rider-card-admin .card-top {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 14px;
}

.rider-card-admin .card-face {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--border-color);
    background: var(--bg-input);
    flex-shrink: 0;
}

.rider-card-admin .card-face-placeholder {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    border: 2px dashed var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: var(--text-muted);
    background: var(--bg-input);
    flex-shrink: 0;
}

.rider-card-admin .card-name {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
}

.rider-card-admin .card-ebike {
    font-size: 12px;
    color: var(--text-muted);
}

.rider-card-admin .card-ebike strong {
    color: var(--accent-blue);
}

.rider-card-admin .card-timer {
    text-align: center;
    padding: 14px;
    background: var(--bg-surface);
    border-radius: var(--radius-sm);
    margin: 12px 0;
    position: relative;
    border: 1px solid transparent;
    transition: border-color 0.3s;
}

.rider-card-admin .card-timer.grace-timer {
    border-color: var(--accent-orange);
}

.rider-card-admin .card-timer.urgent-timer {
    border-color: var(--accent-red);
}

.rider-card-admin .card-timer .time {
    font-size: 32px;
    font-weight: 700;
    font-family: 'Inter', monospace;
    font-variant-numeric: tabular-nums;
    letter-spacing: 2px;
    color: var(--text-primary);
}

.rider-card-admin .card-timer .time.grace {
    color: var(--accent-orange);
    animation: timer-blink 1s infinite;
}

.rider-card-admin .card-timer .time.stolen {
    color: var(--accent-red);
    animation: timer-blink 0.5s infinite;
}

.rider-card-admin .card-timer .time.urgent {
    color: var(--accent-red);
    animation: timer-blink 1.5s infinite;
}

@keyframes timer-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

.rider-card-admin .card-timer .label {
    font-size: 10px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-top: 4px;
}

.rider-card-admin .card-progress-wrap {
    width: 100%;
    height: 6px;
    background: var(--bg-surface);
    border-radius: 4px;
    margin-top: 10px;
    overflow: hidden;
    position: relative;
}

.rider-card-admin .card-progress-bar {
    height: 100%;
    border-radius: 4px;
    transition: width 1s linear;
    position: relative;
}

.rider-card-admin .card-progress-bar.green {
    background: linear-gradient(90deg, var(--accent-green), #34d399);
}

.rider-card-admin .card-progress-bar.orange {
    background: linear-gradient(90deg, var(--accent-orange), #fbbf24);
}

.rider-card-admin .card-progress-bar.red {
    background: linear-gradient(90deg, var(--accent-red), #f87171);
    animation: progress-blink 1s infinite;
}

@keyframes progress-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.rider-card-admin .card-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 4px;
}

.rider-card-admin .card-status {
    display: inline-block;
    padding: 3px 14px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 8px;
}

.rider-card-admin .card-status.active {
    background: rgba(16, 185, 129, 0.12);
    color: var(--accent-green);
}

.rider-card-admin .card-status.grace {
    background: rgba(245, 158, 11, 0.15);
    color: var(--accent-orange);
    animation: status-blink 1s infinite;
}

.rider-card-admin .card-status.stolen {
    background: rgba(239, 68, 68, 0.15);
    color: var(--accent-red);
    animation: status-blink 0.5s infinite;
}

.rider-card-admin .card-status.urgent {
    background: rgba(239, 68, 68, 0.1);
    color: var(--accent-red);
    animation: status-blink 1.5s infinite;
}

@keyframes status-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.4; }
}

.rider-card-admin .card-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border-color);
}

.rider-card-admin .card-details {
    font-size: 11px;
    color: var(--text-muted);
}

.rider-card-admin .card-details span {
    display: block;
}

.rider-card-admin .card-actions .btn-sm {
    padding: 4px 14px;
    border-radius: var(--radius-sm);
    border: none;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}

.rider-card-admin .card-actions .btn-sm.btn-primary {
    background: rgba(59, 130, 246, 0.15);
    color: var(--accent-blue);
}

.rider-card-admin .card-actions .btn-sm.btn-primary:hover {
    background: rgba(59, 130, 246, 0.25);
}

/* Dashboard Cards */
.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}

.dashboard-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    overflow: hidden;
}

.dashboard-card .card-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.dashboard-card .card-header h3 {
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.dashboard-card .card-header h3 .icon {
    font-size: 18px;
}

.dashboard-card .card-body {
    padding: 14px 18px;
    max-height: 300px;
    overflow-y: auto;
}

.dashboard-card .card-body::-webkit-scrollbar {
    width: 4px;
}

.dashboard-card .card-body::-webkit-scrollbar-track {
    background: var(--bg-input);
    border-radius: 2px;
}

.dashboard-card .card-body::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 2px;
}

.badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.badge.danger {
    background: rgba(239, 68, 68, 0.15);
    color: var(--accent-red);
}

.badge.warning {
    background: rgba(245, 158, 11, 0.15);
    color: var(--accent-orange);
}

.badge.success {
    background: rgba(16, 185, 129, 0.15);
    color: var(--accent-green);
}

.stolen-item, .grace-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.2s;
}

.stolen-item:hover, .grace-item:hover {
    background: var(--bg-card-hover);
    margin: 0 -18px;
    padding: 10px 18px;
    border-radius: var(--radius-sm);
}

.stolen-item:last-child, .grace-item:last-child {
    border-bottom: none;
}

.stolen-info, .grace-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.stolen-info strong, .grace-info strong {
    color: var(--text-primary);
    font-size: 13px;
}

.stolen-info span, .grace-info span {
    font-size: 12px;
    color: var(--text-muted);
}

.stolen-time, .grace-time {
    font-size: 11px;
    color: var(--accent-orange);
}

.grace-time.urgent {
    color: var(--accent-red);
    font-weight: 600;
}

.btn-sm {
    padding: 4px 14px;
    border-radius: var(--radius-sm);
    border: none;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}

.btn-sm.btn-danger {
    background: rgba(239, 68, 68, 0.15);
    color: var(--accent-red);
}

.btn-sm.btn-danger:hover {
    background: rgba(239, 68, 68, 0.25);
}

.btn-sm.btn-warning {
    background: rgba(245, 158, 11, 0.15);
    color: var(--accent-orange);
}

.btn-sm.btn-warning:hover {
    background: rgba(245, 158, 11, 0.25);
}

.text-muted {
    color: var(--text-muted);
    font-size: 13px;
}

.empty-state {
    text-align: center;
    padding: 20px;
    color: var(--text-muted);
}

.empty-state p {
    font-size: 13px;
}

/* Notification Styles */
.notifications-list {
    max-height: 400px;
    overflow-y: auto;
}

.notifications-list::-webkit-scrollbar {
    width: 4px;
}

.notifications-list::-webkit-scrollbar-track {
    background: var(--bg-input);
    border-radius: 2px;
}

.notifications-list::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 2px;
}

.notification-item {
    display: flex;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: background 0.2s;
}

.notification-item:hover {
    background: var(--bg-card-hover);
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item.stolen_declared {
    background: rgba(239, 68, 68, 0.05);
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    margin: 4px 0;
}

.notification-item.grace_period_alert {
    background: rgba(245, 158, 11, 0.05);
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    margin: 4px 0;
}

.notification-item.perimeter-alert {
    background: rgba(239, 68, 68, 0.08);
    border-left: 3px solid var(--accent-red);
    padding: 10px 12px;
    border-radius: var(--radius-sm);
    margin: 4px 0;
}

.notification-item.perimeter-alert:hover {
    background: rgba(239, 68, 68, 0.15);
}

.notification-icon {
    font-size: 20px;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-surface);
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 13px;
}

.notification-message {
    font-size: 12px;
    color: var(--text-secondary);
    margin-top: 2px;
}

.notification-time {
    font-size: 10px;
    color: var(--text-muted);
    margin-top: 3px;
}

.notification-action {
    display: flex;
    align-items: center;
    flex-shrink: 0;
    margin-left: 8px;
}

/* Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(8px);
    z-index: 2000;
    justify-content: center;
    align-items: center;
    padding: 20px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modal-overlay.active {
    display: flex;
    opacity: 1;
}

.modal {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    max-width: 600px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    transform: scale(0.95);
    transition: transform 0.3s ease;
    padding: 0;
}

.modal-overlay.active .modal {
    transform: scale(1);
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    background: var(--bg-secondary);
    z-index: 10;
    border-radius: var(--radius) var(--radius) 0 0;
}

.modal-header h2 {
    font-size: 18px;
    font-weight: 700;
}

.modal-header .modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 4px;
    font-size: 24px;
    transition: color 0.2s;
    line-height: 1;
}

.modal-header .modal-close:hover {
    color: var(--text-primary);
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    bottom: 0;
    background: var(--bg-secondary);
    border-radius: 0 0 var(--radius) var(--radius);
}

.modal-footer .nav-buttons {
    display: flex;
    gap: 10px;
}

.modal-footer .nav-btn {
    padding: 8px 20px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-primary);
    cursor: pointer;
    font-weight: 500;
    font-size: 13px;
    transition: all 0.2s;
}

.modal-footer .nav-btn:hover {
    background: var(--bg-card-hover);
    border-color: var(--accent-blue);
}

.modal-footer .nav-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.modal-footer .counter {
    font-size: 13px;
    color: var(--text-muted);
}

.modal-rider-detail {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.modal-rider-detail .detail-row {
    display: flex;
    gap: 12px;
    align-items: center;
    padding: 10px 14px;
    background: var(--bg-surface);
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-color);
}

.modal-rider-detail .detail-row .detail-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
    min-width: 80px;
}

.modal-rider-detail .detail-row .detail-value {
    font-size: 14px;
    color: var(--text-primary);
    font-weight: 500;
}

.modal-rider-detail .detail-row .detail-value.ebike {
    color: var(--accent-blue);
}

.modal-rider-detail .detail-row .detail-value.grace {
    color: var(--accent-orange);
}

.modal-rider-detail .detail-row .detail-value.stolen {
    color: var(--accent-red);
}

.modal-rider-detail .face-container {
    display: flex;
    justify-content: center;
    padding: 12px 0;
}

.modal-rider-detail .face-container img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--border-color);
}

.modal-rider-detail .face-container .no-face {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 3px dashed var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 12px;
    background: var(--bg-surface);
}

.modal-actions-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 16px;
}

.modal-action-btn {
    padding: 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-primary);
    cursor: pointer;
    font-weight: 500;
    font-size: 13px;
    transition: all 0.2s;
    text-decoration: none;
    text-align: center;
}

.modal-action-btn:hover {
    background: var(--bg-card-hover);
    border-color: var(--accent-blue);
}

.modal-action-btn.primary {
    background: rgba(59, 130, 246, 0.15);
    border-color: var(--accent-blue);
    color: var(--accent-blue);
}

.modal-action-btn.primary:hover {
    background: rgba(59, 130, 246, 0.25);
}

.modal-action-btn.danger {
    background: rgba(239, 68, 68, 0.15);
    border-color: var(--accent-red);
    color: var(--accent-red);
}

.modal-action-btn.danger:hover {
    background: rgba(239, 68, 68, 0.25);
}

.modal-action-btn.warning {
    background: rgba(245, 158, 11, 0.15);
    border-color: var(--accent-orange);
    color: var(--accent-orange);
}

.modal-action-btn.warning:hover {
    background: rgba(245, 158, 11, 0.25);
}

/* Actions Grid */
.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.action-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 20px;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.2s;
    color: var(--text-primary);
}

.action-card:hover {
    background: var(--bg-card-hover);
    border-color: var(--accent-blue);
    transform: translateY(-2px);
}

.action-card:active {
    transform: scale(0.98);
}

.action-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: rgba(59, 130, 246, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--accent-blue);
}

.action-icon svg {
    width: 22px;
    height: 22px;
}

.action-text .title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.action-text .desc {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 2px;
}

/* Responsive */
@media (max-width: 1024px) {
    .content {
        padding: 20px;
    }
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
    .rider-cards-grid {
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    }
}

@media (max-width: 768px) {
    :root {
        --sidebar-width: 280px;
        --header-height: 56px;
    }

    .sidebar-toggle {
        display: block;
    }

    .sidebar-close {
        display: block;
    }

    .sidebar {
        transform: translateX(-100%);
        box-shadow: 4px 0 24px rgba(0, 0, 0, 0.5);
    }

    .sidebar.open {
        transform: translateX(0);
    }

    .sidebar-overlay.open {
        display: block;
    }

    .main {
        margin-left: 0;
    }

    .header {
        padding: 0 12px 0 52px;
        height: var(--header-height);
    }

    .header-left h1 {
        font-size: 15px;
    }

    .header-left .subtitle {
        display: none;
    }

    .status-badge {
        font-size: 10px;
        padding: 4px 10px;
        gap: 5px;
    }

    .status-dot {
        width: 6px;
        height: 6px;
    }

    .content {
        padding: 16px 12px;
    }

    .section-header h2 {
        font-size: 11px;
    }

    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 24px;
    }

    .stat-card {
        padding: 16px 18px;
    }

    .stat-icon {
        width: 32px;
        height: 32px;
        margin-bottom: 10px;
    }

    .stat-icon svg {
        width: 16px;
        height: 16px;
    }

    .stat-value {
        font-size: 28px;
    }

    .stat-label {
        font-size: 12px;
    }

    .stat-sublabel {
        font-size: 10px;
    }

    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .rider-cards-grid {
        grid-template-columns: 1fr;
    }

    .actions-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .action-card {
        padding: 16px;
        flex-direction: column;
        text-align: center;
        gap: 10px;
    }

    .action-icon {
        width: 40px;
        height: 40px;
    }

    .action-icon svg {
        width: 18px;
        height: 18px;
    }

    .action-text .title {
        font-size: 13px;
    }

    .action-text .desc {
        font-size: 11px;
    }

    .rider-card-admin .card-timer .time {
        font-size: 28px;
    }
    
    .modal {
        max-width: 100%;
        margin: 10px;
        max-height: 95vh;
    }
    
    .modal-footer {
        flex-direction: column;
        gap: 12px;
    }
    
    .modal-footer .nav-buttons {
        width: 100%;
    }
    
    .modal-footer .nav-btn {
        flex: 1;
        text-align: center;
    }
}

@media (max-width: 420px) {
    .header-left h1 {
        font-size: 13px;
    }

    .status-badge {
        font-size: 9px;
        padding: 3px 8px;
    }

    .content {
        padding: 12px 8px;
    }

    .stats-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .stat-card {
        padding: 12px 14px;
    }

    .stat-value {
        font-size: 22px;
    }

    .stat-label {
        font-size: 11px;
    }

    .stat-sublabel {
        font-size: 9px;
    }

    .stat-icon {
        width: 28px;
        height: 28px;
        margin-bottom: 8px;
    }

    .stat-icon svg {
        width: 14px;
        height: 14px;
    }

    .actions-grid {
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .action-card {
        padding: 12px;
    }

    .action-text .title {
        font-size: 12px;
    }

    .action-text .desc {
        font-size: 10px;
    }

    .action-icon {
        width: 34px;
        height: 34px;
    }

    .action-icon svg {
        width: 16px;
        height: 16px;
    }

    .sidebar {
        width: 260px;
    }

    .rider-card-admin .card-timer .time {
        font-size: 22px;
    }
    
    .modal-actions-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 360px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .actions-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<!-- SIDEBAR TOGGLE -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
</button>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <button class="sidebar-close" id="sidebarClose" aria-label="Close navigation">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>

    <div class="sidebar-brand">
        <div class="logo">Moto<span>Admin</span></div>
        <div class="sub">Fleet Management</div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard.php" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            Dashboard
            <?php if($grace_count > 0 || $stolen_count > 0 || $map_alert_count > 0): ?>
            <span class="badge danger">!</span>
            <?php endif; ?>
        </a>

        <div class="nav-label">Management</div>
        <a href="riders.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Manage Riders
        </a>
        <a href="map.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                <line x1="9" y1="3" x2="9" y2="18"/>
                <line x1="15" y1="6" x2="15" y2="21"/>
            </svg>
            Live Map
            <?php if($map_alert_count > 0): ?>
            <span class="badge danger"><?php echo $map_alert_count; ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
            <div class="info">
                <div class="name"><?php echo htmlspecialchars($admin_name); ?></div>
                <div class="role">Administrator</div>
            </div>
        </div>
        <a href="../auth/logout.php" class="logout-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Sign Out
        </a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <header class="header">
        <div class="header-left">
            <h1>Dashboard</h1>
            <div class="subtitle"><?php echo $current_date; ?></div>
        </div>
        <div class="header-right">
            <div class="status-badge">
                <span class="status-dot"></span>
                Online
            </div>
        </div>
    </header>

    <div class="content">
        <!-- Stats -->
        <div class="section-header">
            <h2>Key Metrics</h2>
        </div>

        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <div class="stat-value"><?php echo $riders; ?></div>
                <div class="stat-label">Total Riders</div>
                <div class="stat-sublabel">Registered fleet</div>
            </div>

            <div class="stat-card green">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <div class="stat-value"><?php echo $active_count; ?></div>
                <div class="stat-label">Active Rentals</div>
                <div class="stat-sublabel">Currently riding</div>
            </div>

            <div class="stat-card amber">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="stat-value"><?php echo $grace_count; ?></div>
                <div class="stat-label">Expired Rentals</div>
                <div class="stat-sublabel">In grace period</div>
            </div>

            <div class="stat-card red">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                </div>
                <div class="stat-value"><?php echo $stolen_count; ?></div>
                <div class="stat-label">Stolen Vehicles</div>
                <div class="stat-sublabel">Declared stolen</div>
            </div>
        </div>

        <!-- Active Rentals -->
        <div class="section-header">
            <h2>Current Active Rentals - Real-time Monitoring (24 Hours)</h2>
            <?php if ($active_count > 0): ?>
            <span class="badge-count info"><?php echo $active_count; ?> active</span>
            <?php endif; ?>
        </div>

        <?php if ($active_count > 0): ?>
        <div class="rider-cards-grid">
            <?php foreach ($active_rentals_list as $index => $rental): ?>
            <?php 
                $remaining = $rental['timer']['time_remaining_seconds'];
                $is_grace = $rental['timer']['is_grace_period'];
                $is_expired = $rental['timer']['is_expired'];
                $is_stolen = $rental['timer']['is_stolen'] ?? false;
                
                $card_class = '';
                $status_class = 'active';
                $status_text = 'Active';
                $progress_class = 'green';
                $timer_class = '';
                $timer_wrap_class = '';
                $total_seconds = $rental['timer']['total_duration_seconds'] ?? (24 * 60 * 60);
                $progress = max(0, min(100, ($remaining / $total_seconds) * 100));
                
                if ($is_stolen) {
                    $card_class = 'stolen';
                    $status_class = 'stolen';
                    $status_text = '🚨 STOLEN';
                    $progress_class = 'red';
                    $timer_class = 'stolen';
                    $timer_wrap_class = 'urgent-timer';
                } elseif ($is_grace) {
                    $card_class = 'grace';
                    $status_class = 'grace';
                    $status_text = 'Grace Period';
                    $progress_class = 'orange';
                    $timer_class = 'grace';
                    $timer_wrap_class = 'grace-timer';
                } elseif ($is_expired) {
                    $card_class = 'urgent';
                    $status_class = 'urgent';
                    $status_text = 'EXPIRED';
                    $progress_class = 'red';
                    $timer_class = 'urgent';
                    $timer_wrap_class = 'urgent-timer';
                } elseif ($remaining < 3600) {
                    $card_class = 'urgent';
                    $status_class = 'urgent';
                    $status_text = 'Urgent';
                    $progress_class = 'red';
                    $timer_class = 'urgent';
                    $timer_wrap_class = 'urgent-timer';
                } elseif ($remaining < 7200) {
                    $status_text = 'Soon';
                    $progress_class = 'orange';
                }
            ?>
            <div class="rider-card-admin <?php echo $card_class; ?>" 
                 data-index="<?php echo $index; ?>"
                 data-start="<?php echo $rental['start_time']; ?>">
                <div class="card-top">
                    <?php if (!empty($rental['face_preview'])): ?>
                        <img class="card-face" src="<?php echo htmlspecialchars($rental['face_preview']); ?>" alt="Face">
                    <?php else: ?>
                        <div class="card-face-placeholder">No Face</div>
                    <?php endif; ?>
                    <div>
                        <div class="card-name"><?php echo htmlspecialchars($rental['fullname']); ?></div>
                        <div class="card-ebike">
                            E-Bike: <strong><?php echo htmlspecialchars($rental['ebike_id']); ?></strong>
                        </div>
                    </div>
                </div>
                
                <div class="card-timer <?php echo $timer_wrap_class; ?>">
                    <div class="time <?php echo $timer_class; ?>" data-end-ts="<?php echo (int)$rental['timer']['end_timestamp']; ?>">
                        <?php echo $rental['timer']['time_remaining_formatted']; ?>
                    </div>
                    <div class="label">Time Remaining (24 Hours)</div>
                </div>
                
                <div class="card-progress-wrap">
                    <div class="card-progress-bar <?php echo $progress_class; ?>" 
                         style="width: <?php echo $progress; ?>%;">
                    </div>
                </div>
                <div class="card-progress-label">
                    <span><?php echo round($progress); ?>% used</span>
                    <span><?php echo $rental['timer']['time_remaining_formatted']; ?> left</span>
                </div>
                
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                    <span class="card-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    <span style="font-size:11px;color:var(--text-muted);">
                        Started: <?php echo date('M j, g:i A', strtotime($rental['start_time'])); ?>
                    </span>
                </div>
                
                <div class="card-bottom">
                    <div class="card-details">
                        <span>📧 <?php echo htmlspecialchars($rental['email']); ?></span>
                        <span>📱 <?php echo htmlspecialchars($rental['phone']); ?></span>
                    </div>
                    <div class="card-actions">
                        <button class="btn-sm btn-primary view-rider-btn" data-index="<?php echo $index; ?>">View</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:30px 20px;background:var(--bg-card);border-radius:var(--radius);border:1px solid var(--border-color);">
            <p>No active rentals at the moment</p>
        </div>
        <?php endif; ?>

        <!-- Notifications & Alerts -->
        <div class="section-header">
            <h2>Notifications & Alerts</h2>
            <?php 
            $total_alerts = $admin_notification_count + $map_alert_count + $stolen_count + $grace_count;
            if ($total_alerts > 0): 
            ?>
                <span class="badge-count danger"><?php echo $total_alerts; ?> alerts</span>
            <?php endif; ?>
        </div>

        <div class="dashboard-card" style="margin-bottom: 24px;">
            <div class="card-body notifications-list">
                <?php 
                $has_any_notification = false;
                
                // PERIMETER ALERTS MULA SA DATABASE
                if ($map_alert_count > 0): 
                    $has_any_notification = true;
                    foreach ($map_alerts as $alert): 
                ?>
                    <div class="notification-item perimeter-alert" onclick="viewMapAlert(<?php echo $alert['rider_id']; ?>)">
                        <div class="notification-icon">🗺️</div>
                        <div class="notification-content">
                            <div class="notification-title">⚠️ <?php echo htmlspecialchars($alert['rider_name']); ?> - Outside Perimeter</div>
                            <div class="notification-message">
                                E-Bike #<?php echo htmlspecialchars($alert['ebike_id']); ?> 
                                <?php echo $alert['is_online'] ? '(Online)' : '(Offline)'; ?>
                            </div>
                            <div class="notification-time">
                                📍 <?php echo $alert['latitude']; ?>, <?php echo $alert['longitude']; ?>
                                <br>🕐 <?php echo date('M j, g:i A', strtotime($alert['created_at'])); ?>
                            </div>
                        </div>
                        <div class="notification-action">
                            <button class="btn-sm btn-danger" onclick="event.stopPropagation(); viewMapAlert(<?php echo $alert['rider_id']; ?>)">Map</button>
                        </div>
                    </div>
                <?php 
                    endforeach;
                endif;
                
                // STOLEN VEHICLES
                if ($stolen_count > 0): 
                    $has_any_notification = true;
                    $stolen_vehicles->data_seek(0);
                    while ($stolen = $stolen_vehicles->fetch_assoc()): 
                ?>
                    <div class="notification-item stolen_declared" onclick="openStolenModal(<?php echo $stolen['rider_id']; ?>)">
                        <div class="notification-icon">🚨</div>
                        <div class="notification-content">
                            <div class="notification-title">Stolen: <?php echo htmlspecialchars($stolen['fullname']); ?></div>
                            <div class="notification-message">E-Bike #<?php echo htmlspecialchars($stolen['ebike_id']); ?> declared stolen</div>
                            <div class="notification-time">🕐 <?php echo date('M j, g:i A', strtotime($stolen['stolen_declared_at'])); ?></div>
                        </div>
                    </div>
                <?php 
                    endwhile;
                endif;
                
                // GRACE PERIOD
                if ($grace_count > 0): 
                    $has_any_notification = true;
                    foreach ($grace_period_rentals as $rental): 
                ?>
                    <div class="notification-item grace_period_alert" onclick="openGraceModal(<?php echo $rental['rider_id']; ?>)">
                        <div class="notification-icon">⏰</div>
                        <div class="notification-content">
                            <div class="notification-title">Expired: <?php echo htmlspecialchars($rental['fullname']); ?></div>
                            <div class="notification-message">E-Bike #<?php echo htmlspecialchars($rental['ebike_id']); ?> - <?php echo $rental['timer']['time_remaining_formatted']; ?> left</div>
                            <div class="notification-time">🕐 Started: <?php echo date('M j, g:i A', strtotime($rental['start_time'])); ?></div>
                        </div>
                    </div>
                <?php 
                    endforeach;
                endif;
                
                // REGULAR NOTIFICATIONS
                if ($admin_notifications && $admin_notifications->num_rows > 0): 
                    $has_any_notification = true;
                    $admin_notifications->data_seek(0);
                    while ($notif = $admin_notifications->fetch_assoc()): 
                ?>
                    <div class="notification-item" onclick="openNotificationModal(<?php echo $notif['id']; ?>)">
                        <div class="notification-icon">ℹ️</div>
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                            <div class="notification-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                            <div class="notification-time"><?php echo date('M j, g:i A', strtotime($notif['created_at'])); ?></div>
                        </div>
                    </div>
                <?php 
                    endwhile;
                endif;
                
                if (!$has_any_notification): 
                ?>
                    <div class="empty-state">
                        <p>No new notifications</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-header">
            <h2>Quick Actions</h2>
        </div>

        <div class="actions-grid">
            <a href="riders.php" class="action-card">
                <div class="action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <div class="action-text">
                    <div class="title">Manage Riders</div>
                    <div class="desc">Approve, edit, or remove riders</div>
                </div>
            </a>

            <a href="map.php" class="action-card">
                <div class="action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                        <line x1="9" y1="3" x2="9" y2="18"/>
                        <line x1="15" y1="6" x2="15" y2="21"/>
                    </svg>
                </div>
                <div class="action-text">
                    <div class="title">Live Map</div>
                    <div class="desc">Track riders in real time</div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- MODALS -->
<div class="modal-overlay" id="riderModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="riderModalTitle">Rider Details</h2>
            <button class="modal-close" onclick="closeModal('riderModal')">✕</button>
        </div>
        <div class="modal-body" id="riderModalBody">
            <div class="modal-rider-detail" id="riderDetailContent"></div>
        </div>
        <div class="modal-footer">
            <div class="nav-buttons">
                <button class="nav-btn" id="riderPrevBtn" onclick="navigateRider(-1)">‹ Previous</button>
                <button class="nav-btn" id="riderNextBtn" onclick="navigateRider(1)">Next ›</button>
            </div>
            <div class="counter" id="riderCounter">1 of 1</div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="stolenModal">
    <div class="modal">
        <div class="modal-header">
            <h2>🚨 Stolen Vehicles</h2>
            <button class="modal-close" onclick="closeModal('stolenModal')">✕</button>
        </div>
        <div class="modal-body" id="stolenModalBody"></div>
        <div class="modal-footer">
            <div class="nav-buttons">
                <button class="nav-btn" id="stolenPrevBtn" onclick="navigateStolen(-1)">‹ Previous</button>
                <button class="nav-btn" id="stolenNextBtn" onclick="navigateStolen(1)">Next ›</button>
            </div>
            <div class="counter" id="stolenCounter">1 of 1</div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="graceModal">
    <div class="modal">
        <div class="modal-header">
            <h2>⏰ Expired Rentals (Grace Period)</h2>
            <button class="modal-close" onclick="closeModal('graceModal')">✕</button>
        </div>
        <div class="modal-body" id="graceModalBody"></div>
        <div class="modal-footer">
            <div class="nav-buttons">
                <button class="nav-btn" id="gracePrevBtn" onclick="navigateGrace(-1)">‹ Previous</button>
                <button class="nav-btn" id="graceNextBtn" onclick="navigateGrace(1)">Next ›</button>
            </div>
            <div class="counter" id="graceCounter">1 of 1</div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="notificationModal">
    <div class="modal">
        <div class="modal-header">
            <h2>🔔 Notification Details</h2>
            <button class="modal-close" onclick="closeModal('notificationModal')">✕</button>
        </div>
        <div class="modal-body" id="notificationModalBody"></div>
        <div class="modal-footer">
            <div class="nav-buttons">
                <button class="nav-btn" id="notifPrevBtn" onclick="navigateNotification(-1)">‹ Previous</button>
                <button class="nav-btn" id="notifNextBtn" onclick="navigateNotification(1)">Next ›</button>
            </div>
            <div class="counter" id="notifCounter">1 of 1</div>
        </div>
    </div>
</div>

<script>
(function() {
    // SIDEBAR TOGGLE
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggleBtn = document.getElementById('sidebarToggle');
    var closeBtn = document.getElementById('sidebarClose');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    document.querySelectorAll('.nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
            closeSidebar();
        }
    });

    // MODAL FUNCTIONS
    window.closeModal = function(modalId) {
        document.getElementById(modalId).classList.remove('active');
        document.body.style.overflow = '';
    };

    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });

    // MAP ALERT FUNCTION
    window.viewMapAlert = function(riderId) {
        window.location.href = 'map.php?focus_rider=' + riderId + '&alert=perimeter';
    };

    // DATA
    var ridersData = <?php echo json_encode($active_rentals_list); ?>;
    var currentRiderIndex = 0;
    var currentStolenIndex = 0;
    var currentGraceIndex = 0;
    var currentNotifIndex = 0;

    var stolenData = [];
    <?php 
    $stolen_vehicles->data_seek(0);
    while ($stolen = $stolen_vehicles->fetch_assoc()) {
        echo "stolenData.push(" . json_encode($stolen) . ");";
    }
    ?>

    var graceData = <?php echo json_encode($grace_period_rentals); ?>;

    var notifData = [];
    <?php 
    if ($admin_notifications) {
        $admin_notifications->data_seek(0);
        while ($notif = $admin_notifications->fetch_assoc()) {
            echo "notifData.push(" . json_encode($notif) . ");";
        }
    }
    ?>

    // RIDER MODAL
    function renderRiderDetail(index) {
        var data = ridersData[index];
        if (!data) return;
        
        var content = document.getElementById('riderDetailContent');
        var isGrace = data.timer && data.timer.is_grace_period;
        var isStolen = data.timer && data.timer.is_stolen;
        var isExpired = data.timer && data.timer.is_expired;
        
        var statusColor = 'info';
        var statusText = 'Active';
        if (isStolen) { statusColor = 'stolen'; statusText = '🚨 STOLEN'; }
        else if (isGrace) { statusColor = 'grace'; statusText = 'Grace Period'; }
        else if (isExpired) { statusColor = 'stolen'; statusText = 'EXPIRED'; }
        
        var faceHtml = data.face_preview 
            ? '<img src="' + data.face_preview + '" alt="Face">'
            : '<div class="no-face">No Face Data</div>';
        
        content.innerHTML = `
            <div class="face-container">${faceHtml}</div>
            <div class="detail-row"><span class="detail-label">👤 Name</span><span class="detail-value">${escapeHtml(data.fullname)}</span></div>
            <div class="detail-row"><span class="detail-label">🔑 E-Bike</span><span class="detail-value ebike">#${escapeHtml(data.ebike_id)}</span></div>
            <div class="detail-row"><span class="detail-label">📧 Email</span><span class="detail-value">${escapeHtml(data.email)}</span></div>
            <div class="detail-row"><span class="detail-label">📱 Phone</span><span class="detail-value">${escapeHtml(data.phone)}</span></div>
            <div class="detail-row"><span class="detail-label">⏱️ Status</span><span class="detail-value ${statusColor}">${statusText}</span></div>
            <div class="detail-row"><span class="detail-label">⏰ Started</span><span class="detail-value">${formatDate(data.start_time)}</span></div>
            <div class="detail-row"><span class="detail-label">⏳ Ends (24h)</span><span class="detail-value">${formatDate(data.end_time)}</span></div>
            <div class="detail-row"><span class="detail-label">⏱️ Remaining</span><span class="detail-value ${isGrace || isExpired ? 'grace' : ''}">${data.timer ? data.timer.time_remaining_formatted : 'N/A'}</span></div>
            <div class="modal-actions-grid">
                <a href="riders.php?view=active&id=${data.rider_id}" class="modal-action-btn primary">View in Riders</a>
                ${isGrace ? `<a href="riders.php?view=expired&id=${data.rider_id}" class="modal-action-btn warning">⚠️ Alert Rider</a>` : ''}
                ${isStolen ? `<a href="riders.php?view=stolen&id=${data.rider_id}" class="modal-action-btn danger">🚨 View Stolen</a>` : ''}
            </div>
        `;
        
        document.getElementById('riderModalTitle').textContent = '👤 ' + data.fullname;
        document.getElementById('riderCounter').textContent = (index + 1) + ' of ' + ridersData.length;
        document.getElementById('riderPrevBtn').disabled = (index === 0);
        document.getElementById('riderNextBtn').disabled = (index === ridersData.length - 1);
        
        currentRiderIndex = index;
    }

    window.openRiderModal = function(index) {
        if (ridersData.length === 0) return;
        if (index === undefined) index = 0;
        if (index < 0) index = 0;
        if (index >= ridersData.length) index = ridersData.length - 1;
        renderRiderDetail(index);
        openModal('riderModal');
    };

    window.navigateRider = function(direction) {
        var newIndex = currentRiderIndex + direction;
        if (newIndex >= 0 && newIndex < ridersData.length) {
            renderRiderDetail(newIndex);
        }
    };

    document.querySelectorAll('.view-rider-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var index = parseInt(this.dataset.index);
            openRiderModal(index);
        });
    });

    document.querySelectorAll('.rider-card-admin').forEach(function(card) {
        card.addEventListener('click', function(e) {
            if (e.target.closest('.card-actions') || e.target.closest('.btn-sm')) return;
            var index = parseInt(this.dataset.index);
            openRiderModal(index);
        });
    });

    // STOLEN MODAL
    function renderStolenDetail(index) {
        var data = stolenData[index];
        if (!data) return;
        
        var body = document.getElementById('stolenModalBody');
        body.innerHTML = `
            <div style="padding:16px;background:var(--bg-surface);border-radius:var(--radius-sm);border:1px solid var(--border-color);margin-bottom:16px;">
                <div style="font-weight:600;font-size:16px;color:var(--text-primary);margin-bottom:8px;">👤 ${escapeHtml(data.fullname)}</div>
                <div style="font-size:13px;color:var(--text-secondary);line-height:1.6;">
                    🔑 E-Bike #${escapeHtml(data.ebike_id)}<br>
                    📧 ${escapeHtml(data.email)}<br>
                    📱 ${escapeHtml(data.phone)}<br>
                    🚨 Declared: ${formatDate(data.stolen_declared_at)}
                </div>
            </div>
            <div class="modal-actions-grid">
                <a href="riders.php?view=stolen&id=${data.rider_id}" class="modal-action-btn danger">🚨 View Details</a>
                <a href="riders.php" class="modal-action-btn primary">Manage Riders</a>
            </div>
        `;
        
        document.getElementById('stolenCounter').textContent = (index + 1) + ' of ' + stolenData.length;
        document.getElementById('stolenPrevBtn').disabled = (index === 0);
        document.getElementById('stolenNextBtn').disabled = (index === stolenData.length - 1);
        
        currentStolenIndex = index;
    }

    window.openStolenModal = function(id) {
        if (stolenData.length === 0) return;
        var index = stolenData.findIndex(function(s) { return s.rider_id == id; });
        if (index === -1) index = 0;
        renderStolenDetail(index);
        openModal('stolenModal');
    };

    window.navigateStolen = function(direction) {
        var newIndex = currentStolenIndex + direction;
        if (newIndex >= 0 && newIndex < stolenData.length) {
            renderStolenDetail(newIndex);
        }
    };

    // GRACE MODAL
    function renderGraceDetail(index) {
        var data = graceData[index];
        if (!data) return;
        
        var isUrgent = data.timer && data.timer.time_remaining_seconds < 300;
        var statusText = isUrgent ? '⚠️ URGENT' : '⏰ Grace Period';
        var statusClass = isUrgent ? 'danger' : 'warning';
        
        var body = document.getElementById('graceModalBody');
        body.innerHTML = `
            <div style="padding:16px;background:var(--bg-surface);border-radius:var(--radius-sm);border:1px solid var(--border-color);margin-bottom:16px;">
                <div style="font-weight:600;font-size:16px;color:var(--text-primary);margin-bottom:8px;">👤 ${escapeHtml(data.fullname)}</div>
                <div style="font-size:13px;color:var(--text-secondary);line-height:1.6;">
                    🔑 E-Bike #${escapeHtml(data.ebike_id)}<br>
                    📧 ${escapeHtml(data.email)}<br>
                    📱 ${escapeHtml(data.phone)}<br>
                    ⏳ Remaining: ${data.timer ? data.timer.time_remaining_formatted : 'N/A'}<br>
                    ⏰ Started: ${formatDate(data.start_time)}
                </div>
            </div>
            <div class="modal-actions-grid">
                <a href="riders.php?view=expired&id=${data.rider_id}" class="modal-action-btn warning">⚠️ Alert Rider</a>
                <a href="riders.php" class="modal-action-btn primary">Manage Riders</a>
            </div>
        `;
        
        document.getElementById('graceCounter').textContent = (index + 1) + ' of ' + graceData.length;
        document.getElementById('gracePrevBtn').disabled = (index === 0);
        document.getElementById('graceNextBtn').disabled = (index === graceData.length - 1);
        
        currentGraceIndex = index;
    }

    window.openGraceModal = function(id) {
        if (graceData.length === 0) return;
        var index = graceData.findIndex(function(g) { return g.rider_id == id; });
        if (index === -1) index = 0;
        renderGraceDetail(index);
        openModal('graceModal');
    };

    window.navigateGrace = function(direction) {
        var newIndex = currentGraceIndex + direction;
        if (newIndex >= 0 && newIndex < graceData.length) {
            renderGraceDetail(newIndex);
        }
    };

    // NOTIFICATION MODAL
    function renderNotificationDetail(index) {
        var data = notifData[index];
        if (!data) return;
        
        var body = document.getElementById('notificationModalBody');
        body.innerHTML = `
            <div style="padding:16px;background:var(--bg-surface);border-radius:var(--radius-sm);border:1px solid var(--border-color);margin-bottom:16px;">
                <div style="font-weight:600;font-size:16px;color:var(--text-primary);margin-bottom:8px;">${escapeHtml(data.title)}</div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">${formatDate(data.created_at)}</div>
                <div style="font-size:14px;color:var(--text-secondary);line-height:1.6;">${escapeHtml(data.message)}</div>
            </div>
            <div class="modal-actions-grid">
                <a href="riders.php" class="modal-action-btn primary">Go to Riders</a>
                <button class="modal-action-btn" onclick="closeModal('notificationModal')">Close</button>
            </div>
        `;
        
        document.getElementById('notifCounter').textContent = (index + 1) + ' of ' + notifData.length;
        document.getElementById('notifPrevBtn').disabled = (index === 0);
        document.getElementById('notifNextBtn').disabled = (index === notifData.length - 1);
        
        currentNotifIndex = index;
    }

    window.openNotificationModal = function(id) {
        if (notifData.length === 0) return;
        var index = notifData.findIndex(function(n) { return n.id == id; });
        if (index === -1) index = 0;
        renderNotificationDetail(index);
        openModal('notificationModal');
    };

    window.navigateNotification = function(direction) {
        var newIndex = currentNotifIndex + direction;
        if (newIndex >= 0 && newIndex < notifData.length) {
            renderNotificationDetail(newIndex);
        }
    };

    // UTILITY FUNCTIONS
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        var d = new Date(dateStr);
        return d.toLocaleString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    // REAL-TIME TIMER UPDATES
    <?php if ($active_count > 0): ?>
    function formatTime(seconds) {
        if (seconds < 0) seconds = 0;
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        return String(hours).padStart(2, '0') + ':' + 
               String(minutes).padStart(2, '0') + ':' + 
               String(secs).padStart(2, '0');
    }

    function updateAllTimers() {
        var timers = document.querySelectorAll('.card-timer .time[data-end-ts]');
        var now = Math.floor(Date.now() / 1000);
        
        timers.forEach(function(el) {
            var end = parseInt(el.dataset.endTs, 10);
            var remaining = end - now;
            if (remaining < 0) remaining = 0;
            el.textContent = formatTime(remaining);
        });

        var cards = document.querySelectorAll('.rider-card-admin');
        cards.forEach(function(card) {
            var timerEl = card.querySelector('.card-timer .time[data-end-ts]');
            var progressBar = card.querySelector('.card-progress-bar');
            var progressLabel = card.querySelector('.card-progress-label span:last-child');
            
            if (timerEl && progressBar) {
                var totalSeconds = 24 * 60 * 60;
                var end = parseInt(timerEl.dataset.endTs, 10);
                var remaining = end - now;
                if (remaining < 0) remaining = 0;
                var progress = Math.max(0, Math.min(100, (remaining / totalSeconds) * 100));
                progressBar.style.width = progress + '%';
                
                var usedPercent = Math.round(100 - progress);
                var label = card.querySelector('.card-progress-label span:first-child');
                if (label) {
                    label.textContent = usedPercent + '% used';
                }
                if (progressLabel) {
                    progressLabel.textContent = formatTime(remaining) + ' left';
                }
            }
        });
    }

    setInterval(updateAllTimers, 1000);
    <?php endif; ?>

})();
</script>

<!-- Global Alert Widget -->
<script src="../assets/js/global-alert-widget.js"></script>
</body>
</html>