<?php

session_start();

if(!isset($_SESSION['admin']))
{
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/daily-rider-reset.php");
require_once("../includes/face-recognition.php");

function riders_face_preview_src($face_data)
{
    $parsed = face_parse_stored_data($face_data);
    if ($parsed && !empty($parsed['image'])) {
        return $parsed['image'];
    }

    $face_data = trim((string)$face_data);
    if (strpos($face_data, 'data:image') === 0) {
        return $face_data;
    }

    return '';
}

$auto_reset_result = maybe_run_daily_rider_reset($conn);

function get_next_ebike_id($conn)
{
    $result = $conn->query("
        SELECT MAX(CAST(ebike_id AS UNSIGNED)) AS max_id
        FROM users
        WHERE role = 'rider' AND ebike_id IS NOT NULL AND ebike_id REGEXP '^[0-9]+$'
    ");
    $row  = $result->fetch_assoc();
    $next = ((int)($row['max_id'] ?? 0)) + 1;
    return str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function backfill_missing_ebike_ids($conn)
{
    $legacy = $conn->query("
        SELECT id FROM users
        WHERE role = 'rider' AND ebike_id IS NULL
        ORDER BY id ASC
    ");

    while($row = $legacy->fetch_assoc())
    {
        $ebike_id = get_next_ebike_id($conn);
        $stmt = $conn->prepare("UPDATE users SET ebike_id = ? WHERE id = ?");
        $stmt->bind_param('si', $ebike_id, $row['id']);
        $stmt->execute();
        $stmt->close();
    }
}

backfill_missing_ebike_ids($conn);

$flash = $_SESSION['riders_flash'] ?? '';
if($auto_reset_result && $flash === '')
{
    $flash = 'Daily 11:00 PM reset completed. All riders cleared — E-Bike IDs restart at 001 tomorrow.';
}
unset($_SESSION['riders_flash']);

if(isset($_GET['delete']))
{
    $id = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'rider'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['riders_flash'] = 'Rider deleted successfully.';
    header("Location: riders.php");
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))
{
    $action = $_POST['action'];

    if($action === 'create_rider')
    {
        $fullname  = trim($_POST['fullname'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $face_data = trim($_POST['face_data'] ?? '');

        if($fullname === '' || $address === '' || $phone === '' || $face_data === '')
        {
            $_SESSION['riders_flash'] = 'Full name, address, phone, and face capture are required.';
        }
        elseif(!face_parse_stored_data($face_data))
        {
            $_SESSION['riders_flash'] = 'Face capture failed validation. Please capture the face again.';
        }
        else
        {
            $ebike_id      = get_next_ebike_id($conn);
            $email         = 'ebike' . $ebike_id . '@rider.local';
            $temp_password = bin2hex(random_bytes(4));
            $password      = password_hash($temp_password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users (fullname, email, phone, address, ebike_id, face_data, password, role, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'rider', 'approved')
            ");
            $stmt->bind_param('sssssss', $fullname, $email, $phone, $address, $ebike_id, $face_data, $password);

            if($stmt->execute())
            {
                $_SESSION['riders_flash'] = 'Rider registered. E-Bike ID: ' . $ebike_id
                    . ' | Login email: ' . $email . ' | Password: ' . $temp_password;
            }
            else
            {
                $_SESSION['riders_flash'] = 'Failed to register rider. Please try again.';
            }
            $stmt->close();
        }

        header("Location: riders.php");
        exit;
    }

    if($action === 'update_rider')
    {
        $id        = (int)($_POST['rider_id'] ?? 0);
        $fullname  = trim($_POST['fullname'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $face_data = trim($_POST['face_data'] ?? '');

        if($id <= 0 || $fullname === '' || $address === '' || $phone === '')
        {
            $_SESSION['riders_flash'] = 'Full name, address, and phone are required.';
        }
        else
        {
            if($face_data !== '')
            {
                if(!face_parse_stored_data($face_data))
                {
                    $_SESSION['riders_flash'] = 'Face capture failed validation. Please capture the face again.';
                }
                else
                {
                    $stmt = $conn->prepare("
                        UPDATE users
                        SET fullname = ?, address = ?, phone = ?, face_data = ?
                        WHERE id = ? AND role = 'rider'
                    ");
                    $stmt->bind_param('ssssi', $fullname, $address, $phone, $face_data, $id);
                    if($stmt->execute() && $stmt->affected_rows >= 0)
                    {
                        $_SESSION['riders_flash'] = 'Rider updated successfully.';
                    }
                    else
                    {
                        $_SESSION['riders_flash'] = 'Failed to update rider.';
                    }
                    $stmt->close();
                }
            }
            else
            {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET fullname = ?, address = ?, phone = ?
                    WHERE id = ? AND role = 'rider'
                ");
                $stmt->bind_param('sssi', $fullname, $address, $phone, $id);
                if($stmt->execute() && $stmt->affected_rows >= 0)
                {
                    $_SESSION['riders_flash'] = 'Rider updated successfully.';
                }
                else
                {
                    $_SESSION['riders_flash'] = 'Failed to update rider.';
                }
                $stmt->close();
            }
        }

        header("Location: riders.php");
        exit;
    }

    if(isset($_POST['generate_device']))
    {
        $rider_id = (int)$_POST['rider_id'];
        $api_token = bin2hex(random_bytes(16));

        $check = $conn->prepare("
            SELECT id, ebike_id FROM users
            WHERE id = ? AND role = 'rider' AND status = 'approved'
        ");
        $check->bind_param('i', $rider_id);
        $check->execute();
        $approved_rider = $check->get_result()->fetch_assoc();
        $check->close();

        if($approved_rider)
        {
            $ebike_num = $approved_rider['ebike_id'] ?: str_pad((string)$rider_id, 3, '0', STR_PAD_LEFT);
            $device_id = 'EBIKE' . str_pad((string)$ebike_num, 4, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("
                INSERT INTO devices (rider_id, device_id, api_token)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE device_id = VALUES(device_id), api_token = VALUES(api_token)
            ");
            $stmt->bind_param('iss', $rider_id, $device_id, $api_token);
            $stmt->execute();
            $stmt->close();
        }

        header("Location: riders.php");
        exit;
    }
}

$riders = $conn->query("
    SELECT u.*, d.device_id, d.api_token
    FROM users u
    LEFT JOIN devices d ON d.rider_id = u.id
    WHERE u.role = 'rider'
    ORDER BY CAST(u.ebike_id AS UNSIGNED) ASC, u.id ASC
");

$admin_name = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Administrator';

$total = 0;
$rows  = [];
while($row = $riders->fetch_assoc()) {
    $rows[] = $row;
    $total++;
}

$next_ebike_id = get_next_ebike_id($conn);
$reset_now  = new DateTime('now', new DateTimeZone(RIDER_RESET_TIMEZONE));
$next_reset = rider_reset_next_scheduled($reset_now);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Manage Riders — MotoAdmin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
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
    padding: 24px 28px;
    flex: 1;
}

/* Flash Message */
.flash {
    padding: 14px 18px;
    border-radius: var(--radius-sm);
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.25);
    color: #93c5fd;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.flash::before {
    content: 'ℹ';
    font-size: 18px;
}

/* Stats Bar */
.stats-bar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 24px;
    align-items: center;
}

.stat-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 20px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    font-size: 13px;
    color: var(--text-secondary);
}

.stat-chip .num {
    font-weight: 700;
    color: var(--accent-blue);
    font-variant-numeric: tabular-nums;
}

.stat-chip .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--accent-blue);
}

.btn-primary {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    border: none;
    background: var(--accent-blue);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}

.btn-primary:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.btn-primary:active {
    transform: translateY(0);
}

.btn-primary svg {
    width: 16px;
    height: 16px;
}

/* ===== RIDER CARDS (Mobile First) ===== */
.rider-grid {
    display: none;
    gap: 12px;
}

.rider-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 16px;
    transition: all 0.2s;
}

.rider-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.rider-card .face {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    border: 2px solid var(--border-color);
    background: var(--bg-input);
    flex-shrink: 0;
}

.rider-card .face-placeholder {
    width: 48px;
    height: 48px;
    border-radius: var(--radius-sm);
    border: 2px dashed var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: var(--text-muted);
    background: var(--bg-input);
    flex-shrink: 0;
}

.rider-card .name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
}

.rider-card .id {
    font-size: 12px;
    color: var(--text-muted);
}

.rider-card-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 16px;
    margin-bottom: 12px;
}

.rider-card-body .item {
    display: flex;
    flex-direction: column;
}

.rider-card-body .item .label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    font-weight: 600;
}

.rider-card-body .item .value {
    font-size: 13px;
    color: var(--text-secondary);
    word-break: break-word;
}

.rider-card-body .item .ebike {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 4px;
    background: rgba(59, 130, 246, 0.12);
    color: var(--accent-blue);
    font-weight: 700;
    font-size: 13px;
}

.rider-card-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    padding-top: 12px;
    border-top: 1px solid var(--border-color);
}

.rider-card-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}

.rider-card-actions .btn:active {
    transform: scale(0.96);
}

.btn-edit {
    background: rgba(59, 130, 246, 0.15);
    color: var(--accent-blue);
    border: 1px solid rgba(59, 130, 246, 0.2);
}

.btn-delete {
    background: rgba(239, 68, 68, 0.1);
    color: var(--accent-red);
    border: 1px solid rgba(239, 68, 68, 0.2);
}

.btn-device-card {
    background: rgba(16, 185, 129, 0.1);
    color: var(--accent-green);
    border: 1px solid rgba(16, 185, 129, 0.2);
    font-size: 11px;
    padding: 4px 12px;
}

.device-info {
    margin-top: 8px;
    padding: 8px 12px;
    background: var(--bg-input);
    border-radius: var(--radius-sm);
    font-size: 11px;
    color: var(--text-muted);
    word-break: break-all;
}

.device-info strong {
    color: var(--text-secondary);
}

/* ===== TABLE (Desktop) ===== */
.table-wrapper {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    overflow: hidden;
}

.table-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.table-header h2 {
    font-size: 14px;
    font-weight: 600;
}

.table-header span {
    font-size: 12px;
    color: var(--text-muted);
}

.table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 700px;
}

