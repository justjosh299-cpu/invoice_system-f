<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$q = trim($_GET["q"] ?? "");

$reports = $clients = $invoices = [];
if ($q !== "") {
    $like = "%" . $q . "%";

    $s = $conn->prepare("SELECT r.id, r.ticket_no, r.device_type, r.ticket_status, r.reported_date,
                         COALESCE(c.client_name,'') client_name
                         FROM reports r LEFT JOIN clients c ON c.id=r.client_id
                         WHERE r.ticket_no LIKE ? OR c.client_name LIKE ? OR r.serial_number LIKE ?
                         ORDER BY r.id DESC LIMIT 20");
    $s->bind_param("sss", $like, $like, $like); $s->execute();
    $reports = $s->get_result()->fetch_all(MYSQLI_ASSOC);

    $s = $conn->prepare("SELECT id, client_name, phone, alternate_phone, address FROM clients
                         WHERE client_name LIKE ? OR phone LIKE ? OR alternate_phone LIKE ?
                         ORDER BY id DESC LIMIT 20");
    $s->bind_param("sss", $like, $like, $like); $s->execute();
    $clients = $s->get_result()->fetch_all(MYSQLI_ASSOC);

    $s = $conn->prepare("SELECT i.id, i.invoice_no, i.issue_date, i.status, i.currency, i.total,
                         COALESCE(c.client_name,'') client_name
                         FROM invoices i LEFT JOIN clients c ON c.id=i.client_id
                         WHERE i.invoice_no LIKE ? OR c.client_name LIKE ?
                         ORDER BY i.id DESC LIMIT 20");
    $s->bind_param("ss", $like, $like); $s->execute();
    $invoices = $s->get_result()->fetch_all(MYSQLI_ASSOC);
}
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Search: <?=h($q)?></title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Search</h1><p>Results for <b><?=h($q)?></b> — <?=count($reports)+count($clients)+count($invoices)?> hits</p></div>
  </div>

  <div class="panel">
    <h2>Tickets (<?=count($reports)?>)</h2>
    <table>
      <tr><th>Ticket</th><th>Client</th><th>Device</th><th>Status</th><th>Date</th><th></th></tr>
      <?php if (empty($reports)): ?><tr><td colspan="6" style="text-align:center;color:#6b7887;padding:14px">No matches.</td></tr><?php endif; ?>
      <?php foreach ($reports as $r): ?>
        <tr>
          <td><b><?=h($r["ticket_no"])?></b></td>
          <td><?=h($r["client_name"])?></td>
          <td><span class="pill <?=h($r["device_type"] ?: "computer")?>"><?=h(ucfirst($r["device_type"] ?: "computer"))?></span></td>
          <td><span class="pill status-<?=str_replace(' ','',h($r["ticket_status"]))?>"><?=h($r["ticket_status"])?></span></td>
          <td><?=h($r["reported_date"])?></td>
          <td><a class="btn small secondary" href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="panel">
    <h2>Invoices (<?=count($invoices)?>)</h2>
    <table>
      <tr><th>Invoice</th><th>Client</th><th>Issued</th><th>Total</th><th>Status</th><th></th></tr>
      <?php if (empty($invoices)): ?><tr><td colspan="6" style="text-align:center;color:#6b7887;padding:14px">No matches.</td></tr><?php endif; ?>
      <?php foreach ($invoices as $iv): ?>
        <tr>
          <td><b><?=h($iv["invoice_no"])?></b></td>
          <td><?=h($iv["client_name"])?></td>
          <td><?=h($iv["issue_date"])?></td>
          <td><?=h($iv["currency"])?> <?=number_format($iv["total"], 2)?></td>
          <td><span class="pill status-<?=h($iv["status"])?>"><?=h($iv["status"])?></span></td>
          <td><a class="btn small secondary" href="invoice_view.php?id=<?=(int)$iv["id"]?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="panel">
    <h2>Clients (<?=count($clients)?>)</h2>
    <table>
      <tr><th>Name</th><th>Phone</th><th>Alt Phone</th><th>Address</th><th></th></tr>
      <?php if (empty($clients)): ?><tr><td colspan="5" style="text-align:center;color:#6b7887;padding:14px">No matches.</td></tr><?php endif; ?>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><b><?=h($c["client_name"])?></b></td>
          <td><?=h($c["phone"])?></td>
          <td><?=h($c["alternate_phone"])?></td>
          <td><?=h($c["address"])?></td>
          <td><a class="btn small secondary" href="client_view.php?id=<?=(int)$c["id"]?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</main></body></html>