<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0); if ($id<=0) die("Invalid part.");
$s = $conn->prepare("SELECT part_name FROM parts WHERE id=?"); $s->bind_param("i",$id); $s->execute();
$p = $s->get_result()->fetch_assoc(); if (!$p) die("Part not found.");
$conn->query("DELETE FROM part_movements WHERE part_id=" . $id);
$d = $conn->prepare("DELETE FROM parts WHERE id=?"); $d->bind_param("i",$id); $d->execute();
log_activity($conn, "Deleted part: " . $p["part_name"]);
header("Location: parts.php"); exit;