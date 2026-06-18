<?php
session_start();

if(!isset($_SESSION['rider'])){
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");

$id = (int)$_SESSION['rider'];

$success = '';
$error   = '';

// ── Handle form submissions ──────────────────────────────────────────────────

if($_SERVER['REQUEST_METHOD'] === 'POST'){

    if(isset($_POST['action'])){

        // ── Update profile details ──────────────────────────────────────────
        if($_POST['action'] === 'update_profile'){

            $fullname = trim($_POST['fullname']);
            $email    = trim($_POST['email']);
            $phone    = trim($_POST['phone']);

            if(empty($fullname) || empty($email)){
                $error = 'Full name and email are required.';
            } else {
                $stmt = $conn->prepare(
                    "UPDATE users SET fullname=?, email=?, phone=? WHERE id=?"
                );
                $stmt->bind_param('sssi', $fullname, $email, $phone, $id);
                if($stmt->execute()){
                    $success = 'Profile updated successfully.';
                    $_SESSION['rider_name'] = $fullname;
                } else {
                    $error = 'Failed to update profile. Please try again.';
                }
                $stmt->close();
            }
        }

        // ── Change password ─────────────────────────────────────────────────
        if($_POST['action'] === 'change_password'){

            $current  = $_POST['current_password'];
            $new      = $_POST['new_password'];
            $confirm  = $_POST['confirm_password'];

            $row = $conn->query(
                "SELECT password FROM users WHERE id=$id LIMIT 1"
            )->fetch_assoc();

            if(!password_verify($current, $row['password'])){
                $error = 'Current password is incorrect.';
            } elseif(strlen($new) < 8){
                $error = 'New password must be at least 8 characters.';
            } elseif($new !== $confirm){
                $error = 'New passwords do not match.';
            } else {
                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $stmt   = $conn->prepare(
                    "UPDATE users SET password=? WHERE id=?"
                );
                $stmt->bind_param('si', $hashed, $id);
                if($stmt->execute()){
                    $success = 'Password changed successfully.';
                } else {
                    $error = 'Failed to change password. Please try again.';
                }
                $stmt->close();
            }
        }
    }
}

// ── Re-fetch fresh user data ─────────────────────────────────────────────────

$user = $conn->query(
    "SELECT fullname, email, phone FROM users WHERE id=$id LIMIT 1"
)->fetch_assoc();

$rider_name = $user['fullname'] ?? 'Rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — MotoRider</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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

/* ── Content ─────────────────────────────────── */

.content {
    padding: 32px 28px;
    max-width: 680px;
    width: 100%;
}

/* ── Alert banners ───────────────────────────── */

.alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 24px;
}

.alert svg {
    width: 16px; height: 16px;
    flex-shrink: 0;
    margin-top: 1px;
}

.alert-success {
    background: rgba(16,185,129,0.1);
    border: 1px solid rgba(16,185,129,0.3);
    color: #34D399;
}

.alert-error {
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.3);
    color: #F87171;
}

/* ── Profile avatar header ───────────────────── */

.profile-header {
    display: flex;
    align-items: center;
    gap: 20px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 24px;
}

.profile-avatar {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-green), #059669);
    display: flex; align-items: center; justify-content: center;
    font-size: 26px;
    font-weight: 700;
    color: #fff;
    flex-shrink: 0;
    letter-spacing: -1px;
}

.profile-meta .full-name {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.2px;
}

.profile-meta .rider-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(16,185,129,0.12);
    color: var(--accent-green);
    font-size: 11px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 20px;
    margin-top: 6px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.profile-meta .rider-badge span {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent-green);
    display: inline-block;
}

/* ── Cards ───────────────────────────────────── */

.card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 20px;
    overflow: hidden;
}

.card-header {
    padding: 18px 24px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.card-icon.green  { background: rgba(16,185,129,0.12); color: var(--accent-green); }
.card-icon.amber  { background: rgba(245,158,11,0.12);  color: var(--accent-amber); }
.card-icon svg    { width: 15px; height: 15px; }

.card-header-text .card-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.card-header-text .card-subtitle {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 1px;
}

.card-body {
    padding: 20px 24px 24px;
}

.card-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 0 24px;
}

/* ── Form ────────────────────────────────────── */

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.6px;
}

.form-input {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text-primary);
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 14px;
    font-weight: 500;
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
    -webkit-appearance: none;
}

