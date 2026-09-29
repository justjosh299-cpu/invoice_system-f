<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if (!is_admin()) { header("Location:index.php"); exit; }

$dir = backup_dir();
$dirOutside = strpos($dir, 'htdocs') === false;

$backups = [];
if (is_dir($dir)) {
    foreach (glob($dir . "/*.sql") as $f) {
        $backups[] = [
            "name" => basename($f),
            "size" => filesize($f),
            "time" => filemtime($f),
            "path" => $f,
        ];
    }
    usort($backups, function($a,$b){ return $b["time"] - $a["time"]; });
}

$lastRun   = get_setting($conn, "last_backup_at", "");
$lastFile  = get_setting($conn, "last_backup_file", "");
$keepDays  = get_setting($conn, "backup_keep_days", "30");
$totalSize = 0;
foreach ($backups as $b) $totalSize += $b["size"];

function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Backup Status</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Backup Status</h1><p>Nightly database backups and retention.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="settings.php">← Settings</a>
      <a class="btn" href="backup.php?run=1" onclick="return confirm('Run a backup now?')">Run Backup Now</a>
    </div>
  </div>

  <?php if (isset($_GET["backup"])): ?>
    <div class="success">Backup created: <b><?=h($_GET["backup"])?></b></div>
  <?php endif; ?>

  <div class="cards">
    <div class="card">
      <small>Backup Folder</small>
      <b style="font-size:13px;word-break:break-all"><?=h($dir)?></b>
    </div>
    <div class="card">
      <small>Location Safe?</small>
      <b style="color:<?=$dirOutside ? '#0a7a2f' : '#a12626'?>">
        <?=$dirOutside ? "✓ Outside webroot" : "⚠ Inside webroot"?>
      </b>
    </div>
    <div class="card">
      <small>Last Backup</small>
      <b style="font-size:14px"><?=$lastRun ? h($lastRun) : "Never"?></b>
    </div>
    <div class="card">
      <small>Total Backups</small>
      <b><?=count($backups)?></b>
    </div>
    <div class="card">
      <small>Total Size</small>
      <b style="font-size:14px"><?=number_format($totalSize/1024/1024, 2)?> MB</b>
    </div>
    <div class="card">
      <small>Retention</small>
      <b style="font-size:14px"><?=h($keepDays)?> days</b>
    </div>
  </div>

  <?php if (!$dirOutside): ?>
    <div class="warn">
      <b>⚠ Backups are inside the webroot.</b>
      That means someone with the right URL could download them. To fix:
      <ol style="margin:8px 0 0 20px;font-size:13px">
        <li>Create a folder at <code>C:\system_diag\backups\</code></li>
        <li>Make sure it's writable by Apache</li>
        <li>Refresh this page — it should switch automatically</li>
      </ol>
    </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Nightly Cron Setup (Windows Task Scheduler)</h2>
    <p class="hint">Create a Basic Task that runs daily at, say, 2:00 AM.</p>
    <table style="margin-top:10px">
      <tr><th style="width:180px">Program</th><td><code>C:\xampp\php\php.exe</code></td></tr>
      <tr><th>Arguments</th><td>
        <code style="word-break:break-all">-r "echo file_get_contents('<?=h(base_url('backup_cron.php?key=' . urlencode(get_setting($conn, 'backup_key', ''))))?>');"</code>
      </td></tr>
      <tr><th>Start in</th><td><code>C:\xampp\php</code></td></tr>
    </table>
    <p class="hint">Save the task once — Windows will keep running it every night. You can also test the URL directly in a browser; it should print <code>OK: omg_diagnostics_...</code>.</p>
  </div>

  <div class="panel">
    <h2>Backups in Folder</h2>
    <?php if (empty($backups)): ?>
      <p class="hint">No backups yet. Click <b>Run Backup Now</b> above.</p>
    <?php else: ?>
      <table>
        <tr><th>File</th><th>Size</th><th>Created</th></tr>
        <?php foreach (array_slice($backups, 0, 30) as $b): ?>
          <tr>
            <td><code><?=h($b["name"])?></code></td>
            <td><?=number_format($b["size"]/1024, 1)?> KB</td>
            <td><?=date("Y-m-d H:i:s", $b["time"])?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <p class="hint" style="margin-top:12px">
        ℹ Files are <b>not downloadable from the web</b> — they're outside the webroot. Access them via File Explorer at the path shown above.
      </p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Restore Instructions</h2>
    <p class="hint">If you ever need to restore a backup:</p>
    <ol style="font-size:13.5px;line-height:1.8">
      <li>Open phpMyAdmin</li>
      <li>Drop the existing tables (or the whole database)</li>
      <li>Click <b>Import</b> → <b>Choose File</b> → navigate to <code><?=h($dir)?></code> → pick a <code>.sql</code> file</li>
      <li>Click <b>Go</b> — the database is restored</li>
    </ol>
  </div>
</main></body></html>