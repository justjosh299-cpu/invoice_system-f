<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") die("Delete requires POST.");
$ticket = trim($_POST["ticket"] ?? ""); $confirm = $_POST["confirm"] ?? "";
if ($ticket === "" || $confirm !== "DELETE") die("Missing ticket or confirmation.");
$s = $conn->prepare("SELECT id, client_id FROM reports WHERE ticket_no=?");
$s->bind_param("s", $ticket); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Report not found.");
$reportId = (int)$r["id"]; $clientId = (int)$r["client_id"];
$conn->query("DELETE FROM diagnostic_items WHERE report_id=" . $reportId);
$conn->query("DELETE FROM status_history WHERE report_id=" . $reportId);
$d = $conn->prepare("DELETE FROM reports WHERE id=?"); $d->bind_param("i", $reportId); $d->execute();
if ($clientId > 0) {
    $chk = $conn->query("SELECT COUNT(*) c FROM reports WHERE client_id=" . $clientId)->fetch_assoc();
    if ((int)$chk["c"] === 0) $conn->query("DELETE FROM clients WHERE id=" . $clientId);
}
log_activity($conn, "Deleted diagnostic report", $ticket);
header("Location:reports.php?deleted=1"); exit;