thead th {
    padding: 12px 16px;
    text-align: left;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--text-muted);
    background: rgba(0, 0, 0, 0.2);
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}

tbody tr {
    border-bottom: 1px solid var(--border-color);
    transition: background 0.15s;
}

tbody tr:last-child {
    border-bottom: none;
}

tbody tr:hover {
    background: rgba(255, 255, 255, 0.02);
}

tbody td {
    padding: 12px 16px;
    font-size: 13px;
    color: var(--text-secondary);
    vertical-align: middle;
}

.table-face {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    border: 1px solid var(--border-color);
    background: var(--bg-input);
}

.table-face-placeholder {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    border: 1px dashed var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    color: var(--text-muted);
    background: var(--bg-input);
}

.table-name {
    font-weight: 500;
    color: var(--text-primary);
}

.table-id {
    font-size: 11px;
    color: var(--text-muted);
}

.table-ebike {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 4px;
    background: rgba(59, 130, 246, 0.1);
    color: var(--accent-blue);
    font-weight: 700;
    font-size: 12px;
}

.table-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}

.table-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: var(--radius-sm);
    font-size: 11px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}

.table-actions .btn:active {
    transform: scale(0.95);
}

.table-actions .btn svg {
    width: 11px;
    height: 11px;
}

.table-device {
    font-size: 11px;
    color: var(--text-muted);
}

