<?php
session_start();

if(!isset($_SESSION['admin'])){
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/zone-settings.php");

// Handle saving polyline perimeter points
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_perimeter')
{
    $perimeter_points = $_POST['perimeter_points'] ?? '';
    // Expecting JSON string like '[{"lat":40.712,"lng":-74.006},...]'
    if(!empty($perimeter_points)) {
        zone_set_setting($conn, 'perimeter_points', $perimeter_points);
        $_SESSION['map_flash'] = 'Operational perimeter saved with ' . substr_count($perimeter_points, 'lat') . ' points.';
    } else {
        zone_set_setting($conn, 'perimeter_points', '');
        $_SESSION['map_flash'] = 'Perimeter cleared. Define new points by clicking on map.';
    }
    header('Location: map.php');
    exit;
}

$flash = $_SESSION['map_flash'] ?? '';
unset($_SESSION['map_flash']);

$zone_settings = zone_load_settings($conn);
// Load perimeter points (JSON string)
$perimeter_points_json = $zone_settings['perimeter_points'] ?? '';
$perimeter_points = [];
if(!empty($perimeter_points_json)) {
    $perimeter_points = json_decode($perimeter_points_json, true);
    if(!is_array($perimeter_points)) $perimeter_points = [];
}

$riders = $conn->query("
    SELECT * FROM users
    WHERE role='rider' AND status='approved'
    ORDER BY fullname ASC
");

$rider_rows = [];
while($row = $riders->fetch_assoc()) {
    $rider_rows[] = $row;
}

$admin_name = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Administrator';

$pending = $conn->query(
    "SELECT COUNT(*) total FROM users WHERE status='pending'"
)->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Live Map — MotoAdmin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>

*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
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
    --text-primary:  #F1F5F9;
    --text-secondary:#94A3B8;
    --text-muted:    #475569;
    --radius:        10px;
    --safe-top: env(safe-area-inset-top, 0px);
    --safe-bottom: env(safe-area-inset-bottom, 0px);
}

html, body {
    height: 100%;
    font-family: 'Inter', system-ui, sans-serif;
    background: var(--bg-base);
    color: var(--text-primary);
    overflow: hidden;
}

body {
    display: flex;
    flex-direction: column;
    padding-top: var(--safe-top);
    padding-bottom: var(--safe-bottom);
}

/* ── Main (fullscreen map) ──────────────────── */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
    height: 100%;
}

/* ── Top bar (compact) ───────────────────────── */
.topbar {
    background: rgba(17, 24, 39, 0.92);
    backdrop-filter: blur(6px);
    border-bottom: 1px solid var(--border);
    padding: 0 12px;
    height: 48px;
    display: flex;
    align-items: center;
    gap: 10px;
    position: sticky;
    top: 0;
    z-index: 150;
    flex-shrink: 0;
}

.back-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--bg-card);
    color: var(--text-primary);
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
    flex-shrink: 0;
    touch-action: manipulation;
}

.back-btn:active {
    transform: scale(0.92);
    background: var(--bg-card-hover);
}

.back-btn svg {
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 2.2;
    fill: none;
}

.topbar-title {
    font-size: 15px;
    font-weight: 600;
    letter-spacing: -0.2px;
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.topbar-status {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    color: var(--text-secondary);
    background: var(--bg-card);
    padding: 3px 10px;
    border-radius: 16px;
    border: 1px solid var(--border);
    flex-shrink: 0;
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
    50%       { box-shadow: 0 0 0 4px rgba(16,185,129,0.05); }
}

/* ── Map wrapper ─────────────────────────────── */
.map-wrapper {
    flex: 1;
    position: relative;
    min-height: 0;
}

#map {
    width: 100%;
    height: 100%;
    position: relative;
    z-index: 0;
}

/* ── Map overlays ────────────────────────────── */
.map-overlays {
    position: absolute;
    inset: 0;
    z-index: 1000;
    pointer-events: none;
}
.map-overlays > * {
    pointer-events: auto;
}

/* ── Floating panel (compact, collapsible) ───── */
.map-panel {
    position: absolute;
    bottom: 12px;
    left: 12px;
    right: 12px;
    background: rgba(17, 24, 39, 0.92);
    backdrop-filter: blur(8px);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 10px 12px 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.6);
    max-height: 50vh;
    overflow-y: auto;
    transition: max-height 0.3s ease, padding 0.3s ease;
}

.map-panel.collapsed {
    max-height: 44px;
    padding: 6px 12px;
    overflow-y: hidden;
}

.map-panel.collapsed .panel-body {
    display: none;
}

.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}

.panel-header .panel-title {
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    margin-bottom: 0;
}

.panel-toggle-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-secondary);
    cursor: pointer;
    transition: background 0.15s;
    touch-action: manipulation;
    padding: 0;
}

.panel-toggle-btn:active {
    background: var(--bg-card-hover);
}

.panel-toggle-btn svg {
    width: 14px;
    height: 14px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
}

.panel-body {
    transition: opacity 0.2s ease;
}

/* Panel body content */
.panel-title {
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    margin-bottom: 6px;
}

/* Rider buttons (compact horizontal scroll) */
.rider-scroll {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding: 2px 0 6px 0;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
    -webkit-overflow-scrolling: touch;
}
.rider-scroll::-webkit-scrollbar {
    height: 2px;
}
.rider-scroll::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 9px;
}

.rider-btn {
    flex: 0 0 auto;
    padding: 4px 12px;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-primary);
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, transform 0.1s;
    white-space: nowrap;
    touch-action: manipulation;
    line-height: 1.3;
}

.rider-btn:active {
    transform: scale(0.95);
}

