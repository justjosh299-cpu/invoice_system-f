<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0); if ($id <= 0) die("Invalid client.");
$s = $conn->prepare("SELECT * FROM clients WHERE id=?"); $s->bind_param("i", $id); $s->execute();
$c = $s->get_result()->fetch_assoc(); if (!$c) die("Client not found.");

$rs = $conn->prepare("SELECT id, ticket_no, device_type, computer_make_model, serial_number, ticket_status, reported_date, final_result, technician_name FROM reports WHERE client_id=? ORDER BY id DESC");
$rs->bind_param("i", $id); $rs->execute(); $reports = $rs->get_result();

$inv = $conn->prepare("SELECT * FROM invoices WHERE client_id=? ORDER BY id DESC");
$inv->bind_param("i", $id); $inv->execute(); $invoices = $inv->get_result();

$stats = $conn->prepare("SELECT COUNT(*) total, SUM(CASE WHEN ticket_status IN ('OPEN','DIAGNOSIS','AWAITING PARTS') THEN 1 ELSE 0 END) active, SUM(CASE WHEN ticket_status='CLOSED' THEN 1 ELSE 0 END) closed FROM reports WHERE client_id=?");
$stats->bind_param("i", $id); $stats->execute(); $st = $stats->get_result()->fetch_assoc();

$depositTotal = function_exists('deposit_total_unapplied_for_client') ? deposit_total_unapplied_for_client($conn, $id) : 0;

function h($x) { return htmlspecialchars($x ?? ""); }
function inv_badge($row) {
    $s = $row["status"];
    if (in_array($s, ["SENT","PARTIAL"], true) && !empty($row["due_date"]) && strtotime($row["due_date"]) < strtotime(date("Y-m-d"))) $s = "OVERDUE";
    return $s;
}
?><!doctype html><html><head><title><?=h($c["client_name"])?></title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1><?=h($c["client_name"])?></h1><p>Full service and billing history.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="clients.php">← Clients</a>
      <?php if (file_exists(__DIR__ . '/client_statement.php')): ?>
        <a class="btn secondary" href="client_statement.php?client_id=<?=(int)$c["id"]?>">📄 Statement</a>
      <?php endif; ?>
      <?php if (file_exists(__DIR__ . '/excel_export.php')): ?>
        <a class="btn secondary" href="excel_export.php?type=statement&client_id=<?=(int)$c["id"]?>">⬇ Excel</a>
      <?php endif; ?>
      <a class="btn" href="invoice_new.php">+ New Invoice</a>
    </div>
  </div>

  <div class="panel"><h2>Client Information</h2>
    <div class="clientmeta">
      <div><b>Address</b><?=h($c["address"]) ?: "—"?></div>
      <div><b>Phone</b><?=h($c["phone"]) ?: "—"?></div>
      <div><b>Alternate Phone</b><?=h($c["alternate_phone"]) ?: "—"?></div>
      <div><b>Client Since</b><?=h($c["created_at"])?></div>
    </div>
    <div class="cards">
      <div class="card"><small>Total Tickets</small><b><?=(int)$st["total"]?></b></div>
      <div class="card"><small>Active</small><b><?=(int)$st["active"]?></b></div>
      <div class="card"><small>Closed</small><b><?=(int)$st["closed"]?></b></div>
      <?php if ($depositTotal > 0): ?>
        <div class="card"><small>Unapplied Deposits</small><b style="color:#0a7a2f"><?=number_format($depositTotal, 0)?></b></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel"><h2>Tickets</h2>
    <table>
      <tr><th>Ticket</th><th>Device</th><th>Make / Model</th><th>Status</th><th>Result</th><th>Date</th><th></th></tr>
      <?php if ($reports->num_rows === 0): ?><tr><td colspan="7" style="text-align:center;color:#6b7887;padding:20px">No tickets yet.</td></tr><?php endif; ?>
      <?php while ($r = $reports->fetch_assoc()): ?>
        <tr>
          <td><b><?=h($r["ticket_no"])?></b></td>
          <td><span class="pill <?=h($r["device_type"] ?: "computer")?>"><?=h(ucfirst($r["device_type"] ?: "computer"))?></span></td>
          <td><?=h($r["computer_make_model"])?></td>
          <td><span class="pill status-<?=str_replace(' ','',h($r["ticket_status"]))?>"><?=h($r["ticket_status"])?></span></td>
          <td><?=h($r["final_result"])?></td>
          <td><?=h($r["reported_date"])?></td>
          <td><a class="btn small secondary" href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>">View</a></td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <div class="panel">
    <div class="panelhead"><h2>Invoices</h2><a href="invoice_new.php">+ New Invoice</a></div>
    <table>
      <tr><th>Invoice</th><th>Issued</th><th>Due</th><th>Total</th><th>Balance</th><th>Status</th><th></th></tr>
      <?php if ($invoices->num_rows === 0): ?><tr><td colspan="7" style="text-align:center;color:#6b7887;padding:20px">No invoices yet.</td></tr><?php endif; ?>
      <?php while ($iv = $invoices->fetch_assoc()): $b = inv_badge($iv); ?>
        <tr>
          <td><b><?=h($iv["invoice_no"])?></b></td>
          <td><?=h($iv["issue_date"])?></td>
          <td><?=h($iv["due_date"])?></td>
          <td><?=h($iv["currency"])?> <?=number_format($iv["total"], 2)?></td>
          <td><?=h($iv["currency"])?> <?=number_format($iv["total"] - $iv["amount_paid"], 2)?></td>
          <td><span class="pill status-<?=$b?>"><?=$b?></span></td>
          <td><a class="btn small secondary" href="invoice_view.php?id=<?=(int)$iv["id"]?>">View</a></td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>
</main></body></html>