.table-device strong {
    color: var(--text-secondary);
}

/* ===== EMPTY STATE ===== */
.empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--text-muted);
}

.empty-state svg {
    width: 48px;
    height: 48px;
    margin-bottom: 16px;
    opacity: 0.3;
}

.empty-state p {
    font-size: 14px;
    margin-bottom: 16px;
}

/* ===== MODALS ===== */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

.modal-overlay.open {
    display: flex;
}

.modal {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    width: 100%;
    max-width: 520px;
    max-height: 92vh;
    overflow-y: auto;
    animation: modal-in 0.25s ease;
}

@keyframes modal-in {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(10px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.modal-header {
    padding: 20px 24px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header h3 {
    font-size: 17px;
    font-weight: 700;
}

.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 4px;
    transition: color 0.2s;
}

.modal-close:hover {
    color: var(--text-primary);
}

.modal-close svg {
    width: 20px;
    height: 20px;
}

.modal-body {
    padding: 20px 24px 24px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--text-secondary);
    margin-bottom: 6px;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 10px 14px;
    background: var(--bg-input);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    color: var(--text-primary);
    font-family: inherit;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group textarea:focus {
    border-color: var(--accent-blue);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 60px;
}

.ebike-preview {
    padding: 10px 14px;
    background: rgba(59, 130, 246, 0.08);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: var(--radius-sm);
    font-size: 14px;
    color: var(--accent-blue);
    font-weight: 600;
}

.face-capture {
    border: 1px solid var(--border-color);
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: #000;
    position: relative;
}

.face-capture video,
.face-capture canvas,
.face-capture img {
    width: 100%;
    display: block;
    max-height: 200px;
    object-fit: cover;
}

.face-capture canvas,
.face-capture img {
    display: none;
}

.face-capture.has-photo video {
    display: none;
}
.face-capture.has-photo canvas,
.face-capture.has-photo img {
    display: block;
}

.face-actions {
    display: flex;
    gap: 8px;
    margin-top: 10px;
}

.face-actions .btn-face {
    flex: 1;
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}

.face-actions .btn-face.primary {
    background: var(--accent-blue);
    border-color: var(--accent-blue);
    color: #fff;
}

.face-actions .btn-face:active {
    transform: scale(0.96);
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
}

.modal-footer .btn {
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.2s;
    -webkit-tap-highlight-color: transparent;
}

.modal-footer .btn:active {
    transform: scale(0.96);
}

.btn-cancel {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.btn-submit {
    background: var(--accent-blue);
    color: #fff;
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
        padding: 14px 12px;
    }

    .flash {
        font-size: 12px;
        padding: 10px 14px;
        margin-bottom: 14px;
    }

    /* Stats */
    .stats-bar {
        gap: 8px;
        margin-bottom: 16px;
    }

    .stat-chip {
        font-size: 12px;
        padding: 6px 12px;
        flex: 1 1 auto;
        justify-content: center;
    }

    .btn-primary {
        width: 100%;
        justify-content: center;
        padding: 12px;
        font-size: 14px;
        margin-left: 0;
        margin-top: 4px;
    }

    /* Show cards, hide table on mobile */
    .rider-grid {
        display: grid;
    }

    .table-wrapper {
        display: none;
    }

    /* Modals mobile */
    .modal {
        max-width: 100%;
        margin: 10px;
        border-radius: var(--radius);
        max-height: 90vh;
    }

    .modal-header {
        padding: 16px 18px 0;
    }

    .modal-header h3 {
        font-size: 15px;
    }

    .modal-body {
        padding: 16px 18px 18px;
    }

    .modal-footer {
        flex-direction: column-reverse;
        gap: 8px;
    }

    .modal-footer .btn {
        width: 100%;
        justify-content: center;
        padding: 12px;
    }

    .face-capture video,
    .face-capture canvas,
    .face-capture img {
        max-height: 160px;
    }

    /* Rider card mobile */
    .rider-card-body {
        grid-template-columns: 1fr 1fr;
        gap: 6px 12px;
    }

    .rider-card-body .item .value {
        font-size: 12px;
    }

    .rider-card-actions .btn {
        font-size: 11px;
        padding: 5px 10px;
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
        padding: 10px 8px;
    }

    .stat-chip {
        font-size: 10px;
        padding: 4px 10px;
        flex: 1 1 100%;
    }

    .rider-card {
        padding: 12px;
    }

    .rider-card .face {
        width: 40px;
        height: 40px;
    }

    .rider-card .name {
        font-size: 13px;
    }

    .rider-card-body {
        grid-template-columns: 1fr 1fr;
        gap: 4px 8px;
    }

    .rider-card-body .item .label {
        font-size: 9px;
    }

    .rider-card-body .item .value {
        font-size: 11px;
    }

    .rider-card-actions .btn {
        font-size: 10px;
        padding: 4px 8px;
    }

    .rider-card-actions .btn svg {
        width: 10px;
        height: 10px;
    }

    .modal-body {
        padding: 12px 14px 14px;
    }

    .form-group input,
    .form-group textarea {
        font-size: 13px;
        padding: 8px 12px;
    }

    .sidebar {
        width: 260px;
    }
}

/* Show table on larger screens, hide cards */
@media (min-width: 769px) {
    .rider-grid {
        display: none !important;
    }
    .table-wrapper {
        display: block;
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
        <a href="dashboard.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            Dashboard
        </a>

        <div class="nav-label">Management</div>
        <a href="riders.php" class="nav-link active">
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
            <h1>Manage Riders</h1>
            <div class="subtitle">Register riders with facial recognition &amp; auto-assigned IDs</div>
        </div>
        <div class="header-right">
            <div class="status-badge">
                <span class="status-dot"></span>
                Online
            </div>
        </div>
    </header>

    <div class="content">
        <?php if($flash): ?>
        <div class="flash"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="stats-bar">
            <div class="stat-chip">
                <span class="dot"></span>
                Riders <span class="num"><?php echo $total; ?></span>
            </div>
            <div class="stat-chip">
                <span class="dot"></span>
                Next ID <span class="num"><?php echo htmlspecialchars($next_ebike_id); ?></span>
            </div>
            <div class="stat-chip">
                <span class="dot"></span>
                Reset <span class="num"><?php echo $next_reset->format('M j, g:i A'); ?></span>
            </div>
            <button class="btn-primary" id="btn-open-create">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Register Rider
            </button>
        </div>

        <!-- ===== MOBILE: RIDER CARDS ===== -->
        <div class="rider-grid" id="riderGrid">
            <?php if(empty($rows)): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                </svg>
                <p>No riders registered yet.</p>
                <button class="btn-primary" id="btn-open-create-empty">Register First Rider</button>
            </div>
            <?php else: ?>
            <?php foreach($rows as $row): ?>
            <?php $face_preview = riders_face_preview_src($row['face_data'] ?? ''); ?>
            <div class="rider-card">
                <div class="rider-card-header">
                    <?php if($face_preview !== ''): ?>
                    <img class="face" src="<?php echo htmlspecialchars($face_preview); ?>" alt="Face">
                    <?php else: ?>
                    <div class="face-placeholder">N/A</div>
                    <?php endif; ?>
                    <div>
                        <div class="name"><?php echo htmlspecialchars($row['fullname']); ?></div>
                        <div class="id">#<?php echo (int)$row['id']; ?></div>
                    </div>
                </div>
                <div class="rider-card-body">
                    <div class="item">
                        <span class="label">Address</span>
                        <span class="value"><?php echo htmlspecialchars($row['address'] ?? '—'); ?></span>
                    </div>
                    <div class="item">
                        <span class="label">Phone</span>
                        <span class="value"><?php echo htmlspecialchars($row['phone'] ?? '—'); ?></span>
                    </div>
                    <div class="item">
                        <span class="label">E-Bike ID</span>
                        <span class="ebike"><?php echo htmlspecialchars($row['ebike_id'] ?? '—'); ?></span>
                    </div>
                    <div class="item">
                        <span class="label">Device</span>
                        <span class="value" style="font-size:11px;color:var(--text-muted);">
                            <?php if(!empty($row['device_id'])): ?>
                            <?php echo htmlspecialchars($row['device_id']); ?>
                            <?php else: ?>
                            Not generated
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php if(!empty($row['device_id'])): ?>
                <div class="device-info">
                    <strong>Token:</strong> <?php echo htmlspecialchars($row['api_token']); ?>
                </div>
                <?php endif; ?>
                <div class="rider-card-actions">
                    <button class="btn btn-edit btn-edit-rider" data-id="<?php echo (int)$row['id']; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        Edit
                    </button>
                    <a href="?delete=<?php echo (int)$row['id']; ?>" class="btn btn-delete" onclick="return confirm('Delete this rider permanently?');">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        </svg>
                        Delete
                    </a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="rider_id" value="<?php echo (int)$row['id']; ?>">
                        <button type="submit" name="generate_device" class="btn btn-device-card">
                            <?php echo !empty($row['device_id']) ? '↻ Regenerate' : '+ Generate'; ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ===== DESKTOP: TABLE ===== -->
        <div class="table-wrapper">
            <div class="table-header">
                <h2>Rider Registry</h2>
                <span><?php echo $total; ?> total</span>
            </div>

            <?php if(empty($rows)): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                </svg>
                <p>No riders registered yet.</p>
                <button class="btn-primary" id="btn-open-create-table">Register First Rider</button>
            </div>
            <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Face</th>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Phone</th>
                            <th>E-Bike ID</th>
                            <th>Device</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($rows as $row): ?>
                    <?php $face_preview = riders_face_preview_src($row['face_data'] ?? ''); ?>
                    <tr>
                        <td>
                            <?php if($face_preview !== ''): ?>
                            <img class="table-face" src="<?php echo htmlspecialchars($face_preview); ?>" alt="Face">
                            <?php else: ?>
                            <div class="table-face-placeholder">N/A</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-name"><?php echo htmlspecialchars($row['fullname']); ?></div>
                            <div class="table-id">#<?php echo (int)$row['id']; ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($row['address'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($row['phone'] ?? '—'); ?></td>
                        <td><span class="table-ebike"><?php echo htmlspecialchars($row['ebike_id'] ?? '—'); ?></span></td>
                        <td class="table-device">
                            <?php if(!empty($row['device_id'])): ?>
                            <strong>ID:</strong> <?php echo htmlspecialchars($row['device_id']); ?><br>
                            <strong>Token:</strong> <?php echo htmlspecialchars($row['api_token']); ?>
                            <?php else: ?>
                            Not generated
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <button class="btn btn-edit btn-edit-rider" data-id="<?php echo (int)$row['id']; ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                    Edit
                                </button>
                                <a href="?delete=<?php echo (int)$row['id']; ?>" class="btn btn-delete" onclick="return confirm('Delete this rider permanently?');">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                    </svg>
                                    Delete
                                </a>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="rider_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" name="generate_device" class="btn btn-device-card" style="font-size:10px;padding:3px 10px;">
                                        <?php echo !empty($row['device_id']) ? '↻ Regenerate' : '+ Generate'; ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== CREATE MODAL ===== -->
<div class="modal-overlay" id="create-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Register Rider</h3>
            <button class="modal-close" data-close-modal>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="POST" id="create-form">
                <input type="hidden" name="action" value="create_rider">
                <input type="hidden" name="face_data" id="create-face-data">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="fullname" required placeholder="Juan Dela Cruz">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" required placeholder="Street, Barangay, City"></textarea>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" required placeholder="09XX XXX XXXX">
                </div>
                <div class="form-group">
                    <label>E-Bike ID (auto-assigned)</label>
                    <div class="ebike-preview">Next available: <?php echo htmlspecialchars($next_ebike_id); ?></div>
                </div>
                <div class="form-group">
                    <label>Facial Recognition</label>
                    <div class="face-capture" id="create-face-capture">
                        <video id="create-video" autoplay playsinline muted></video>
                        <canvas id="create-canvas"></canvas>
                        <img id="create-preview" alt="Captured face">
                    </div>
                    <div class="face-actions">
                        <button type="button" class="btn-face primary" id="create-capture-btn">Capture Face</button>
                        <button type="button" class="btn-face" id="create-retake-btn">Retake</button>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-close-modal>Cancel</button>
                    <button type="submit" class="btn btn-submit">Register Rider</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== EDIT MODAL ===== -->
<div class="modal-overlay" id="edit-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Rider</h3>
            <button class="modal-close" data-close-modal>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="POST" id="edit-form">
                <input type="hidden" name="action" value="update_rider">
                <input type="hidden" name="rider_id" id="edit-rider-id">
                <input type="hidden" name="face_data" id="edit-face-data">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="fullname" id="edit-fullname" required>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" id="edit-address" required></textarea>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" id="edit-phone" required>
                </div>
                <div class="form-group">
                    <label>E-Bike ID</label>
                    <div class="ebike-preview" id="edit-ebike-display">—</div>
                </div>
                <div class="form-group">
                    <label>Facial Recognition</label>
                    <div class="face-capture" id="edit-face-capture">
                        <video id="edit-video" autoplay playsinline muted></video>
                        <canvas id="edit-canvas"></canvas>
                        <img id="edit-preview" alt="Face preview">
                    </div>
                    <div class="face-actions">
                        <button type="button" class="btn-face primary" id="edit-capture-btn">Capture New Face</button>
                        <button type="button" class="btn-face" id="edit-retake-btn">Retake</button>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" data-close-modal>Cancel</button>
                    <button type="submit" class="btn btn-submit">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script id="riders-data" type="application/json"><?php
    $rider_json = [];
    foreach ($rows as $row) {
        $rider_json[$row['id']] = [
            'fullname' => $row['fullname'],
            'address'  => $row['address'] ?? '',
            'phone'    => $row['phone'] ?? '',
            'ebike_id' => $row['ebike_id'] ?? '',
            'face_data'=> $row['face_data'] ?? '',
            'face_preview'=> riders_face_preview_src($row['face_data'] ?? ''),
        ];
    }
    echo json_encode($rider_json, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?></script>

<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="../assets/js/face-recognition.js"></script>
<script>
(function(){
    var ridersData = {};
    try {
        ridersData = JSON.parse(document.getElementById('riders-data').textContent || '{}');
    } catch (e) {
        ridersData = {};
    }

    var createModal = document.getElementById('create-modal');
    var editModal   = document.getElementById('edit-modal');
    var activeStream = null;

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

    document.querySelectorAll('.nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) closeSidebar();
        });
    });

    // ===== CAMERA =====
    function stopCamera() {
        if (activeStream) {
            activeStream.getTracks().forEach(function(t){ t.stop(); });
            activeStream = null;
        }
    }

    function startCamera(videoEl) {
        stopCamera();
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Camera not supported in this browser.');
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
            .then(function(stream) {
                activeStream = stream;
                videoEl.srcObject = stream;
            })
            .catch(function() {
                alert('Unable to access camera. Please allow camera permission.');
            });
    }

    function setupCapture(prefix, hiddenInput, requireCapture) {
        var wrap    = document.getElementById(prefix + '-face-capture');
        var video   = document.getElementById(prefix + '-video');
        var canvas  = document.getElementById(prefix + '-canvas');
        var preview = document.getElementById(prefix + '-preview');
        var captureBtn = document.getElementById(prefix + '-capture-btn');
        var retakeBtn  = document.getElementById(prefix + '-retake-btn');

        captureBtn.addEventListener('click', function() {
            if (!video.videoWidth) return;
            captureBtn.disabled = true;
            captureBtn.textContent = 'Scanning…';

            FaceRecognition.extractFromVideo(video)
                .then(function(result) {
                    canvas.width  = video.videoWidth;
                    canvas.height = video.videoHeight;
                    canvas.getContext('2d').drawImage(video, 0, 0);
                    var dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                    hiddenInput.value = FaceRecognition.buildStoredFacePayload(dataUrl, result.descriptor);
                    preview.src = dataUrl;
                    wrap.classList.add('has-photo');
                })
                .catch(function(err) {
                    alert(err.message || 'Could not capture face. Try again with your face centered.');
                })
                .finally(function() {
                    captureBtn.disabled = false;
                    captureBtn.textContent = prefix === 'edit' ? 'Capture New Face' : 'Capture Face';
                });
        });

        retakeBtn.addEventListener('click', function() {
            hiddenInput.value = '';
            preview.removeAttribute('src');
            wrap.classList.remove('has-photo');
            startCamera(video);
        });

        return {
            open: function(existingFace) {
                hiddenInput.value = '';
                preview.removeAttribute('src');
                wrap.classList.remove('has-photo');
                if (existingFace) {
                    hiddenInput.value = existingFace;
                    preview.src = existingFace;
                    wrap.classList.add('has-photo');
                } else {
                    startCamera(video);
                }
            },
            validate: function() {
                if (requireCapture && !hiddenInput.value) {
                    alert('Please capture a face photo before submitting.');
                    return false;
                }
                if (requireCapture && !FaceRecognition.parseStoredFacePayload(hiddenInput.value)) {
                    alert('Face profile is invalid. Please capture the face again.');
                    return false;
                }
                return true;
            }
        };
    }

    var createCapture = setupCapture('create', document.getElementById('create-face-data'), true);
    var editCapture   = setupCapture('edit', document.getElementById('edit-face-data'), false);

    function openModal(modal) {
        modal.classList.add('open');
        if (window.innerWidth <= 768) closeSidebar();
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.classList.remove('open');
        stopCamera();
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-close-modal]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            closeModal(createModal);
            closeModal(editModal);
        });
    });

    [createModal, editModal].forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal(modal);
        });
    });

    function bindOpenCreate(btn) {
        if (!btn) return;
        btn.addEventListener('click', function() {
            document.getElementById('create-form').reset();
            createCapture.open();
            openModal(createModal);
        });
    }

    bindOpenCreate(document.getElementById('btn-open-create'));
    bindOpenCreate(document.getElementById('btn-open-create-empty'));
    bindOpenCreate(document.getElementById('btn-open-create-table'));

    document.getElementById('create-form').addEventListener('submit', function(e) {
        if (!createCapture.validate()) e.preventDefault();
    });

    document.getElementById('edit-form').addEventListener('submit', function(e) {
        if (!editCapture.validate()) e.preventDefault();
    });

    document.querySelectorAll('.btn-edit-rider').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var rider = ridersData[btn.dataset.id] || {};
            document.getElementById('edit-rider-id').value = btn.dataset.id;
            document.getElementById('edit-fullname').value = rider.fullname || '';
            document.getElementById('edit-address').value  = rider.address || '';
            document.getElementById('edit-phone').value    = rider.phone || '';
            document.getElementById('edit-ebike-display').textContent = rider.ebike_id || '—';
            editCapture.open(rider.face_preview || '');
            openModal(editModal);
        });
    });

})();
</script>

</body>
</html>