.rider-btn.active {
    background: rgba(59,130,246,0.18);
    border-color: var(--accent-blue);
    color: var(--text-primary);
}

.rider-btn.no-signal {
    opacity: 0.5;
    border-style: dashed;
}

.rider-btn .rider-status {
    display: block;
    font-size: 8px;
    font-weight: 400;
    color: var(--text-muted);
    margin-top: 0px;
}

.rider-btn.active .rider-status {
    color: var(--accent-blue);
}

/* Info row (compact) */
.rider-info {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 4px 0 6px;
    padding: 5px 8px;
    background: var(--bg-card);
    border-radius: 6px;
    border: 1px solid var(--border);
}

.rider-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent-green);
    box-shadow: 0 0 0 2px rgba(16,185,129,0.2);
    flex-shrink: 0;
    animation: pulse-green 2s infinite;
}

@keyframes pulse-green {
    0%, 100% { box-shadow: 0 0 0 2px rgba(16,185,129,0.2); }
    50%       { box-shadow: 0 0 0 5px rgba(16,185,129,0.05); }
}

.rider-info-text .label {
    font-size: 8px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.rider-info-text .coords {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.2px;
    line-height: 1.2;
}

/* Refresh row (compact) */
.refresh-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 4px 0 6px;
}
.refresh-label {
    font-size: 9px;
    color: var(--text-muted);
}
.refresh-bar-wrap {
    flex: 1;
    margin: 0 6px;
    height: 2px;
    background: var(--border);
    border-radius: 2px;
    overflow: hidden;
}
.refresh-bar {
    height: 100%;
    background: var(--accent-blue);
    border-radius: 2px;
    width: 100%;
    transition: width 1s linear;
}

/* Zone actions (compact) */
.zone-actions {
    display: flex;
    gap: 6px;
    margin-top: 2px;
}
.btn-zone {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 5px 10px;
    border-radius: 6px;
    border: 1px solid rgba(245, 158, 11, 0.4);
    background: rgba(245, 158, 11, 0.08);
    color: var(--accent-amber);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s;
    touch-action: manipulation;
}
.btn-zone:active {
    background: rgba(245, 158, 11, 0.18);
}
.btn-zone svg { width: 13px; height: 13px; }

.btn-zone-close {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-secondary);
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    touch-action: manipulation;
}
.btn-zone-close:active {
    background: var(--bg-card-hover);
}

/* Zone panel (expandable, compact) */
.zone-panel {
    display: none;
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid var(--border);
}
.zone-panel.open { display: block; }

.zone-panel label {
    display: block;
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    margin-bottom: 3px;
}
.zone-panel textarea {
    width: 100%;
    padding: 4px 6px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-primary);
    font-size: 9px;
    font-family: monospace;
    resize: vertical;
    min-height: 32px;
    line-height: 1.3;
}
.zone-panel-hint {
    font-size: 9px;
    color: var(--text-muted);
    margin: 3px 0 6px;
    line-height: 1.2;
}
.zone-panel-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.btn-zone-apply {
    flex: 1;
    padding: 5px 8px;
    border-radius: 6px;
    border: none;
    background: var(--accent-blue);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    touch-action: manipulation;
}
.btn-zone-apply:active {
    opacity: 0.8;
}
.btn-zone-clear {
    flex: 1;
    padding: 5px 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--accent-red);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    touch-action: manipulation;
}
.btn-zone-clear:active {
    background: var(--bg-card-hover);
}
.edit-mode-status {
    font-size: 8px;
    color: var(--text-muted);
    text-align: center;
    margin-top: 4px;
    letter-spacing: 0.3px;
}

/* Flash message - hidden, using SweetAlert only for save */
.map-flash {
    display: none;
}

/* Legend (compact, bottom-right) */
.zone-legend-float {
    position: absolute;
    bottom: 68px;
    right: 10px;
    background: rgba(17, 24, 39, 0.88);
    backdrop-filter: blur(4px);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 6px 10px;
    font-size: 9px;
    color: var(--text-secondary);
    box-shadow: 0 8px 24px rgba(0,0,0,0.35);
    max-width: 140px;
}
.zone-legend-float.collapsed .zone-legend-body {
    display: none;
}
.btn-legend-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    width: 100%;
    margin-bottom: 2px;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--text-primary);
    font-size: 9px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    text-align: left;
}
.btn-legend-toggle svg {
    width: 10px;
    height: 10px;
    color: var(--text-muted);
    transition: transform 0.15s;
}
.zone-legend-float.collapsed .btn-legend-toggle svg {
    transform: rotate(-90deg);
}
.zone-legend-item {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 3px;
}
.zone-legend-item:last-child { margin-bottom: 0; }
.zone-legend-line {
    width: 16px;
    height: 0;
    border-top: 2px solid var(--accent-amber);
}
.zone-legend-swatch {
    width: 16px;
    height: 6px;
    border-radius: 2px;
    background: rgba(239, 68, 68, 0.18);
    border: 1px solid rgba(239, 68, 68, 0.45);
}
.zone-legend-line-dash {
    width: 16px;
    height: 0;
    border-top: 2px dashed #3B82F6;
}

/* Leaflet dark overrides */
.leaflet-container {
    background: #0d1929;
}
.leaflet-control-zoom a {
    background: var(--bg-surface) !important;
    color: var(--text-primary) !important;
    border-color: var(--border) !important;
}
.leaflet-control-zoom a:hover {
    background: var(--bg-card) !important;
}
.leaflet-control-attribution {
    display: none !important;
}

