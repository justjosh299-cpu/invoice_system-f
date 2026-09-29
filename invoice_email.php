<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$id = (int)($_POST["id"] ?? 0);
$to = trim($_POST["to"] ?? "");
$subject = trim($_POST["subject"] ?? "Invoice");
$message = trim($_POST["message"] ?? "");
if ($id <= 0 || !filter_var($to, FILTER_VALIDATE_EMAIL)) die("Invalid input.");

$smtpHost = get_setting($conn, "smtp_host", "");
$smtpUser = get_setting($conn, "smtp_user", "");
if ($smtpHost === "" || $smtpUser === "") die("SMTP not configured. See Settings → Email.");

// Fetch invoice + items
$s = $conn->prepare("SELECT i.*, c.client_name FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$s->bind_param("i", $id); $s->execute();
$inv = $s->get_result()->fetch_assoc();
if (!$inv) die("Invoice not found.");

// Build the PDF by calling invoice_pdf.php internally
$pdfUrl = base_url("invoice_pdf.php?id=" . $id);
$pdfData = "";
if (function_exists("curl_init")) {
    $ch = curl_init($pdfUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . "=" . session_id());
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $pdfData = curl_exec($ch); curl_close($ch);
}
if ($pdfData === "" || substr($pdfData, 0, 4) !== "%PDF") die("Could not build invoice PDF.");

$usePhpMailer = file_exists(__DIR__ . '/vendor/PHPMailer/src/PHPMailer.php');
if (!$usePhpMailer) die("PHPMailer not installed. See Settings → Email.");

require __DIR__ . '/vendor/PHPMailer/src/Exception.php';
require __DIR__ . '/vendor/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/vendor/PHPMailer/src/SMTP.php';
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = get_setting($conn, "smtp_pass", "");
    $mail->SMTPSecure = get_setting($conn, "smtp_secure", "tls") === "ssl" ? "ssl" : "tls";
    $mail->Port = (int)get_setting($conn, "smtp_port", 587);
    $mail->CharSet = "UTF-8";
    $mail->setFrom(get_setting($conn, "smtp_from", $smtpUser), get_setting($conn, "smtp_from_name", "OMG"));
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body = $message;
    $mail->addStringAttachment($pdfData, $inv["invoice_no"] . ".pdf", "base64", "application/pdf");
    $mail->send();
    log_activity($conn, "Emailed invoice " . $inv["invoice_no"] . " to $to");
    header("Location:invoice_view.php?id=" . $id . "&emailed=1");
    exit;
} catch (Throwable $e) {
    die("Email failed: " . htmlspecialchars($mail->ErrorInfo ?: $e->getMessage()));
}