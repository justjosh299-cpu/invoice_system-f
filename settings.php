<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if (!is_admin()) { header("Location:index.php"); exit; }
$msg = ""; $err = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $section = $_POST["section"] ?? "";
    if ($section === "header") {
        $mode = ($_POST["header_mode"] ?? "classic") === "banner" ? "banner" : "classic";
        set_setting($conn, "header_mode", $mode);
        $msg .= "Header style saved. ";
        if (!empty($_FILES["letterhead_banner"]["name"]) && $_FILES["letterhead_banner"]["error"] === UPLOAD_ERR_OK) {
            $allowed = ["png"=>1,"jpg"=>1,"jpeg"=>1,"gif"=>1,"webp"=>1];
            if ($_FILES["letterhead_banner"]["size"] > 2 * 1024 * 1024) $err .= "Banner too large. ";
            else {
                $ext = strtolower(pathinfo($_FILES["letterhead_banner"]["name"], PATHINFO_EXTENSION));
                if (!isset($allowed[$ext])) $err .= "Bad banner format. ";
                else {
                    $dest = "assets/uploads/letterhead_banner." . $ext;
                    foreach ($allowed as $e => $_) @unlink(__DIR__ . "/assets/uploads/letterhead_banner." . $e);
                    if (move_uploaded_file($_FILES["letterhead_banner"]["tmp_name"], __DIR__ . "/" . $dest)) {
                        set_setting($conn, "letterhead_banner", $dest);
                        $msg .= "Banner uploaded. "; log_activity($conn, "Uploaded letterhead banner");
                    } else $err .= "Could not save banner. ";
                }
            }
        }
        if (isset($_POST["clear_banner"])) {
            $conn->query("DELETE FROM settings WHERE setting_key='letterhead_banner'");
            foreach (["png","jpg","jpeg","gif","webp"] as $e) @unlink(__DIR__ . "/assets/uploads/letterhead_banner." . $e);
            $msg .= "Banner cleared. ";
        }
    }
    if ($section === "logos") {
        $targets = ["company_logo" => "company_logo", "coat_of_arms" => "coat_of_arms"];
        $allowed = ["png"=>1,"jpg"=>1,"jpeg"=>1,"gif"=>1,"svg"=>1];
        foreach ($targets as $field => $key) {
            if (!empty($_FILES[$field]["name"]) && $_FILES[$field]["error"] === UPLOAD_ERR_OK) {
                if ($_FILES[$field]["size"] > 1024*1024) { $err .= "File too large for $field. "; continue; }
                $ext = strtolower(pathinfo($_FILES[$field]["name"], PATHINFO_EXTENSION));
                if (!isset($allowed[$ext])) { $err .= "Bad format for $field. "; continue; }
                $dest = "assets/uploads/" . $key . "." . $ext;
                foreach ($allowed as $e => $_) @unlink(__DIR__ . "/assets/uploads/" . $key . "." . $e);
                if (move_uploaded_file($_FILES[$field]["tmp_name"], __DIR__ . "/" . $dest)) {
                    set_setting($conn, $key, $dest);
                    $msg .= ucfirst(str_replace("_"," ",$key)) . " uploaded. ";
                } else $err .= "Could not save $field. ";
            }
        }
        if (isset($_POST["clear_logo"])) {
            $conn->query("DELETE FROM settings WHERE setting_key='company_logo'");
            foreach (["png","jpg","jpeg","gif","svg"] as $e) @unlink(__DIR__ . "/assets/uploads/company_logo." . $e);
            $msg .= "Logo cleared. ";
        }
        if (isset($_POST["clear_coat"])) {
            $conn->query("DELETE FROM settings WHERE setting_key='coat_of_arms'");
            foreach (["png","jpg","jpeg","gif","svg"] as $e) @unlink(__DIR__ . "/assets/uploads/coat_of_arms." . $e);
            $msg .= "Coat cleared. ";
        }
    }
    if ($section === "email") {
        set_setting($conn, "smtp_host",     trim($_POST["smtp_host"] ?? ""));
        set_setting($conn, "smtp_port",     trim($_POST["smtp_port"] ?? ""));
        set_setting($conn, "smtp_user",     trim($_POST["smtp_user"] ?? ""));
        set_setting($conn, "smtp_pass",     $_POST["smtp_pass"] ?? "");
        set_setting($conn, "smtp_from",     trim($_POST["smtp_from"] ?? ""));
        set_setting($conn, "smtp_from_name",trim($_POST["smtp_from_name"] ?? ""));
        set_setting($conn, "smtp_secure",   ($_POST["smtp_secure"] ?? "tls") === "ssl" ? "ssl" : "tls");
        $msg .= "Email saved. ";
    }
    if ($section === "sms") {
        set_setting($conn, "sms_enabled", isset($_POST["sms_enabled"]) ? "1" : "0");
        set_setting($conn, "sms_provider", $_POST["sms_provider"] ?? "africastalking");
        set_setting($conn, "sms_username", trim($_POST["sms_username"] ?? ""));
        set_setting($conn, "sms_api_key",  trim($_POST["sms_api_key"] ?? ""));
        set_setting($conn, "sms_sender",   trim($_POST["sms_sender"] ?? ""));
        set_setting($conn, "sms_url",      trim($_POST["sms_url"] ?? ""));
        $msg .= "SMS saved. ";
    }
    if ($section === "workflow") {
        set_setting($conn, "workflow_strict", isset($_POST["workflow_strict"]) ? "1" : "0");
        $msg .= "Workflow saved. ";
    }
    if ($section === "invoice") {
        set_setting($conn, "invoice_prefix",   trim($_POST["invoice_prefix"] ?? "INV") ?: "INV");
        set_setting($conn, "invoice_currency", trim($_POST["invoice_currency"] ?? "UGX") ?: "UGX");
        set_setting($conn, "invoice_vat",      trim($_POST["invoice_vat"] ?? "18"));
        set_setting($conn, "invoice_due_days", trim($_POST["invoice_due_days"] ?? "14"));
        set_setting($conn, "invoice_terms",    trim($_POST["invoice_terms"] ?? ""));
        $msg .= "Invoice settings saved. ";
        log_activity($conn, "Updated invoice settings");
    }
    if ($section === "brand") {
        set_setting($conn, "brand_colour",      $_POST["brand_colour"] ?? "#0b2545");
        set_setting($conn, "brand_colour_dark", $_POST["brand_colour_dark"] ?? "#12345f");
        $msg .= "Brand colours saved. ";
        log_activity($conn, "Updated brand colour");
    }
    if ($section === "backup") {
        set_setting($conn, "backup_key", trim($_POST["backup_key"] ?? ""));
        $keep = (int)($_POST["backup_keep_days"] ?? 30);
        if ($keep < 1) $keep = 1;
        if ($keep > 365) $keep = 365;
        set_setting($conn, "backup_keep_days", (string)$keep);
        $msg .= "Backup settings saved. ";
    }
}
if (isset($_GET["regen_key"]) && is_admin()) {
    set_setting($conn, "backup_key", bin2hex(random_bytes(12)));
    header("Location: settings.php"); exit;
}