/* Rider map markers */
.rider-map-icon-shell {
    background: transparent !important;
    border: none !important;
}
.rider-map-marker {
    position: relative;
    width: 32px;
    height: 32px;
}
.rider-map-dot {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.45);
    z-index: 2;
}
.rider-map-pulse {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 10px;
    height: 10px;
    margin: -5px 0 0 -5px;
    border-radius: 50%;
    animation: rider-marker-pulse 2s ease-out infinite;
    z-index: 1;
}
.rider-map-pulse--delay {
    animation-delay: 1s;
}
.rider-map-marker--live .rider-map-dot {
    background: var(--accent-green);
}
.rider-map-marker--live .rider-map-pulse {
    background: rgba(16, 185, 129, 0.55);
}
.rider-map-marker--stale .rider-map-dot {
    background: var(--accent-amber);
}
.rider-map-marker--stale .rider-map-pulse {
    background: rgba(245, 158, 11, 0.45);
    animation-duration: 3s;
}
@keyframes rider-marker-pulse {
    0% { transform: scale(1); opacity: 0.75; }
    70% { opacity: 0.2; }
    100% { transform: scale(3.2); opacity: 0; }
}

/* ── Draggable point markers ─────────────────── */
.perimeter-marker-draggable {
    background: transparent !important;
    border: none !important;
}
.perimeter-marker-draggable .marker-inner {
    width: 22px;
    height: 22px;
    background: #3B82F6;
    border: 2px solid #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: bold;
    color: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.5);
    cursor: grab;
    transition: transform 0.1s;
}
.perimeter-marker-draggable .marker-inner:active {
    cursor: grabbing;
    transform: scale(1.15);
}
.perimeter-marker-draggable .marker-inner.dragging {
    transform: scale(1.2);
    background: #60A5FA;
}

/* Fixed perimeter point markers (not draggable) */
.perimeter-point-fixed {
    background: transparent !important;
    border: none !important;
}
.perimeter-point-fixed .point-inner {
    width: 14px;
    height: 14px;
    background: #3B82F6;
    border: 2px solid #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 7px;
    font-weight: bold;
    color: #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.4);
}

/* Swipe hint - hidden since we removed swipe feature */
.swipe-hint {
    display: none;
}

/* Responsive fine-tune */
@media (max-width: 480px) {
    .map-panel {
        bottom: 8px;
        left: 8px;
        right: 8px;
        padding: 8px 10px 10px;
        max-height: 45vh;
    }
    .map-panel.collapsed {
        max-height: 40px;
        padding: 4px 10px;
    }
    .topbar {
        padding: 0 10px;
        height: 44px;
    }
    .topbar-title {
        font-size: 13px;
    }
    .rider-btn {
        font-size: 10px;
        padding: 3px 10px;
    }
    .rider-btn .rider-status {
        font-size: 7px;
    }
    .zone-legend-float {
        bottom: 62px;
        right: 6px;
        padding: 4px 8px;
        font-size: 8px;
        max-width: 120px;
    }
    .rider-info-text .coords {
        font-size: 10px;
    }
    .btn-zone {
        font-size: 10px;
        padding: 4px 8px;
    }
    .btn-zone svg {
        width: 11px;
        height: 11px;
    }
    .btn-zone-close {
        font-size: 10px;
        padding: 4px 10px;
    }
}

/* SweetAlert dark theme override */
.swal2-popup {
    background: var(--bg-surface) !important;
    color: var(--text-primary) !important;
    border: 1px solid var(--border) !important;
}
.swal2-title {
    color: var(--text-primary) !important;
}
.swal2-html-container {
    color: var(--text-secondary) !important;
}
.swal2-confirm {
    background: var(--accent-blue) !important;
}
.swal2-cancel {
    background: var(--bg-card) !important;
    color: var(--text-secondary) !important;
}
.swal2-close {
    color: var(--text-secondary) !important;
}

/* Toast notification for quick feedback */
.toast-notification {
    position: fixed;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(17, 24, 39, 0.95);
    backdrop-filter: blur(8px);
    color: var(--text-primary);
    padding: 8px 16px;
    border-radius: 8px;
    border: 1px solid var(--border);
    font-size: 12px;
    font-weight: 500;
    z-index: 9999;
    box-shadow: 0 4px 16px rgba(0,0,0,0.5);
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s ease;
    max-width: 80%;
    text-align: center;
}

.toast-notification.show {
    opacity: 1;
}

/* Perimeter line styling - ensure visibility */
.perimeter-line {
    filter: drop-shadow(0 0 6px rgba(245, 158, 11, 0.5));
}

/* Make sure perimeter is always on top */
.leaflet-overlay-pane {
    z-index: 400 !important;
}
.leaflet-marker-pane {
    z-index: 500 !important;
}
.leaflet-popup-pane {
    z-index: 600 !important;
}
</style>
</head>
<body>

