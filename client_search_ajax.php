<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { http_response_code(403); exit; }
header('Content-Type: application/json');
$q = trim($_GET["q"] ?? ""); if ($q === "") { echo json_encode([]); exit; }
$like = "%" . $q . "%";
$s = $conn->prepare("SELECT id, client_name, address, phone, alternate_phone FROM clients WHERE client_name LIKE ? OR phone LIKE ? OR alternate_phone LIKE ? ORDER BY id DESC LIMIT 10");
$s->bind_param("sss", $like, $like, $like); $s->execute(); $r = $s->get_result();
$out = [];
while ($row = $r->fetch_assoc()) { $out[] = $row; }
echo json_encode($out);