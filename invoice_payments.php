<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

// Delete payment
if (isset($_GET["delete"]) && isset($_GET["invoice_id"])) {
    $pid = (int)$_GET["delete"]; $iid = (int)$_GET["invoice_id"];
    $s = $conn->prepare("DELETE FROM invoice_payments WHERE id=?");
    $s->bind_param("i", $pid); $s->execute();
    update_invoice_totals($conn, $iid);
    log_activity($conn, "Deleted payment #$pid from invoice #$iid");
    header("Location:invoice_view.php?id=" . $iid);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") die("POST required.");
$iid = (int)($_POST["invoice_id"] ?? 0);
$paidOn = $_POST["paid_on"] ?? date("Y-m-d");
$amount = (float)($_POST["amount"] ?? 0);
$method = $_POST["method"] ?? "CASH";
$reference = trim($_POST["reference"] ?? "");
$note = trim($_POST["note"] ?? "");
if ($iid <= 0 || $amount <= 0) die("Invalid amount.");

$uid = (int)$_SESSION["user_id"];
$s = $conn->prepare("INSERT INTO invoice_payments(invoice_id, paid_on, amount, method, reference, note, created_by) VALUES(?,?,?,?,?,?,?)");
$s->bind_param("isdsssi", $iid, $paidOn, $amount, $method, $reference, $note, $uid);
$s->execute();

// Update status based on new totals
$tot = update_invoice_totals($conn, $iid);
$newStatus = null;
if ($tot["balance"] <= 0.009) $newStatus = "PAID";
elseif ($tot["paid"] > 0) $newStatus = "PARTIAL";
if ($newStatus) {
    $st = $conn->prepare("UPDATE invoices SET status=? WHERE id=?");
    $st->bind_param("si", $newStatus, $iid); $st->execute();
}

log_activity($conn, "Recorded payment of " . number_format($amount, 2) . " on invoice #$iid");
$ret = $_POST["return"] ?? "view";
header("Location:invoice_view.php?id=" . $iid . "&paid=1");
exit;