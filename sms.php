<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

if ($_SERVER["REQUEST_METHOD"] !== "POST") die("POST required.");
$ticket = trim($_POST["ticket"] ?? "");
$phone  = trim($_POST["phone"] ?? "");
$message = trim($_POST["message"] ?? "");

if ($ticket === "" || $phone === "" || $message === "") die("Missing fields.");

$s = $conn->prepare("SELECT r.ticket_no FROM reports r WHERE r.ticket_no=?");
$s->bind_param("s", $ticket); $s->execute();
if (!$s->get_result()->fetch_assoc()) die("Ticket not found.");

$result = send_sms($conn, $phone, $message, $ticket);
if ($result["ok"]) {
    header("Location:view_report.php?ticket=" . urlencode($ticket) . "&sms=1");
} else {
    die("SMS failed: " . htmlspecialchars($result["err"] ?? "unknown error"));
}
exit;