<!-- ── Main ─────────────────────────────────── -->
<div class="main">

    <header class="topbar">
        <button class="back-btn" id="backBtn" aria-label="Go back to dashboard">
            <svg viewBox="0 0 24 24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </button>
        <span class="topbar-title">Live Map</span>
        <div class="topbar-status">
            <span class="status-dot"></span>
            <span>Live</span>
        </div>
    </header>

    <div class="map-wrapper" id="mapWrapper">

        <div id="map"></div>

        <div class="map-overlays">

            <?php if($flash): ?>
            <div class="map-flash" data-flash="<?php echo htmlspecialchars($flash); ?>"></div>
            <?php endif; ?>

            <div class="zone-legend-float" id="zone-legend-float">
                <button type="button" class="btn-legend-toggle" id="btn-toggle-legend" aria-expanded="true">
                    <span>Legend</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="zone-legend-body">
                    <div class="zone-legend-item">
                        <span class="zone-legend-line"></span>
                        <span>Perimeter</span>
                    </div>
                    <div class="zone-legend-item">
                        <span class="zone-legend-swatch"></span>
                        <span>Outside</span>
                    </div>
                    <div class="zone-legend-item">
                        <span class="zone-legend-line-dash"></span>
                        <span>Draft</span>
                    </div>
                    <div class="zone-legend-item" style="margin-top:3px;">
                        <span style="display:inline-block;width:14px;height:14px;background:#3B82F6;border:2px solid #fff;border-radius:50%;"></span>
                        <span>Saved Point</span>
                    </div>
                </div>
            </div>

            <!-- Floating panel (collapsible) -->
            <div class="map-panel" id="mapPanel">

                <div class="panel-header">
                    <div class="panel-title">Riders</div>
                    <button type="button" class="panel-toggle-btn" id="panelToggleBtn" aria-label="Toggle panel">
                        <svg viewBox="0 0 24 24" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="18 15 12 9 6 15"/>
                        </svg>
                    </button>
                </div>

                <div class="panel-body" id="panelBody">

                    <div class="rider-scroll" id="riderButtons">
                        <?php if(empty($rider_rows)): ?>
                        <button type="button" class="rider-btn disabled">No riders</button>
                        <?php else: ?>
                        <?php foreach($rider_rows as $row): ?>
                        <button type="button" class="rider-btn" data-rider-id="<?php echo (int)$row['id']; ?>">
                            <?php echo htmlspecialchars($row['fullname']); ?>
                            <span class="rider-status">—</span>
                        </button>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="rider-info">
                        <div class="rider-dot" id="selectedRiderDot"></div>
                        <div class="rider-info-text">
                            <div class="label">Connection</div>
                            <div class="coords" id="coordsDisplay">Fetching…</div>
                        </div>
                    </div>

                    <div class="refresh-row">
                        <span class="refresh-label">Refresh</span>
                        <div class="refresh-bar-wrap">
                            <div class="refresh-bar" id="refreshBar"></div>
                        </div>
                        <span class="refresh-label" id="countdownLabel">5s</span>
                    </div>

                    <div class="zone-actions">
                        <button type="button" class="btn-zone" id="btnToggleZonePanel" aria-expanded="false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                                <line x1="9" y1="3" x2="9" y2="18"/>
                                <line x1="15" y1="6" x2="15" y2="21"/>
                            </svg>
                            Perimeter
                        </button>
                        <button type="button" class="btn-zone-close" id="btnCloseZonePanel">Close</button>
                    </div>

                    <div class="zone-panel" id="zonePanel">
                        <form method="POST" id="perimeterForm">
                            <input type="hidden" name="action" value="save_perimeter">
                            <input type="hidden" name="perimeter_points" id="perimeterPointsInput" value="<?php echo htmlspecialchars($perimeter_points_json); ?>">

                            <label for="perimeterStatus">Points</label>
                            <textarea id="perimeterStatus" rows="2" readonly style="font-family: monospace; font-size: 9px;"><?php 
                                if(count($perimeter_points) > 0) {
                                    echo count($perimeter_points) . ' points saved' . "\n";
                                    foreach($perimeter_points as $i => $p) {
                                        echo ($i+1).': '.$p['lat'].', '.$p['lng']."\n";
                                    }
                                } else {
                                    echo 'No perimeter points saved — tap map to add';
                                }
                            ?></textarea>

                            <div class="zone-panel-hint" id="perimeterHint">
                                Tap map to add points. Drag any blue point to reposition. Long-tap to remove.
                            </div>

                            <div class="zone-panel-actions">
                                <button type="submit" class="btn-zone-apply" id="savePerimeterBtn">💾 Save</button>
                                <button type="button" class="btn-zone-clear" id="btnClearPerimeter">🗑️ Clear</button>
                            </div>
                            <div class="edit-mode-status" id="editModeStatus">✏️ Editing: OFF</div>
                        </form>
                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<!-- Toast notification for quick feedback -->
<div class="toast-notification" id="toastNotification"></div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>

// ---------- Toast notification helper ----------
function showToast(message, duration = 2000) {
    var toast = document.getElementById('toastNotification');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(function() {
        toast.classList.remove('show');
    }, duration);
}

// ---------- Back navigation (button only, no swipe) ----------
document.getElementById('backBtn').addEventListener('click', function(e) {
    e.preventDefault();
    if (document.referrer && document.referrer.includes('dashboard.php')) {
        window.history.back();
    } else {
        window.location.href = 'dashboard.php';
    }
});

// ---------- Panel toggle ----------
var mapPanel = document.getElementById('mapPanel');
var panelToggleBtn = document.getElementById('panelToggleBtn');
var isPanelCollapsed = false;

panelToggleBtn.addEventListener('click', function() {
    isPanelCollapsed = !isPanelCollapsed;
    mapPanel.classList.toggle('collapsed', isPanelCollapsed);
    var icon = panelToggleBtn.querySelector('svg');
    if (isPanelCollapsed) {
        icon.innerHTML = '<polyline points="6 9 12 15 18 9"/>';
        panelToggleBtn.setAttribute('aria-label', 'Expand panel');
    } else {
        icon.innerHTML = '<polyline points="18 15 12 9 6 15"/>';
        panelToggleBtn.setAttribute('aria-label', 'Collapse panel');
    }
    setTimeout(function() { if(map) map.invalidateSize(); }, 350);
});

