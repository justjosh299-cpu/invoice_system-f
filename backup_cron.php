<?php
require "config.php";

// Allow access only via a valid backup key
$key = $_GET["key"] ?? "";
$storedKey = get_setting($conn, "backup_key", "");

if ($storedKey === "") {
    http_response_code(500);
    header("Content-Type: text/plain");
    echo "FAIL: no backup_key set in Settings\n";
    exit;
}
if (!hash_equals($storedKey, $key)) {
    http_response_code(403);
    header("Content-Type: text/plain");
    echo "Forbidden.\n";
    exit;
}

// Delegate to backup.php
$_GET["run"] = 1;
$_GET["key"] = $key;
require __DIR__ . "/backup.php";