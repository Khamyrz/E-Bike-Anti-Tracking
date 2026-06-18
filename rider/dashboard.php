<?php

session_start();

if(!isset($_SESSION['rider']))
{
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/rider-online.php");

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

rider_online_ensure_columns($conn); // ← FIXED: was $rider_online_ensure_columns($conn)

$online_row = $conn->query("
    SELECT is_online, last_online_at, online_source
    FROM users
    WHERE id = $rider_id
    LIMIT 1
")->fetch_assoc();

$is_online = rider_is_currently_online($online_row ?: []);
$online_label = rider_online_status_label(array_merge($online_row ?: [], ['is_online' => $is_online ? 1 : 0]));

$current_date = date('l, F j, Y');

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rider Dashboard — MotoAdmin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>

*, *::before, *::after {
    box-sizing: border-box;
    margin: 0; padding: 0;
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
    --sidebar-w:     240px;
    --radius:        12px;
}

html, body {
    height: 100%;
    font-family: 'Inter', system-ui, sans-serif;
    background: var(--bg-base);
    color: var(--text-primary);
}

body { display: flex; }

/* ── Sidebar ─────────────────────────────────── */

.sidebar {
    width: var(--sidebar-w);
    background: var(--bg-surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 100;
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

.sidebar-logo .wordmark span { color: var(--accent-blue); }

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
    background: rgba(59,130,246,0.12);
    color: var(--accent-blue);
}

.nav-link.active::before {
    content: '';
    position: absolute;
    left: 0; top: 50%;
    transform: translateY(-50%);
    width: 3px; height: 20px;
    background: var(--accent-blue);
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
    z-index: 50;
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

/* ── Content ─────────────────────────────────── */

.content {
    padding: 28px;
    flex: 1;
}

.section-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    margin-bottom: 16px;
}

/* ── Welcome card ────────────────────────────── */

.welcome-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px 28px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    position: relative;
    overflow: hidden;
}

.welcome-card::before {
    content: '';
    position: absolute;
    right: -60px; top: -60px;
    width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(16,185,129,0.08) 0%, transparent 70%);
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

.status-badge-large {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
}

.status-badge-large.approved {
    background: rgba(16,185,129,0.12);
    border: 1px solid rgba(16,185,129,0.25);
    color: var(--accent-green);
}

.status-badge-large.pending {
    background: rgba(245,158,11,0.12);
    border: 1px solid rgba(245,158,11,0.25);
    color: var(--accent-amber);
}

.status-badge-large::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
}

/* ── Info cards ──────────────────────────────── */

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}

.info-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    position: relative;
    overflow: hidden;
}

.info-card::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
}

.info-card.blue::before   { background: var(--accent-blue); }
.info-card.green::before  { background: var(--accent-green); }
.info-card.purple::before { background: var(--accent-purple); }

.info-icon {
    width: 34px; height: 34px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px;
}

.info-card.blue   .info-icon { background: rgba(59,130,246,0.12);  color: var(--accent-blue); }
.info-card.green  .info-icon { background: rgba(16,185,129,0.12);  color: var(--accent-green); }
.info-card.purple .info-icon { background: rgba(139,92,246,0.12); color: var(--accent-purple); }
.info-icon svg { width: 16px; height: 16px; }

.info-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    margin-bottom: 4px;
}

.info-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    word-break: break-all;
}

.info-sub {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}

/* ── Location card ───────────────────────────── */

.location-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 14px;
}

.location-dot {
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

.location-card.no-data .location-dot {
    background: var(--text-muted);
    box-shadow: none;
    animation: none;
}

.location-text .label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
}

.location-text .coords {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}

.location-text .coords.muted {
    color: var(--text-muted);
    font-weight: 400;
}

/* ── Quick actions ───────────────────────────── */

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}

.action-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: background 0.15s, border-color 0.15s, transform 0.15s;
    color: var(--text-primary);
}

.action-card:hover {
    background: var(--bg-card-hover);
    border-color: var(--accent-blue);
    transform: translateY(-1px);
}

.action-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.action-card.blue   .action-icon { background: rgba(59,130,246,0.1);  color: var(--accent-blue); }
.action-card.green  .action-icon { background: rgba(16,185,129,0.1);  color: var(--accent-green); }
.action-card.purple .action-icon { background: rgba(139,92,246,0.1); color: var(--accent-purple); }
.action-icon svg { width: 20px; height: 20px; }

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

