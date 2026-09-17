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
$perimeter_points = zone_get_perimeter($conn);
$perimeter_points_json = !empty($perimeter_points) ? json_encode($perimeter_points) : '';

$riders = $conn->query("
    SELECT id, fullname, ebike_id, status
    FROM users
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

.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
    height: 100%;
}

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

.map-overlays {
    position: absolute;
    inset: 0;
    z-index: 1000;
    pointer-events: none;
}
.map-overlays > * {
    pointer-events: auto;
}

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

.panel-title {
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    margin-bottom: 6px;
}

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
/* ✅ ADD THIS TO YOUR CSS */
.rider-map-marker--outside .rider-map-bike {
    background: #EF4444 !important; /* Red for outside perimeter */
}
.rider-map-marker--outside .rider-map-pulse {
    background: rgba(239, 68, 68, 0.55) !important;
}
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

.map-flash {
    display: none;
}

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

.leaflet-container {
    background: #0d1929;
}
.leaflet-tile-pane {
    filter: invert(92%) hue-rotate(180deg) brightness(82%) contrast(92%) saturate(70%);
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

.rider-map-icon-shell {
    background: transparent !important;
    border: none !important;
}
.rider-map-marker {
    position: relative;
    width: 34px;
    height: 34px;
}
.rider-map-bike {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
}
.rider-map-bike svg {
    width: 16px;
    height: 16px;
}
.rider-map-pulse {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 28px;
    height: 28px;
    margin: -14px 0 0 -14px;
    border-radius: 50%;
    animation: rider-marker-pulse 2s ease-out infinite;
    z-index: 1;
}
.rider-map-pulse--delay {
    animation-delay: 1s;
}
.rider-map-marker--live .rider-map-bike {
    background: var(--accent-green);
}
.rider-map-marker--live .rider-map-pulse {
    background: rgba(16, 185, 129, 0.55);
}
/* ✅ GRAY para sa offline */
.rider-map-marker--stale .rider-map-bike {
    background: #6B7280;
}
.rider-map-marker--stale .rider-map-pulse {
    background: rgba(71, 85, 105, 0.45);
    animation-duration: 3s;
}
@keyframes rider-marker-pulse {
    0% { transform: scale(1); opacity: 0.75; }
    70% { opacity: 0.2; }
    100% { transform: scale(3.2); opacity: 0; }
}

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

.perimeter-point-saved {
    background: transparent !important;
    border: none !important;
}
.perimeter-point-saved .point-inner {
    width: 22px;
    height: 22px;
    background: #F59E0B;
    border: 2px solid #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    color: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.45);
}

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

.perimeter-line {
    filter: drop-shadow(0 0 6px rgba(245, 158, 11, 0.5));
}

.leaflet-overlay-pane {
    z-index: 400 !important;
}
.leaflet-marker-pane {
    z-index: 500 !important;
}
.leaflet-popup-pane {
    z-index: 600 !important;
}

.custom-popup .leaflet-popup-content-wrapper {
    background: #111827 !important;
    color: #F1F5F9 !important;
    border: 1px solid #2D3F55 !important;
    border-radius: 8px !important;
    box-shadow: 0 4px 16px rgba(0,0,0,0.5) !important;
}
.custom-popup .leaflet-popup-tip {
    background: #111827 !important;
    border: 1px solid #2D3F55 !important;
}
.custom-popup .leaflet-popup-content {
    margin: 10px 12px !important;
    font-size: 12px;
    line-height: 1.5;
    min-width: 140px;
}

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
/* ✅ Vibration Alert Styles */
.rider-map-marker--vibration .rider-map-bike {
    background: #F59E0B !important;
    animation: vibration-shake 0.3s infinite;
}

.rider-map-marker--vibration .rider-map-pulse {
    background: rgba(245, 158, 11, 0.6) !important;
    animation: vibration-pulse 0.5s ease-out infinite;
}

@keyframes vibration-shake {
    0%, 100% { transform: translate(-50%, -50%) rotate(0deg); }
    25% { transform: translate(-50%, -50%) rotate(-10deg); }
    75% { transform: translate(-50%, -50%) rotate(10deg); }
}

@keyframes vibration-pulse {
    0% { transform: scale(1); opacity: 0.8; }
    100% { transform: scale(2.5); opacity: 0; }
}

/* Vibration alert badge */
.vibration-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #F59E0B;
    color: #fff;
    font-size: 8px;
    font-weight: bold;
    padding: 2px 5px;
    border-radius: 8px;
    animation: vibration-badge-pulse 1s infinite;
    z-index: 10;
}