// ---------- SweetAlert flash messages (only for saved perimeter) ----------
document.addEventListener('DOMContentLoaded', function() {
    var flashElement = document.querySelector('.map-flash[data-flash]');
    if (flashElement) {
        var flashMessage = flashElement.getAttribute('data-flash');
        if (flashMessage) {
            Swal.fire({
                icon: 'success',
                title: 'Perimeter Saved!',
                text: flashMessage,
                timer: 3000,
                showConfirmButton: true,
                confirmButtonColor: '#3B82F6',
                confirmButtonText: 'OK',
                background: '#111827',
                color: '#F1F5F9',
                timerProgressBar: true
            });
        }
    }
});

// ---------- Core logic ----------
var savedPerimeterPoints = <?php echo json_encode($perimeter_points); ?>;
var perimeterPoints = [];
var editingMode = false;
var draftPolyline = null;
var pointMarkers = [];
var dragMarkers = [];
var fixedMarkers = [];
var perimeterPolygon = null;
var restrictedAreaPolygon = null;
var map;
var riderList = [];
var selectedRiderId = null;

// ---------- Nearest neighbor auto-connect ----------
function nearestNeighborConnect(points) {
    if (points.length < 3) return points;
    
    var remaining = points.map(function(p, idx) {
        return { lat: p.lat, lng: p.lng, index: idx };
    });
    
    var sorted = [remaining[0]];
    remaining.splice(0, 1);
    
    while (remaining.length > 0) {
        var last = sorted[sorted.length - 1];
        var nearestIdx = 0;
        var nearestDist = Infinity;
        
        for (var i = 0; i < remaining.length; i++) {
            var dx = remaining[i].lat - last.lat;
            var dy = remaining[i].lng - last.lng;
            var dist = dx * dx + dy * dy;
            if (dist < nearestDist) {
                nearestDist = dist;
                nearestIdx = i;
            }
        }
        
        sorted.push(remaining[nearestIdx]);
        remaining.splice(nearestIdx, 1);
    }
    
    return sorted.map(function(p) {
        return { lat: p.lat, lng: p.lng };
    });
}

function autoConnectPoints(points) {
    if (points.length < 3) return points;
    return nearestNeighborConnect(points);
}

function parseTimestamp(value) {
    if(!value) return null;
    var parsed = new Date(String(value).replace(' ', 'T'));
    return isNaN(parsed.getTime()) ? null : parsed;
}

function formatLastOnline(lastOnline) {
    var parsed = parseTimestamp(lastOnline);
    if(!parsed) return 'No signal';
    var ageMs = Date.now() - parsed.getTime();
    if(ageMs < 60000) return 'Active now';
    if(ageMs < 3600000) return Math.floor(ageMs / 60000) + 'm ago';
    return parsed.toLocaleString();
}

function getRiderById(id) {
    return riderList.find(function(item) { return String(item.id) === String(id); });
}

function setActiveButton(id) {
    document.querySelectorAll('.rider-btn[data-rider-id]').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.riderId === String(id));
    });
}

function updateSelectedRiderDisplay(rider) {
    var coordsDisplay = document.getElementById('coordsDisplay');
    var dot = document.getElementById('selectedRiderDot');
    if(!coordsDisplay || !rider) return;
    var name = rider.fullname || ('Rider ' + rider.id);
    var online = !!rider.is_online;
    var label = rider.status_label || (online ? 'Online' : 'Offline');
    var details = name + ' — ' + label;
    if(rider.battery) details += ' | ' + rider.battery + '%';
    details += ' (' + formatLastOnline(rider.last_online_at || rider.last_signal) + ')';
    coordsDisplay.textContent = details;
    if(dot) {
        dot.style.background = online ? 'var(--accent-green)' : 'var(--text-muted)';
        dot.style.boxShadow = online ? '0 0 0 2px rgba(16,185,129,0.2)' : 'none';
        dot.style.animation = online ? 'pulse-green 2s infinite' : 'none';
    }
}

function renderRiderButtons(data) {
    riderList = Array.isArray(data) ? data : [];
    var container = document.getElementById('riderButtons');
    if(!container) return;
    container.innerHTML = '';
    if(!riderList.length) {
        container.innerHTML = '<button type="button" class="rider-btn disabled">No riders</button>';
        document.getElementById('coordsDisplay').textContent = 'No rider data';
        return;
    }
    if(!selectedRiderId) {
        var firstOnline = riderList.find(function(r) { return r.is_online; });
        selectedRiderId = String((firstOnline || riderList[0]).id);
    }
    riderList.forEach(function(rider) {
        var online = !!rider.is_online;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rider-btn' + (String(rider.id) === selectedRiderId ? ' active' : '') + (!online ? ' no-signal' : '');
        btn.dataset.riderId = rider.id;
        var name = document.createElement('span');
        name.textContent = rider.fullname || ('Rider ' + rider.id);
        btn.appendChild(name);
        var status = document.createElement('span');
        status.className = 'rider-status';
        status.textContent = rider.status_label || (online ? 'Online' : 'Offline');
        btn.appendChild(status);
        btn.addEventListener('click', function() {
            selectedRiderId = String(rider.id);
            setActiveButton(selectedRiderId);
            updateSelectedRiderDisplay(rider);
        });
        container.appendChild(btn);
    });
    updateSelectedRiderDisplay(getRiderById(selectedRiderId));
}

function refreshRiderStatuses(data) {
    riderList = Array.isArray(data) ? data : [];
    document.querySelectorAll('.rider-btn[data-rider-id]').forEach(function(btn) {
        var rider = getRiderById(btn.dataset.riderId);
        if(!rider) return;
        var online = !!rider.is_online;
        var status = btn.querySelector('.rider-status');
        btn.classList.toggle('no-signal', !online);
        if(status) status.textContent = rider.status_label || (online ? 'Online' : 'Offline');
    });
    updateSelectedRiderDisplay(getRiderById(selectedRiderId));
}

