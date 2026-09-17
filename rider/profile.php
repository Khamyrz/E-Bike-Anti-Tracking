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
                    $rider_name = $fullname;
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

// Get online status
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
<title>My Profile — MotoRider</title>
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
    padding-bottom: 80px; /* Space for bottom nav */
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

/* ── Main Content ───────────────────────────── */

.main {
    flex: 1;
    padding: 16px 16px 20px;
    max-width: 100%;
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
    margin-bottom: 20px;
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
    margin-bottom: 20px;
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
    padding: 18px 20px 0;
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
.card-icon svg    { width: 15px; height: 15px; stroke: currentColor; stroke-width: 2; fill: none; }

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
    padding: 20px 20px 24px;
}

.card-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 0 20px;
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
.toggle-pw svg   { width: 15px; height: 15px; pointer-events: none; stroke: currentColor; stroke-width: 2; fill: none; }

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

.btn svg { width: 14px; height: 14px; stroke: currentColor; stroke-width: 2; fill: none; }

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

/* ── Desktop Sidebar ─────────────────────────── */

.sidebar {
    display: none;
}

/* ── Responsive Breakpoints ──────────────────── */

/* Tablets and small laptops */
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
    
    .drawer-overlay, .drawer {
        display: none !important;
    }
    
    .form-grid {
        grid-template-columns: 1fr 1fr;
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
}

/* ── Mobile Styles ───────────────────────────── */

@media (max-width: 768px) {
    .menu-toggle {
        display: flex !important;
    }
    
    .topbar-left .brand small {
        display: none;
    }
    
    .status-indicator span:not(.status-dot) {
        display: none;
    }
    
    .profile-header {
        padding: 18px;
        gap: 14px;
        flex-wrap: wrap;
    }
    
    .profile-avatar {
        width: 52px;
        height: 52px;
        font-size: 20px;
    }
    
    .profile-meta .full-name {
        font-size: 16px;
    }
    
    .card-body {
        padding: 16px 16px 20px;
    }
    
    .card-header {
        padding: 16px 16px 0;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-group.full {
        grid-column: 1;
    }
    
    .form-footer {
        flex-direction: column-reverse;
    }
    
    .form-footer .btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 380px) {
    .profile-header {
        padding: 14px;
        gap: 10px;
    }
    
    .profile-avatar {
        width: 44px;
        height: 44px;
        font-size: 17px;
    }
    
    .profile-meta .full-name {
        font-size: 14px;
    }
    
    .card-body {
        padding: 12px 12px 16px;
    }
    
    .card-header {
        padding: 12px 12px 0;
    }
    
    .form-input {
        padding: 8px 12px;
        font-size: 13px;
    }
}

/* ── Touch optimizations ────────────────────── */
@media (hover: none) {
    .btn:active { transform: scale(0.96); }
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
        
        <a href="map.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
            Track Bike
        </a>
        
        <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);padding:12px 12px 4px;margin-top:4px;">Account</div>
        
        <a href="profile.php" class="nav-item-drawer active">
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
            Moto<span>Rider</span>
            <small>Profile</small>
        </div>
    </div>
    <div class="topbar-right">
        <div class="status-indicator">
            <span class="status-dot"></span>
            <span><?php echo htmlspecialchars($online_label); ?></span>
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
        
        <a href="map.php" class="nav-item-drawer">
            <svg viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
            Track Bike
        </a>
        
        <div class="nav-section" style="margin-top:12px;">Account</div>
        
        <a href="profile.php" class="nav-item-drawer active">
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

<!-- ── Main Content ───────────────────────────── -->
<main class="main">

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

</main>

<!-- ── Bottom Navigation ──────────────────────── -->
<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        <span>Home</span>
    </a>
    
    <a href="map.php" class="nav-item">
        <svg viewBox="0 0 24 24"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
        <span>Map</span>
    </a>
    
    <a href="profile.php" class="nav-item active">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>Profile</span>
    </a>
    
    <a href="../auth/logout.php" class="nav-item logout-item">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Logout</span>
    </a>
</nav>

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

// ── Toggle password visibility ───────────────────────────────────────────────

document.querySelectorAll('.toggle-pw').forEach(function(btn){
    btn.addEventListener('click', function(){
        var targetId = this.getAttribute('data-target');
        var input    = document.getElementById(targetId);
        var isText   = input.type === 'text';
        input.type   = isText ? 'password' : 'text';

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

// Rider ID for session ping
window.RIDER_ID = <?php echo $id; ?>;
</script>

<?php if(file_exists("../assets/js/rider-session-ping.js")): ?>
<script src="../assets/js/rider-session-ping.js"></script>
<?php endif; ?>

<!-- Rider Global Alert System -->
<script src="../assets/js/rider-global-alert.js"></script>
</body>
</html>