@keyframes vibration-badge-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}
</style>
</head>
<body>

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
                    <div class="zone-legend-item" style="margin-top:4px;border-top:1px solid var(--border);padding-top:4px;">
                        <span style="display:inline-block;width:14px;height:14px;background:#10B981;border-radius:50%;"></span>
                        <span>Rider (Online)</span>
                    </div>
                    <div class="zone-legend-item">
                        <span style="display:inline-block;width:14px;height:14px;background:#6B7280;border-radius:50%;"></span>
                        <span>Rider (Offline)</span>
                    </div>
                    <div class="zone-legend-item" style="margin-top:4px;">
                        <span style="display:inline-block;width:14px;height:14px;background:#F59E0B;border-radius:50%;"></span>
                        <span>Vibration Alert</span>
                    </div>
                </div>
            </div>

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

<div class="toast-notification" id="toastNotification"></div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
<script src="../assets/js/geofence-map.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>

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

document.getElementById('backBtn').addEventListener('click', function(e) {
    e.preventDefault();
    if (document.referrer && document.referrer.includes('dashboard.php')) {
        window.history.back();
    } else {
        window.location.href = 'dashboard.php';
    }
});

var mapPanel = document.getElementById('mapPanel');
var panelToggleBtn = document.getElementById('panelToggleBtn');
var isPanelCollapsed = false;

panelToggleBtn.addEventListener('click', function() {
    isPanelCollapsed = !isPanelCollapsed;
    mapPanel.classList.toggle('collapsed', isPanelCollapsed);
    var icon = panelToggleBtn.querySelector('svg');
    if (isPanelCollapsed) {
        icon.innerHTML = '<polyline points="6 9 12 15 18 9"/>';
    } else {
        icon.innerHTML = '<polyline points="18 15 12 9 6 15"/>';
    }
    setTimeout(function() { if(map) map.invalidateSize(); }, 350);
});

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

var savedPerimeterPoints = <?php echo json_encode($perimeter_points); ?>;
var perimeterPoints = [];
var editingMode = false;
var draftPolyline = null;
var pointMarkers = [];
var dragMarkers = [];
var fixedMarkers = [];
var perimeterPolygon = null;
var perimeterGlowPolygon = null;
var restrictedAreaPolygon = null;
var map;
var riderList = [];
var selectedRiderId = null;
var riderMarkers = [];
var geofenceAlertState = {};
var geofenceLayers = {};

var EBIKE_ICON_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' +
    '<circle cx="5.5" cy="17.5" r="3.5"/>' +
    '<circle cx="18.5" cy="17.5" r="3.5"/>' +
    '<path d="M5.5 17.5L10 8h4l2 4h3"/>' +
    '<path d="M10 8l3 5.5h5.5"/>' +
    '<path d="M9 8h4"/>' +
    '<circle cx="12" cy="6" r="1" fill="#fff" stroke="none"/>' +
    '</svg>';

