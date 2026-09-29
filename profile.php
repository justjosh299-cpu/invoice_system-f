<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$uid = (int)$_SESSION["user_id"];
$msg = ""; $err = "";

if (isset($_GET["avatar"])) {
    if ($_GET["avatar"] === "1") $msg = "Profile photo updated.";
    elseif ($_GET["avatar"] === "removed") $msg = "Profile photo removed.";
}
if (isset($_GET["err"])) $err = $_GET["err"];

$s = $conn->prepare("SELECT * FROM users WHERE id=?");
$s->bind_param("i", $uid); $s->execute();
$me = $s->get_result()->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    if ($action === "start_totp") {
        $secret = TOTP::randomSecret(20);
        $u = $conn->prepare("UPDATE users SET totp_secret=?, totp_enabled=0 WHERE id=?");
        $u->bind_param("si", $secret, $uid); $u->execute();
        header("Location: profile.php?setup=1"); exit;
    }
    if ($action === "confirm_totp") {
        $code = trim($_POST["code"] ?? "");
        if (TOTP::verify($me["totp_secret"], $code)) {
            $conn->query("UPDATE users SET totp_enabled=1 WHERE id=$uid");
            log_activity($conn, "Enabled 2FA");
            $msg = "Two-factor authentication enabled.";
            $me["totp_enabled"] = 1;
        } else $err = "Invalid code. Try again.";
    }
    if ($action === "disable_totp") {
        $conn->query("UPDATE users SET totp_enabled=0, totp_secret=NULL WHERE id=$uid");
        log_activity($conn, "Disabled 2FA");
        $msg = "Two-factor disabled.";
        $me["totp_enabled"] = 0;
    }
    if ($action === "save_theme") {
        set_user_theme($conn, $uid, $_POST["theme"] ?? "light");
        header("Location: profile.php?theme=1"); exit;
    }
    if ($action === "save_branch") {
        $bid = (int)($_POST["branch_id"] ?? 0);
        $u = $conn->prepare("UPDATE users SET branch_id=? WHERE id=?");
        $u->bind_param("ii", $bid, $uid); $u->execute();
        $_SESSION["branch_id"] = $bid;
        $msg = "Branch preference saved.";
    }
    // Reload
    $s = $conn->prepare("SELECT * FROM users WHERE id=?");
    $s->bind_param("i", $uid); $s->execute();
    $me = $s->get_result()->fetch_assoc();
}

$theme = get_user_theme($conn, $uid);
$branches = function_exists('list_branches') ? list_branches($conn, true) : [];
$showSetup = isset($_GET["setup"]) && !empty($me["totp_secret"]) && !$me["totp_enabled"];

$avatarUrl = "";
if (!empty($me["avatar"]) && file_exists(__DIR__ . '/' . $me["avatar"])) {
    $avatarUrl = $me["avatar"];
}

