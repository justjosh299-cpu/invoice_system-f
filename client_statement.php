<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$clientId = (int)($_GET["client_id"] ?? 0);
if ($clientId <= 0) die("Missing client.");

$s = $conn->prepare("SELECT * FROM clients WHERE id=?");
$s->bind_param("i", $clientId); $s->execute();
$c = $s->get_result()->fetch_assoc(); if (!$c) die("Client not found.");

$from = $_GET["from"] ?? date("Y-m-d", strtotime("-11 months"));
$to   = $_GET["to"]   ?? date("Y-m-d");

// Build activity list: invoices + payments
$invoices = $conn->prepare("SELECT id, invoice_no, issue_date, due_date, status, currency, total, amount_paid
                            FROM invoices WHERE client_id=? AND issue_date BETWEEN ? AND ? ORDER BY issue_date ASC");
$invoices->bind_param("iss", $clientId, $from, $to); $invoices->execute();
$invs = $invoices->get_result()->fetch_all(MYSQLI_ASSOC);

$payments = $conn->prepare("SELECT p.*, i.invoice_no, i.currency FROM invoice_payments p
                            LEFT JOIN invoices i ON i.id=p.invoice_id
                            WHERE i.client_id=? AND p.paid_on BETWEEN ? AND ? ORDER BY p.paid_on ASC, p.id ASC");
$payments->bind_param("iss", $clientId, $from, $to); $payments->execute();
$pays = $payments->get_result()->fetch_all(MYSQLI_ASSOC);

function h($x){ return htmlspecialchars($x ?? ""); }
$cur = "UGX";
if (!empty($invs)) $cur = $invs[0]["currency"] ?: $cur;
?><!doctype html><html><head><title>Statement — <?=h($c["client_name"])?></title>
<link rel="stylesheet" href="assets/style.css"></head><body class="reportbody">
<div class="printbar">
  <a href="client_view.php?id=<?=$clientId?>">← Back to Client</a>
  <a href="excel_export.php?type=statement&client_id=<?=$clientId?>&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>">⬇ Excel</a>
  <button onclick="window.print()">Print / Save PDF</button>
</div>

<div class="page">
  <div class="reporthead">
    <div class="logo-left">
      <?php $logo = get_setting($conn, "company_logo", ""); if ($logo && file_exists(__DIR__.'/'.$logo)): ?>
        <img src="<?=h($logo)?>" alt="Logo">
      <?php else: ?><div class="placeholder">LOGO</div><?php endif; ?>
    </div>
    <div class="center">
      <b>OMG TECHNOLOGIES UGANDA</b><br>
      <strong>IT SUPPORT &amp; COMPUTER SERVICES DEPARTMENT</strong>
      <div>P.O. BOX, 291027 SOROTI- UGANDA<br>
        TEL: +256 774 610 005 | +256 778 508 802<br>
        EMAIL: omgtechnologiesusmclimited@gmail.com</div>
    </div>
    <div class="logo-right">
      <?php $coat = get_setting($conn, "coat_of_arms", ""); if ($coat && file_exists(__DIR__.'/'.$coat)): ?>
        <img src="<?=h($coat)?>" alt="Coat of Arms">
      <?php else: ?><div class="placeholder">COAT</div><?php endif; ?>
    </div>
  </div>

  <h1>CLIENT ACCOUNT STATEMENT</h1>

  <div class="rgrid" style="margin:12px 0 18px">
    <div><b>Client</b><br><?=h($c["client_name"])?></div>
    <div><b>Address</b><br><?=h($c["address"])?></div>
    <div><b>Phone</b><br><?=h($c["phone"])?></div>
    <div><b>Period</b><br><?=date("d M Y", strtotime($from))?> — <?=date("d M Y", strtotime($to))?></div>
  </div>

  <?php
  // Merge events
  $events = [];
  foreach ($invs as $iv) $events[] = ["date"=>$iv["issue_date"], "type"=>"Invoice", "ref"=>$iv["invoice_no"], "debit"=>(float)$iv["total"], "credit"=>0, "currency"=>$iv["currency"]];
  foreach ($pays as $p) $events[] = ["date"=>$p["paid_on"], "type"=>"Payment", "ref"=>$p["invoice_no"] . " · " . $p["method"], "debit"=>0, "credit"=>(float)$p["amount"], "currency"=>$p["currency"]];
  usort($events, function($a,$b){ return strcmp($a["date"], $b["date"]); });

  $balance = 0;
  ?>
  <table>
    <tr><th>Date</th><th>Type</th><th>Reference</th><th style="text-align:right">Invoice</th><th style="text-align:right">Payment</th><th style="text-align:right">Balance</th></tr>
    <?php if (empty($events)): ?>
      <tr><td colspan="6" style="text-align:center;color:#6b7887;padding:20px">No activity in this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($events as $e):
      $balance += $e["debit"] - $e["credit"];
    ?>
      <tr>
        <td><?=h($e["date"])?></td>
        <td><?=h($e["type"])?></td>
        <td><?=h($e["ref"])?></td>
        <td style="text-align:right"><?=$e["debit"]>0 ? number_format($e["debit"], 2) : "—"?></td>
        <td style="text-align:right"><?=$e["credit"]>0 ? number_format($e["credit"], 2) : "—"?></td>
        <td style="text-align:right"><b><?=number_format($balance, 2)?></b></td>
      </tr>
    <?php endforeach; ?>
    <tr style="background:#eef3f9">
      <td colspan="3"><b>Closing Balance</b></td>
      <td colspan="2"></td>
      <td style="text-align:right"><b><?=h($cur)?> <?=number_format($balance, 2)?></b></td>
    </tr>
  </table>

  <p class="hint" style="margin-top:16px">Prepared by OMG Technologies Uganda · <?=date("d M Y")?></p>
</div>
</body></html>