// ✅ UPDATED: createRiderMarker with vibration alert
function createRiderMarker(rider) {
    var isOnline = rider.is_online;
    var hasLocation = rider.has_location && rider.lat && rider.lng;
    var hasVibration = rider.vibration == 1 || rider.vibration_alert == true;
    
    var onlineClass = isOnline ? 'live' : 'stale';
    var markerColor = isOnline ? '#10B981' : '#6B7280';
    
    // ✅ VIBRATION ALERT - Orange color with shake animation
    if (hasVibration) {
        onlineClass = 'vibration';
        markerColor = '#F59E0B';
    }
    // ✅ GEOFENCE CHECK - Red if outside perimeter
    else if (rider.geofence_status === 'outside' || 
        (rider.geofence && rider.geofence.status === 'outside')) {
        markerColor = '#EF4444';
        onlineClass = 'outside';
    }
    
    var icon = L.divIcon({
        className: 'rider-map-icon-shell',
        html: `
            <div class="rider-map-marker rider-map-marker--${onlineClass}" style="cursor:pointer;">
                <div class="rider-map-bike" style="background: ${markerColor};">
                    ${EBIKE_ICON_SVG}
                </div>
                ${isOnline ? '<div class="rider-map-pulse"></div><div class="rider-map-pulse rider-map-pulse--delay"></div>' : ''}
                ${hasVibration ? '<div class="vibration-badge">⚠️ VIB</div>' : ''}
            </div>
        `,
        iconSize: [34, 34],
        iconAnchor: [17, 17]
    });
    
    var popupContent = `
        <div style="min-width:160px;">
            <strong style="font-size:14px;">${rider.fullname}</strong><br>
            <span style="color:#94A3B8;font-size:11px;">E-Bike: ${rider.ebike_id || 'N/A'}</span><br>
            <span style="color:${isOnline ? '#10B981' : '#6B7280'};font-size:11px;">
                ${isOnline ? '🟢 Online' : '🔴 Offline'}
            </span><br>
            ${hasVibration ? 
              '<span style="color:#F59E0B;font-size:11px;font-weight:bold;">⚠️ VIBRATION DETECTED!</span><br>' : ''}
            ${rider.geofence_status === 'outside' ? 
              '<span style="color:#EF4444;font-size:11px;">⚠️ OUTSIDE PERIMETER</span><br>' : ''}
            ${rider.last_signal ? `<span style="color:#475569;font-size:10px;">🕐 ${new Date(rider.last_signal).toLocaleString()}</span><br>` : ''}
            ${hasLocation ? `<span style="color:#475569;font-size:10px;">📍 ESP32: ${rider.lat.toFixed(6)}, ${rider.lng.toFixed(6)}</span>` : '📡 Waiting for GPS'}
        </div>
    `;
    
    var marker = L.marker([rider.lat || 0, rider.lng || 0], { 
        icon: icon,
        title: rider.fullname
    });
    
    marker.bindPopup(popupContent, { className: 'custom-popup' });
    
    marker.on('click', function() {
        if (rider.has_location && rider.lat && rider.lng) {
            map.flyTo([rider.lat, rider.lng], 17, {
                duration: 1.0,
                easeLinearity: 0.25
            });
        }
    });
    
    return marker;
}

// ✅ UPDATED: updateRiderMarkers with geofence checking for all riders
function updateRiderMarkers(riders) {
    riderMarkers.forEach(function(marker) {
        if (marker && map.hasLayer(marker)) {
            map.removeLayer(marker);
        }
    });
    riderMarkers = [];
    riderList = riders;
    
    riders.forEach(function(rider) {
        // Check if rider has location (even if offline)
        var hasLastLocation = rider.lat !== null && rider.lng !== null && 
                              rider.lat !== undefined && rider.lng !== undefined &&
                              rider.lat != 0 && rider.lng != 0;
        
        // ✅ CALCULATE GEOFENCE STATUS for ALL riders with location
        if (hasLastLocation && savedPerimeterPoints && savedPerimeterPoints.length >= 3) {
            var geofenceResult = GeofenceMap.evaluate(rider.lat, rider.lng, savedPerimeterPoints);
            rider.geofence_status = geofenceResult.status;
        }
        
        // Show marker if rider has location data
        if (hasLastLocation) {
            var marker = createRiderMarker(rider);
            marker.riderId = rider.id;
            marker.addTo(map);
            riderMarkers.push(marker);
        }
    });
    
    renderRiderButtons(riders);
    
    if (!selectedRiderId || !riderList.find(function(r) { return String(r.id) === selectedRiderId; })) {
        var firstOnline = riderList.find(function(r) { return r.is_online; });
        var firstWithLocation = riderList.find(function(r) { return r.lat && r.lng; });
        var selected = firstOnline || firstWithLocation || riderList[0];
        if (selected) {
            selectedRiderId = String(selected.id);
            setActiveButton(selectedRiderId);
            updateSelectedRiderDisplay(getRiderById(selectedRiderId));
        }
    }
}
// ✅ VIBRATION ALERT FUNCTION
var vibrationAlertState = {};