function updateMap() {
    fetch('../api/get-all-locations.php')
        .then(function(response) { return response.json(); })
        .then(function(data) {
            var hasButtons = document.querySelector('.rider-btn[data-rider-id]');
            if(!hasButtons) renderRiderButtons(data);
            else refreshRiderStatuses(data);
        })
        .catch(function() {
            document.getElementById('coordsDisplay').textContent = 'Status unavailable';
        });
}

// ---------- Enhanced Perimeter helpers with guaranteed visibility ----------
function drawPerimeterLayers() {
    console.log('drawPerimeterLayers called with', perimeterPoints.length, 'points');
    
    // Remove existing layers
    if(perimeterPolygon) {
        map.removeLayer(perimeterPolygon);
        perimeterPolygon = null;
    }
    if(restrictedAreaPolygon) {
        map.removeLayer(restrictedAreaPolygon);
        restrictedAreaPolygon = null;
    }
    
    // Check if we have enough points
    if(!perimeterPoints || perimeterPoints.length < 3) {
        console.log('Not enough points to draw perimeter (need at least 3)');
        if(draftPolyline) {
            map.removeLayer(draftPolyline);
            draftPolyline = null;
        }
        return;
    }
    
    // Auto-connect points using nearest neighbor
    var connectedPoints = autoConnectPoints(perimeterPoints);
    console.log('Connected points:', connectedPoints.length);
    
    // Convert points to Leaflet format [lat, lng]
    var leafletPoints = connectedPoints.map(function(p) { 
        return [p.lat, p.lng]; 
    });
    
    console.log('Leaflet points:', leafletPoints);
    
    // Draw the perimeter polygon with HIGH VISIBILITY
    perimeterPolygon = L.polygon(leafletPoints, {
        color: '#F59E0B',
        weight: 5,
        opacity: 1.0,
        fill: false,
        interactive: false,
        smoothFactor: 1,
        className: 'perimeter-line',
        stroke: true,
        dashArray: null
    }).addTo(map);
    
    // Add a glow effect by drawing a thicker transparent line behind it
    var glowPolygon = L.polygon(leafletPoints, {
        color: '#F59E0B',
        weight: 12,
        opacity: 0.2,
        fill: false,
        interactive: false,
        smoothFactor: 1
    }).addTo(map);
    // Store reference to glow polygon to remove later
    perimeterPolygon._glow = glowPolygon;
    
    // Create restricted area (outside perimeter) - red semi-transparent overlay
    var outerBounds = [[90, -180], [90, 180], [-90, 180], [-90, -180]];
    var holePoints = leafletPoints.slice().reverse();
    
    restrictedAreaPolygon = L.polygon([outerBounds, holePoints], {
        stroke: false,
        fillColor: '#EF4444',
        fillOpacity: 0.10,
        interactive: false,
        smoothFactor: 1
    }).addTo(map);
    
    console.log('Perimeter drawn successfully with', leafletPoints.length, 'points');
    
    // Also ensure draft line is removed when we have a proper perimeter
    if(draftPolyline) {
        map.removeLayer(draftPolyline);
        draftPolyline = null;
    }
    
    // Zoom to fit the perimeter
    if (leafletPoints.length > 0) {
        var bounds = L.latLngBounds(leafletPoints);
        map.fitBounds(bounds, { padding: [50, 50] });
    }
}

function drawDraftLayer() {
    if(draftPolyline) {
        map.removeLayer(draftPolyline);
        draftPolyline = null;
    }
    
    if(editingMode && perimeterPoints.length >= 2) {
        var leafletPoints = perimeterPoints.map(function(p) { 
            return [p.lat, p.lng]; 
        });
        
        draftPolyline = L.polyline(leafletPoints, {
            color: '#3B82F6',
            weight: 3,
            dashArray: '8, 6',
            opacity: 0.8,
            interactive: false,
            smoothFactor: 1
        }).addTo(map);
    }
}

function createDraggableMarker(point, index) {
    var marker = L.marker([point.lat, point.lng], {
        icon: L.divIcon({
            className: 'perimeter-marker-draggable',
            html: '<div class="marker-inner">' + (index + 1) + '</div>',
            iconSize: [22, 22],
            iconAnchor: [11, 11]
        }),
        draggable: true,
        autoPan: true,
        autoPanSpeed: 10
    });

    marker.on('dragstart', function() {
        var el = marker.getElement();
        if (el) {
            var inner = el.querySelector('.marker-inner');
            if (inner) inner.classList.add('dragging');
        }
    });

    marker.on('drag', function(e) {
        var latlng = e.latlng;
        perimeterPoints[index] = { lat: latlng.lat, lng: latlng.lng };
        drawPerimeterLayers();
        drawDraftLayer();
        updatePerimeterStatusDisplay();
        updatePointLabels();
    });

    marker.on('dragend', function() {
        var el = marker.getElement();
        if (el) {
            var inner = el.querySelector('.marker-inner');
            if (inner) inner.classList.remove('dragging');
        }
        showToast('Point ' + (index + 1) + ' repositioned');
    });

    marker.on('contextmenu', function(e) {
        L.DomEvent.stopPropagation(e);
        if(editingMode) {
            if (confirm('Remove point ' + (index + 1) + '?')) {
                perimeterPoints.splice(index, 1);
                updateAllMarkers();
                drawDraftLayer();
                drawPerimeterLayers();
                updatePerimeterStatusDisplay();
                showToast('Point ' + (index + 1) + ' removed');
            }
        }
    });

    return marker;
}