function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>My Profile</title>
<link rel="stylesheet" href="assets/style.css">
<?php if ($theme === "dark"): ?>
<style>
  body { background:#0e1420; color:#e2e8f0; }
  .panel { background:#18202e; border-color:#26303f; color:#e2e8f0; }
  .panel h2 { color:#90caf9; }
  header { background:#050a12; }
  input, select, textarea { background:#0e1420; color:#e2e8f0; border-color:#2a3444; }
  table th { background:#0e1420; color:#90caf9; }
  table td { border-color:#26303f; }
  .btn { background:#1e3a5f; }
  .btn.secondary { background:#2a3444; color:#e2e8f0; }
  .btn.danger { background:#7a1c1c; }
  .success { background:#12301e; color:#7ae0a0; }
  .error { background:#3a1414; color:#ff9b9b; }
  .hint { color:#8896a8; }
  .avatar-preview { background:#0e1420; border-color:#2a3444; }
</style>
<?php endif; ?>
<style>
  .avatar-preview {
    width: 160px; height: 160px; border-radius: 50%;
    border: 3px solid #cfd8e3; background: #f4f6f8;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; font-size: 56px; font-weight: 700; color: #0b2545;
  }
  .avatar-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .avatar-row { display: flex; gap: 24px; align-items: center; flex-wrap: wrap; }

  .signout-panel { text-align: center; }
  .signout-panel .btn-signout {
    display: inline-flex; align-items: center; gap: 8px;
    background: #a12626; color: #fff; padding: 12px 24px;
    border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px;
    transition: background .15s;
  }
  .signout-panel .btn-signout:hover { background: #7a1c1c; }
</style>
</head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>My Profile</h1><p>Photo, security, preferences, and defaults.</p></div></div>
  <?php if ($msg): ?><div class="success"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>

  <!-- PROFILE PHOTO -->
  <div class="panel">
    <h2>Profile Photo</h2>
    <div class="avatar-row">
      <div class="avatar-preview">
        <?php if ($avatarUrl): ?>
          <img src="<?=h($avatarUrl)?>?v=<?=time()?>" alt="Avatar">
        <?php else: ?>
          <?=strtoupper(substr($me["full_name"], 0, 1))?>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:240px">
        <form method="post" action="avatar_upload.php" enctype="multipart/form-data">
          <label>Choose an image (PNG/JPG/WebP, max 1 MB)
            <input type="file" name="avatar" accept=".png,.jpg,.jpeg,.webp,.gif" required>
          </label>
          <div class="actions" style="justify-content:flex-start">
            <button class="btn">Upload</button>
            <?php if ($avatarUrl): ?>
              <a class="btn secondary" href="avatar_upload.php?remove=1" onclick="return confirm('Remove your profile photo?')">Remove</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="panel"><h2>Account</h2>
    <div class="grid">
      <label>Username<input value="<?=h($me["username"])?>" disabled></label>
      <label>Full Name<input value="<?=h($me["full_name"])?>" disabled></label>
      <label>Role<input value="<?=h($me["role"])?>" disabled></label>
    </div>
  </div>

  <div class="panel"><h2>Two-Factor Authentication (TOTP)</h2>
    <?php if (!empty($me["totp_enabled"])): ?>
      <p class="success">✔ 2FA is enabled on your account.</p>
      <form method="post" onsubmit="return confirm('Disable 2FA?')">
        <input type="hidden" name="action" value="disable_totp">
        <button class="btn danger">Disable 2FA</button>
      </form>
    <?php elseif ($showSetup && !empty($me["totp_secret"])): ?>
      <p class="hint">Scan this QR in Google Authenticator, Authy, or 1Password, then enter the 6-digit code to confirm.</p>
      <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
        <div id="qr" style="background:#fff;padding:8px;border-radius:8px;border:1px solid #cfd8e3"></div>
        <div>
          <p><b>Or enter this secret manually:</b></p>
          <p style="font-family:monospace;font-size:14px;background:#eef3f9;color:#0b2545;padding:8px 12px;border-radius:6px;letter-spacing:2px"><?=h($me["totp_secret"])?></p>
        </div>
      </div>
      <form method="post" style="margin-top:14px">
        <input type="hidden" name="action" value="confirm_totp">
        <label>6-digit code<input name="code" inputmode="numeric" maxlength="6" required autofocus></label>
        <div class="actions"><button class="btn">Confirm &amp; Enable</button></div>
      </form>
    <?php else: ?>
      <p class="hint">2FA is not enabled. Turn it on for an extra layer of security.</p>
      <form method="post">
        <input type="hidden" name="action" value="start_totp">
        <button class="btn">Enable 2FA</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="panel"><h2>Theme</h2>
    <form method="post">
      <input type="hidden" name="action" value="save_theme">
      <label>Choose theme
        <select name="theme">
          <option value="light" <?=$theme==="light"?"selected":""?>>Light</option>
          <option value="dark" <?=$theme==="dark"?"selected":""?>>Dark</option>
        </select>
      </label>
      <div class="actions"><button class="btn">Save Theme</button></div>
    </form>
  </div>

  <?php if (!empty($branches)): ?>
  <div class="panel"><h2>Default Branch</h2>
    <form method="post">
      <input type="hidden" name="action" value="save_branch">
      <label>Branch
        <select name="branch_id">
          <option value="0">— Not set —</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?=(int)$b["id"]?>" <?=((int)($me["branch_id"] ?? 0)===(int)$b["id"])?"selected":""?>><?=h($b["name"])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="actions"><button class="btn">Save Branch</button></div>
    </form>
  </div>
  <?php endif; ?>

  <!-- SIGN OUT -->
  <div class="panel signout-panel">
    <h2>Sign Out</h2>
    <p class="hint" style="margin-bottom:14px">Finished for the day? Sign out of your account safely.</p>
    <a class="btn-signout" href="logout.php"
       onclick="return confirm('Sign out now?')">
      🚪 Sign Out
    </a>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<?php if ($showSetup && !empty($me["totp_secret"])): ?>
<script>
new QRCode(document.getElementById("qr"), {
  text: <?=json_encode(TOTP::otpauthURL("OMG Technologies", $me["username"], $me["totp_secret"]))?>,
  width: 160, height: 160,
  colorDark: "#0b2545", colorLight: "#ffffff",
  correctLevel: QRCode.CorrectLevel.M
});
</script>
<?php endif; ?>
</body></html>