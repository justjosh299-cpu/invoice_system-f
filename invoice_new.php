<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$fromReport = (int)($_GET["report_id"] ?? 0);
$preClientId = 0;
$preClientName = "";
$preNotes = "";
$preItems = [];

if ($fromReport > 0) {
    $r = $conn->prepare("SELECT r.*, c.client_name, c.address, c.phone, c.alternate_phone
                         FROM reports r LEFT JOIN clients c ON c.id = r.client_id WHERE r.id=?");
    $r->bind_param("i", $fromReport); $r->execute();
    $rep = $r->get_result()->fetch_assoc();
    if ($rep) {
        $preClientId = (int)$rep["client_id"];
        $preClientName = $rep["client_name"] ?? "";
        $devLabel = (($rep["device_type"] ?? "computer") === "printer") ? "Printer / Photocopier / Scanner" : "Computer / Laptop";
        $desc = "Diagnostic & repair service — Ticket " . $rep["ticket_no"] . "\n"
              . "Device: " . $devLabel . ($rep["computer_make_model"] ? " ({$rep["computer_make_model"]})" : "");
        $preItems[] = [
            "description" => $desc,
            "quantity"    => 1,
            "unit_price"  => (float)($rep["estimated_cost"] ?? 0),
        ];
        $preNotes = "This invoice references service ticket " . $rep["ticket_no"] . ".";
    }
}

$defaultVat = (float)get_setting($conn, "invoice_vat", "18");
$defaultCurrency = get_setting($conn, "invoice_currency", "UGX");
$defaultDueDays = (int)get_setting($conn, "invoice_due_days", 14);
$defaultTerms = get_setting($conn, "invoice_terms", "");
$invoiceNo = next_invoice_no($conn);
$today = date("Y-m-d");
$dueDate = date("Y-m-d", strtotime("+$defaultDueDays days"));

// Handle POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $clientId   = (int)($_POST["client_id"] ?? 0);
    $reportId   = (int)($_POST["report_id"] ?? 0);
    $issueDate  = $_POST["issue_date"] ?? date("Y-m-d");
    $dueDate    = $_POST["due_date"] ?? $dueDate;
    $currency   = trim($_POST["currency"] ?? $defaultCurrency);
    $discount   = (float)($_POST["discount_amount"] ?? 0);
    $taxRate    = (float)($_POST["tax_rate"] ?? $defaultVat);
    $notes      = trim($_POST["notes"] ?? "");
    $terms      = trim($_POST["terms"] ?? "");
    $status     = $_POST["status"] ?? "DRAFT";
    $tech       = $_SESSION["name"] ?? "";

    // client: reuse existing, or create new from "new_client_*" fields
    if ($clientId <= 0) {
        $newName = trim($_POST["new_client_name"] ?? "");
        if ($newName !== "") {
            $s = $conn->prepare("INSERT INTO clients(client_name,address,phone,alternate_phone) VALUES(?,?,?,?)");
            $a = $_POST["new_client_address"] ?? ""; $p = $_POST["new_client_phone"] ?? ""; $ap = $_POST["new_client_alt_phone"] ?? "";
            $s->bind_param("ssss", $newName, $a, $p, $ap); $s->execute();
            $clientId = $s->insert_id;
        }
    }

    $s = $conn->prepare("INSERT INTO invoices(invoice_no, report_id, client_id, issue_date, due_date, status, currency,
                        discount_amount, tax_rate, notes, terms, technician_name, created_by)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $emptyReport = $reportId > 0 ? $reportId : null;
    $uid = (int)$_SESSION["user_id"];
    $s->bind_param("siisssddssssi",
        $invoiceNo, $emptyReport, $clientId, $issueDate, $dueDate, $status, $currency,
        $discount, $taxRate, $notes, $terms, $tech, $uid);
    $s->execute();
    $invoiceId = $s->insert_id;

    // items
    $descs  = $_POST["item_description"] ?? [];
    $qtys   = $_POST["item_quantity"] ?? [];
    $prices = $_POST["item_unit_price"] ?? [];
    foreach ($descs as $i => $d) {
        $d = trim($d); if ($d === "") continue;
        $q = (float)($qtys[$i] ?? 1);
        $p = (float)($prices[$i] ?? 0);
        $line = $q * $p;
        $it = $conn->prepare("INSERT INTO invoice_items(invoice_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
        $it->bind_param("isdid", $invoiceId, $d, $q, $p, $line);
        $it->execute();
    }

    update_invoice_totals($conn, $invoiceId);

    log_activity($conn, "Created invoice " . $invoiceNo);
    header("Location:invoice_view.php?id=" . $invoiceId);
    exit;
}

function h($x) { return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>New Invoice</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>New Invoice</h1><p>Create a new invoice for a client.</p></div>
    <div class="ticketbox">Invoice<br><b><?=h($invoiceNo)?></b></div>
  </div>
  <form method="post" id="invoiceForm" autocomplete="off">
    <input type="hidden" name="report_id" value="<?=$fromReport?>">

    <section class="panel"><h2>1. Client Information</h2>
      <?php if ($preClientId > 0): ?>
        <input type="hidden" name="client_id" value="<?=$preClientId?>">
        <p class="hint">Linked to existing client: <b><?=h($preClientName)?></b></p>
      <?php else: ?>
        <div class="grid">
          <label>Existing Client ID (optional)
            <input type="number" name="client_id" value="0" placeholder="Leave 0 to create a new client">
          </label>
          <label>New Client Name
            <input name="new_client_name" value="<?=h($preClientName)?>" placeholder="Used if ID is 0">
          </label>
          <label>Address<input name="new_client_address"></label>
          <label>Phone<input name="new_client_phone"></label>
          <label>Alt. Phone<input name="new_client_alt_phone"></label>
        </div>
        <p class="hint">Tip: find client IDs in the <a href="clients.php" target="_blank">Clients page</a>.</p>
      <?php endif; ?>
    </section>

    <section class="panel"><h2>2. Invoice Details</h2>
      <div class="grid">
        <label>Issue Date<input type="date" name="issue_date" value="<?=$today?>" required></label>
        <label>Due Date<input type="date" name="due_date" value="<?=$dueDate?>"></label>
        <label>Currency<input name="currency" value="<?=h($defaultCurrency)?>" maxlength="10"></label>
        <label>Status<select name="status">
          <?php foreach (["DRAFT","SENT","PARTIAL","PAID","CANCELLED"] as $st): ?>
            <option value="<?=$st?>" <?=$st==="DRAFT"?"selected":""?>><?=$st?></option>
          <?php endforeach; ?>
        </select></label>
      </div>
    </section>

    <section class="panel"><h2>3. Line Items</h2>
      <table class="diag" id="itemsTable">
        <tr><th>Description</th><th style="width:100px">Qty</th><th style="width:160px">Unit Price</th><th style="width:160px">Line Total</th><th style="width:50px"></th></tr>
        <?php
        if (empty($preItems)) $preItems[] = ["description"=>"","quantity"=>1,"unit_price"=>0];
        foreach ($preItems as $item): ?>
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

    <section class="panel"><h2>4. Totals &amp; Adjustments</h2>
      <div class="grid">
        <label>Discount<input type="number" step="0.01" name="discount_amount" value="0"></label>
        <label>VAT Rate (%)<input type="number" step="0.01" name="tax_rate" value="<?=h($defaultVat)?>"></label>
        <label>Notes<textarea name="notes"><?=h($preNotes)?></textarea></label>
        <label>Terms<textarea name="terms"><?=h($defaultTerms)?></textarea></label>
      </div>
    </section>

    <div class="actions">
      <a href="invoices.php" class="btn secondary">Cancel</a>
      <button class="btn" type="submit">Save Invoice →</button>
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