<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$clients = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name");
$defaultVat = (float)get_setting($conn, "invoice_vat", "18");
$quoteNo = next_quote_no($conn);
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $cid = (int)($_POST["client_id"] ?? 0);
    $issue = $_POST["issue_date"] ?? date("Y-m-d");
    $valid = $_POST["valid_until"] ?? date("Y-m-d", strtotime("+14 days"));
    $cur = trim($_POST["currency"] ?? "UGX");
    $disc = (float)($_POST["discount_amount"] ?? 0);
    $tax = (float)($_POST["tax_rate"] ?? 0);
    $status = $_POST["status"] ?? "DRAFT";
    $notes = trim($_POST["notes"] ?? "");
    $terms = trim($_POST["terms"] ?? "");
    $uid = (int)$_SESSION["user_id"];

    if ($cid <= 0) die("Select a client.");
    $s = $conn->prepare("INSERT INTO quotations(quote_no, client_id, issue_date, valid_until, status, currency, discount_amount, tax_rate, notes, terms, created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $s->bind_param("siss s d d s s i", $quoteNo, $cid, $issue, $valid, $status, $cur, $disc, $tax, $notes, $terms, $uid);
    $s->execute();
    $qid = $s->insert_id;

    $descs = $_POST["item_description"] ?? [];
    $qtys  = $_POST["item_quantity"] ?? [];
    $prices = $_POST["item_unit_price"] ?? [];
    foreach ($descs as $i => $d) {
        $d = trim($d); if ($d === "") continue;
        $qq = (float)($qtys[$i] ?? 1); $pp = (float)($prices[$i] ?? 0); $ln = $qq*$pp;
        $it = $conn->prepare("INSERT INTO quotation_items(quote_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
        $it->bind_param("isdid", $qid, $d, $qq, $pp, $ln);
        $it->execute();
    }
    update_quote_totals($conn, $qid);
    log_activity($conn, "Created quotation $quoteNo");
    header("Location: quote_view.php?id=$qid"); exit;
}
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>New Quotation</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>New Quotation</h1><p><?=h($quoteNo)?></p></div><a class="btn secondary" href="quotations.php">← Back</a></div>
  <form method="post" id="quoteForm">
    <section class="panel"><h2>Client &amp; Details</h2>
      <div class="grid">
        <label>Client
          <select name="client_id" required>
            <option value="">— Select —</option>
            <?php while ($c = $clients->fetch_assoc()): ?>
              <option value="<?=(int)$c["id"]?>"><?=h($c["client_name"])?></option>
            <?php endwhile; ?>
          </select>
        </label>
        <label>Issue Date<input type="date" name="issue_date" value="<?=date('Y-m-d')?>"></label>
        <label>Valid Until<input type="date" name="valid_until" value="<?=date('Y-m-d', strtotime('+14 days'))?>"></label>
        <label>Currency<input name="currency" value="UGX"></label>
        <label>Status
          <select name="status">
            <option value="DRAFT">DRAFT</option>
            <option value="SENT">SENT</option>
          </select>
        </label>
      </div>
    </section>
    <section class="panel"><h2>Line Items</h2>
      <table class="diag" id="itemsTable">
        <tr><th>Description</th><th style="width:100px">Qty</th><th style="width:160px">Unit Price</th><th style="width:160px">Line Total</th><th style="width:50px"></th></tr>
        <tr class="itemrow">
          <td><textarea name="item_description[]" rows="1" style="min-height:auto"></textarea></td>
          <td><input type="number" step="0.01" name="item_quantity[]" class="qty" value="1"></td>
          <td><input type="number" step="0.01" name="item_unit_price[]" class="price" value="0"></td>
          <td class="linetotal" style="text-align:right;padding-top:14px">0.00</td>
          <td><button type="button" class="btn small danger removeRow">×</button></td>
        </tr>
      </table>
      <div class="actions" style="justify-content:flex-start"><button type="button" class="btn secondary" id="addRow">+ Add Row</button></div>
    </section>
    <section class="panel"><h2>Totals &amp; Notes</h2>
      <div class="grid">
        <label>Discount<input type="number" step="0.01" name="discount_amount" value="0"></label>
        <label>VAT Rate (%)<input type="number" step="0.01" name="tax_rate" value="<?=h($defaultVat)?>"></label>
        <label>Notes<textarea name="notes"></textarea></label>
        <label>Terms<textarea name="terms"><?=h(get_setting($conn, "invoice_terms", ""))?></textarea></label>
      </div>
    </section>
    <div class="actions"><a href="quotations.php" class="btn secondary">Cancel</a><button class="btn">Save Quotation</button></div>
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