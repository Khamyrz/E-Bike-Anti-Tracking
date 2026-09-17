<?php
session_start();

if (!isset($_SESSION['rider'])) {
    header("Location: ../index.php");
    exit;
}

include("../config/database.php");
require_once("../includes/rental-timer.php");
require_once("../includes/rental-notifications.php");

$rider_id = (int)$_SESSION['rider'];
$rider_name = isset($_SESSION['rider_name']) ? $_SESSION['rider_name'] : 'Rider';

$rentalTimer = new RentalTimer($conn, $rider_id);
$rentalStatus = $rentalTimer->getRentalStatus($rider_id);
$is_stolen = $rentalTimer->isRiderStolen($rider_id);

// Check if there's an active rental
if (!$rentalStatus || !$rentalStatus['has_rental']) {
    $_SESSION['flash_message'] = 'No active rental session found.';
    header("Location: dashboard.php");
    exit;
}

// Handle return submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_ebike'])) {
    $result = $rentalTimer->returnEbike($rider_id);
    
    if ($result['success']) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        header("Location: ../index.php?returned=1");
        exit;
    } else {
        $error = $result['message'];
    }
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Return E-Bike — MotoAdmin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg-base: #0B1120;
            --bg-surface: #111827;
            --bg-card: #1E293B;
            --bg-card-hover: #243044;
            --border: #2D3F55;
            --accent-blue: #3B82F6;
            --accent-green: #10B981;
            --accent-red: #EF4444;
            --accent-amber: #F59E0B;
            --text-primary: #F1F5F9;
            --text-secondary: #94A3B8;
            --text-muted: #475569;
            --radius: 12px;
            --radius-sm: 8px;
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-base);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar .brand {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--text-primary);
            text-decoration: none;
        }

        .topbar .brand span {
            color: var(--accent-blue);
        }

        .topbar .back-btn {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            border: 1px solid var(--border);
            transition: all 0.2s;
        }

        .topbar .back-btn:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        .main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .return-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }

        .return-card .icon {
            font-size: 56px;
            margin-bottom: 16px;
        }

        .return-card h2 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .return-card .subtitle {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 24px;
        }

        .return-card .details {
            background: var(--bg-surface);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-bottom: 24px;
            text-align: left;
        }

        .return-card .details .row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        .return-card .details .row:last-child {
            border-bottom: none;
        }

        .return-card .details .label {
            color: var(--text-muted);
        }

        .return-card .details .value {
            color: var(--text-primary);
            font-weight: 500;
        }

        .return-card .details .value.ebike {
            color: var(--accent-blue);
            font-weight: 700;
        }

        .return-card .timer-info {
            background: rgba(59, 130, 246, 0.08);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .return-card .timer-info strong {
            color: var(--text-primary);
        }

        .return-card .timer-info .time {
            color: var(--accent-blue);
            font-weight: 700;
            font-size: 18px;
        }

        .btn {
            display: inline-block;
            padding: 12px 32px;
            border-radius: var(--radius-sm);
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn:active {
            transform: scale(0.97);
        }

        .btn-primary {
            background: var(--accent-blue);
            color: #fff;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-success {
            background: var(--accent-green);
            color: #fff;
        }

        .btn-success:hover {
            opacity: 0.9;
        }

        .btn-danger {
            background: var(--accent-red);
            color: #fff;
        }

        .btn-danger:hover {
            opacity: 0.9;
        }

        .btn-secondary {
            background: var(--bg-surface);
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        .flash {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            font-size: 13px;
            font-weight: 500;
        }

        .flash.success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.25);
            color: #6ee7b7;
        }

        .flash.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
        }

        .warning-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 20px;
            color: var(--accent-amber);
            font-size: 13px;
            text-align: left;
        }

        .warning-box strong {
            display: block;
            margin-bottom: 4px;
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        @media (max-width: 480px) {
            .return-card {
                padding: 20px;
            }

            .return-card .details .row {
                flex-direction: column;
                gap: 2px;
                padding: 6px 0;
            }

            .actions {
                flex-direction: column;
            }

            .actions .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <a href="dashboard.php" class="brand">Moto<span>Admin</span></a>
    <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
</header>

<main class="main">
    <div class="return-card">
        <?php if ($is_stolen): ?>
            <div class="icon">🚨</div>
            <h2>Vehicle Declared Stolen</h2>
            <p class="subtitle">This vehicle has been declared stolen. Please contact support immediately.</p>
            <div class="warning-box">
                <strong>⚠️ URGENT</strong>
                You have failed to return the E-Bike within the 24-hour period. 
                This is a serious matter. Please contact support immediately.
            </div>
            <div class="actions">
                <button class="btn btn-danger" onclick="contactSupport()">Contact Support Now</button>
                <a href="dashboard.php" class="btn btn-secondary">Go to Dashboard</a>
            </div>
        <?php else: ?>
            <div class="icon">🔑</div>
            <h2>Return E-Bike</h2>
            <p class="subtitle">Confirm that you are returning the e-bike to end your rental session.</p>

            <?php if (isset($error)): ?>
                <div class="flash error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="flash success"><?php echo htmlspecialchars($flash); ?></div>
            <?php endif; ?>

            <div class="details">
                <div class="row">
                    <span class="label">Rider</span>
                    <span class="value"><?php echo htmlspecialchars($rider_name); ?></span>
                </div>
                <div class="row">
                    <span class="label">E-Bike ID</span>
                    <span class="value ebike"><?php echo htmlspecialchars($rentalStatus['ebike_id']); ?></span>
                </div>
                <div class="row">
                    <span class="label">Started</span>
                    <span class="value"><?php echo date('M j, g:i A', strtotime($rentalStatus['start_time'])); ?></span>
                </div>
                <div class="row">
                    <span class="label">Ends</span>
                    <span class="value"><?php echo date('M j, g:i A', strtotime($rentalStatus['end_time'])); ?></span>
                </div>
            </div>

            <div class="timer-info">
                <strong>⏱️ Time Remaining:</strong>
                <span class="time" id="return-timer"><?php echo $rentalStatus['time_remaining_formatted']; ?></span>
            </div>

            <?php if ($rentalStatus['is_grace_period']): ?>
                <div class="warning-box">
                    <strong>⚠️ GRACE PERIOD</strong>
                    Your rental has expired. You have <strong><?php echo $rentalTimer->grace_period_minutes; ?> minutes</strong> 
                    to return the E-Bike. If you fail to return it, the vehicle will be declared as stolen.
                </div>
            <?php endif; ?>

            <form method="POST" onsubmit="return confirm('Are you sure you want to return this E-Bike?\n\nYour rental will end, you will be logged out, and your account will be permanently removed. You must register again with the administrator to log in.');">
                <input type="hidden" name="return_ebike" value="1">
                <div class="actions">
                    <button type="submit" class="btn <?php echo $rentalStatus['is_grace_period'] ? 'btn-danger' : 'btn-success'; ?>">
                        <?php echo $rentalStatus['is_grace_period'] ? '⚠️ Return E-Bike Now!' : '✅ Return E-Bike'; ?>
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<script>
// Contact support function
window.contactSupport = function() {
    alert('Please contact support at: support@motoadmin.com or call +63 900 000 0000');
};

// Real-time timer update
(function() {
    const timerElement = document.getElementById('return-timer');
    if (!timerElement) return;

    const endTime = '<?php echo $rentalStatus['end_time'] ?? ''; ?>';
    if (!endTime) return;

    function updateTimer() {
        const now = Math.floor(Date.now() / 1000);
        const end = Math.floor(new Date(endTime).getTime() / 1000);
        let remaining = end - now;

        if (remaining <= 0) {
            timerElement.textContent = '00:00:00';
            // Check if we should reload to show grace period
            if (remaining > -600) { // Within grace period
                setTimeout(function() { location.reload(); }, 5000);
            }
            return;
        }

        const hours = Math.floor(remaining / 3600);
        const minutes = Math.floor((remaining % 3600) / 60);
        const seconds = remaining % 60;

        timerElement.textContent = 
            String(hours).padStart(2, '0') + ':' +
            String(minutes).padStart(2, '0') + ':' +
            String(seconds).padStart(2, '0');
    }

    updateTimer();
    setInterval(updateTimer, 1000);
})();
</script>

</body>
</html>