function createFixedMarker(point, index) {
    var marker = L.marker([point.lat, point.lng], {
        icon: L.divIcon({
            className: 'perimeter-point-fixed',
            html: '<div class="point-inner">' + (index + 1) + '</div>',
            iconSize: [14, 14],
            iconAnchor: [7, 7]
        }),
        interactive: false,
        zIndexOffset: 500
    });
    marker.bindTooltip('Point ' + (index + 1), { permanent: false, direction: 'top' });
    return marker;
}

function updatePointLabels() {
    pointMarkers.forEach(function(marker) { 
        if(marker && map.hasLayer(marker)) {
            map.removeLayer(marker); 
        }
    });
    pointMarkers = [];
    
    if(!editingMode || perimeterPoints.length === 0) return;
    
    perimeterPoints.forEach(function(point, idx) {
        var label = L.marker([point.lat, point.lng], {
            icon: L.divIcon({
                className: 'point-label',
                html: '<div style="background:#3B82F6;color:#fff;border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:bold;opacity:0.85;border:1px solid rgba(255,255,255,0.3);">'+(idx+1)+'</div>',
                iconSize: [16, 16],
                iconAnchor: [8, 8]
            }),
            interactive: false,
            zIndexOffset: 1000
        }).addTo(map);
        pointMarkers.push(label);
    });
}

function updateAllMarkers() {
    // Remove existing markers
    dragMarkers.forEach(function(marker) { 
        if(marker && map.hasLayer(marker)) {
            map.removeLayer(marker); 
        }
    });
    fixedMarkers.forEach(function(marker) { 
        if(marker && map.hasLayer(marker)) {
            map.removeLayer(marker); 
        }
    });
    pointMarkers.forEach(function(marker) { 
        if(marker && map.hasLayer(marker)) {
            map.removeLayer(marker); 
        }
    });
    dragMarkers = [];
    fixedMarkers = [];
    pointMarkers = [];

    if(editingMode) {
        // Show draggable markers in editing mode
        perimeterPoints.forEach(function(point, idx) {
            var dragMarker = createDraggableMarker(point, idx);
            dragMarker.addTo(map);
            dragMarkers.push(dragMarker);
        });
        updatePointLabels();
    } else {
        // Show fixed markers in view mode
        perimeterPoints.forEach(function(point, idx) {
            var fixedMarker = createFixedMarker(point, idx);
            fixedMarker.addTo(map);
            fixedMarkers.push(fixedMarker);
        });
    }
}

function updatePerimeterStatusDisplay() {
    var statusText = document.getElementById('perimeterStatus');
    if(statusText) {
        if(perimeterPoints.length === 0) {
            statusText.value = 'No perimeter points saved — tap map to add';
        } else {
            var pts = perimeterPoints.map(function(p,i) {
                return (i+1)+': '+p.lat.toFixed(4)+', '+p.lng.toFixed(4);
            }).join('\n');
            statusText.value = perimeterPoints.length + ' points saved\n' + pts;
        }
    }
    var modeSpan = document.getElementById('editModeStatus');
    if(modeSpan) {
        modeSpan.textContent = editingMode ? '✏️ Editing: ON — drag points to move, long-tap to remove' : '✏️ Editing: OFF';
    }
}

function loadSavedPerimeter() {
    // Load saved points from PHP
    perimeterPoints = JSON.parse(JSON.stringify(savedPerimeterPoints));
    
    console.log('Loading perimeter with ' + perimeterPoints.length + ' points');
    console.log('Points:', perimeterPoints);
    
    if(perimeterPoints && perimeterPoints.length >= 3) {
        // Auto-connect saved points to ensure clean perimeter
        perimeterPoints = autoConnectPoints(perimeterPoints);
        drawPerimeterLayers();
        console.log('Perimeter drawn with ' + perimeterPoints.length + ' points');
    } else if(perimeterPoints && perimeterPoints.length > 0 && perimeterPoints.length < 3) {
        console.log('Not enough points to draw perimeter (need at least 3, have ' + perimeterPoints.length + ')');
        showToast('Need at least 3 points to form a perimeter');
    } else {
        console.log('No saved perimeter points');
    }
    
    // Always show markers for saved points (even if less than 3)
    updateAllMarkers();
    updatePerimeterStatusDisplay();
}

function startEditingMode() {
    editingMode = true;
    updateAllMarkers();
    drawDraftLayer();
    drawPerimeterLayers();
    updatePerimeterStatusDisplay();
    map.getContainer().style.cursor = 'crosshair';
    showToast('Editing mode ON — tap map to add points');
}

function stopEditingMode(savePoints) {
    editingMode = false;
    // Clear markers
    dragMarkers.forEach(function(marker) { 
        if(marker && map.hasLayer(marker)) {
            map.removeLayer(marker); 
        }
    });
    pointMarkers.forEach(function(marker) { 
        if(marker && map.hasLayer(marker)) {
            map.removeLayer(marker); 
        }
    });
    dragMarkers = [];
    pointMarkers = [];
    
    if(draftPolyline) {
        map.removeLayer(draftPolyline);
        draftPolyline = null;
    }
    map.getContainer().style.cursor = '';
    
    if(savePoints) {
        // Save points to savedPerimeterPoints
        savedPerimeterPoints = JSON.parse(JSON.stringify(perimeterPoints));
        drawPerimeterLayers();
        // Show fixed markers
        updateAllMarkers();
    } else {
        // Discard changes
        perimeterPoints = JSON.parse(JSON.stringify(savedPerimeterPoints));
        drawPerimeterLayers();
        updateAllMarkers();
    }
    updatePerimeterStatusDisplay();
    showToast('Editing mode OFF');
}