.form-input:focus {
    border-color: var(--accent-green);
    box-shadow: 0 0 0 3px rgba(16,185,129,0.12);
}

.form-input::placeholder {
    color: var(--text-muted);
    font-weight: 400;
}

/* Password input wrapper */
.input-wrapper {
    position: relative;
}

.input-wrapper .form-input {
    padding-right: 42px;
}

.toggle-pw {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s;
}

.toggle-pw:hover { color: var(--text-secondary); }
.toggle-pw svg   { width: 15px; height: 15px; pointer-events: none; }

/* Password strength bar */
.pw-strength {
    height: 3px;
    border-radius: 3px;
    background: var(--border);
    margin-top: 6px;
    overflow: hidden;
}

.pw-strength-bar {
    height: 100%;
    border-radius: 3px;
    width: 0%;
    transition: width 0.3s, background 0.3s;
}

.pw-hint {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* Form footer */
.form-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    margin-top: 20px;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 20px;
    border-radius: 8px;
    font-family: 'Inter', system-ui, sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: opacity 0.15s, transform 0.1s;
    text-decoration: none;
}

.btn:active { transform: scale(0.98); }

.btn-primary {
    background: var(--accent-green);
    color: #fff;
}

.btn-primary:hover { opacity: 0.88; }

.btn-ghost {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1px solid var(--border);
}

.btn-ghost:hover {
    background: var(--bg-card-hover);
    color: var(--text-primary);
}

.btn svg { width: 14px; height: 14px; }

/* ── Responsive ──────────────────────────────── */

@media (max-width: 768px) {
    :root { --sidebar-w: 0px; }
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; }
    .topbar { padding: 0 16px; }
    .content { padding: 20px 16px; }

    .form-grid { grid-template-columns: 1fr; }
    .form-group.full { grid-column: 1; }

    .profile-header { padding: 18px; gap: 14px; }
    .profile-avatar { width: 52px; height: 52px; font-size: 20px; }
    .profile-meta .full-name { font-size: 16px; }

    .card-body  { padding: 16px 16px 20px; }
    .card-header { padding: 16px 16px 0; }

    .form-footer { flex-direction: column-reverse; }
    .form-footer .btn { width: 100%; justify-content: center; }
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

        <a href="map.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/>
                <line x1="9" y1="3" x2="9" y2="18"/>
                <line x1="15" y1="6" x2="15" y2="21"/>
            </svg>
            My Location
        </a>

        <a href="profile.php" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            My Profile
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
            <h1>My Profile</h1>
            <p>Manage your personal details and password</p>
        </div>
    </header>

    <div class="content">

        <!-- Alert banners -->
        <?php if($success): ?>
        <div class="alert alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <?php if($error): ?>
        <div class="alert alert-error">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <!-- Profile header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($rider_name, 0, 1)); ?>
            </div>
            <div class="profile-meta">
                <div class="full-name"><?php echo htmlspecialchars($rider_name); ?></div>
                <div class="rider-badge"><span></span> Active Rider</div>
            </div>
        </div>

        <!-- ── Edit Profile Card ───────────────────────────────────────────── -->
        <div class="card">

            <div class="card-header">
                <div class="card-icon green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </div>
                <div class="card-header-text">
                    <div class="card-title">Personal Details</div>
                    <div class="card-subtitle">Update your name, email, and phone number</div>
                </div>
            </div>

            <div class="card-body">
                <form method="POST" action="profile.php" id="profile-form">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="form-grid">

                        <div class="form-group full">
                            <label class="form-label" for="fullname">Full Name</label>
                            <input
                                class="form-input"
                                type="text"
                                id="fullname"
                                name="fullname"
                                value="<?php echo htmlspecialchars($user['fullname']); ?>"
                                placeholder="Your full name"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">Email Address</label>
                            <input
                                class="form-input"
                                type="email"
                                id="email"
                                name="email"
                                value="<?php echo htmlspecialchars($user['email']); ?>"
                                placeholder="you@example.com"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="phone">Phone Number</label>
                            <input
                                class="form-input"
                                type="tel"
                                id="phone"
                                name="phone"
                                value="<?php echo htmlspecialchars($user['phone']); ?>"
                                placeholder="+63 9XX XXX XXXX"
                            >
                        </div>

                    </div>

                    <div class="form-footer">
                        <button type="reset" class="btn btn-ghost">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="1 4 1 10 7 10"/>
                                <path d="M3.51 15a9 9 0 1 0 .49-3.35"/>
                            </svg>
                            Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                <polyline points="17 21 17 13 7 13 7 21"/>
                                <polyline points="7 3 7 8 15 8"/>
                            </svg>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>

        </div>

        <!-- ── Change Password Card ────────────────────────────────────────── -->
        <div class="card">

            <div class="card-header">
                <div class="card-icon amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <div class="card-header-text">
                    <div class="card-title">Change Password</div>
                    <div class="card-subtitle">Must be at least 8 characters long</div>
                </div>
            </div>

            <div class="card-body">
                <form method="POST" action="profile.php" id="password-form">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-grid">

                        <div class="form-group full">
                            <label class="form-label" for="current_password">Current Password</label>
                            <div class="input-wrapper">
                                <input
                                    class="form-input"
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    placeholder="Enter your current password"
                                    required
                                >
                                <button type="button" class="toggle-pw" data-target="current_password" aria-label="Toggle visibility">
                                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <div class="input-wrapper">
                                <input
                                    class="form-input"
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    placeholder="At least 8 characters"
                                    required
                                    oninput="checkStrength(this.value)"
                                >
                                <button type="button" class="toggle-pw" data-target="new_password" aria-label="Toggle visibility">
                                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="pw-strength">
                                <div class="pw-strength-bar" id="strength-bar"></div>
                            </div>
                            <div class="pw-hint" id="strength-hint">Enter a new password</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <div class="input-wrapper">
                                <input
                                    class="form-input"
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    placeholder="Repeat new password"
                                    required
                                >
                                <button type="button" class="toggle-pw" data-target="confirm_password" aria-label="Toggle visibility">
                                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                    </div>

                    <div class="form-footer">
                        <button type="reset" class="btn btn-ghost" onclick="resetStrength()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="1 4 1 10 7 10"/>
                                <path d="M3.51 15a9 9 0 1 0 .49-3.35"/>
                            </svg>
                            Clear
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            Update Password
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </div><!-- /.content -->
</div><!-- /.main -->

