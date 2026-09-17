<?php
session_start();

if(!isset($_SESSION['rider'])){
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/rider-online.php");

$id = (int)$_SESSION['rider'];
$rider_name = isset($_SESSION['rider_name']) ? $_SESSION['rider_name'] : 'Rider';

rider_set_online($conn, $id, 'login');

// Fetch rider details
$rider = $conn->prepare(
    "SELECT * FROM users WHERE id = ? AND role = 'rider'"
);
$rider->bind_param("i", $id);
$rider->execute();
$rider_data = $rider->get_result()->fetch_assoc();

// Latest SIM800L signal
$location = $conn->prepare(
    "SELECT latitude, longitude, battery, speed, vibration, created_at
     FROM gps_logs
     WHERE rider_id = ?
     ORDER BY id DESC
     LIMIT 1"
);

$location->bind_param("i", $id);
$location->execute();
$loc = $location->get_result()->fetch_assoc();

rider_online_ensure_columns($conn);

$online_row = $conn->query("
    SELECT is_online, last_online_at, online_source
    FROM users
    WHERE id = $id
    LIMIT 1
")->fetch_assoc();

$is_online = rider_is_currently_online($online_row ?: []);
$online_label = rider_online_status_label(array_merge($online_row ?: [], ['is_online' => $is_online ? 1 : 0]));

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>My Location — MotoRider</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"/>

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
    width: 100%;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
    background: var(--bg-base);
    color: var(--text-primary);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow: hidden;
    position: relative;
}

body {
    display: flex;
    flex-direction: column;
    height: 100vh;
    width: 100vw;
}

/* ── Mobile Bottom Navigation ────────────────── */

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
    z-index: 1000;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    background: rgba(17, 24, 39, 0.95);
    height: 70px;
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
    z-index: 1001;
}

.nav-item svg {
    width: 24px;
    height: 24px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.nav-item.active {
    color: var(--accent-green);
}

.nav-item.active::after {
    content: '';
    position: absolute;
    top: -1px;
    left: 50%;
    transform: translateX(-50%);
    width: 20px;
    height: 2px;
    background: var(--accent-green);
    border-radius: 0 0 2px 2px;
}

.nav-item.logout-item {
    color: var(--accent-red);
}

.nav-item.logout-item:active {
    color: #dc2626;
}

/* ── Top Bar ─────────────────────────────────── */

.topbar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 150;
    flex-shrink: 0;
    min-height: 60px;
    height: 60px;
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

.back-button {
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
}

.back-button:hover {
    background: var(--bg-card-hover);
    color: var(--text-primary);
}

.back-button svg {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.topbar-left .brand {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.5px;
    color: var(--text-primary);
}

.topbar-left .brand span {
    color: var(--accent-green);
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

.avatar-small {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-green), #059669);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    color: white;
    flex-shrink: 0;
}

/* ── Map wrapper ─────────────────────────────── */

.map-wrapper {
    position: fixed;
    top: 60px;
    left: 0;
    right: 0;
    bottom: 70px;
    background: var(--bg-base);
    overflow: hidden;
    z-index: 1;
}

#map {
    width: 100%;
    height: 100%;
    background: var(--bg-base) !important;
}

/* ── Floating panel ──────────────────────────── */

.map-panel {
    position: absolute;
    top: 16px;
    left: 16px;
    z-index: 100;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 8px 32px rgba(0,0,0,0.6);
    pointer-events: auto;
    max-height: calc(100% - 32px);
    overflow-y: auto;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    width: 280px;
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s;
}

.map-panel.collapsed .panel-body {
    display: none;
}

.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    cursor: pointer;
    user-select: none;
    border-radius: var(--radius) var(--radius) 0 0;
    transition: background 0.15s;
}

.panel-header:hover {
    background: var(--bg-card);
}

.panel-header .panel-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.9px;
    color: var(--text-muted);
}

.panel-toggle {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 4px;
    transition: transform 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.panel-toggle svg {
    width: 16px;
    height: 16px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.map-panel.collapsed .panel-toggle svg {
    transform: rotate(-90deg);
}

.panel-body {
    padding: 0 16px 16px;
}

.panel-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 14px 0;
}

/* Rider info row */
.rider-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.rider-dot {
    width: 10px; 
    height: 10px;
    border-radius: 50%;
    background: var(--accent-green);
    box-shadow: 0 0 0 3px rgba(16,185,129,0.2);
    flex-shrink: 0;
    animation: pulse-green 2s infinite;
    transition: background 0.3s;
}

.rider-dot.offline {
    background: var(--text-muted);
    box-shadow: none;
    animation: none;
}

@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 3px rgba(16,185,129,0.2); }
    50%       { box-shadow: 0 0 0 6px rgba(16,185,129,0.05); }
}

