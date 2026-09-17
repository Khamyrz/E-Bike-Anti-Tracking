<?php
session_start();

require __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/face-recognition.php';
require_once __DIR__ . '/includes/rider-online.php';

$message                 = "";
$message_type            = "error";
$face_unauthorized       = false;
$show_admin_login_modal  = false;
$show_sweet_alert        = false;
$sweet_alert_data        = [];
$face_retry              = false;
$face_retry_message      = "";

if (isset($_GET['returned']) && $_GET['returned'] === '1') {
    $show_sweet_alert = true;
    $sweet_alert_data = [
        'icon'  => 'success',
        'title' => 'E-Bike Returned',
        'text'  => 'Your E-Bike has been returned successfully. Your account has been removed. Please ask the administrator to register you again before logging in.',
    ];
}

// ── Admin login ─────────────────────────────────
if(isset($_POST['login']))
{
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    $show_admin_login_modal = true;

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows > 0)
    {
        $user = $result->fetch_assoc();
        if(password_verify($password, $user['password']))
        {
            $_SESSION['admin']      = $user['id'];
            $_SESSION['admin_name'] = $user['fullname'];
            header("Location: admin/dashboard.php");
            exit;
        }
        $message = "Incorrect password. Please try again.";
        $show_sweet_alert = true;
        $sweet_alert_data = ['icon'=>'error','title'=>'Login Failed','text'=>$message];
    }
    else
    {
        $message = "No admin account found with that email.";
        $show_sweet_alert = true;
        $sweet_alert_data = ['icon'=>'error','title'=>'Account Not Found','text'=>$message];
    }
}

// ── Facial Recognition Login ──────────────────
if(isset($_POST['face_login']))
{
    $face_descriptor_raw = $_POST['face_descriptor'] ?? '';
    $captured_descriptor = face_parse_login_descriptor($face_descriptor_raw);

    if(!$captured_descriptor)
    {
        $message = "Face scan failed. Please try again with good lighting.";
        $show_sweet_alert = true;
        $sweet_alert_data = ['icon'=>'error','title'=>'Scan Failed','text'=>$message];
        $face_retry = true;
        $face_retry_message = "⚠️ Face detection failed. Please ensure good lighting and try again.";
    }
    else
    {
        // Check if any riders exist
        $count_check  = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='rider' AND status='approved' AND face_data IS NOT NULL AND face_data!=''");
        $count_row    = $count_check->fetch_assoc();
        $total_riders = $count_row['total'] ?? 0;

        if($total_riders === 0)
        {
            $face_unauthorized = true;
            $show_sweet_alert  = true;
            $sweet_alert_data  = ['icon'=>'error','title'=>'No Riders Found','text'=>'No riders registered. Contact administrator.'];
            $face_retry = true;
            $face_retry_message = "⚠️ No registered riders found. Please contact administrator.";
        }
        else
        {
            // ================================================================
            // SECURITY: Try to find a match using STRICT comparison
            // ================================================================
            $match = face_find_registered_match($conn, $captured_descriptor);
            
            if($match)
            {
                // ================================================================
                // Fetch the full rider data including status
                // ================================================================
                $rider_stmt = $conn->prepare("
                    SELECT id, fullname, email, ebike_id, face_data, status, phone, address
                    FROM users 
                    WHERE id = ? AND role = 'rider'
                ");
                $rider_stmt->bind_param('i', $match['id']);
                $rider_stmt->execute();
                $rider_result = $rider_stmt->get_result();
                $full_rider = $rider_result->fetch_assoc();
                $rider_stmt->close();
                
                // ================================================================
                // SECURITY: Verify the rider exists and is approved
                // ================================================================
                if(!$full_rider)
                {
                    $face_unauthorized = true;
                    $show_sweet_alert  = true;
                    $sweet_alert_data  = ['icon'=>'error','title'=>'Account Error','text'=>'Rider account not found. Please contact administrator.'];
                    $face_retry = true;
                    $face_retry_message = "⚠️ Account error. Please contact administrator.";
                }
                elseif($full_rider['status'] !== 'approved')
                {
                    $face_unauthorized = true;
                    $show_sweet_alert  = true;
                    $sweet_alert_data  = ['icon'=>'error','title'=>'Account Not Approved','text'=>'Your account is pending approval. Contact administrator.'];
                    $face_retry = true;
                    $face_retry_message = "⚠️ Account pending approval. Please contact administrator.";
                }
                else
                {
                    // ================================================================
                    // SUCCESS: Valid face match with approved rider
                    // ================================================================
                    $_SESSION['rider']      = (int)$full_rider['id'];
                    $_SESSION['rider_name'] = $full_rider['fullname'];
                    $_SESSION['rider_email'] = $full_rider['email'];
                    $_SESSION['rider_ebike_id'] = $full_rider['ebike_id'];
                    $_SESSION['face_login'] = true;
                    $_SESSION['face_verified_at'] = time();
                    
                    rider_set_online($conn, $full_rider['id'], 'login');
                    
                    $show_sweet_alert = true;
                    $sweet_alert_data = [
                        'icon'     => 'success',
                        'title'    => 'Welcome ' . $full_rider['fullname'] . '!',
                        'text'     => 'Biometric verification successful. Redirecting to dashboard...',
                        'redirect' => 'rider/dashboard.php'
                    ];
                    
                    // Log successful login
                    error_log("Face login successful: Rider #{$full_rider['id']} ({$full_rider['fullname']}) - Distance: " . number_format($match['distance'], 6));
                }
            }
            else
            {
                // ================================================================
                // SECURITY: No match found - reject access with retry option
                // ================================================================
                $face_unauthorized = true;
                $show_sweet_alert  = true;
                $sweet_alert_data  = [
                    'icon'=>'error',
                    'title'=>'Access Denied',
                    'text'=>'Face not recognized. Please ensure you are registered and try again with better lighting.'
                ];
                
                // Set retry flag for frontend
                $face_retry = true;
                $face_retry_message = "❌ Face not recognized. Please try again with better lighting and face centered.";
                
                // Log failed attempt for security audit
                error_log('Failed face login attempt: No matching rider found');
            }
        }
    }
}

// ── Admin registration ────────────────────────
if(isset($_POST['register_admin']))
{
    $fullname = trim($_POST['admin_fullname']);
    $email    = trim($_POST['admin_email']);
    $phone    = trim($_POST['admin_phone']);
    $password = password_hash($_POST['admin_password'], PASSWORD_DEFAULT);

    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if($check->num_rows > 0)
    {
        $show_sweet_alert = true;
        $sweet_alert_data = ['icon'=>'error','title'=>'Registration Failed','text'=>'That email is already registered.'];
    }
    else
    {
        $ins = $conn->prepare("INSERT INTO users (fullname,email,phone,password,role,status) VALUES (?,?,?,?,'admin','approved')");
        $ins->bind_param("ssss",$fullname,$email,$phone,$password);
        $ins->execute();
        $show_sweet_alert = true;
        $sweet_alert_data = ['icon'=>'success','title'=>'Account Created!','text'=>'Admin account created. You can now log in.'];
    }
}

// ── Has admin? ─────────────────────────────────
$admin_count_result = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='admin'");
$has_admin = false;
if($admin_count_result && $admin_count_result->num_rows > 0)
    $has_admin = (int)$admin_count_result->fetch_assoc()['total'] > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MotoAdmin — E-Bike Tracker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg-base:       #0B1120;
    --bg-surface:    #111827;
    --bg-card:       #1E293B;
    --bg-card-hover: #243044;
    --border:        #2D3F55;
    --accent-blue:   #3B82F6;
    --accent-green:  #10B981;
    --accent-red:    #EF4444;
    --accent-purple: #8B5CF6;
    --accent-orange: #F59E0B;
    --text-primary:  #F1F5F9;
    --text-secondary:#94A3B8;
    --text-muted:    #475569;
}

