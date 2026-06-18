<?php
session_start();

if(!isset($_SESSION['rider'])){
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");

$id = (int)$_SESSION['rider'];

$rider = $conn->query("
    SELECT fullname FROM users WHERE id = $id LIMIT 1
")->fetch_assoc();

$rider_name = $rider ? $rider['fullname'] : 'Rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Location — MotoRider</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>

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
    --sidebar-w:     240px;
    --radius:        12px;
}

html, body {
    height: 100%;
    font-family: 'Inter', system-ui, sans-serif;
    background: var(--bg-base);
    color: var(--text-primary);
}

body {
    display: flex;
}

/* ── Sidebar ─────────────────────────────────── */

.sidebar {
    width: var(--sidebar-w);
    background: var(--bg-surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 200;
}

.sidebar-logo {
    padding: 24px 20px 20px;
    border-bottom: 1px solid var(--border);
}

.sidebar-logo .wordmark {
    font-size: 17px;
    font-weight: 700;
    letter-spacing: -0.3px;
    color: var(--text-primary);
}

.sidebar-logo .wordmark span { color: var(--accent-green); }

.sidebar-logo .tagline {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.sidebar-nav {
    flex: 1;
    padding: 16px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.nav-section-label {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    padding: 12px 8px 6px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 500;
    transition: background 0.15s, color 0.15s;
    position: relative;
}

.nav-link:hover {
    background: var(--bg-card);
    color: var(--text-primary);
}

.nav-link.active {
    background: rgba(16,185,129,0.12);
    color: var(--accent-green);
}

.nav-link.active::before {
    content: '';
    position: absolute;
    left: 0; top: 50%;
    transform: translateY(-50%);
    width: 3px; height: 20px;
    background: var(--accent-green);
    border-radius: 0 3px 3px 0;
}

.nav-link svg {
    width: 16px; height: 16px;
    flex-shrink: 0;
    opacity: 0.8;
}

.sidebar-footer {
    padding: 16px 12px;
    border-top: 1px solid var(--border);
}

.sidebar-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    margin-bottom: 8px;
}

.avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-green), #059669);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 600; flex-shrink: 0;
    color: #fff;
}

.sidebar-user .user-info .name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
}

.sidebar-user .user-info .role {
    font-size: 11px;
    color: var(--text-muted);
}

.logout-link {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-muted);
    font-size: 13px;
    font-weight: 500;
    transition: background 0.15s, color 0.15s;
}

.logout-link:hover {
    background: rgba(239,68,68,0.1);
    color: var(--accent-red);
}

.logout-link svg { width: 15px; height: 15px; }

/* ── Main ────────────────────────────────────── */

.main {
    margin-left: var(--sidebar-w);
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

.topbar {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border);
    padding: 0 28px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 150;
    flex-shrink: 0;
}

.topbar-left h1 {
    font-size: 18px;
    font-weight: 600;
    letter-spacing: -0.2px;
}

.topbar-left p {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 1px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    color: var(--text-secondary);
    background: var(--bg-card);
    padding: 6px 12px;
    border-radius: 20px;
    border: 1px solid var(--border);
}

.status-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--accent-green);
    box-shadow: 0 0 0 2px rgba(16,185,129,0.25);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 2px rgba(16,185,129,0.25); }
    50%       { box-shadow: 0 0 0 5px rgba(16,185,129,0.05); }
}

/* ── Map wrapper ─────────────────────────────── */

.map-wrapper {
    flex: 1;
    position: relative;
    display: flex;
}

#map {
    flex: 1;
    height: calc(100vh - 64px);
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
    padding: 16px;
    width: 260px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
}

.panel-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.9px;
    color: var(--text-muted);
    margin-bottom: 10px;
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
    width: 10px; height: 10px;
    border-radius: 50%;
    background: var(--accent-green);
    box-shadow: 0 0 0 3px rgba(16,185,129,0.2);
    flex-shrink: 0;
    animation: pulse-green 2s infinite;
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
}

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
    width: 14px; height: 14px;
    color: var(--accent-green);
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