.rider-info-text .label {
    font-size: 11px;
    color: var(--text-muted);
}

.rider-info-text .coords {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.3px;
    margin-top: 1px;
    word-break: break-word;
}

.geofence-status {
    margin-top: 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-secondary);
}

.geofence-status.inside { color: var(--accent-green); }
.geofence-status.warning { color: var(--accent-amber); }
.geofence-status.outside { color: var(--accent-red); }
.geofence-status.none { color: var(--text-muted); }

/* Rider name chip */
.rider-name-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 9px 12px;
}

.rider-name-chip svg {
    width: 14px; 
    height: 14px;
    stroke: var(--accent-green);
    stroke-width: 2;
    fill: none;
    flex-shrink: 0;
}

.rider-name-chip .chip-label {
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1;
}

.rider-name-chip .chip-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    line-height: 1;
    margin-top: 2px;
}

/* Refresh countdown */
.refresh-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 12px;
}

.refresh-label {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
}

.refresh-bar-wrap {
    flex: 1;
    margin: 0 10px;
    height: 3px;
    background: var(--border);
    border-radius: 3px;
    overflow: hidden;
}

.refresh-bar {
    height: 100%;
    background: var(--accent-green);
    border-radius: 3px;
    width: 100%;
    transition: width 1s linear;
}

/* ── Leaflet dark overrides ──────────────────── */

.leaflet-container {
    background: var(--bg-base) !important;
}

.leaflet-tile-pane {
    filter: invert(92%) hue-rotate(180deg) brightness(82%) contrast(92%) saturate(70%);
}

.leaflet-control-zoom {
    border: 1px solid var(--border) !important;
    border-radius: 8px !important;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.4) !important;
    z-index: 50 !important;
}

.leaflet-control-zoom a {
    background: var(--bg-surface) !important;
    color: var(--text-primary) !important;
    border-color: var(--border) !important;
    width: 32px !important;
    height: 32px !important;
    line-height: 32px !important;
    font-size: 16px !important;
    transition: background 0.15s;
}

.leaflet-control-zoom a:hover {
    background: var(--bg-card) !important;
}

.leaflet-control-zoom a:first-child {
    border-bottom: 1px solid var(--border) !important;
}

.leaflet-control-attribution {
    display: none !important;
}

.leaflet-popup-content-wrapper {
    background: var(--bg-surface) !important;
    color: var(--text-primary) !important;
    border: 1px solid var(--border);
    border-radius: 8px !important;
}

.leaflet-popup-tip {
    background: var(--bg-surface) !important;
    border: 1px solid var(--border);
}

/* ── Loading state ───────────────────────────── */

.loading-overlay {
    position: absolute;
    inset: 0;
    background: var(--bg-base);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 999;
    flex-direction: column;
    gap: 16px;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid var(--border);
    border-top-color: var(--accent-green);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.loading-text {
    font-size: 14px;
    color: var(--text-muted);
}

/* ── Mobile Drawer ───────────────────────────── */

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
    background: rgba(16,185,129,0.12);
    color: var(--accent-green);
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

/* ── Desktop Sidebar ─────────────────────────── */

.sidebar {
    display: none;
}

/* ── Responsive Breakpoints ──────────────────── */

/* Tablets and small laptops */
@media (min-width: 768px) {
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
        color: var(--accent-green);
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
        background: rgba(16,185,129,0.12);
        color: var(--accent-green);
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
    
    .topbar {
        left: 220px;
        right: 0;
    }
    
    .map-wrapper {
        top: 60px;
        left: 220px;
        right: 0;
        bottom: 0;
    }
    
    .menu-toggle {
        display: none !important;
    }
    
    .drawer-overlay, .drawer {
        display: none !important;
    }
    
    .map-panel {
        width: 280px;
    }
}

@media (min-width: 1024px) {
    .sidebar {
        width: 260px;
        padding: 24px 16px;
    }
    
    .topbar {
        left: 260px;
    }
    
    .map-wrapper {
        left: 260px;
    }
}

/* ── Mobile Styles ───────────────────────────── */

@media (max-width: 768px) {
    .menu-toggle {
        display: flex !important;
    }
    
    .topbar-left .brand small {
        display: none;
    }
    
    .back-button span {
        display: none;
    }
    
    .back-button {
        padding: 4px 10px;
    }
    
    .status-indicator span:not(.status-dot) {
        display: none;
    }
    
    .map-panel {
        width: calc(100% - 24px);
        top: 12px;
        left: 12px;
        max-height: calc(100% - 24px);
    }
    
    .bottom-nav {
        height: 65px;
    }
    
    .map-wrapper {
        bottom: 65px;
    }
}

@media (max-width: 380px) {
    .map-panel {
        top: 8px;
        left: 8px;
        width: calc(100% - 16px);
    }
    
    .panel-header {
        padding: 12px 14px;
    }
    
    .panel-body {
        padding: 0 14px 14px;
    }
    
    .rider-info-text .coords {
        font-size: 11px;
    }
    
    .rider-name-chip .chip-name {
        font-size: 12px;
    }
}

/* ── Touch optimizations ────────────────────── */
@media (hover: none) {
    .panel-header:hover {
        background: transparent;
    }
}

/* ── Scrollbar ───────────────────────────────── */

::-webkit-scrollbar {
    width: 4px;
}

::-webkit-scrollbar-track {
    background: var(--bg-base);
}

::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--text-muted);
}

