<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") die("Delete requires POST.");
$id = (int)($_POST["id"] ?? 0);
$confirm = $_POST["confirm"] ?? "";
if ($id <= 0 || $confirm !== "DELETE") die("Missing invoice or confirmation.");

$s = $conn->prepare("SELECT invoice_no FROM invoices WHERE id=?");
$s->bind_param("i", $id); $s->execute();
$inv = $s->get_result()->fetch_assoc();
if (!$inv) die("Invoice not found.");

$conn->query("DELETE FROM invoice_items WHERE invoice_id=" . $id);
$conn->query("DELETE FROM invoice_payments WHERE invoice_id=" . $id);
$d = $conn->prepare("DELETE FROM invoices WHERE id=?");
$d->bind_param("i", $id); $d->execute();

log_activity($conn, "Deleted invoice " . $inv["invoice_no"]);
header("Location:invoices.php?deleted=1");
exit;