// ---------- Init map ----------
function initMap() {
    console.log('Initializing map...');
    var defaultCenter = [14.5995, 120.9842];
    if(savedPerimeterPoints && savedPerimeterPoints.length) {
        defaultCenter = [savedPerimeterPoints[0].lat, savedPerimeterPoints[0].lng];
        console.log('Centering on first point:', defaultCenter);
    }
    map = L.map('map', { 
        zoomControl: true, 
        attributionControl: false,
        zoom: 13
    }).setView(defaultCenter, 13);
    
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '', subdomains: 'abcd', maxZoom: 19
    }).addTo(map);
    
    console.log('Map initialized, loading saved perimeter...');
    
    // Load and display saved perimeter
    loadSavedPerimeter();
    
    // Force a redraw after a short delay to ensure everything renders
    setTimeout(function() {
        if(map) {
            map.invalidateSize();
            drawPerimeterLayers();
            console.log('Forced perimeter redraw');
        }
    }, 500);
    
    map.on('click', function(e) {
        if(editingMode) {
            perimeterPoints.push({lat: e.latlng.lat, lng: e.latlng.lng});
            // Auto-connect points to prevent crossing
            perimeterPoints = autoConnectPoints(perimeterPoints);
            updateAllMarkers();
            drawDraftLayer();
            drawPerimeterLayers();
            updatePerimeterStatusDisplay();
            showToast('Point ' + perimeterPoints.length + ' added');
        }
    });
}

// ---------- DOM ready ----------
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    initMap();
    updateMap();

    var INTERVAL = 5;
    var countdown = INTERVAL;
    var refreshBar = document.getElementById('refreshBar');
    var countLabel = document.getElementById('countdownLabel');

    function tickCountdown() {
        countdown--;
        if(countdown < 0) countdown = INTERVAL;
        var pct = (countdown / INTERVAL) * 100;
        if(refreshBar) refreshBar.style.width = pct + '%';
        if(countLabel) countLabel.textContent = countdown + 's';
    }
    setInterval(updateMap, 5000);
    setInterval(tickCountdown, 1000);

    // Zone panel controls
    var zonePanel = document.getElementById('zonePanel');
    var btnToggle = document.getElementById('btnToggleZonePanel');
    var btnClose = document.getElementById('btnCloseZonePanel');
    var btnClear = document.getElementById('btnClearPerimeter');

    function setZonePanelOpen(isOpen) {
        if(!zonePanel || !btnToggle) return;
        zonePanel.classList.toggle('open', isOpen);
        btnToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        if(isOpen && !editingMode) startEditingMode();
        else if(!isOpen && editingMode) stopEditingMode(true);
    }

    btnToggle.addEventListener('click', function() {
        setZonePanelOpen(!zonePanel.classList.contains('open'));
    });
    btnClose.addEventListener('click', function() {
        setZonePanelOpen(false);
    });
    
    btnClear.addEventListener('click', function() {
        Swal.fire({
            title: 'Clear All Points?',
            text: 'This will permanently remove all saved perimeter points.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            cancelButtonColor: '#1E293B',
            confirmButtonText: 'Clear All',
            cancelButtonText: 'Cancel',
            background: '#111827',
            color: '#F1F5F9'
        }).then(function(result) {
            if (result.isConfirmed) {
                perimeterPoints = [];
                if(editingMode) { 
                    updateAllMarkers(); 
                    drawDraftLayer(); 
                }
                drawPerimeterLayers();
                updatePerimeterStatusDisplay();
                
                // Update hidden input and save to database
                document.getElementById('perimeterPointsInput').value = JSON.stringify([]);
                document.getElementById('perimeterForm').submit();
                
                showToast('All points cleared');
            }
        });
    });

    var perimeterForm = document.getElementById('perimeterForm');
    var perimeterPointsInput = document.getElementById('perimeterPointsInput');
    
    perimeterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Auto-connect before saving
        if (perimeterPoints.length >= 3) {
            perimeterPoints = autoConnectPoints(perimeterPoints);
        }
        
        perimeterPointsInput.value = JSON.stringify(perimeterPoints);
        savedPerimeterPoints = JSON.parse(JSON.stringify(perimeterPoints));
        
        var formData = new FormData(perimeterForm);
        
        Swal.fire({
            title: 'Saving Perimeter...',
            text: 'Please wait while your perimeter is being saved',
            allowOutsideClick: false,
            showConfirmButton: false,
            willOpen: function() {
                Swal.showLoading();
            },
            background: '#111827',
            color: '#F1F5F9'
        });
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            window.location.reload();
        })
        .catch(function(error) {
            Swal.fire({
                icon: 'error',
                title: 'Save Failed',
                text: 'There was an error saving your perimeter. Please try again.',
                background: '#111827',
                color: '#F1F5F9',
                confirmButtonColor: '#3B82F6',
                confirmButtonText: 'OK'
            });
        });
    });

    // Legend toggle
    var legendFloat = document.getElementById('zone-legend-float');
    var btnToggleLegend = document.getElementById('btn-toggle-legend');
    btnToggleLegend.addEventListener('click', function() {
        legendFloat.classList.toggle('collapsed');
        btnToggleLegend.setAttribute('aria-expanded', legendFloat.classList.contains('collapsed') ? 'false' : 'true');
    });

    // Force map resize after all elements are rendered
    setTimeout(function() { 
        if(map) {
            map.invalidateSize();
            drawPerimeterLayers();
            console.log('Final map resize and redraw');
        }
    }, 800);
});
</script>
</body>
</html>