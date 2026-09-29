<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$ticket  = trim($_POST["ticket"]  ?? "");
$to      = trim($_POST["to"]      ?? "");
$subject = trim($_POST["subject"] ?? "Your Service Report");
$message = trim($_POST["message"] ?? "");
if ($ticket === "" || !filter_var($to, FILTER_VALIDATE_EMAIL)) die("Invalid input.");

$smtpHost = get_setting($conn, "smtp_host", "");
$smtpUser = get_setting($conn, "smtp_user", "");
if ($smtpHost === "" || $smtpUser === "") die("SMTP not configured. See Settings → Email.");

$pdfUrl = base_url("pdf_report.php?ticket=" . urlencode($ticket));
$pdfData = "";
if (function_exists("curl_init")) {
    $ch = curl_init($pdfUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . "=" . session_id());
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $pdfData = curl_exec($ch); curl_close($ch);
}
if ($pdfData === "" || substr($pdfData, 0, 4) !== "%PDF") die("Could not build PDF.");

$usePhpMailer = file_exists(__DIR__ . '/vendor/PHPMailer/src/PHPMailer.php');
$ok = false; $err = "";
if ($usePhpMailer) {
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
        $mail->addStringAttachment($pdfData, $ticket . ".pdf", "base64", "application/pdf");
        $mail->send(); $ok = true;
    } catch (Throwable $e) { $err = $mail->ErrorInfo ?: $e->getMessage(); }
} else {
    die("PHPMailer not installed. See Settings → Email.");
}
if ($ok) {
    log_activity($conn, "Emailed report to $to", $ticket);
    header("Location:view_report.php?ticket=" . urlencode($ticket) . "&emailed=1"); exit;
}
die("Email failed: " . htmlspecialchars($err));