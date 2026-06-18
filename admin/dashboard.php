<?php

session_start();

if(!isset($_SESSION['admin']))
{
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/daily-rider-reset.php");
maybe_run_daily_rider_reset($conn);

$pending =
$conn->query(
"SELECT COUNT(*) total
 FROM users
 WHERE status='pending'"
)->fetch_assoc()['total'];

$riders =
$conn->query(
"SELECT COUNT(*) total
 FROM users
 WHERE role='rider'"
)->fetch_assoc()['total'];

$admin_name = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Administrator';
$current_date = date('l, F j, Y');

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
    --border-color: #2a3a52;
    --text-primary: #f0f4ff;
    --text-secondary: #94a3b8;
    --text-muted: #4a5a72;
    --accent-blue: #3b82f6;
    --accent-blue-light: #60a5fa;
    --accent-green: #10b981;
    --accent-red: #ef4444;
    --accent-orange: #f59e0b;
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

/* ===== SIDEBAR ===== */
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

/* ===== MOBILE SIDEBAR CONTROLS ===== */
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

/* ===== MAIN CONTENT ===== */
.main {
    margin-left: var(--sidebar-width);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    flex: 1;
}

/* ===== HEADER ===== */
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

/* ===== CONTENT ===== */
.content {
    padding: 28px;
    flex: 1;
}

.section-header {
    margin-bottom: 20px;
}

.section-header h2 {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
}

/* ===== STAT CARDS ===== */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
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

/* ===== QUICK ACTIONS ===== */
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

/* ============================================================ */
/* ===== RESPONSIVE ===== */

/* Tablets */
@media (max-width: 1024px) {
    .content {
        padding: 20px;
    }
}

/* Mobile */
@media (max-width: 768px) {
    :root {
        --sidebar-width: 280px;
        --header-height: 56px;
    }

    /* Sidebar toggle */
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

    /* Header */
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

    /* Content */
    .content {
        padding: 16px 12px;
    }

    .section-header h2 {
        font-size: 11px;
    }

    /* Stats - 2 columns on mobile */
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

    /* Actions */
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
}

/* Small phones */
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
}

/* Very small phones */
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

<!-- ===== SIDEBAR TOGGLE (Mobile) ===== -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
</button>

<!-- ===== SIDEBAR OVERLAY ===== -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ===== SIDEBAR ===== -->
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
            <?php if($pending > 0): ?>
            <span class="badge"><?php echo $pending; ?></span>
            <?php endif; ?>
        </a>
        <a href="map.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                <line x1="9" y1="3" x2="9" y2="18"/>
                <line x1="15" y1="6" x2="15" y2="21"/>
            </svg>
            Live Map
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

<!-- ===== MAIN ===== -->
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
            <div class="stat-card amber">
                <div class="stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="stat-value"><?php echo $pending; ?></div>
                <div class="stat-label">Pending Riders</div>
                <div class="stat-sublabel">Awaiting approval</div>
            </div>

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

<script>
(function() {
    // ===== SIDEBAR TOGGLE =====
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

    // Close sidebar when a nav link is clicked (mobile)
    document.querySelectorAll('.nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });

    // Close sidebar on window resize if open
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && sidebar.classList.contains('open')) {
            closeSidebar();
        }
    });
})();
</script>

</body>
</html>