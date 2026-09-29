<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0);
$s = $conn->prepare("SELECT quote_no FROM quotations WHERE id=?"); $s->bind_param("i",$id); $s->execute();
$q = $s->get_result()->fetch_assoc(); if (!$q) die("Not found.");
$conn->query("DELETE FROM quotation_items WHERE quote_id=" . $id);
$d = $conn->prepare("DELETE FROM quotations WHERE id=?"); $d->bind_param("i",$id); $d->execute();
log_activity($conn, "Deleted quotation " . $q["quote_no"]);
header("Location: quotations.php?deleted=1"); exit;