$logo    = get_setting($conn, "company_logo", "");
$coat    = get_setting($conn, "coat_of_arms", "");
$banner  = get_setting($conn, "letterhead_banner", "");
$mode    = get_header_mode($conn);
$smtpHost = get_setting($conn, "smtp_host", ""); $smtpPort = get_setting($conn, "smtp_port", "");
$smtpUser = get_setting($conn, "smtp_user", ""); $smtpPass = get_setting($conn, "smtp_pass", "");
$smtpFrom = get_setting($conn, "smtp_from", ""); $smtpName = get_setting($conn, "smtp_from_name", "OMG Technologies Uganda");
$smtpSecure = get_setting($conn, "smtp_secure", "tls");
$smsEnabled = get_setting($conn, "sms_enabled", "0");
$smsProvider = get_setting($conn, "sms_provider", "africastalking");
$smsUser = get_setting($conn, "sms_username", ""); $smsKey = get_setting($conn, "sms_api_key", "");
$smsSender = get_setting($conn, "sms_sender", ""); $smsUrl = get_setting($conn, "sms_url", "");
$backupKey = get_setting($conn, "backup_key", "");
$backupKeepDays = get_setting($conn, "backup_keep_days", "30");
$workflowStrict = get_setting($conn, "workflow_strict", "1");