</style>
</head>
<body data-rider-id="<?php echo $id; ?>">

<!-- ── Mobile Drawer ──────────────────────────── -->
<div class="drawer-overlay" id="drawerOverlay"></div>

<div class="drawer" id="drawer">
    <div class="drawer-header">
        <div class="sidebar-logo">
            <div class="brand">Moto<span>Rider</span></div>
            <div class="sub">Rider Portal</div>
        </div>
        <button class="drawer-close" id="drawerClose" aria-label="Close menu">✕</button>
    </div>
    
    <div class="drawer-user">
        <div class="avatar-small">
            <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
        </div>
        <div class="info">
            <div class="name"><?php echo htmlspecialchars($rider_name); ?></div>
            <div class="role">Rider</div>
        </div>
    </div>
    
    <nav class="drawer-nav">
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);padding:4px 12px 8px;">Overview</div>
        
        <a href="dashboard.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);padding:12px 12px 4px;margin-top:4px;">My Bike</div>
        
        <a href="map.php" class="nav-item-drawer active">
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
        
        <a href="dashboard.php" class="back-button">
            <svg viewBox="0 0 24 24">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
            <span>Back</span>
        </a>
        
        <div class="brand">
            Moto<span>Rider</span>
            <small>Map</small>
        </div>
    </div>
    <div class="topbar-right">
        <div class="status-indicator">
            <span class="status-dot"></span>
            <span>SIM800L</span>
        </div>
        <div class="avatar-small" id="avatarDesktop">
            <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
        </div>
    </div>
</header>

<!-- ── Desktop Sidebar ─────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-logo">
        <div class="brand">Moto<span>Rider</span></div>
        <div class="sub">Rider Portal</div>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">Overview</div>
        
        <a href="dashboard.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        
        <div class="nav-section" style="margin-top:12px;">My Bike</div>
        
        <a href="map.php" class="nav-item-drawer active">
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
            <div class="avatar-small">
                <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
            </div>
            <div class="info">
                <div class="name"><?php echo htmlspecialchars($rider_name); ?></div>
                <div class="role">Rider</div>
            </div>
        </div>
    </div>
</aside>

<!-- ── Map Container ──────────────────────────── -->
<div class="map-wrapper">

    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading map data...</div>
    </div>

    <!-- Floating info panel -->
    <div class="map-panel" id="mapPanel">
        <div class="panel-header" id="panelToggle">
            <span class="panel-title">Active Rider</span>
            <button class="panel-toggle" aria-label="Toggle panel">
                <svg viewBox="0 0 24 24">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </button>
        </div>
        
        <div class="panel-body">
            <div class="rider-name-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <div>
                    <div class="chip-label">Signed in as</div>
                    <div class="chip-name"><?php echo htmlspecialchars($rider_name); ?></div>
                </div>
            </div>

            <hr class="panel-divider">

            <div class="rider-info">
                <div class="rider-dot" id="status-dot"></div>
                <div class="rider-info-text">
                    <div class="label">ESP32 GPS status</div>
                    <div class="coords" id="coords-display">Fetching…</div>
                    <div class="geofence-status" id="geofence-status">Perimeter: loading…</div>
                </div>
            </div>

            <div class="refresh-row">
                <span class="refresh-label">Refresh in</span>
                <div class="refresh-bar-wrap">
                    <div class="refresh-bar" id="refresh-bar"></div>
                </div>
                <span class="refresh-label" id="countdown">5s</span>
            </div>
        </div>
    </div>

    <div id="map"></div>
</div>

<!-- ── Bottom Navigation ──────────────────────── -->
<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span>Home</span>
    </a>
    
    <a href="map.php" class="nav-item active">
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

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
<script src="../assets/js/geofence-map.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>