/* ── Responsive ──────────────────────────────── */

@media (max-width: 768px) {
    :root { --sidebar-w: 0px; }
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; }
    .topbar { padding: 0 16px; }

    .map-panel {
        width: calc(100% - 32px);
        top: 12px; left: 16px;
    }
}

</style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────── -->
<aside class="sidebar">

    <div class="sidebar-logo">
        <div class="wordmark">Moto<span>Rider</span></div>
        <div class="tagline">Rider Portal</div>
    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-label">Navigation</div>

        <a href="dashboard.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            Dashboard
        </a>

        <a href="map.php" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                <line x1="9" y1="3" x2="9" y2="18"/>
                <line x1="15" y1="6" x2="15" y2="21"/>
            </svg>
            My Location
        </a>

    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="avatar"><?php echo strtoupper(substr($rider_name, 0, 1)); ?></div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars($rider_name); ?></div>
                <div class="role">Rider</div>
            </div>
        </div>
        <a href="../auth/logout.php" class="logout-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Sign Out
        </a>
    </div>

</aside>

<!-- ── Main ─────────────────────────────────── -->
<div class="main">

    <header class="topbar">
        <div class="topbar-left">
            <h1>Connection Status</h1>
            <p>SIM800L online signal for your e-bike module</p>
        </div>
        <div class="topbar-right">
            <div class="status-indicator">
                <span class="status-dot"></span>
                SIM800L Link
            </div>
        </div>
    </header>

    <div class="map-wrapper">

        <!-- Floating info panel -->
        <div class="map-panel">

            <div class="panel-title">Active Rider</div>

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
                    <div class="label">SIM800L status</div>
                    <div class="coords" id="coords-display">Fetching…</div>
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

        <div id="map"></div>

    </div>

</div>

<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

<script>

window.RIDER_ID = <?php echo $id; ?>;
var riderId = window.RIDER_ID;

var defaultCenter = [14.5995, 120.9842];
var map = L.map('map', { zoomControl: true, attributionControl: false }).setView(defaultCenter, 13);

L.tileLayer(
    'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    { attribution: '', subdomains: 'abcd', maxZoom: 19 }
).addTo(map);

function updateStatusDisplay(data) {
    var display = document.getElementById('coords-display');
    var dot = document.getElementById('status-dot');
    if (!display || !data) {
        if (display) display.textContent = 'Status unavailable';
        return;
    }

    var label = data.status_label || (data.is_online ? 'Online' : 'Offline');
    var text = label;
    if (data.battery) {
        text += ' · Battery ' + data.battery + '%';
    }
    if (data.last_online_at) {
        text += ' · Last active ' + data.last_online_at;
    }
    display.textContent = text;

    if (dot) {
        dot.style.background = data.is_online ? '#10B981' : '#475569';
        dot.style.boxShadow = data.is_online ? '0 0 0 3px rgba(16,185,129,0.2)' : 'none';
    }
}

var INTERVAL   = 5;
var countdown  = INTERVAL;
var refreshBar = document.getElementById('refresh-bar');
var countLabel = document.getElementById('countdown');

function tickCountdown() {
    countdown--;
    if(countdown < 0) countdown = INTERVAL;
    var pct = (countdown / INTERVAL) * 100;
    refreshBar.style.width = pct + '%';
    countLabel.textContent  = countdown + 's';
}

function updateMap() {
    fetch('../api/get-location.php?rider_id=' + riderId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            updateStatusDisplay(data);
        })
        .catch(function() {
            document.getElementById('coords-display').textContent = 'Unavailable';
        });

    countdown = INTERVAL;
}

updateMap();
setInterval(updateMap, 5000);
setInterval(tickCountdown, 1000);
</script>
<script src="../assets/js/rider-session-ping.js"></script>
</body>
</html>