function checkVibrationAlerts(riders) {
    riders.forEach(function(rider) {
        if (!rider.has_location || !rider.lat || !rider.lng) return;
        
        var hasVibration = rider.vibration == 1 || rider.vibration_alert == true;
        var key = String(rider.id);
        
        if (hasVibration) {
            // Show alert only once per vibration event
            if (!vibrationAlertState[key] || 
                (Date.now() - vibrationAlertState[key] > 30000)) { // 30 sec cooldown
                
                vibrationAlertState[key] = Date.now();
                showVibrationAlert(rider);
                saveVibrationAlert(rider);
            }
        } else {
            // Reset state when no vibration
            vibrationAlertState[key] = null;
        }
    });
}

function showVibrationAlert(rider) {
    // Play alert sound (optional)
    playAlertSound();
    
    Swal.fire({
        title: '⚠️ Vibration Detected!',
        html: `
            <div style="text-align: left; padding: 10px;">
                <strong style="font-size: 16px;">${rider.fullname}</strong><br>
                <span style="color: #F59E0B; font-size: 14px;">
                    ${rider.is_online ? '🟢 Online' : '🔴 Offline'} - Possible theft/movement!
                </span><br><br>
                <span style="font-size: 12px; color: #94A3B8;">
                    📍 Location: ${rider.lat.toFixed(6)}, ${rider.lng.toFixed(6)}<br>
                    🕐 Time: ${new Date().toLocaleString()}
                </span>
            </div>
        `,
        icon: 'warning',
        confirmButtonText: 'View on Map',
        confirmButtonColor: '#F59E0B',
        showCancelButton: true,
        cancelButtonText: 'Dismiss',
        cancelButtonColor: '#1E293B',
        background: '#111827',
        color: '#F1F5F9',
        timer: 15000,
        timerProgressBar: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            map.flyTo([rider.lat, rider.lng], 18, { duration: 1.2 });
        }
    });
}

function playAlertSound() {
    // Create audio context for alert sound
    try {
        var audioContext = new (window.AudioContext || window.webkitAudioContext)();
        var oscillator = audioContext.createOscillator();
        var gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        gainNode.gain.value = 0.3;
        
        oscillator.start();
        
        // Beep pattern
        setTimeout(function() { oscillator.frequency.value = 600; }, 150);
        setTimeout(function() { oscillator.frequency.value = 800; }, 300);
        setTimeout(function() { oscillator.stop(); }, 450);
    } catch(e) {
        console.log('Audio not supported');
    }
}

// ✅ SAVE VIBRATION ALERT TO DATABASE
function saveVibrationAlert(rider) {
    fetch('../api/save-alert.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            rider_id: rider.id,
            rider_name: rider.fullname,
            ebike_id: rider.ebike_id || 'N/A',
            latitude: rider.lat,
            longitude: rider.lng,
            is_online: rider.is_online ? 1 : 0,
            alert_type: 'vibration',  // ✅ Important: mark as vibration alert
            vibration: 1
        })
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        console.log('Vibration alert saved:', data);
    })
    .catch(function(error) {
        console.error('Error saving vibration alert:', error);
    });
}

