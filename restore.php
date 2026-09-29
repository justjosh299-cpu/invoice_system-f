<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if (!is_admin()) { header("Location:index.php"); exit; }

$dir = backup_dir();
$msg = ""; $err = "";

// --- Preview mode ---
if (isset($_GET["preview"])) {
    $name = basename($_GET["preview"]);
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (!file_exists($path) || substr($name, -4) !== ".sql") die("Backup not found.");
    $size = filesize($path);
    $fh = fopen($path, "r");
    $head = fread($fh, 8192); fclose($fh);
    preg_match_all('/CREATE TABLE[^`]*`([^`]+)`/i', $head, $m);
    $tablesInHead = $m[1] ?? [];
    ?>
    <!doctype html><html><head><title>Restore Preview</title>
    <link rel="stylesheet" href="assets/style.css"></head><body>
    <?php include "nav.php"; ?>
    <main>
      <div class="hero"><div><h1>Preview Backup</h1><p><?=htmlspecialchars($name)?></p></div>
        <a class="btn secondary" href="restore.php">← Back</a></div>
      <div class="panel">
        <div class="grid">
          <label>File<input value="<?=htmlspecialchars($name)?>" disabled></label>
          <label>Size<input value="<?=number_format($size/1024,1)?> KB" disabled></label>
          <label>Modified<input value="<?=date("Y-m-d H:i:s", filemtime($path))?>" disabled></label>
        </div>
        <h3 style="margin-top:18px;font-size:15px;color:#0b2545">Tables found in first 8 KB</h3>
        <p class="hint"><?=htmlspecialchars(implode(", ", $tablesInHead)) ?: "(scanning truncated)"?></p>
      </div>
      <div class="warn">
        <b>⚠ Restoring will REPLACE current data.</b>
        All existing rows in the tables present in the backup will be lost.
        This cannot be undone — make sure you know what you're doing.
      </div>
      <form method="post" onsubmit="return confirm('Final confirmation: restore this backup? Current data will be overwritten.')">
        <input type="hidden" name="action" value="restore">
        <input type="hidden" name="file" value="<?=htmlspecialchars($name)?>">
        <label style="display:flex;gap:8px;align-items:center;font-size:13px">
          <input type="checkbox" name="ack" value="yes" required style="width:auto">
          I understand this will overwrite current data
        </label>
        <div class="actions">
          <a href="restore.php" class="btn secondary">Cancel</a>
          <button class="btn danger">Restore Now</button>
        </div>
      </form>
    </main></body></html>
    <?php
    exit;
}

// --- Restore action ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "restore") {
    if (($_POST["ack"] ?? "") !== "yes") { $err = "You must acknowledge the warning."; }
    else {
        $name = basename($_POST["file"] ?? "");
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($path) || substr($name, -4) !== ".sql") $err = "Backup file not found.";
        else {
            // Safety: snapshot current DB first
            $snapshotName = "pre_restore_" . date("Y-m-d_His") . ".sql";
            $snapshotPath = $dir . DIRECTORY_SEPARATOR . $snapshotName;
            $tables = [];
            $res = $conn->query("SHOW TABLES");
            while ($row = $res->fetch_array()) $tables[] = $row[0];
            $fh = fopen($snapshotPath, "w");
            fwrite($fh, "-- Auto snapshot before restore " . date("c") . "\nSET NAMES utf8mb4;\n\n");
            foreach ($tables as $t) {
                $cr = $conn->query("SHOW CREATE TABLE `$t`")->fetch_assoc();
                fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n" . $cr["Create Table"] . ";\n\n");
                $data = $conn->query("SELECT * FROM `$t`");
                if ($data && $data->num_rows) {
                    while ($row = $data->fetch_assoc()) {
                        $cols = array_map(function($c){ return "`$c`"; }, array_keys($row));
                        $vals = array_map(function($v) use ($conn) {
                            return $v === null ? "NULL" : "'" . $conn->real_escape_string($v) . "'";
                        }, array_values($row));
                        fwrite($fh, "INSERT INTO `$t` (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ");\n");
                    }
                }
                fwrite($fh, "\n");
            }
            fclose($fh);

            // Now restore
            $sql = file_get_contents($path);
            if ($sql === false || trim($sql) === "") $err = "Could not read backup file.";
            else {
                $conn->query("SET FOREIGN_KEY_CHECKS=0");
                if ($conn->multi_query($sql)) {
                    $count = 0;
                    do { $count++; } while ($conn->more_results() && $conn->next_result());
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    log_activity($conn, "Restored DB from backup: $name");
                    $msg = "Restored from <b>" . htmlspecialchars($name) . "</b>. Pre-restore snapshot saved as <b>" . htmlspecialchars($snapshotName) . "</b>.";
                } else {
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    $err = "Restore failed: " . htmlspecialchars($conn->error);
                }
            }
        }
    }
}