$invPrefix = get_setting($conn, "invoice_prefix", "INV");
$invCurrency = get_setting($conn, "invoice_currency", "UGX");
$invVat = get_setting($conn, "invoice_vat", "18");
$invDueDays = get_setting($conn, "invoice_due_days", "14");
$invTerms = get_setting($conn, "invoice_terms", "");

$brandColour     = get_setting($conn, "brand_colour", "#0b2545");
$brandColourDark = get_setting($conn, "brand_colour_dark", "#12345f");

$dompdfInstalled = file_exists(__DIR__ . '/vendor/dompdf/autoload.inc.php');
$phpMailerInstalled = file_exists(__DIR__ . '/vendor/PHPMailer/src/PHPMailer.php');
?><!doctype html><html><head><title>Settings</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Settings</h1><p>Header, brand, images, email, SMS, workflow, invoices, backups.</p></div></div>
  <?php if ($msg): ?><div class="success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="error"><?=htmlspecialchars($err)?></div><?php endif; ?>
  <?php if (isset($_GET["backup"])): ?><div class="success">Backup: <b><?=htmlspecialchars($_GET["backup"])?></b></div><?php endif; ?>

  <!-- BRAND COLOUR -->
  <div class="panel">
    <h2>Brand Colour</h2>
    <p class="hint">Applies to the top navigation bar and accent colours across the app.</p>
    <form method="post">
      <input type="hidden" name="section" value="brand">
      <div class="grid">
        <label>Primary Colour
          <input type="color" name="brand_colour" value="<?=htmlspecialchars($brandColour)?>">
        </label>
        <label>Hover / Accent Colour
          <input type="color" name="brand_colour_dark" value="<?=htmlspecialchars($brandColourDark)?>">
        </label>
      </div>
      <div class="actions">
        <button class="btn">Save Brand Colours</button>
      </div>
    </form>
  </div>

  <!-- HEADER -->
  <div class="panel">
    <h2>Report Header Style</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="section" value="header">
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(260px,1fr))">
        <label class="modecard <?=$mode==='classic'?'selected':''?>"><input type="radio" name="header_mode" value="classic" <?=$mode==='classic'?'checked':''?> style="width:auto"><b>Classic</b><small>Logo · Text · Coat of Arms</small></label>
        <label class="modecard <?=$mode==='banner'?'selected':''?>"><input type="radio" name="header_mode" value="banner" <?=$mode==='banner'?'checked':''?> style="width:auto"><b>Banner</b><small>Full-width letterhead</small></label>
      </div>
      <h3 style="margin-top:20px;font-size:14px;color:#0b2545">Letterhead Banner</h3>
      <div class="bannerpreview">
        <?php if ($banner && file_exists(__DIR__ . '/' . $banner)): ?><img src="<?=htmlspecialchars($banner)?>?v=<?=time()?>"><?php else: ?><span class="empty">No banner</span><?php endif; ?>
      </div>
      <label style="margin-top:8px">Choose banner<input type="file" name="letterhead_banner" accept=".png,.jpg,.jpeg,.gif,.webp"></label>
      <div class="actions">
        <?php if ($banner): ?><button type="submit" name="clear_banner" value="1" class="btn secondary" onclick="return confirm('Remove banner?')">Remove Banner</button><?php endif; ?>
        <button class="btn">Save Header</button>
      </div>
    </form>
  </div>

  <!-- LOGOS -->
  <div class="panel">
    <h2>Company Logo &amp; Coat of Arms</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="section" value="logos">
      <div class="grid">
        <div><p class="hint">Company Logo</p><div class="logopreview"><?php if ($logo && file_exists(__DIR__ . '/' . $logo)): ?><img src="<?=htmlspecialchars($logo)?>?v=<?=time()?>"><?php else: ?><span class="empty">No logo</span><?php endif; ?></div><label>Choose file<input type="file" name="company_logo" accept=".png,.jpg,.jpeg,.gif,.svg"></label></div>
        <div><p class="hint">Coat of Arms</p><div class="logopreview"><?php if ($coat && file_exists(__DIR__ . '/' . $coat)): ?><img src="<?=htmlspecialchars($coat)?>?v=<?=time()?>"><?php else: ?><span class="empty">No coat</span><?php endif; ?></div><label>Choose file<input type="file" name="coat_of_arms" accept=".png,.jpg,.jpeg,.gif,.svg"></label></div>
      </div>
      <div class="actions">
        <?php if ($logo): ?><button type="submit" name="clear_logo" value="1" class="btn secondary" onclick="return confirm('Remove logo?')">Remove Logo</button><?php endif; ?>
        <?php if ($coat): ?><button type="submit" name="clear_coat" value="1" class="btn secondary" onclick="return confirm('Remove coat?')">Remove Coat</button><?php endif; ?>
        <button class="btn">Upload</button>
      </div>
    </form>
  </div>

  <!-- INVOICE -->
  <div class="panel">
    <h2>Invoice Settings</h2>
    <form method="post">
      <input type="hidden" name="section" value="invoice">
      <div class="grid">
        <label>Invoice Prefix<input name="invoice_prefix" value="<?=htmlspecialchars($invPrefix)?>" placeholder="INV"></label>
        <label>Default Currency<input name="invoice_currency" value="<?=htmlspecialchars($invCurrency)?>" placeholder="UGX"></label>
        <label>Default VAT Rate (%)<input type="number" step="0.01" name="invoice_vat" value="<?=htmlspecialchars($invVat)?>"></label>
        <label>Due Days<input type="number" name="invoice_due_days" value="<?=htmlspecialchars($invDueDays)?>"></label>
      </div>
      <label>Default Terms<textarea name="invoice_terms"><?=htmlspecialchars($invTerms)?></textarea></label>
      <div class="actions"><button class="btn">Save Invoice Settings</button></div>
    </form>
  </div>

  <!-- WORKFLOW -->
  <div class="panel">
    <h2>Status Workflow</h2>
    <form method="post">
      <input type="hidden" name="section" value="workflow">
      <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="workflow_strict" <?=$workflowStrict==='1'?'checked':''?> style="width:auto"> Enforce strict status transitions</label>
      <div class="actions"><button class="btn">Save Workflow</button></div>
    </form>
  </div>

  <!-- EMAIL -->
  <div class="panel">
    <h2>Email (SMTP)</h2>
    <?php if (!$phpMailerInstalled): ?><div class="warn">PHPMailer not installed. Extract <code>src/</code> to <code>vendor/PHPMailer/</code>.</div><?php else: ?><div class="success">✔ PHPMailer detected.</div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="section" value="email">
      <div class="grid">
        <label>SMTP Host<input name="smtp_host" value="<?=htmlspecialchars($smtpHost)?>"></label>
        <label>Port<input name="smtp_port" value="<?=htmlspecialchars($smtpPort)?>"></label>
        <label>Security<select name="smtp_secure"><option value="tls" <?=$smtpSecure==="tls"?"selected":""?>>TLS</option><option value="ssl" <?=$smtpSecure==="ssl"?"selected":""?>>SSL</option></select></label>
        <label>Username<input name="smtp_user" value="<?=htmlspecialchars($smtpUser)?>"></label>
        <label>Password<input type="password" name="smtp_pass" value="<?=htmlspecialchars($smtpPass)?>"></label>
        <label>From Email<input name="smtp_from" value="<?=htmlspecialchars($smtpFrom)?>"></label>
        <label>From Name<input name="smtp_from_name" value="<?=htmlspecialchars($smtpName)?>"></label>
      </div>
      <div class="actions"><button class="btn">Save Email</button></div>
    </form>
  </div>

  <!-- SMS -->
  <div class="panel">
    <h2>SMS Notifications</h2>
    <form method="post">
      <input type="hidden" name="section" value="sms">
      <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><input type="checkbox" name="sms_enabled" <?=$smsEnabled==='1'?'checked':''?> style="width:auto"> Enable SMS on status change</label>
      <div class="grid">
        <label>Provider<select name="sms_provider"><option value="africastalking" <?=$smsProvider==="africastalking"?"selected":""?>>Africa's Talking</option><option value="custom" <?=$smsProvider==="custom"?"selected":""?>>Custom HTTP</option></select></label>
        <label>Username<input name="sms_username" value="<?=htmlspecialchars($smsUser)?>"></label>
        <label>API Key<input type="password" name="sms_api_key" value="<?=htmlspecialchars($smsKey)?>"></label>
        <label>Sender ID<input name="sms_sender" value="<?=htmlspecialchars($smsSender)?>"></label>
        <label>Custom URL<input name="sms_url" value="<?=htmlspecialchars($smsUrl)?>"></label>
      </div>
      <div class="actions"><button class="btn">Save SMS</button></div>
    </form>
  </div>

  <!-- BACKUPS -->
  <div class="panel">
    <h2>Backups</h2>
    <p class="hint">Cron URL: <code>http://<?=htmlspecialchars($_SERVER["HTTP_HOST"])?><?=htmlspecialchars(dirname($_SERVER["SCRIPT_NAME"]))?>/backup.php?run=1&amp;key=YOUR_KEY</code></p>
    <form method="post">
      <input type="hidden" name="section" value="backup">
      <div class="grid">
        <label>Backup URL Key<input name="backup_key" value="<?=htmlspecialchars($backupKey)?>"></label>
        <label>Keep backups for (days)
          <input type="number" name="backup_keep_days" min="1" max="365"
                 value="<?=htmlspecialchars($backupKeepDays)?>">
        </label>
      </div>
      <div class="actions">
        <a class="btn secondary" href="settings.php?regen_key=1" onclick="return confirm('Regenerate key?')">Regenerate Key</a>
        <button class="btn">Save Settings</button>
        <a class="btn success" href="backup.php?run=1">Run Backup Now</a>
        <?php if (file_exists(__DIR__ . '/backup_status.php')): ?>
          <a class="btn secondary" href="backup_status.php">📊 Backup Status →</a>
        <?php endif; ?>
        <?php if (file_exists(__DIR__ . '/restore.php')): ?>
          <a class="btn danger" href="restore.php" onclick="return confirm('Restoring from backup will overwrite current data. Continue?')">♻ Restore From Backup →</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- PDF -->
  <div class="panel">
    <h2>PDF Library (Dompdf)</h2>
    <?php if ($dompdfInstalled): ?><div class="success">✔ Dompdf installed.</div><?php else: ?><div class="warn">Dompdf missing. <a href="pdf_report.php?ticket=SETUP">Auto-install</a>.</div><?php endif; ?>
  </div>

  <script>
    document.querySelectorAll('.modecard input[type=radio]').forEach(function(r){
      r.addEventListener('change', function(){
        document.querySelectorAll('.modecard').forEach(function(c){ c.classList.remove('selected'); });
        if (r.checked) r.closest('.modecard').classList.add('selected');
      });
    });
  </script>
</main></body></html>