function showGeofenceAlert(rider) {
    Swal.fire({
        title: '⚠️ Perimeter Breach!',
        html: `
            <div style="text-align: left; padding: 10px;">
                <strong style="font-size: 16px;">${rider.fullname}</strong><br>
                <span style="color: #EF4444; font-size: 14px;">
                    ${rider.is_online ? '🟢 Online - Currently outside!' : '🔴 Offline - Last location outside!'}
                </span><br><br>
                <span style="font-size: 12px; color: #94A3B8;">
                    📍 Location: ${rider.lat.toFixed(6)}, ${rider.lng.toFixed(6)}<br>
                    🕐 Time: ${new Date().toLocaleString()}
                </span>
            </div>
        `,
        icon: 'warning',
        confirmButtonText: 'Acknowledge',
        confirmButtonColor: '#EF4444',
        showCancelButton: true,
        cancelButtonText: 'View on Map',
        cancelButtonColor: '#3B82F6',
        background: '#111827',
        color: '#F1F5F9',
        timer: 10000,
        timerProgressBar: true,
        allowOutsideClick: false
    }).then((result) => {
        if (result.dismiss === Swal.DismissReason.cancel) {
            // Focus on rider location
            map.flyTo([rider.lat, rider.lng], 17, { duration: 1.0 });
        }
    });
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
    if (rider.location_source) {
        details += '';
    }
    if(rider.battery) details += '' + '';
    if(rider.has_location && rider.lat && rider.lng) {
        details += ' | 📍 ' + rider.lat.toFixed(6) + ', ' + rider.lng.toFixed(6);
    } else {
        details += ' | 📡 Waiting for GPS';
    }
    details += ' (' + formatLastOnline(rider.last_online_at || rider.last_signal) + ')';
    coordsDisplay.textContent = details;
    
    if(dot) {
        dot.style.background = online ? '#10B981' : '#6B7280';
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
        return;
    }
    if(!selectedRiderId) {
        var firstOnline = riderList.find(function(r) { return r.is_online; });
        selectedRiderId = String((firstOnline || riderList[0]).id);
    }
    riderList.forEach(function(rider) {
        var signalAge = rider.signal_age_seconds || 999;
        var online = rider.is_online && signalAge < 60;
        var hasLocation = rider.has_location && rider.lat && rider.lng;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rider-btn' + (String(rider.id) === selectedRiderId ? ' active' : '') + (!online ? ' no-signal' : '');
        btn.dataset.riderId = rider.id;
        var name = document.createElement('span');
        name.textContent = rider.fullname || ('Rider ' + rider.id);
        btn.appendChild(name);
        var status = document.createElement('span');
        status.className = 'rider-status';
        status.textContent = (online ? '🟢' : '🔴') + ' ' + (online ? 'Online' : 'Offline');
        status.textContent += hasLocation ? ' ' : ' ';
        btn.appendChild(status);
        
        // ✅ UPDATED: Click handler with map centering
        btn.addEventListener('click', function() {
            selectedRiderId = String(rider.id);
            setActiveButton(selectedRiderId);
            updateSelectedRiderDisplay(rider);
            
            // ✅ Center map on rider's location
            if (rider.has_location && rider.lat && rider.lng) {
                var zoomLevel = 17; // Street-level zoom
                
                // Smooth animation to rider's location
                map.flyTo([rider.lat, rider.lng], zoomLevel, {
                    duration: 1.2, // Animation duration in seconds
                    easeLinearity: 0.25
                });
                
                // Open the popup for this rider
                var riderMarker = riderMarkers.find(function(marker) {
                    var markerLatLng = marker.getLatLng();
                    return markerLatLng.lat === rider.lat && markerLatLng.lng === rider.lng;
                });
                
                if (riderMarker) {
                    setTimeout(function() {
                        riderMarker.openPopup();
                    }, 1200); // Open popup after animation completes
                }
            } else {
                // If no location, show toast
                showToast('No GPS location available for this rider', 2000);
            }
        });
        
        container.appendChild(btn);
    });
    updateSelectedRiderDisplay(getRiderById(selectedRiderId));
}

