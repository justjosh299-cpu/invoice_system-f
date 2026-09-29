<?php
require "config.php";
if (isset($_SESSION["user_id"])) { header("Location:index.php"); exit; }
$error = "";
$stage = $_POST["stage"] ?? "password";

if ($_SERVER["REQUEST_METHOD"] === "POST" && $stage === "password") {
    $u = trim($_POST["username"] ?? "");
    $p = $_POST["password"] ?? "";
    $s = $conn->prepare("SELECT * FROM users WHERE username=? AND active=1");
    $s->bind_param("s", $u); $s->execute();
    $x = $s->get_result()->fetch_assoc();
    if ($x && password_verify($p, $x["password_hash"])) {
        if (!empty($x["totp_enabled"]) && !empty($x["totp_secret"])) {
            $_SESSION["pending_2fa_user"] = $x["id"];
            header("Location: login.php?stage=2fa"); exit;
        }
        $_SESSION["user_id"] = $x["id"];
        $_SESSION["name"]    = $x["full_name"];
        $_SESSION["role"]    = $x["role"];
        $_SESSION["branch_id"] = (int)($x["branch_id"] ?? 0);
        if (function_exists("log_login_attempt")) log_login_attempt($conn, $x["id"], $u, 1);
        log_activity($conn, "Signed in", null);
        header("Location:index.php"); exit;
    }
    if (function_exists("log_login_attempt")) log_login_attempt($conn, $x["id"] ?? null, $u, 0);
    $error = "Invalid username or password.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $stage === "2fa") {
    $uid = (int)($_SESSION["pending_2fa_user"] ?? 0);
    $code = trim($_POST["code"] ?? "");
    if ($uid <= 0) { header("Location: login.php"); exit; }
    $s = $conn->prepare("SELECT * FROM users WHERE id=? AND active=1");
    $s->bind_param("i", $uid); $s->execute();
    $x = $s->get_result()->fetch_assoc();
    if ($x && class_exists("TOTP") && TOTP::verify($x["totp_secret"], $code)) {
        $_SESSION["user_id"] = $x["id"];
        $_SESSION["name"]    = $x["full_name"];
        $_SESSION["role"]    = $x["role"];
        $_SESSION["branch_id"] = (int)($x["branch_id"] ?? 0);
        unset($_SESSION["pending_2fa_user"]);
        if (function_exists("log_login_attempt")) log_login_attempt($conn, $x["id"], $x["username"], 1);
        log_activity($conn, "Signed in (2FA)", null);
        header("Location:index.php"); exit;
    }
    if (function_exists("log_login_attempt")) log_login_attempt($conn, $uid, "", 0);
    $error = "Invalid or expired code.";
}

$asking2fa = isset($_GET["stage"]) && $_GET["stage"] === "2fa" && isset($_SESSION["pending_2fa_user"]);
?><!doctype html><html><head><title>OMG Diagnostic Login</title>
<link rel="stylesheet" href="assets/style.css"></head><body class="login">
<?php if ($asking2fa): ?>
  <form method="post" class="loginbox">
    <input type="hidden" name="stage" value="2fa">
    <div class="brand">OMG TECHNOLOGIES UGANDA</div>
    <h1>Two-Factor Code</h1>
    <?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <p class="hint">Open your authenticator app and enter the 6-digit code.</p>
    <label>Code<input name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus required></label>
    <button class="btn">Verify</button>
    <p class="hint">Wrong device? <a href="login.php">Restart sign-in</a></p>
  </form>
<?php else: ?>
  <form method="post" class="loginbox">
    <input type="hidden" name="stage" value="password">
    <div class="brand">OMG TECHNOLOGIES UGANDA</div>
    <h1>Diagnostic System</h1>
    <?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <label>Username<input name="username" required autofocus></label>
    <label>Password<input type="password" name="password" required></label>
    <button class="btn">Sign In</button>
    <p class="hint">Client checking status? <a href="portal.php">Use the Client Portal →</a></p>
  </form>
<?php endif; ?>
</body></html>