html, body { height: 100%; font-family: 'Inter', system-ui, sans-serif; background: var(--bg-base); color: var(--text-primary); }
body { display: flex; flex-direction: column; min-height: 100vh; }

/* ── Header ── */
.top-header { background: var(--bg-surface); border-bottom: 1px solid var(--border); padding: 16px 24px; text-align: center; }
.brand-center { display: inline-flex; align-items: center; gap: 12px; }
.brand-icon-header { width: 36px; height: 36px; background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--accent-blue); }
.brand-icon-header svg { width: 20px; height: 20px; }
.brand-wordmark { font-size: 24px; font-weight: 800; letter-spacing: -0.5px; cursor: default; }
.brand-wordmark span { color: var(--accent-blue); }
.brand-tagline { font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--text-muted); margin-top: 2px; }
.admin-secret-trigger { cursor: default; user-select: none; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }

/* ── Layout ── */
.main-content { flex: 1; display: flex; align-items: center; justify-content: center; padding: 32px 20px; }
.form-card { width: 100%; max-width: 520px; }
.form-heading { font-size: 28px; font-weight: 800; letter-spacing: -0.5px; text-align: center; margin-bottom: 4px; }
.form-subheading { font-size: 14px; color: var(--text-muted); text-align: center; margin-bottom: 32px; }

/* ── Scanner ── */
.scanner-container { background: var(--bg-card); border-radius: 20px; padding: 24px; border: 1px solid var(--border); margin-bottom: 20px; }

.holo-wrap {
    position: relative;
    width: 100%;
    aspect-ratio: 4/3;
    max-height: 340px;
    border-radius: 16px;
    overflow: hidden;
    background: #000A18;
}

.holo-wrap video {
    position: absolute;
    inset: 0; width: 100%; height: 100%;
    object-fit: cover;
    border-radius: 16px;
    z-index: 1;
}

.holo-wrap canvas.mesh-canvas {
    position: absolute;
    inset: 0; width: 100%; height: 100%;
    z-index: 2;
    pointer-events: none;
    border-radius: 16px;
}

.holo-wrap canvas.snap-canvas { display: none; }

.holo-wrap img.preview-img {
    position: absolute;
    inset: 0; width: 100%; height: 100%;
    object-fit: cover;
    border-radius: 16px;
    z-index: 3;
    display: none;
}

.holo-wrap.has-photo video      { display: none; }
.holo-wrap.has-photo canvas.mesh-canvas { display: none; }
.holo-wrap.has-photo img.preview-img    { display: block; }

.holo-wrap::before {
    content: '';
    position: absolute; inset: 0;
    border-radius: 16px;
    background: radial-gradient(ellipse at center, transparent 45%, rgba(0,10,24,0.55) 100%);
    z-index: 3;
    pointer-events: none;
}