// ✅ UPDATED: checkRiderGeofenceAlerts - now checks ALL riders with location
function checkRiderGeofenceAlerts(riders) {
    if (!savedPerimeterPoints || savedPerimeterPoints.length < 3 || typeof GeofenceMap === 'undefined') {
        return;
    }
    
    riders.forEach(function(rider) {
        // Check if rider has location data (even if offline)
        if (!rider.has_location || !rider.lat || !rider.lng) return;
        
        // Get geofence status from API or calculate it
        var status = (rider.geofence && rider.geofence.status) ? 
                     rider.geofence.status : 
                     GeofenceMap.evaluate(rider.lat, rider.lng, savedPerimeterPoints).status;
        
        var key = String(rider.id);
        
        // Store the geofence status on the rider object
        rider.geofence_status = status;
        
        if (status === 'outside') {
            // Show alert for both online AND offline riders
            GeofenceMap.handleStatusChange('outside', key, geofenceAlertState, {
                title: 'Perimeter Breach',
                text: rider.fullname + ' is outside the perimeter (Last known location)'
            });
        } else if (status === 'warning') {
            geofenceAlertState[key] = 'warning';
        } else if (status === 'inside') {
            geofenceAlertState[key] = 'inside';
        }
    });
}

function updateMap() {
    fetch('../api/get-all-locations.php?t=' + Date.now(), {
        cache: 'no-store',
        headers: { 'Cache-Control': 'no-cache' }
    })
    .then(function(response) { 
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json(); 
    })
    .then(function(data) {
        // Update markers
        updateRiderMarkers(data);
        
        // ✅ Check vibration alerts
        checkVibrationAlerts(data);
        
        // ✅ Check geofence alerts
        saveGeofenceAlerts(data);
    })
    .catch(function(error) {
        console.error('Error updating map:', error);
    });
}

// ✅ BAGONG FUNCTION - Mag-save ng alerts sa database
function saveGeofenceAlerts(riders) {
    if (!savedPerimeterPoints || savedPerimeterPoints.length < 3 || typeof GeofenceMap === 'undefined') {
        return;
    }
    
    riders.forEach(function(rider) {
        if (!rider.has_location || !rider.lat || !rider.lng) return;
        
        var status = GeofenceMap.evaluate(rider.lat, rider.lng, savedPerimeterPoints).status;
        rider.geofence_status = status;
        
        if (status === 'outside') {
            // I-save sa database
            fetch('../api/save-alert.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    rider_id: rider.id,
                    rider_name: rider.fullname,
                    ebike_id: rider.ebike_id || 'N/A',
                    latitude: rider.lat,
                    longitude: rider.lng,
                    is_online: rider.is_online ? 1 : 0
                })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                console.log('Alert saved:', data);
            })
            .catch(function(error) {
                console.error('Error saving alert:', error);
            });
        }
    });
}

// ---------- Perimeter functions ----------
function nearestNeighborConnect(points) {
    if (points.length < 3) return points;
    var remaining = points.map(function(p, idx) { return { lat: p.lat, lng: p.lng, index: idx }; });
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
            if (dist < nearestDist) { nearestDist = dist; nearestIdx = i; }
        }
        sorted.push(remaining[nearestIdx]);
        remaining.splice(nearestIdx, 1);
    }
    return sorted.map(function(p) { return { lat: p.lat, lng: p.lng }; });
}

function autoConnectPoints(points) {
    if (points.length < 3) return points;
    return nearestNeighborConnect(points);
}

function clearPerimeterLayers() {
    if (perimeterPolygon) { map.removeLayer(perimeterPolygon); perimeterPolygon = null; }
    if (perimeterGlowPolygon) { map.removeLayer(perimeterGlowPolygon); perimeterGlowPolygon = null; }
    if (restrictedAreaPolygon) { map.removeLayer(restrictedAreaPolygon); restrictedAreaPolygon = null; }
}

