<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? ($_POST["id"] ?? 0));
$s = $conn->prepare("SELECT * FROM quotations WHERE id=?"); $s->bind_param("i",$id); $s->execute();
$q = $s->get_result()->fetch_assoc(); if (!$q) die("Not found.");
$clients = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name");
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $issue = $_POST["issue_date"] ?? date("Y-m-d");
    $valid = $_POST["valid_until"] ?? null;
    $cur = trim($_POST["currency"] ?? "UGX");
    $disc = (float)($_POST["discount_amount"] ?? 0);
    $tax = (float)($_POST["tax_rate"] ?? 0);
    $status = $_POST["status"] ?? "DRAFT";
    $notes = trim($_POST["notes"] ?? "");
    $terms = trim($_POST["terms"] ?? "");
    $u = $conn->prepare("UPDATE quotations SET issue_date=?, valid_until=?, currency=?, discount_amount=?, tax_rate=?, status=?, notes=?, terms=? WHERE id=?");
    $u->bind_param("sssddsssi", $issue, $valid, $cur, $disc, $tax, $status, $notes, $terms, $id);
    $u->execute();
    $conn->query("DELETE FROM quotation_items WHERE quote_id=" . $id);
    $descs = $_POST["item_description"] ?? [];
    $qtys  = $_POST["item_quantity"] ?? [];
    $prices = $_POST["item_unit_price"] ?? [];
    foreach ($descs as $i => $d) {
        $d = trim($d); if ($d === "") continue;
        $qq = (float)($qtys[$i] ?? 1); $pp = (float)($prices[$i] ?? 0); $ln = $qq*$pp;
        $it = $conn->prepare("INSERT INTO quotation_items(quote_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
        $it->bind_param("isdid", $id, $d, $qq, $pp, $ln);
        $it->execute();
    }
    update_quote_totals($conn, $id);
    log_activity($conn, "Updated quotation " . $q["quote_no"]);
    header("Location: quote_view.php?id=$id&updated=1"); exit;
}
$items = $conn->query("SELECT * FROM quotation_items WHERE quote_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
if (empty($items)) $items[] = ["description"=>"","quantity"=>1,"unit_price"=>0];
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Edit Quote</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Edit Quotation</h1><p><?=h($q["quote_no"])?></p></div><a class="btn secondary" href="quote_view.php?id=<?=$id?>">← Back</a></div>
  <form method="post" id="quoteForm">
    <input type="hidden" name="id" value="<?=$id?>">
    <section class="panel"><h2>Details</h2>
      <div class="grid">
        <label>Client<input value="<?php
          $cn = $conn->query("SELECT client_name FROM clients WHERE id=" . (int)$q["client_id"])->fetch_assoc();
          echo h($cn["client_name"] ?? "—");
        ?>" disabled></label>
        <label>Issue Date<input type="date" name="issue_date" value="<?=h($q["issue_date"])?>"></label>
        <label>Valid Until<input type="date" name="valid_until" value="<?=h($q["valid_until"])?>"></label>
        <label>Currency<input name="currency" value="<?=h($q["currency"])?>"></label>
        <label>Status<select name="status">
          <?php foreach (["DRAFT","SENT","ACCEPTED","REJECTED","EXPIRED","CONVERTED"] as $s): ?>
            <option <?=$q["status"]===$s?"selected":""?>><?=$s?></option>
          <?php endforeach; ?>
        </select></label>
      </div>
    </section>
    <section class="panel"><h2>Line Items</h2>
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
    <section class="panel"><h2>Totals &amp; Notes</h2>
      <div class="grid">
        <label>Discount<input type="number" step="0.01" name="discount_amount" value="<?=h($q["discount_amount"])?>"></label>
        <label>VAT Rate (%)<input type="number" step="0.01" name="tax_rate" value="<?=h($q["tax_rate"])?>"></label>
        <label>Notes<textarea name="notes"><?=h($q["notes"])?></textarea></label>
        <label>Terms<textarea name="terms"><?=h($q["terms"])?></textarea></label>
      </div>
    </section>
    <div class="actions"><a href="quote_view.php?id=<?=$id?>" class="btn secondary">Cancel</a><button class="btn">Save Changes</button></div>
  </form>
</main>
<script>
function recalcRow(row){var q=parseFloat(row.querySelector('.qty').value||0);var p=parseFloat(row.querySelector('.price').value||0);row.querySelector('.linetotal').textContent=(q*p).toFixed(2);}
function bindRow(row){row.querySelector('.qty').addEventListener('input',function(){recalcRow(row);});row.querySelector('.price').addEventListener('input',function(){recalcRow(row);});var rb=row.querySelector('.removeRow');if(rb)rb.addEventListener('click',function(){row.remove();});recalcRow(row);}
document.querySelectorAll('.itemrow').forEach(bindRow);
document.getElementById('addRow').addEventListener('click',function(){
  var t=document.getElementById('itemsTable');var tr=document.createElement('tr');tr.className='itemrow';
  tr.innerHTML='<td><textarea name="item_description[]" rows="1" style="min-height:auto"></textarea></td>'
    +'<td><input type="number" step="0.01" name="item_quantity[]" class="qty" value="1"></td>'
    +'<td><input type="number" step="0.01" name="item_unit_price[]" class="price" value="0"></td>'
    +'<td class="linetotal" style="text-align:right;padding-top:14px">0.00</td>'
    +'<td><button type="button" class="btn small danger removeRow">×</button></td>';
  t.appendChild(tr);bindRow(tr);
});
</script>
</body></html>