<script>

// ── Toggle password visibility ───────────────────────────────────────────────

document.querySelectorAll('.toggle-pw').forEach(function(btn){
    btn.addEventListener('click', function(){
        var targetId = this.getAttribute('data-target');
        var input    = document.getElementById(targetId);
        var isText   = input.type === 'text';
        input.type   = isText ? 'password' : 'text';

        // swap icon
        var svg = this.querySelector('svg');
        if(isText){
            svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        } else {
            svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
        }
    });
});

// ── Password strength meter ──────────────────────────────────────────────────

function checkStrength(val){
    var bar   = document.getElementById('strength-bar');
    var hint  = document.getElementById('strength-hint');
    var score = 0;

    if(val.length >= 8)  score++;
    if(val.length >= 12) score++;
    if(/[A-Z]/.test(val)) score++;
    if(/[0-9]/.test(val)) score++;
    if(/[^A-Za-z0-9]/.test(val)) score++;

    var levels = [
        { pct:'0%',   color:'transparent',        text:'Enter a new password' },
        { pct:'25%',  color:'#EF4444',             text:'Weak' },
        { pct:'50%',  color:'#F59E0B',             text:'Fair' },
        { pct:'75%',  color:'#3B82F6',             text:'Good' },
        { pct:'100%', color:'#10B981',             text:'Strong' }
    ];

    var idx = val.length === 0 ? 0 : Math.min(score, 4);
    bar.style.width      = levels[idx].pct;
    bar.style.background = levels[idx].color;
    hint.textContent     = levels[idx].text;
    hint.style.color     = idx === 0 ? 'var(--text-muted)' : levels[idx].color;
}

function resetStrength(){
    checkStrength('');
}

// Auto-dismiss alert after 5 s
var alert = document.querySelector('.alert');
if(alert){
    setTimeout(function(){
        alert.style.transition = 'opacity 0.4s';
        alert.style.opacity    = '0';
        setTimeout(function(){ alert.remove(); }, 400);
    }, 5000);
}

</script>
<script>window.RIDER_ID = <?php echo $id; ?>;</script>
<script src="../assets/js/rider-location-tracker.js"></script>
</body>
</html>