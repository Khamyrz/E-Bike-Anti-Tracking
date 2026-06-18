<?php
session_start();

$conn = new mysqli("localhost", "root", "", "ebike_tracker");
$message = "";

if (isset($_POST['register'])) {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $check = $conn->query("SELECT * FROM users WHERE email='$email'");

    if ($check->num_rows > 0) {
        $message = "Email already exists.";
    } else {
        $conn->query("INSERT INTO users (fullname, email, phone, password, role, status) VALUES ('$fullname', '$email', '$phone', '$password', 'rider', 'pending')");
        $message = "Registration successful. Waiting for Admin Approval.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rider Registration</title>
  <style>
    * { box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #0f172a, #1e293b);
      padding: 20px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: #fff;
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 18px 35px rgba(0,0,0,0.25);
    }
    .header {
      background: linear-gradient(135deg, #0ea5e9, #2563eb);
      padding: 28px;
      color: #fff;
      text-align: center;
    }
    .header h1 { margin: 0 0 6px; font-size: 28px; }
    .header p { margin: 0; font-size: 14px; color: #e0f2fe; }
    .body { padding: 24px; }
    .input-group { margin-bottom: 14px; }
    .input-group input {
      width: 100%;
      padding: 14px;
      border: 1px solid #dbe4ee;
      border-radius: 12px;
      font-size: 15px;
    }
    .btn {
      width: 100%;
      padding: 14px;
      border: none;
      border-radius: 12px;
      background: #2563eb;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
    }
    .btn:hover { background: #1d4ed8; }
    .message { text-align: center; color: #b91c1c; font-weight: 600; margin-bottom: 12px; }
    .link { text-align: center; margin-top: 10px; font-size: 13px; }
    .link a { color: #2563eb; text-decoration: none; font-weight: 600; }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <h1>Rider Registration</h1>
      <p>Sign up to start tracking your e-bike.</p>
    </div>
    <div class="body">
      <?php if ($message != '') echo "<div class='message'>$message</div>"; ?>
      <form method="POST">
        <div class="input-group"><input type="text" name="fullname" placeholder="Full Name" required></div>
        <div class="input-group"><input type="email" name="email" placeholder="Email" required></div>
        <div class="input-group"><input type="text" name="phone" placeholder="Phone Number" required></div>
        <div class="input-group"><input type="password" name="password" placeholder="Password" required></div>
        <button type="submit" name="register" class="btn">Register Rider</button>
      </form>
      <div class="link"><a href="../index.php">Back to Login</a></div>
    </div>
  </div>
</body>
</html>