.holo-frame-svg {
    position: absolute;
    inset: 0; width: 100%; height: 100%;
    z-index: 4;
    pointer-events: none;
    overflow: visible;
}

.holo-corner {
    fill: none;
    stroke-width: 2.5;
    stroke-linecap: round;
    transition: stroke 0.35s ease;
}

.holo-outer-ring {
    fill: none;
    stroke-width: 0.7;
    stroke-dasharray: 10 5;
    transition: stroke 0.35s ease;
    transform-origin: 50% 50%;
    transform-box: fill-box;
}

.holo-tick {
    stroke-width: 1.2;
    stroke-linecap: square;
    transition: stroke 0.35s ease;
    opacity: 0.5;
}

.holo-scanline-rect {
    opacity: 0;
    transition: opacity 0.3s;
}

.state-idle   .holo-corner      { stroke: #00BFFF; }
.state-idle   .holo-outer-ring  { stroke: rgba(0,191,255,0.35); }
.state-idle   .holo-tick        { stroke: #00BFFF; }
.state-idle   .holo-scanline-rect { opacity: 1; }

.state-detect .holo-corner      { stroke: #00FF88; }
.state-detect .holo-outer-ring  { stroke: rgba(0,255,136,0.4); }
.state-detect .holo-tick        { stroke: #00FF88; }
.state-detect .holo-scanline-rect { opacity: 0; }

.state-noface .holo-corner      { stroke: #FF4444; }
.state-noface .holo-outer-ring  { stroke: rgba(255,68,68,0.35); }
.state-noface .holo-tick        { stroke: #FF4444; }
.state-noface .holo-scanline-rect { opacity: 0; }

.state-ok     .holo-corner      { stroke: #00FF88; }
.state-ok     .holo-outer-ring  { stroke: rgba(0,255,136,0.55); }
.state-ok     .holo-tick        { stroke: #00FF88; }
.state-ok     .holo-scanline-rect { opacity: 0; }

.state-fail   .holo-corner      { stroke: #FF4444; }
.state-fail   .holo-outer-ring  { stroke: rgba(255,68,68,0.5); }
.state-fail   .holo-tick        { stroke: #FF4444; }
.state-fail   .holo-scanline-rect { opacity: 0; }

.state-retry  .holo-corner      { stroke: #F59E0B; }
.state-retry  .holo-outer-ring  { stroke: rgba(245,158,11,0.4); }
.state-retry  .holo-tick        { stroke: #F59E0B; }
.state-retry  .holo-scanline-rect { opacity: 0; }

.state-idle .holo-corner { animation: cornerPulse 1.8s ease-in-out infinite; }
@keyframes cornerPulse { 0%,100%{opacity:1}50%{opacity:0.4} }

.state-idle .holo-outer-ring,
.state-detect .holo-outer-ring,
.state-noface .holo-outer-ring,
.state-retry .holo-outer-ring { animation: ringRotate 8s linear infinite; }
@keyframes ringRotate { to { transform: rotate(360deg); } }

@keyframes scanSweep {
    0%   { transform: translateY(0); opacity:0.7; }
    48%  { opacity:0.7; }
    50%  { opacity:0; transform: translateY(0); }
    52%  { opacity:0.7; }
    100% { transform: translateY(240px); opacity:0.7; }
}
.state-idle .holo-scanline-rect { animation: scanSweep 2.2s linear infinite; }

.biometric-status-bar {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; margin-top: 14px; padding: 10px 16px;
    background: var(--bg-surface); border-radius: 12px; border: 1px solid var(--border);
}

.status-led { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; transition: all 0.3s; }
.led-blue  { background:#00BFFF; box-shadow:0 0 10px rgba(0,191,255,0.6); }
.led-green { background:#00FF88; box-shadow:0 0 14px rgba(0,255,136,0.7); animation: ledPulse 0.7s ease-in-out infinite; }
.led-red   { background:#FF4444; box-shadow:0 0 10px rgba(255,68,68,0.6); animation: ledPulse 1s ease-in-out infinite; }
.led-orange{ background:#F59E0B; box-shadow:0 0 10px rgba(245,158,11,0.6); animation: ledPulse 0.8s ease-in-out infinite; }
@keyframes ledPulse { 0%,100%{opacity:1}50%{opacity:0.4} }

.status-text { font-size: 12px; font-weight: 500; color: var(--text-secondary); letter-spacing: 0.2px; }
.status-text .c-blue  { color: #00BFFF; font-weight: 600; }
.status-text .c-green { color: #00FF88; font-weight: 600; }
.status-text .c-red   { color: #FF5555; font-weight: 600; }
.status-text .c-orange{ color: #F59E0B; font-weight: 600; }

.progress-wrap { height: 3px; background: var(--bg-surface); border-radius: 2px; overflow: hidden; opacity: 0; transition: opacity 0.3s; margin-top: 10px; }
.progress-wrap.show { opacity: 1; }
.progress-bar { height: 100%; width: 0%; background: linear-gradient(90deg,#00BFFF,#00FF88); border-radius: 2px; transition: width 0.1s linear; }

.btn-retake {
    display: block; width: 100%; margin-top: 14px;
    padding: 12px 16px; border-radius: 10px;
    border: 1px solid var(--border); background: var(--bg-card);
    color: var(--text-secondary); font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: all 0.15s;
}
.btn-retake:hover { background: var(--bg-card-hover); border-color: var(--accent-blue); color: var(--text-primary); }
.btn-retake.retry { border-color: var(--accent-orange); color: var(--accent-orange); }
.btn-retake.retry:hover { background: rgba(245,158,11,0.1); border-color: var(--accent-orange); }

.face-instruction { font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 16px; line-height: 1.6; }
.face-instruction strong { color: var(--text-secondary); }
.face-instruction .ag { color: #00FF88; font-weight: 600; }
.face-instruction .ar { color: #FF5555; font-weight: 600; }
.face-instruction .ao { color: #F59E0B; font-weight: 600; }

.retry-message-box {
    display: <?php echo $face_retry ? 'block' : 'none'; ?>;
    margin-top: 12px;
    padding: 12px 16px;
    border-radius: 10px;
    background: rgba(245,158,11,0.1);
    border: 1px solid rgba(245,158,11,0.3);
    color: var(--accent-orange);
    font-size: 13px;
    text-align: center;
    animation: fadeInDown 0.5s ease;
}

@keyframes fadeInDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.page-footer { text-align: center; font-size: 11px; color: var(--text-muted); margin-top: 24px; padding: 16px 0; border-top: 1px solid var(--border); }

.modal-overlay { position: fixed; inset: 0; background: rgba(11,17,32,0.75); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 1000; backdrop-filter: blur(4px); }
.modal-card { width: 100%; max-width: 380px; background: var(--bg-surface); border: 1px solid var(--border); border-radius: 16px; padding: 28px; position: relative; box-shadow: 0 24px 64px rgba(0,0,0,0.6); }
.modal-card-header { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.modal-icon { width: 38px; height: 38px; background: rgba(139,92,246,0.12); border: 1px solid rgba(139,92,246,0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--accent-purple); flex-shrink: 0; }
.modal-icon svg { width: 18px; height: 18px; }
.modal-card-header h3 { font-size: 16px; font-weight: 700; }
.modal-sub { font-size: 12px; color: var(--text-muted); margin-bottom: 20px; padding-left: 50px; }
.modal-close { position: absolute; top: 16px; right: 16px; width: 28px; height: 28px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); transition: all 0.15s; }
.modal-close:hover { background: var(--bg-card-hover); color: var(--text-primary); }
.modal-close svg { width: 14px; height: 14px; }
.field { margin-bottom: 14px; }
.field label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.7px; color: var(--text-muted); margin-bottom: 6px; }
.field input { width: 100%; padding: 11px 14px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 9px; color: var(--text-primary); font-family: 'Inter', system-ui, sans-serif; font-size: 14px; outline: none; transition: border-color 0.15s; }
.field input::placeholder { color: var(--text-muted); }
.field input:focus { border-color: var(--accent-blue); background: var(--bg-card-hover); }
.btn-primary { width: 100%; padding: 12px; background: var(--accent-blue); color: #fff; border: none; border-radius: 9px; font-family: 'Inter', system-ui, sans-serif; font-size: 14px; font-weight: 600; cursor: pointer; transition: opacity 0.15s, transform 0.1s; margin-top: 4px; }
.btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
.modal-overlay.is-open { display: flex !important; }
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.7s linear infinite; vertical-align: middle; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }

.auto-retry-badge {
    display: <?php echo $face_retry ? 'inline-flex' : 'none'; ?>;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    background: rgba(245,158,11,0.15);
    border: 1px solid rgba(245,158,11,0.3);
    color: var(--accent-orange);
    font-size: 11px;
    font-weight: 600;
}

@media (max-width: 640px) {
    .top-header { padding: 12px 16px; }
    .brand-wordmark { font-size: 20px; }
    .form-heading { font-size: 22px; }
    .form-card { padding: 0; }
    .holo-wrap { max-height: 280px; }
    .scanner-container { padding: 16px; }
    .retry-message-box { font-size: 12px; padding: 10px 14px; }
}
</style>
</head>
<body>

<header class="top-header">
    <div class="brand-center admin-secret-trigger" id="admin-secret-brand">
        <div class="brand-icon-header">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"/>
                <path d="M12 2v3M12 19v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M2 12h3M19 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/>
            </svg>
        </div>
        <div>
            <div class="brand-wordmark">Moto<span>Admin</span></div>
            <div class="brand-tagline">Anti-Theft E-Bike Fleet Management</div>
        </div>
    </div>
</header>

<main class="main-content">
    <div class="form-card">

        <div class="form-heading">Rider Sign In</div>
        <div class="form-subheading">
            Holographic Biometric Recognition
            <span class="auto-retry-badge" id="autoRetryBadge">
                ⟳ Auto-Retry Enabled
            </span>
        </div>

        <form method="POST" id="faceLoginForm">
            <input type="hidden" name="face_login" value="1">
            <input type="hidden" name="face_descriptor" id="face-descriptor-input">

            <div class="scanner-container">

                <div class="holo-wrap state-idle" id="holoWrap">

                    <video id="loginVideo" autoplay playsinline muted></video>
                    <canvas id="meshCanvas" class="mesh-canvas"></canvas>
                    <canvas id="snapCanvas" class="snap-canvas"></canvas>
                    <img id="previewImg" class="preview-img" alt="Captured">

                    <svg class="holo-frame-svg" id="holoFrameSvg"
                         viewBox="0 0 400 300" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">

                        <defs>
                            <linearGradient id="slGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%"   stop-color="#00BFFF" stop-opacity="0"/>
                                <stop offset="40%"  stop-color="#00BFFF" stop-opacity="0.55"/>
                                <stop offset="60%"  stop-color="#00BFFF" stop-opacity="0.55"/>
                                <stop offset="100%" stop-color="#00BFFF" stop-opacity="0"/>
                            </linearGradient>
                        </defs>

                        <circle class="holo-outer-ring" cx="200" cy="150" r="118"/>

                        <polyline class="holo-corner" points="50,95 50,62 85,62"/>
                        <polyline class="holo-corner" points="315,62 350,62 350,95"/>
                        <polyline class="holo-corner" points="50,205 50,238 85,238"/>
                        <polyline class="holo-corner" points="315,238 350,238 350,205"/>

                        <line class="holo-tick" x1="50" y1="128" x2="66" y2="128"/>
                        <line class="holo-tick" x1="50" y1="150" x2="60" y2="150"/>
                        <line class="holo-tick" x1="50" y1="172" x2="66" y2="172"/>
                        <line class="holo-tick" x1="334" y1="128" x2="350" y2="128"/>
                        <line class="holo-tick" x1="340" y1="150" x2="350" y2="150"/>
                        <line class="holo-tick" x1="334" y1="172" x2="350" y2="172"/>

                        <rect class="holo-scanline-rect" x="66" y="62" width="268" height="10"
                              fill="url(#slGrad)" rx="2"/>

                    </svg>
                </div>

                <div class="biometric-status-bar">
                    <span class="status-led led-blue" id="statusLed"></span>
                    <span class="status-text" id="statusText">
                        <span class="c-blue">■</span> Initializing holographic scanner...
                    </span>
                </div>

                <div class="progress-wrap" id="progressWrap">
                    <div class="progress-bar" id="progressBar"></div>
                </div>

                <!-- Retry Message -->
                <div class="retry-message-box" id="retryMessageBox">
                    <?php echo htmlspecialchars($face_retry_message); ?>
                    <br><small style="opacity:0.7;">Please try again. The scanner will auto-retry.</small>
                </div>

                <button type="button" class="btn-retake <?php echo $face_retry ? 'retry' : ''; ?>" id="retakeBtn" style="<?php echo $face_retry ? 'display:block;' : 'display:none;'; ?>">
                    <?php echo $face_retry ? '⟳ Retry — Scan Again' : '⟳ Retake — Try Again'; ?>
                </button>

            </div>

            <div class="face-instruction">
                <strong>Look directly at the camera.</strong> The holographic mesh activates when your face is detected.<br>
                <span class="ag">Green mesh</span> = identity verified &nbsp;|&nbsp; <span class="ar">Red mesh</span> = face not registered &nbsp;|&nbsp; <span class="ao">Orange mesh</span> = retry mode
            </div>

        </form>

        <div class="page-footer admin-secret-trigger" id="admin-secret-footer">Powered by ESP32 + SIM800L + GPS</div>
    </div>
</main>

<!-- Admin Login Modal -->
<div id="adminLoginModal" class="modal-overlay">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeAdminLoginModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div class="modal-card-header">
            <div class="modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            </div>
            <h3>Admin Sign In</h3>
        </div>
        <p class="modal-sub">Secure administrator access.</p>
        <form method="POST">
            <input type="hidden" name="login_role" value="admin">
            <div class="field"><label>Email Address</label><input type="email" name="email" placeholder="admin@example.com" required></div>
            <div class="field"><label>Password</label><input type="password" name="password" placeholder="Enter your password" required></div>
            <button type="submit" name="login" class="btn-primary">Sign In as Admin</button>
        </form>
    </div>
</div>

<!-- Admin Registration Modal -->
<div id="adminModal" class="modal-overlay">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeAdminModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div class="modal-card-header">
            <div class="modal-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
            </div>
            <h3>Admin Registration</h3>
        </div>
        <p class="modal-sub">Create the system administrator account.</p>
        <form method="POST">
            <div class="field"><label>Full Name</label><input type="text" name="admin_fullname" placeholder="Your full name" required></div>
            <div class="field"><label>Email Address</label><input type="email" name="admin_email" placeholder="admin@example.com" required></div>
            <div class="field"><label>Phone Number</label><input type="text" name="admin_phone" placeholder="+63 900 000 0000" required></div>
            <div class="field"><label>Password</label><input type="password" name="admin_password" placeholder="Create a strong password" required></div>
            <button type="submit" name="register_admin" class="btn-primary">Create Admin Account</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="assets/js/face-recognition.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

var adminModal      = document.getElementById('adminModal');
var adminLoginModal = document.getElementById('adminLoginModal');
var hasAdmin        = <?php echo $has_admin ? 'true' : 'false'; ?>;
var faceRetry       = <?php echo $face_retry ? 'true' : 'false'; ?>;

function pauseScanner(){
    if(typeof rafId!=='undefined'&&rafId){ cancelAnimationFrame(rafId); rafId=null; }
    if(typeof stream!=='undefined'&&stream){ stream.getTracks().forEach(function(t){ t.stop(); }); stream=null; }
    if(typeof clearMesh==='function') clearMesh();
    if(typeof setState==='function')
        setState('state-idle','led-blue','<span class="c-blue">\u25a0</span> Scanner paused\u2014close modal to resume');
}
function resumeScanner(){
    if(typeof startCamera==='function') startCamera();
}
function openAdminModal(){
    adminModal.classList.add('is-open');
    document.body.style.overflow='hidden';
    pauseScanner();
}
function closeAdminModal(){
    adminModal.classList.remove('is-open');
    document.body.style.overflow='';
    resumeScanner();
}
function openAdminLoginModal(){
    adminLoginModal.classList.add('is-open');
    document.body.style.overflow='hidden';
    pauseScanner();
}
function closeAdminLoginModal(){
    adminLoginModal.classList.remove('is-open');
    document.body.style.overflow='';
    resumeScanner();
}
window.openAdminModal=openAdminModal; window.closeAdminModal=closeAdminModal;
window.openAdminLoginModal=openAdminLoginModal; window.closeAdminLoginModal=closeAdminLoginModal;
adminModal.addEventListener('click',function(e){ if(e.target===adminModal) closeAdminModal(); });
adminLoginModal.addEventListener('click',function(e){ if(e.target===adminLoginModal) closeAdminLoginModal(); });

<?php if($show_sweet_alert): ?>
(function(){
    var redirect = <?php echo isset($sweet_alert_data['redirect']) ? "'" . $sweet_alert_data['redirect'] . "'" : 'null'; ?>;
    var isSuccess = <?php echo $sweet_alert_data['icon'] === 'success' ? 'true' : 'false'; ?>;

    Swal.fire({
        icon: '<?php echo $sweet_alert_data['icon']; ?>',
        title: '<?php echo addslashes($sweet_alert_data['title']); ?>',
        text: '<?php echo addslashes($sweet_alert_data['text']); ?>',
        confirmButtonColor: '#3B82F6',
        showConfirmButton: false,
        allowOutsideClick: false,
        allowEscapeKey: false,
        timer: 500,
        timerProgressBar: false,
        didOpen: function(){
            if(redirect){
                setTimeout(function(){ window.location.href = redirect; }, 500);
            } else if(isSuccess){
                setTimeout(function(){ Swal.close(); }, 500);
            }
        }
    });
})();
<?php endif; ?>

(function(){
    var taps=0, firstT=0, lastE=0;
    function onTap(e){
        var now=Date.now();
        if(now-lastE<250) return; lastE=now;
        if(e.type==='touchend') e.preventDefault();
        if(!firstT||(now-firstT)>4000){ taps=1; firstT=now; } else taps++;
        if(taps>=5){ taps=0; firstT=0; if(hasAdmin) openAdminLoginModal(); else openAdminModal(); }
    }
    document.querySelectorAll('.admin-secret-trigger').forEach(function(el){
        el.addEventListener('click',onTap);
        el.addEventListener('touchend',onTap,{passive:false});
    });
})();

var holoWrap      = document.getElementById('holoWrap');
var video         = document.getElementById('loginVideo');
var meshCanvas    = document.getElementById('meshCanvas');
var snapCanvas    = document.getElementById('snapCanvas');
var previewImg    = document.getElementById('previewImg');
var descInput     = document.getElementById('face-descriptor-input');
var form          = document.getElementById('faceLoginForm');
var statusLed     = document.getElementById('statusLed');
var statusText    = document.getElementById('statusText');
var progressWrap  = document.getElementById('progressWrap');
var progressBar   = document.getElementById('progressBar');
var retakeBtn     = document.getElementById('retakeBtn');
var retryMessageBox = document.getElementById('retryMessageBox');

var stream        = null;
var modelsReady   = false;
var rafId         = null;
var scanTimer     = null;
var stableFrames  = 0;
var NEED_STABLE   = 4;
var capturing     = false;
var done          = false;
var retryMode     = faceRetry;
var mCtx          = meshCanvas.getContext('2d');

var STATES = ['state-idle','state-detect','state-noface','state-ok','state-fail','state-retry'];
function setState(s, ledCls, html){
    STATES.forEach(function(c){ holoWrap.classList.remove(c); });
    holoWrap.classList.add(s);
    statusLed.className = 'status-led ' + ledCls;
    statusText.innerHTML = html;
}

// If retry mode is active, show retry state
if (retryMode) {
    setState('state-retry', 'led-orange', 
        '<span class="c-orange">⟳</span> Auto-retry mode — scanning again...');
    retakeBtn.style.display = 'block';
    retakeBtn.classList.add('retry');
    retakeBtn.textContent = '⟳ Retry — Scan Again';
    retryMessageBox.style.display = 'block';
}

function syncCanvasSize(){
    var rect = video.getBoundingClientRect();
    if(rect.width > 0 && meshCanvas.width !== rect.width){
        meshCanvas.width  = rect.width;
        meshCanvas.height = rect.height;
    }
}

function drawMesh(landmarks, color){
    syncCanvasSize();
    mCtx.clearRect(0, 0, meshCanvas.width, meshCanvas.height);

    var pts = landmarks.positions;
    var scaleX = meshCanvas.width  / video.videoWidth;
    var scaleY = meshCanvas.height / video.videoHeight;

    function pt(i){ return { x: pts[i].x * scaleX, y: pts[i].y * scaleY }; }

    var alpha       = color === 'green' ? 0.75 : (color === 'orange' ? 0.75 : 0.80);
    var lineAlpha   = color === 'green' ? 0.55 : (color === 'orange' ? 0.55 : 0.60);
    var dotColor    = color === 'green' ? 'rgba(0,255,136,' + alpha + ')' : 
                      (color === 'orange' ? 'rgba(245,158,11,' + alpha + ')' : 'rgba(255,68,68,' + alpha + ')');
    var lineColor   = color === 'green' ? 'rgba(0,255,136,' + lineAlpha + ')' : 
                      (color === 'orange' ? 'rgba(245,158,11,' + lineAlpha + ')' : 'rgba(255,68,68,' + lineAlpha + ')');

    mCtx.strokeStyle = lineColor;
    mCtx.lineWidth   = 0.9;
    mCtx.lineCap     = 'round';
    mCtx.lineJoin    = 'round';

    function polyline(indices, close){
        mCtx.beginPath();
        var p = pt(indices[0]); mCtx.moveTo(p.x, p.y);
        for(var k=1;k<indices.length;k++){ var q=pt(indices[k]); mCtx.lineTo(q.x,q.y); }
        if(close) mCtx.closePath();
        mCtx.stroke();
    }

    function line(a,b){
        mCtx.beginPath();
        var pa=pt(a), pb=pt(b);
        mCtx.moveTo(pa.x,pa.y); mCtx.lineTo(pb.x,pb.y);
        mCtx.stroke();
    }

    polyline([0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16]);
    polyline([17,18,19,20,21]);
    polyline([22,23,24,25,26]);
    polyline([27,28,29,30]);
    polyline([31,32,33,34,35]);
    polyline([36,37,38,39,40,41], true);
    polyline([42,43,44,45,46,47], true);
    polyline([48,49,50,51,52,53,54,55,56,57,58,59], true);
    polyline([60,61,62,63,64,65,66,67], true);

    line(27,21); line(27,22);
    line(17,36); line(26,45);
    line(21,39); line(22,42);
    line(27,39); line(27,42);
    line(30,48); line(30,54);
    line(33,51); line(33,57);
    line(0,36);  line(16,45);
    line(1,41);  line(15,46);
    line(4,48);  line(12,54);
    line(6,58);  line(10,56);
    line(3,31);  line(13,35);
    line(6,48);  line(10,54);
    line(8,57); line(8,51);
    line(41,31); line(46,35);
    line(37,19); line(44,24);

    mCtx.fillStyle = dotColor;
    mCtx.shadowColor = dotColor;
    mCtx.shadowBlur  = 6;
    for(var i=0; i<pts.length; i++){
        var d = pt(i);
        mCtx.beginPath();
        mCtx.arc(d.x, d.y, 1.8, 0, Math.PI*2);
        mCtx.fill();
    }
    mCtx.shadowBlur = 0;
}

function clearMesh(){
    syncCanvasSize();
    mCtx.clearRect(0, 0, meshCanvas.width, meshCanvas.height);
}

function startCamera(){
    if(stream){ stream.getTracks().forEach(function(t){ t.stop(); }); stream=null; }
    if(rafId){ cancelAnimationFrame(rafId); rafId=null; }
    if(scanTimer){ clearInterval(scanTimer); scanTimer=null; }
    stableFrames=0; capturing=false; done=false;
    holoWrap.classList.remove('has-photo');
    previewImg.removeAttribute('src');

    // Reset retry mode if we're starting fresh
    if (!retryMode) {
        retakeBtn.style.display = 'none';
        retakeBtn.classList.remove('retry');
        retryMessageBox.style.display = 'none';
    } else {
        retakeBtn.style.display = 'block';
        retakeBtn.classList.add('retry');
        retakeBtn.textContent = '⟳ Retry — Scan Again';
        retryMessageBox.style.display = 'block';
        setState('state-retry', 'led-orange', 
            '<span class="c-orange">⟳</span> Auto-retry mode — scanning again...');
    }

    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
        Swal.fire({icon:'error',title:'Camera Not Supported',text:'Camera not supported in this browser.',confirmButtonColor:'#3B82F6'});
        return;
    }

    navigator.mediaDevices.getUserMedia({video:{facingMode:'user'},audio:false})
        .then(function(s){
            stream = s;
            video.srcObject = s;
            if (!retryMode) {
                setState('state-idle','led-blue','<span class="c-blue">■</span> Loading face recognition engine...');
            } else {
                setState('state-retry','led-orange','<span class="c-orange">⟳</span> Retrying face recognition...');
            }
            return FaceRecognition.loadModels();
        })
        .then(function(){
            modelsReady = true;
            if (!retryMode) {
                setState('state-idle','led-blue','<span class="c-blue">■</span> Holographic scanner active');
            } else {
                setState('state-retry','led-orange','<span class="c-orange">⟳</span> Auto-retry active — scanning...');
            }
            startMeshLoop();
        })
        .catch(function(err){
            var msg = (err && err.message) ? err.message : 'Camera access denied. Please allow camera permission.';
            Swal.fire({icon:'error',title:'Camera Error',text:msg,confirmButtonColor:'#3B82F6'});
            setState('state-noface','led-red','<span class="c-red">■</span> Camera unavailable');
        });
}

var lastDetectAt = 0;
var DETECT_INTERVAL = 120;
var lastLandmarks   = null;
var lastHadFace     = false;

function startMeshLoop(){
    if(rafId) cancelAnimationFrame(rafId);

    function loop(){
        rafId = requestAnimationFrame(loop);
        if(done || capturing) return;
        if(!video.videoWidth) return;

        syncCanvasSize();

        var now = Date.now();
        if(now - lastDetectAt < DETECT_INTERVAL){
            if(lastLandmarks && lastHadFace){
                var color = retryMode ? 'orange' : 'green';
                drawMesh(lastLandmarks, color);
            }
            return;
        }
        lastDetectAt = now;

        faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({inputSize:224,scoreThreshold:0.5}))
            .withFaceLandmarks()
            .then(function(result){
                if(done || capturing) return;

                if(result && result.landmarks){
                    lastLandmarks = result.landmarks;
                    lastHadFace   = true;
                    stableFrames++;

                    var pct = Math.min((stableFrames / NEED_STABLE) * 100, 100);
                    progressWrap.classList.add('show');
                    progressBar.style.width = pct + '%';

                    if(stableFrames >= NEED_STABLE){
                        // Reset retry mode on successful capture
                        retryMode = false;
                        retakeBtn.style.display = 'none';
                        retakeBtn.classList.remove('retry');
                        retryMessageBox.style.display = 'none';
                        triggerCapture(result.landmarks);
                    } else {
                        var color = retryMode ? 'orange' : 'green';
                        drawMesh(result.landmarks, color);
                        if (retryMode) {
                            setState('state-retry','led-orange',
                                '<span class="c-orange">⟳</span> Retry: Face detected — locking on... (' + stableFrames + '/' + NEED_STABLE + ')');
                        } else {
                            setState('state-detect','led-green',
                                '<span class="c-green">■</span> Face detected — locking on... (' + stableFrames + '/' + NEED_STABLE + ')');
                        }
                    }
                } else {
                    lastLandmarks = null;
                    lastHadFace   = false;
                    stableFrames  = 0;
                    clearMesh();
                    progressWrap.classList.remove('show');
                    progressBar.style.width = '0%';
                    if (retryMode) {
                        setState('state-retry','led-orange','<span class="c-orange">⟳</span> Retry: No face detected — adjust position');
                    } else {
                        setState('state-noface','led-red','<span class="c-red">■</span> No face detected — adjust position');
                    }
                }
            });
    }

    loop();
}

function triggerCapture(landmarks){
    if(capturing || done) return;
    capturing = true;
    if(rafId){ cancelAnimationFrame(rafId); rafId=null; }

    setState('state-detect','led-green','<span class="c-green">■</span> Biometric locked — extracting descriptor...');
    progressBar.style.width = '100%';

    var color = retryMode ? 'orange' : 'green';
    drawMesh(landmarks, color);

    FaceRecognition.extractFromVideo(video)
        .then(function(result){
            snapCanvas.width  = video.videoWidth;
            snapCanvas.height = video.videoHeight;
            snapCanvas.getContext('2d').drawImage(video, 0, 0);
            previewImg.src = snapCanvas.toDataURL('image/jpeg', 0.85);
            holoWrap.classList.add('has-photo');

            descInput.value = JSON.stringify(result.descriptor);

            setState('state-ok','led-green','<span class="c-green">■</span> Identity verified — submitting...');
            done = true;
            clearMesh();

            setTimeout(function(){ form.submit(); }, 400);
        })
        .catch(function(err){
            capturing    = false;
            lastLandmarks = null;
            stableFrames  = 0;
            progressWrap.classList.remove('show');
            progressBar.style.width = '0%';

            // Set retry mode on failure
            retryMode = true;
            retakeBtn.style.display = 'block';
            retakeBtn.classList.add('retry');
            retakeBtn.textContent = '⟳ Retry — Scan Again';
            retryMessageBox.style.display = 'block';

            if(err && err.landmarks){
                drawMesh(err.landmarks, 'red');
            } else {
                clearMesh();
            }

            setState('state-retry','led-orange','<span class="c-orange">⟳</span> Extraction failed — auto-retry enabled');
            Swal.fire({
                icon:'error',
                title:'Scan Failed',
                text: err.message || 'Could not extract face data. Auto-retry activated. Please try again.',
                confirmButtonColor:'#3B82F6',
                timer: 2000,
                timerProgressBar: true
            }).then(function() {
                // Auto-retry: restart the scanner
                startCamera();
            });
        });
}

retakeBtn.addEventListener('click', function(){
    descInput.value = '';
    retryMode = true;
    retakeBtn.style.display = 'block';
    retakeBtn.classList.add('retry');
    retakeBtn.textContent = '⟳ Retry — Scan Again';
    retryMessageBox.style.display = 'block';
    setState('state-retry','led-orange','<span class="c-orange">⟳</span> Manual retry — restarting scanner...');
    startCamera();
});

form.addEventListener('submit', function(e){
    if(!descInput.value){
        e.preventDefault();
        Swal.fire({icon:'warning',title:'No Face Data',text:'Please wait for the scanner to detect your face.',confirmButtonColor:'#3B82F6'});
    }
});

startCamera();
window.addEventListener('beforeunload', function(){
    if(stream) stream.getTracks().forEach(function(t){ t.stop(); });
    if(rafId)  cancelAnimationFrame(rafId);
});

}); // DOMContentLoaded
</script>
</body>
</html>