function drawPerimeterLayers(isPreview) {
    isPreview = !!isPreview;
    clearPerimeterLayers();
    if (draftPolyline && editingMode) { map.removeLayer(draftPolyline); draftPolyline = null; }
    if (!perimeterPoints || perimeterPoints.length < 3) {
        if (editingMode) drawDraftLayer();
        return;
    }
    var connectedPoints = isPreview ? perimeterPoints : autoConnectPoints(perimeterPoints);
    var leafletPoints = connectedPoints.map(function(p) { return [p.lat, p.lng]; });
    if (editingMode) {
        draftPolyline = L.polyline(leafletPoints.concat([leafletPoints[0]]), {
            color: '#F59E0B', weight: 4, dashArray: '10, 8', opacity: 0.95, interactive: false
        }).addTo(map);
        return;
    }
    perimeterPolygon = L.polygon(leafletPoints, {
        color: '#F59E0B', weight: 5, opacity: 1.0, fill: false, interactive: false, className: 'perimeter-line'
    }).addTo(map);
    perimeterGlowPolygon = L.polygon(leafletPoints, {
        color: '#F59E0B', weight: 12, opacity: 0.2, fill: false, interactive: false
    }).addTo(map);
    var outerBounds = [[90, -180], [90, 180], [-90, 180], [-90, -180]];
    restrictedAreaPolygon = L.polygon([outerBounds, leafletPoints.slice().reverse()], {
        stroke: false, fillColor: '#EF4444', fillOpacity: 0.10, interactive: false
    }).addTo(map);
}

function drawDraftLayer() {
    if (draftPolyline) { map.removeLayer(draftPolyline); draftPolyline = null; }
    if (!editingMode || perimeterPoints.length < 2) return;
    draftPolyline = L.polyline(perimeterPoints.map(function(p) { return [p.lat, p.lng]; }), {
        color: '#3B82F6', weight: 3, dashArray: '8, 6', opacity: 0.8, interactive: false
    }).addTo(map);
}

function createDraggableMarker(point, index) {
    var marker = L.marker([point.lat, point.lng], {
        icon: L.divIcon({
            className: 'perimeter-marker-draggable',
            html: '<div class="marker-inner">' + (index + 1) + '</div>',
            iconSize: [22, 22], iconAnchor: [11, 11]
        }),
        draggable: true, autoPan: false
    });
    marker.on('drag', function(e) {
        perimeterPoints[index] = { lat: e.latlng.lat, lng: e.latlng.lng };
        if (perimeterPoints.length >= 3) drawPerimeterLayers(true);
        else drawDraftLayer();
        updatePerimeterStatusDisplay();
    });
    return marker;
}

function updateAllMarkers() {
    dragMarkers.forEach(function(m) { if(m && map.hasLayer(m)) map.removeLayer(m); });
    fixedMarkers.forEach(function(m) { if(m && map.hasLayer(m)) map.removeLayer(m); });
    pointMarkers.forEach(function(m) { if(m && map.hasLayer(m)) map.removeLayer(m); });
    dragMarkers = []; fixedMarkers = []; pointMarkers = [];
    if(editingMode) {
        perimeterPoints.forEach(function(point, idx) {
            var dm = createDraggableMarker(point, idx);
            dm.addTo(map); dragMarkers.push(dm);
        });
    }
}

function updatePerimeterStatusDisplay() {
    var statusText = document.getElementById('perimeterStatus');
    if(statusText) {
        if(perimeterPoints.length === 0) {
            statusText.value = 'No perimeter points saved';
        } else {
            statusText.value = perimeterPoints.length + ' points saved';
        }
    }
}

function loadSavedPerimeter() {
    perimeterPoints = JSON.parse(JSON.stringify(savedPerimeterPoints));
    if(perimeterPoints && perimeterPoints.length >= 3) {
        perimeterPoints = autoConnectPoints(perimeterPoints);
        drawPerimeterLayers(false);
    }
    updateAllMarkers();
    updatePerimeterStatusDisplay();
}

function startEditingMode() {
    editingMode = true;
    updateAllMarkers();
    drawPerimeterLayers(true);
    map.getContainer().style.cursor = 'crosshair';
}

