<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$myName = $_SESSION["name"] ?? "";
$showClosed = isset($_GET["closed"]);

$sql = "SELECT r.*, COALESCE(c.client_name,'') client_name
        FROM reports r LEFT JOIN clients c ON c.id=r.client_id
        WHERE r.technician_name = ?";
if (!$showClosed) $sql .= " AND r.ticket_status <> 'CLOSED'";
$sql .= " ORDER BY r.id DESC";

$s = $conn->prepare($sql);
$s->bind_param("s", $myName); $s->execute();
$rows = $s->get_result();

$counts = ["OPEN"=>0,"DIAGNOSIS"=>0,"AWAITING PARTS"=>0,"REPAIRED"=>0,"CLOSED"=>0];
$r2 = $conn->prepare("SELECT ticket_status, COUNT(*) c FROM reports WHERE technician_name=? GROUP BY ticket_status");
$r2->bind_param("s", $myName); $r2->execute();
$res = $r2->get_result();
while ($row = $res->fetch_assoc()) $counts[$row["ticket_status"]] = (int)$row["c"];

function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>My Tickets</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>My Tickets</h1><p>Tickets assigned to <b><?=h($myName)?></b>.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="my_tickets.php<?=$showClosed?'':'?closed=1'?>"><?=$showClosed?'Hide closed':'Show closed'?></a>
      <a class="btn" href="new_report.php">+ New Report</a>
    </div>
  </div>

  <div class="cards">
    <?php foreach (["OPEN","DIAGNOSIS","AWAITING PARTS","REPAIRED","CLOSED"] as $st): ?>
      <div class="card"><small><?=h($st)?></small><b><?=(int)$counts[$st]?></b></div>
    <?php endforeach; ?>
  </div>

  <div class="panel">
    <table>
      <tr><th>Ticket</th><th>Client</th><th>Device</th><th>Make / Model</th><th>Status</th><th>Reported</th><th>Actions</th></tr>
      <?php if ($rows->num_rows === 0): ?><tr><td colspan="7" style="text-align:center;color:#6b7887;padding:20px">No tickets assigned to you.</td></tr><?php endif; ?>
      <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><b><?=h($r["ticket_no"])?></b></td>
          <td><?=h($r["client_name"])?></td>
          <td><span class="pill <?=h($r["device_type"] ?: "computer")?>"><?=h(ucfirst($r["device_type"] ?: "computer"))?></span></td>
          <td><?=h($r["computer_make_model"])?></td>
          <td><span class="pill status-<?=str_replace(' ','',h($r["ticket_status"]))?>"><?=h($r["ticket_status"])?></span></td>
          <td><?=h($r["reported_date"])?></td>
          <td class="rowactions">
            <a class="btn small secondary" href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>">View</a>
            <a class="btn small secondary" href="edit_report.php?ticket=<?=urlencode($r["ticket_no"])?>">Edit</a>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>
</main></body></html>