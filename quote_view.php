<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0);
$s = $conn->prepare("SELECT q.*, c.client_name, c.address, c.phone, c.alternate_phone FROM quotations q LEFT JOIN clients c ON c.id=q.client_id WHERE q.id=?");
$s->bind_param("i",$id); $s->execute();
$q = $s->get_result()->fetch_assoc(); if (!$q) die("Not found.");
$items = $conn->query("SELECT * FROM quotation_items WHERE quote_id=$id ORDER BY id");
$tot = compute_quote_totals($conn, $id);
function h($x){ return htmlspecialchars($x ?? ""); }

if (isset($_GET["accept"])) {
    $u = $conn->prepare("UPDATE quotations SET status='ACCEPTED', accepted_at=NOW() WHERE id=?");
    $u->bind_param("i",$id); $u->execute();
    log_activity($conn, "Accepted quotation " . $q["quote_no"]);
    header("Location: quote_view.php?id=$id&accepted=1"); exit;
}
if (isset($_GET["convert"])) {
    $invoiceId = quote_to_invoice($conn, $id);
    if ($invoiceId) { header("Location: invoice_view.php?id=$invoiceId&from_quote=1"); exit; }
}
?><!doctype html><html><head><title><?=h($q["quote_no"])?></title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Quotation <?=h($q["quote_no"])?></h1>
      <p><span class="pill status-<?=$q["status"]?>"><?=h($q["status"])?></span></p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="quote_edit.php?id=<?=$id?>">✎ Edit</a>
      <a class="btn secondary" href="quote_pdf.php?id=<?=$id?>">⬇ PDF</a>
      <?php if ($q["status"] === "SENT" || $q["status"] === "DRAFT"): ?>
        <a class="btn success" href="quote_view.php?id=<?=$id?>&accept=1" onclick="return confirm('Mark as accepted?')">✓ Accept</a>
      <?php endif; ?>
      <?php if ($q["status"] === "ACCEPTED"): ?>
        <a class="btn" href="quote_view.php?id=<?=$id?>&convert=1" onclick="return confirm('Convert this quote to an invoice?')">🧾 Convert to Invoice</a>
      <?php endif; ?>
      <?php if (!empty($q["invoice_id"])): ?>
        <a class="btn secondary" href="invoice_view.php?id=<?=(int)$q["invoice_id"]?>">View Linked Invoice →</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($_GET["accepted"])): ?><div class="success">Quotation accepted. You can now convert it to an invoice.</div><?php endif; ?>

  <div class="panel">
    <div class="grid">
      <div><b>Client</b><br><?=h($q["client_name"])?><br><?=h($q["address"])?><br><?=h($q["phone"])?></div>
      <div><b>Issued</b><br><?=h($q["issue_date"])?><br><b>Valid Until</b><br><?=h($q["valid_until"])?></div>
      <div><b>Currency</b><br><?=h($q["currency"])?></div>
    </div>
  </div>

  <div class="panel"><table>
    <tr><th>Description</th><th class="num" style="text-align:right">Qty</th><th class="num" style="text-align:right">Unit</th><th class="num" style="text-align:right">Line Total</th></tr>
    <?php while ($it = $items->fetch_assoc()): ?>
      <tr>
        <td><?=nl2br(h($it["description"]))?></td>
        <td style="text-align:right"><?=number_format($it["quantity"],2)?></td>
        <td style="text-align:right"><?=number_format($it["unit_price"],2)?></td>
        <td style="text-align:right"><?=number_format($it["quantity"]*$it["unit_price"],2)?></td>
      </tr>
    <?php endwhile; ?>
  </table></div>

  <div class="panel">
    <table style="width:320px;margin-left:auto">
      <tr><td>Subtotal</td><td style="text-align:right"><?=h($q["currency"])?> <?=number_format($tot["subtotal"],2)?></td></tr>
      <?php if ($tot["discount"] > 0): ?><tr><td>Discount</td><td style="text-align:right">- <?=number_format($tot["discount"],2)?></td></tr><?php endif; ?>
      <?php if ($tot["tax_rate"] > 0): ?><tr><td>VAT (<?=number_format($tot["tax_rate"],2)?>%)</td><td style="text-align:right"><?=number_format($tot["tax"],2)?></td></tr><?php endif; ?>
      <tr><td><b>Total</b></td><td style="text-align:right"><b><?=h($q["currency"])?> <?=number_format($tot["total"],2)?></b></td></tr>
    </table>
  </div>

  <?php if ($q["notes"]): ?><div class="panel"><h2>Notes</h2><p><?=nl2br(h($q["notes"]))?></p></div><?php endif; ?>
  <?php if ($q["terms"]): ?><div class="panel"><h2>Terms</h2><p><?=nl2br(h($q["terms"]))?></p></div><?php endif; ?>
</main></body></html>