/* ── Responsive ──────────────────────────────── */

@media (max-width: 768px) {
    :root { --sidebar-w: 0px; }
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; }
    .content { padding: 20px 16px; }
    .topbar { padding: 0 16px; }
    .welcome-card { flex-direction: column; align-items: flex-start; }
    .info-grid, .actions-grid { grid-template-columns: 1fr 1fr; }
}

</style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────── -->
<aside class="sidebar">

    <div class="sidebar-logo">
        <div class="wordmark">Moto<span>Admin</span></div>
        <div class="tagline">Fleet Management</div>
    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-label">Overview</div>

        <a href="dashboard.php" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            Dashboard
        </a>

        <div class="nav-section-label">My Bike</div>

        <a href="map.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                <line x1="9" y1="3" x2="9" y2="18"/>
                <line x1="15" y1="6" x2="15" y2="21"/>
            </svg>
            Track Bike
        </a>

        <div class="nav-section-label">Account</div>

        <a href="profile.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="8" r="4"/>
                <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
            </svg>
            Profile
        </a>

    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="avatar">
                <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
            </div>
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
            <h1>Dashboard</h1>
            <p><?php echo $current_date; ?></p>
        </div>
        <div class="status-indicator">
            <span class="status-dot"></span>
            Online
        </div>
    </header>

    <div class="content">

        <!-- Welcome -->
        <div class="welcome-card">
            <div class="welcome-text">
                <h2>Welcome back, <?php echo htmlspecialchars(explode(' ', $rider_name)[0]); ?>!</h2>
                <p>Here's an overview of your account and bike status.</p>
            </div>
            <?php
            $status = $rider_data['status'] ?? 'pending';
            echo "<span class=\"status-badge-large {$status}\">" . ucfirst($status) . "</span>";
            ?>
        </div>

        <!-- Account info -->
        <div class="section-label">Account Details</div>

        <div class="info-grid">

            <div class="info-card blue">
                <div class="info-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>
                </div>
                <div class="info-label">Full Name</div>
                <div class="info-value"><?php echo htmlspecialchars($rider_data['fullname'] ?? '—'); ?></div>
            </div>

            <div class="info-card green">
                <div class="info-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                </div>
                <div class="info-label">Email</div>
                <div class="info-value"><?php echo htmlspecialchars($rider_data['email'] ?? '—'); ?></div>
            </div>

            <div class="info-card purple">
                <div class="info-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 11.51 18a19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                    </svg>
                </div>
                <div class="info-label">Phone</div>
                <div class="info-value"><?php echo htmlspecialchars($rider_data['phone'] ?? '—'); ?></div>
            </div>

        </div>

        <!-- SIM800L connection status -->
        <div class="section-label">SIM800L Status</div>

        <div class="location-card" style="margin-bottom:24px;">
            <div class="location-dot" style="<?php echo $is_online ? '' : 'background:var(--text-muted);box-shadow:none;'; ?>"></div>
            <div class="location-text">
                <div class="label">Connection</div>
                <div class="coords"><?php echo htmlspecialchars($online_label); ?></div>
                <?php if($loc && !empty($loc['battery'])): ?>
                <div class="coords muted" style="margin-top:6px;font-size:12px;">
                    Last module signal: Battery <?php echo htmlspecialchars($loc['battery']); ?>%
                    <?php if(!empty($loc['created_at'])): ?>
                    · <?php echo htmlspecialchars($loc['created_at']); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="section-label">Quick Actions</div>

        <div class="actions-grid">

            <a href="map.php" class="action-card green">
                <div class="action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                        <line x1="9" y1="3" x2="9" y2="18"/>
                        <line x1="15" y1="6" x2="15" y2="21"/>
                    </svg>
                </div>
                <div class="action-text">
                    <div class="title">Fleet Map</div>
                    <div class="desc">View SIM800L online status</div>
                </div>
            </a>

            <a href="profile.php" class="action-card blue">
                <div class="action-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>
                </div>
                <div class="action-text">
                    <div class="title">My Profile</div>
                    <div class="desc">View & edit your details</div>
                </div>
            </a>

        </div>

    </div><!-- /content -->

</div><!-- /main -->

<script>window.RIDER_ID = <?php echo $rider_id; ?>;</script>
<script src="../assets/js/rider-session-ping.js"></script>
</body>
</html>