// ── Mobile drawer toggle ──────────────────────
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

// ── Panel toggle ──────────────────────────────
const panelToggle = document.getElementById('panelToggle');
const mapPanel = document.getElementById('mapPanel');
let panelCollapsed = false;

if (panelToggle) {
    panelToggle.addEventListener('click', function(e) {
        e.stopPropagation();
        panelCollapsed = !panelCollapsed;
        mapPanel.classList.toggle('collapsed', panelCollapsed);
        
        setTimeout(function() {
            map.invalidateSize();
        }, 350);
    });
}

// ── Map initialization ─────────────────────────

window.RIDER_ID = <?php echo $id; ?>;
var riderId = window.RIDER_ID;

var defaultCenter = GeofenceMap.BANTAYAN_CENTER;
var defaultZoom = GeofenceMap.BANTAYAN_ZOOM;

var map = L.map('map', { 
    zoomControl: true, 
    attributionControl: false,
    center: defaultCenter,
    zoom: defaultZoom,
    maxBounds: GeofenceMap.BANTAYAN_MAX_BOUNDS,
    maxBoundsViscosity: 0.85,
    fadeAnimation: true,
    zoomAnimation: true,
    markerZoomAnimation: true,
    inertia: true,
    inertiaDeceleration: 3000
});

setTimeout(function() {
    map.invalidateSize();
}, 200);

var mapLayer = L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    { 
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19,
        minZoom: 3
    }
).addTo(map);

var riderMarker = L.marker(defaultCenter, {
    icon: L.divIcon({
        className: 'rider-marker',
        html: '<div style="width:20px;height:20px;background:#10B981;border-radius:50%;border:3px solid #fff;box-shadow:0 0 20px rgba(16,185,129,0.6);"></div>',
        iconSize: [20, 20],
        iconAnchor: [10, 10]
    }),
    title: 'Your Location'
}).addTo(map);

var savedPerimeterPoints = [];
var geofenceLayers = {};
var geofenceAlertState = {};
var geofenceStatusEl = document.getElementById('geofence-status');

function loadPerimeter() {
    fetch('../api/get-perimeter.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            savedPerimeterPoints = Array.isArray(data.points) ? data.points : [];
            if (savedPerimeterPoints.length >= 3) {
                GeofenceMap.drawPerimeter(map, savedPerimeterPoints, geofenceLayers);
                if (geofenceStatusEl) {
                    geofenceStatusEl.textContent = 'Perimeter active (' + savedPerimeterPoints.length + ' points)';
                    geofenceStatusEl.className = 'geofence-status inside';
                }
                map.fitBounds(L.polygon(savedPerimeterPoints.map(function(p) {
                    return [p.lat, p.lng];
                })).getBounds(), { padding: [40, 40] });
            } else if (geofenceStatusEl) {
                geofenceStatusEl.textContent = 'No perimeter set by admin';
                geofenceStatusEl.className = 'geofence-status none';
                map.setView(GeofenceMap.BANTAYAN_CENTER, GeofenceMap.BANTAYAN_ZOOM);
            }
        })
        .catch(function() {
            if (geofenceStatusEl) {
                geofenceStatusEl.textContent = 'Perimeter unavailable';
                geofenceStatusEl.className = 'geofence-status none';
            }
        });
}