// --- List backups ---
$backups = [];
if (is_dir($dir)) {
    foreach (glob($dir . "/*.sql") as $f) {
        $backups[] = ["name"=>basename($f), "size"=>filesize($f), "time"=>filemtime($f)];
    }
    usort($backups, function($a,$b){ return $b["time"] - $a["time"]; });
}
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Restore From Backup</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Restore From Backup</h1><p>Roll the database back to any previous backup.</p></div>
    <a class="btn secondary" href="backup_status.php">📊 Backup Status</a>
  </div>
  <?php if ($msg): ?><div class="success"><?=$msg?></div><?php endif; ?>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>

  <div class="warn">
    <b>Important:</b> Before restoring, the system automatically saves a
    <b>pre-restore snapshot</b> of your current database to the same folder.
    If something goes wrong, you can restore that snapshot.
  </div>

  <div class="panel">
    <h2>Backup Folder</h2>
    <p class="hint"><code><?=h($dir)?></code></p>
    <?php if (empty($backups)): ?>
      <p class="hint">No backup files found.</p>
    <?php else: ?>
      <table>
        <tr><th>File</th><th>Size</th><th>Created</th><th></th></tr>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><code><?=h($b["name"])?></code></td>
            <td><?=number_format($b["size"]/1024, 1)?> KB</td>
            <td><?=date("Y-m-d H:i:s", $b["time"])?></td>
            <td class="rowactions">
              <a class="btn small secondary" href="restore.php?preview=<?=urlencode($b["name"])?>">Preview</a>
              <a class="btn small secondary" href="restore.php?preview=<?=urlencode($b["name"])?>#restore">Restore…</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Restore from Uploaded File</h2>
    <p class="hint">Or restore a <code>.sql</code> file from your computer.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="restore_upload">
      <label>Choose file<input type="file" name="sqlfile" accept=".sql" required></label>
      <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px">
        <input type="checkbox" name="ack" value="yes" required style="width:auto">
        I understand this will overwrite current data
      </label>
      <div class="actions">
        <button class="btn danger">Upload &amp; Restore</button>
      </div>
    </form>
    <?php
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "restore_upload") {
        if (($_POST["ack"] ?? "") !== "yes") { echo '<div class="error">You must acknowledge the warning.</div>'; }
        elseif (empty($_FILES["sqlfile"]["tmp_name"])) { echo '<div class="error">No file uploaded.</div>'; }
        else {
            $tmp = $_FILES["sqlfile"]["tmp_name"];
            $sql = file_get_contents($tmp);
            if (trim($sql) === "") echo '<div class="error">File is empty.</div>';
            else {
                $conn->query("SET FOREIGN_KEY_CHECKS=0");
                if ($conn->multi_query($sql)) {
                    do {} while ($conn->more_results() && $conn->next_result());
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    log_activity($conn, "Restored DB from uploaded file");
                    echo '<div class="success">Uploaded file restored successfully.</div>';
                } else {
                    $conn->query("SET FOREIGN_KEY_CHECKS=1");
                    echo '<div class="error">Restore failed: ' . h($conn->error) . '</div>';
                }
            }
        }
    }
    ?>
  </div>
</main></body></html>