function stopEditingMode(savePoints) {
    editingMode = false;
    dragMarkers.forEach(function(m) { if(m && map.hasLayer(m)) map.removeLayer(m); });
    pointMarkers.forEach(function(m) { if(m && map.hasLayer(m)) map.removeLayer(m); });
    dragMarkers = []; pointMarkers = [];
    if(draftPolyline) { map.removeLayer(draftPolyline); draftPolyline = null; }
    map.getContainer().style.cursor = '';
    if(savePoints) {
        savedPerimeterPoints = JSON.parse(JSON.stringify(perimeterPoints));
        drawPerimeterLayers(false);
        updateAllMarkers();
    } else {
        perimeterPoints = JSON.parse(JSON.stringify(savedPerimeterPoints));
        drawPerimeterLayers(false);
        updateAllMarkers();
    }
    updatePerimeterStatusDisplay();
}

function initMap() {
    // ✅ MAS MALAWAK NA BOUNDS (kasama ang buong Cebu Province)
    var cebuProvinceBounds = L.latLngBounds(
        [9.5, 123.3],  // Southwest corner (malapit sa Santander)
        [11.5, 124.5]  // Northeast corner (malapit sa Bantayan Island)
    );
    
    map = L.map('map', { 
        zoomControl: true, 
        attributionControl: false,
        maxBounds: cebuProvinceBounds,  // ✅ Mas malawak na bounds
        maxBoundsViscosity: 0.5
    }).setView(GeofenceMap.BANTAYAN_CENTER, GeofenceMap.BANTAYAN_ZOOM);
    
    var mapLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    loadSavedPerimeter();
    setTimeout(function() { updateMap(); }, 1000);
    
    map.on('click', function(e) {
        if(editingMode) {
            perimeterPoints.push({lat: e.latlng.lat, lng: e.latlng.lng});
            updateAllMarkers();
            if (perimeterPoints.length >= 3) drawPerimeterLayers(true);
            else drawDraftLayer();
            updatePerimeterStatusDisplay();
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initMap();
    updateMap();

    var INTERVAL = 5;
    var countdown = INTERVAL;
    var refreshBar = document.getElementById('refreshBar');
    var countLabel = document.getElementById('countdownLabel');

    function tickCountdown() {
        countdown--;
        if(countdown < 0) countdown = INTERVAL;
        if(refreshBar) refreshBar.style.width = (countdown / INTERVAL) * 100 + '%';
        if(countLabel) countLabel.textContent = countdown + 's';
    }
    
    // ✅ AUTO-REFRESH every 5 seconds
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
        if(isOpen && !editingMode) startEditingMode();
        else if(!isOpen && editingMode) stopEditingMode(true);
    }

    btnToggle.addEventListener('click', function() { setZonePanelOpen(!zonePanel.classList.contains('open')); });
    btnClose.addEventListener('click', function() { setZonePanelOpen(false); });
    
    btnClear.addEventListener('click', function() {
        Swal.fire({
            title: 'Clear All Points?',
            text: 'This will permanently remove all saved perimeter points.',
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#EF4444', cancelButtonColor: '#1E293B',
            confirmButtonText: 'Clear All', cancelButtonText: 'Cancel',
            background: '#111827', color: '#F1F5F9'
        }).then(function(result) {
            if (result.isConfirmed) {
                perimeterPoints = [];
                drawPerimeterLayers(editingMode);
                updatePerimeterStatusDisplay();
                document.getElementById('perimeterPointsInput').value = JSON.stringify([]);
                document.getElementById('perimeterForm').submit();
            }
        });
    });

    var perimeterForm = document.getElementById('perimeterForm');
    perimeterForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (perimeterPoints.length >= 3) perimeterPoints = autoConnectPoints(perimeterPoints);
        document.getElementById('perimeterPointsInput').value = JSON.stringify(perimeterPoints);
        perimeterForm.submit();
    });

    var legendFloat = document.getElementById('zone-legend-float');
    document.getElementById('btn-toggle-legend').addEventListener('click', function() {
        legendFloat.classList.toggle('collapsed');
    });
});
</script>
<!-- Global Alert Widget -->
<script src="../assets/js/global-alert-widget.js"></script>
</body>
</html>