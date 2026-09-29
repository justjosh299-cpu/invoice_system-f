<?php
require "config.php";

$isCron = isset($_GET["run"]) && isset($_GET["key"]);
if ($isCron) {
    $storedKey = get_setting($conn, "backup_key", "");
    if ($storedKey === "" || !hash_equals($storedKey, $_GET["key"] ?? "")) {
        http_response_code(403); die("Forbidden.");
    }
} else {
    if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
    if (!is_admin()) { header("Location:index.php"); exit; }
}

$backupDir = backup_dir();
$filename = "omg_diagnostics_" . date("Y-m-d_His") . ".sql";
$path = $backupDir . DIRECTORY_SEPARATOR . $filename;
$ok = false;

// Try mysqldump first
$mysqldump = trim(@shell_exec("which mysqldump 2>/dev/null") ?: "");
if ($mysqldump === "") {
    foreach (["C:\\xampp\\mysql\\bin\\mysqldump.exe","C:\\system_diag\\mysql\\bin\\mysqldump.exe"] as $p) {
        if (file_exists($p)) { $mysqldump = $p; break; }
    }
}
if ($mysqldump !== "" && function_exists("shell_exec")) {
    $cmd = escapeshellarg($mysqldump)
         . " --host=" . escapeshellarg($db_host)
         . " --user=" . escapeshellarg($db_user)
         . ($db_pass !== "" ? " --password=" . escapeshellarg($db_pass) : "")
         . " --single-transaction --routines --events "
         . escapeshellarg($db_name)
         . " > " . escapeshellarg($path);
    @shell_exec($cmd . " 2>&1");
    if (file_exists($path) && filesize($path) > 100) $ok = true;
}

// Pure-PHP fallback
if (!$ok) {
    $tables = [];
    $res = $conn->query("SHOW TABLES");
    while ($row = $res->fetch_array()) $tables[] = $row[0];
    $fh = @fopen($path, "w");
    if (!$fh) {
        if ($isCron) { header('Content-Type: text/plain'); die("FAIL: cannot write to $path\n"); }
        die("Cannot write backup file to: " . htmlspecialchars($path));
    }
    fwrite($fh, "-- OMG Diagnostics backup " . date("c") . "\nSET NAMES utf8mb4;\n\n");
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
    $ok = true;
}

// Retention: delete old backups
$keepDays = (int)get_setting($conn, "backup_keep_days", "30");
if ($ok && $keepDays > 0) {
    $cutoff = time() - ($keepDays * 86400);
    foreach (glob($backupDir . "/*.sql") as $f) {
        if (basename($f) === $filename) continue;
        if (filemtime($f) < $cutoff) @unlink($f);
    }
}

if ($ok) {
    set_setting($conn, "last_backup_at", date("Y-m-d H:i:s"));
    set_setting($conn, "last_backup_file", $filename);
    if (isset($_SESSION["user_id"])) log_activity($conn, "Database backup: $filename");
}

if ($isCron) {
    header('Content-Type: text/plain');
    echo $ok ? "OK: $filename ($backupDir)\n" : "FAIL\n";
    exit;
}
header("Location: backup_status.php?backup=" . urlencode($filename));
exit;