function updateGeofenceUI(data) {
    if (!geofenceStatusEl) return;

    var gf = data.geofence || {};
    if (!gf.active) {
        geofenceStatusEl.textContent = savedPerimeterPoints.length >= 3
            ? 'Perimeter active'
            : 'No perimeter set by admin';
        geofenceStatusEl.className = 'geofence-status ' + (savedPerimeterPoints.length >= 3 ? 'inside' : 'none');
        return;
    }

    var label = GeofenceMap.statusLabel(gf.status);
    if (gf.status === 'warning' && gf.distance_to_boundary_m) {
        label += ' (' + gf.distance_to_boundary_m + 'm to edge)';
    }
    geofenceStatusEl.textContent = 'Zone: ' + label;
    geofenceStatusEl.className = 'geofence-status ' + gf.status;

    if (data.has_location && data.lat && data.lng) {
        var status = gf.status;
        if (status === 'outside') {
            GeofenceMap.handleStatusChange('outside', 'self', geofenceAlertState, {
                title: 'Outside Allowed Zone',
                text: 'You have left the operational perimeter set by admin. Return to the allowed zone immediately.'
            });
            riderMarker.setIcon(L.divIcon({
                className: 'rider-marker',
                html: '<div style="width:20px;height:20px;background:#EF4444;border-radius:50%;border:3px solid #fff;box-shadow:0 0 20px rgba(239,68,68,0.6);"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            }));
        } else if (status === 'warning') {
            GeofenceMap.handleStatusChange('warning', 'self', geofenceAlertState, {
                title: 'Approaching Boundary',
                text: 'Warning: You are nearing the edge of the allowed zone. Turn back to stay inside the perimeter.'
            });
            riderMarker.setIcon(L.divIcon({
                className: 'rider-marker',
                html: '<div style="width:20px;height:20px;background:#F59E0B;border-radius:50%;border:3px solid #fff;box-shadow:0 0 20px rgba(245,158,11,0.6);"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            }));
        } else {
            geofenceAlertState.self = 'inside';
            riderMarker.setIcon(L.divIcon({
                className: 'rider-marker',
                html: '<div style="width:20px;height:20px;background:#10B981;border-radius:50%;border:3px solid #fff;box-shadow:0 0 20px rgba(16,185,129,0.6);"></div>',
                iconSize: [20, 20],
                iconAnchor: [10, 10]
            }));
        }
    }
}

loadPerimeter();
setInterval(loadPerimeter, 60000);

// ── Update functions ───────────────────────────

function updateStatusDisplay(data) {
    var display = document.getElementById('coords-display');
    var dot = document.getElementById('status-dot');
    var loadingOverlay = document.getElementById('loadingOverlay');
    
    if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
    }
    
    if (!display || !data) {
        if (display) display.textContent = 'Status unavailable';
        return;
    }

    var label = data.status_label || (data.is_online ? 'Online' : 'Offline');
    var text = label;
    if (data.battery) {
        text += ' · Battery ' + data.battery + '%';
    }
    if (data.has_location && data.lat && data.lng) {
        text += ' · 📍 ESP32 ' + data.lat.toFixed(6) + ', ' + data.lng.toFixed(6);
    } else {
        text += ' · 📡 Waiting for ESP32 GPS';
    }
    if (data.speed) {
        text += ' · Speed ' + data.speed + ' km/h';
    }
    if (data.last_signal) {
        text += ' · Last update ' + formatTimeAgo(data.last_signal);
    }
    display.textContent = text;

    if (dot) {
        if (data.is_online) {
            dot.className = 'rider-dot';
        } else {
            dot.className = 'rider-dot offline';
        }
    }
    
    if (data.has_location && data.lat && data.lng) {
        var lat = parseFloat(data.lat);
        var lng = parseFloat(data.lng);
        if (!isNaN(lat) && !isNaN(lng)) {
            var newPos = [lat, lng];
            riderMarker.setLatLng(newPos);
            
            var currentCenter = map.getCenter();
            var distance = map.distance(currentCenter, newPos);
            var islandBounds = L.latLngBounds(GeofenceMap.BANTAYAN_MAX_BOUNDS);
            if (distance > 500 && islandBounds.contains(newPos)) {
                map.panTo(newPos);
            }
        }
    } else {
        riderMarker.setLatLng(defaultCenter);
    }

    updateGeofenceUI(data);
}

// Helper function to format time ago
function formatTimeAgo(dateStr) {
    var date = new Date(dateStr);
    var now = new Date();
    var diffMs = now - date;
    var diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return diffMins + 'm ago';
    var diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return diffHours + 'h ago';
    return Math.floor(diffHours / 24) + 'd ago';
}

// Update the updateMap function to handle the new response
function updateMap() {
    fetch('../api/get-location.php?rider_id=' + riderId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            updateStatusDisplay(data);
        })
        .catch(function() {
            var display = document.getElementById('coords-display');
            if (display) display.textContent = 'Unavailable';
            var loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) loadingOverlay.style.display = 'none';
        });

    countdown = INTERVAL;
}

// ── Start updates ──────────────────────────────

updateMap();
setInterval(updateMap, 5000);
setInterval(tickCountdown, 1000);

// ── Fix map on resize ──────────────────────────

window.addEventListener('resize', function() {
    setTimeout(function() {
        map.invalidateSize();
    }, 100);
});

window.addEventListener('orientationchange', function() {
    setTimeout(function() {
        map.invalidateSize();
    }, 300);
});

window.addEventListener('beforeunload', function() {
    if (map) {
        map.remove();
    }
});

setTimeout(function() {
    var loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
    }
}, 5000);

console.log('Map initialized successfully');

</script>

<?php if(file_exists("../assets/js/rider-session-ping.js")): ?>
<script src="../assets/js/rider-session-ping.js"></script>
<?php endif; ?>
<!-- Rider Global Alert System -->
<script src="../assets/js/rider-global-alert.js"></script>
</body>
</html>