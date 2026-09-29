<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? ($_POST["id"] ?? 0)); if ($id <= 0) die("Invalid invoice.");
$s = $conn->prepare("SELECT i.*, c.client_name, c.address, c.phone FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$s->bind_param("i", $id); $s->execute();
$inv = $s->get_result()->fetch_assoc(); if (!$inv) die("Invoice not found.");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $issueDate = $_POST["issue_date"] ?? date("Y-m-d");
    $dueDate   = $_POST["due_date"] ?? null;
    $currency  = trim($_POST["currency"] ?? "UGX");
    $discount  = (float)($_POST["discount_amount"] ?? 0);
    $taxRate   = (float)($_POST["tax_rate"] ?? 0);
    $notes     = trim($_POST["notes"] ?? "");
    $terms     = trim($_POST["terms"] ?? "");
    $status    = $_POST["status"] ?? "DRAFT";

    $u = $conn->prepare("UPDATE invoices SET issue_date=?, due_date=?, currency=?, discount_amount=?, tax_rate=?,
                        notes=?, terms=?, status=? WHERE id=?");
    $u->bind_param("sssddsssi", $issueDate, $dueDate, $currency, $discount, $taxRate, $notes, $terms, $status, $id);
    $u->execute();

    // Replace items
    $conn->query("DELETE FROM invoice_items WHERE invoice_id=" . $id);
    $descs  = $_POST["item_description"] ?? [];
    $qtys   = $_POST["item_quantity"] ?? [];
    $prices = $_POST["item_unit_price"] ?? [];
    foreach ($descs as $i => $d) {
        $d = trim($d); if ($d === "") continue;
        $q = (float)($qtys[$i] ?? 1);
        $p = (float)($prices[$i] ?? 0);
        $line = $q * $p;
        $it = $conn->prepare("INSERT INTO invoice_items(invoice_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
        $it->bind_param("isdid", $id, $d, $q, $p, $line);
        $it->execute();
    }
    update_invoice_totals($conn, $id);

    log_activity($conn, "Updated invoice " . $inv["invoice_no"]);
    header("Location:invoice_view.php?id=" . $id . "&updated=1");
    exit;
}

$items = $conn->query("SELECT * FROM invoice_items WHERE invoice_id=" . $id . " ORDER BY id")->fetch_all(MYSQLI_ASSOC);
if (empty($items)) $items[] = ["description"=>"","quantity"=>1,"unit_price"=>0];
function h($x) { return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Edit Invoice <?=h($inv["invoice_no"])?></title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Edit Invoice</h1><p>Invoice <b><?=h($inv["invoice_no"])?></b></p></div>
    <div class="ticketbox">Editing<br><b><?=h($inv["invoice_no"])?></b></div>
  </div>
  <form method="post" id="invoiceForm" autocomplete="off">
    <input type="hidden" name="id" value="<?=$id?>">

    <section class="panel"><h2>1. Invoice Details</h2>
      <div class="grid">
        <label>Client<input value="<?=h($inv["client_name"])?>" disabled></label>
        <label>Issue Date<input type="date" name="issue_date" value="<?=h($inv["issue_date"])?>" required></label>
        <label>Due Date<input type="date" name="due_date" value="<?=h($inv["due_date"])?>"></label>
        <label>Currency<input name="currency" value="<?=h($inv["currency"])?>" maxlength="10"></label>
        <label>Status<select name="status">
          <?php foreach (["DRAFT","SENT","PARTIAL","PAID","CANCELLED"] as $st): ?>
            <option value="<?=$st?>" <?=($inv["status"]===$st)?"selected":""?>><?=$st?></option>
          <?php endforeach; ?>
        </select></label>
      </div>
    </section>

    <section class="panel"><h2>2. Line Items</h2>
      <table class="diag" id="itemsTable">
        <tr><th>Description</th><th style="width:100px">Qty</th><th style="width:160px">Unit Price</th><th style="width:160px">Line Total</th><th style="width:50px"></th></tr>
        <?php foreach ($items as $item): ?>
          <tr class="itemrow">
            <td><textarea name="item_description[]" rows="1" style="min-height:auto"><?=h($item["description"])?></textarea></td>
            <td><input type="number" step="0.01" name="item_quantity[]" class="qty" value="<?=h($item["quantity"])?>"></td>
            <td><input type="number" step="0.01" name="item_unit_price[]" class="price" value="<?=h($item["unit_price"])?>"></td>
            <td class="linetotal" style="text-align:right;padding-top:14px">0.00</td>
            <td><button type="button" class="btn small danger removeRow">×</button></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="actions" style="justify-content:flex-start"><button type="button" class="btn secondary" id="addRow">+ Add Row</button></div>
    </section>

    <section class="panel"><h2>3. Totals &amp; Adjustments</h2>
      <div class="grid">
        <label>Discount<input type="number" step="0.01" name="discount_amount" value="<?=h($inv["discount_amount"])?>"></label>
        <label>VAT Rate (%)<input type="number" step="0.01" name="tax_rate" value="<?=h($inv["tax_rate"])?>"></label>
        <label>Notes<textarea name="notes"><?=h($inv["notes"])?></textarea></label>
        <label>Terms<textarea name="terms"><?=h($inv["terms"])?></textarea></label>
      </div>
    </section>

    <div class="actions">
      <a href="invoice_view.php?id=<?=$id?>" class="btn secondary">Cancel</a>
      <button class="btn" type="submit">Save Changes →</button>
    </div>
  </form>
</main>
<script>
function recalcRow(row) {
  var q = parseFloat(row.querySelector('.qty').value || 0);
  var p = parseFloat(row.querySelector('.price').value || 0);
  row.querySelector('.linetotal').textContent = (q * p).toFixed(2);
}
function bindRow(row) {
  row.querySelector('.qty').addEventListener('input', function(){ recalcRow(row); });
  row.querySelector('.price').addEventListener('input', function(){ recalcRow(row); });
  var rb = row.querySelector('.removeRow');
  if (rb) rb.addEventListener('click', function(){ row.remove(); });
  recalcRow(row);
}
document.querySelectorAll('.itemrow').forEach(bindRow);
document.getElementById('addRow').addEventListener('click', function(){
  var tbody = document.getElementById('itemsTable');
  var tr = document.createElement('tr');
  tr.className = 'itemrow';
  tr.innerHTML = '<td><textarea name="item_description[]" rows="1" style="min-height:auto"></textarea></td>'
    + '<td><input type="number" step="0.01" name="item_quantity[]" class="qty" value="1"></td>'
    + '<td><input type="number" step="0.01" name="item_unit_price[]" class="price" value="0"></td>'
    + '<td class="linetotal" style="text-align:right;padding-top:14px">0.00</td>'
    + '<td><button type="button" class="btn small danger removeRow">×</button></td>';
  tbody.appendChild(tr);
  bindRow(tr);
});
</script>
</body></html>