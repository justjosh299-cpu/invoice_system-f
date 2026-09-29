<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0);
$s = $conn->prepare("SELECT title FROM recurring_invoices WHERE id=?"); $s->bind_param("i",$id); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Not found.");
$d = $conn->prepare("DELETE FROM recurring_invoices WHERE id=?"); $d->bind_param("i",$id); $d->execute();
log_activity($conn, "Deleted recurring schedule: " . $r["title"]);
header("Location: recurring.php"); exit;