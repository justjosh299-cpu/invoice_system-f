<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$reportId = (int)($_GET["report_id"] ?? ($_POST["report_id"] ?? 0));
if ($reportId <= 0) die("Missing report_id.");

$s = $conn->prepare("SELECT r.*, c.client_name, c.address, c.phone FROM reports r LEFT JOIN clients c ON c.id=r.client_id WHERE r.id=?");
$s->bind_param("i", $reportId); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Report not found.");

$labourRate = (float)get_setting($conn, "labour_rate", "0");
$defaultVat = (float)get_setting($conn, "invoice_vat", "18");
$msg = ""; $err = "";

// Convert-to-invoice action
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_invoice") {
    $labourHours  = (float)($_POST["labour_hours"] ?? 0);
    $labourRateIn = (float)($_POST["labour_rate"] ?? 0);
    $discount     = (float)($_POST["discount_amount"] ?? 0);
    $taxRate      = (float)($_POST["tax_rate"] ?? $defaultVat);
    $notes        = trim($_POST["notes"] ?? "");
    $cur          = trim($_POST["currency"] ?? "UGX");

    $partIds    = $_POST["part_id"]     ?? [];
    $partQtys   = $_POST["part_qty"]    ?? [];
    $customDesc = $_POST["custom_desc"] ?? [];
    $customQty  = $_POST["custom_qty"]  ?? [];
    $customPrice= $_POST["custom_price"]?? [];

    if (empty($partIds) && empty(array_filter($customDesc))) {
        $err = "Add at least one part or custom line.";
    } else {
        // Create invoice
        $invoiceNo = next_invoice_no($conn);
        $issueDate = date("Y-m-d");
        $dueDays = (int)get_setting($conn, "invoice_due_days", "14");
        $dueDate = date("Y-m-d", strtotime("+$dueDays days"));
        $terms = get_setting($conn, "invoice_terms", "");
        $tech = $_SESSION["name"] ?? "";
        $uid = (int)$_SESSION["user_id"];

        $ins = $conn->prepare("INSERT INTO invoices(invoice_no, report_id, client_id, issue_date, due_date, status, currency,
                               discount_amount, tax_rate, notes, terms, technician_name, created_by)
                               VALUES(?,?,?,?,?, 'DRAFT', ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param("siisssddsssi",
            $invoiceNo, $reportId, $r["client_id"], $issueDate, $dueDate, $cur,
            $discount, $taxRate, $notes, $terms, $tech, $uid);
        $ins->execute();
        $invoiceId = $ins->insert_id;

        // Parts from inventory
        foreach ($partIds as $i => $pid) {
            $pid = (int)$pid; if ($pid <= 0) continue;
            $qty = (float)($partQtys[$i] ?? 1); if ($qty <= 0) continue;
            $p = $conn->query("SELECT * FROM parts WHERE id=" . $pid)->fetch_assoc();
            if (!$p) continue;
            $desc = "Part: " . $p["part_name"] . ($p["part_code"] ? " (".$p["part_code"].")" : "");
            $price = (float)$p["unit_cost"];
            $line = $qty * $price;
            $it = $conn->prepare("INSERT INTO invoice_items(invoice_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
            $it->bind_param("isdid", $invoiceId, $desc, $qty, $price, $line);
            $it->execute();
            // Deduct stock via part_move
            if (function_exists('part_move')) {
                part_move($conn, $pid, -1 * (int)$qty, "used", "invoice", $invoiceId, "Used on ticket " . $r["ticket_no"]);
            }
        }
        // Custom lines
        foreach ($customDesc as $i => $d) {
            $d = trim($d); if ($d === "") continue;
            $qty = (float)($customQty[$i] ?? 1);
            $price = (float)($customPrice[$i] ?? 0);
            $line = $qty * $price;
            $it = $conn->prepare("INSERT INTO invoice_items(invoice_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
            $it->bind_param("isdid", $invoiceId, $d, $qty, $price, $line);
            $it->execute();
        }
        // Labour as a line item
        if ($labourHours > 0 && $labourRateIn > 0) {
            $desc = "Labour (" . number_format($labourHours, 2) . " hrs @ " . number_format($labourRateIn, 2) . "/hr)";
            $line = $labourHours * $labourRateIn;
            $it = $conn->prepare("INSERT INTO invoice_items(invoice_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
            $it->bind_param("isdid", $invoiceId, $desc, $labourHours, $labourRateIn, $line);
            $it->execute();
        }

        update_invoice_totals($conn, $invoiceId);

        // Apply any deposits
        if (function_exists('apply_deposits_to_invoice')) {
            apply_deposits_to_invoice($conn, $invoiceId, $reportId, (int)$r["client_id"]);
        }

        log_activity($conn, "Created invoice $invoiceNo from cost worksheet for ticket " . $r["ticket_no"], $r["ticket_no"]);
        header("Location: invoice_view.php?id=" . $invoiceId . "&from=worksheet");
        exit;
    }
}

$parts = $conn->query("SELECT id, part_code, part_name, quantity, unit_cost FROM parts WHERE active=1 AND quantity > 0 ORDER BY part_name");
function h($x){ return htmlspecialchars($x ?? ""); }
$depositAvail = function_exists('deposit_total_for_report') ? deposit_total_for_report($conn, $reportId) : 0;
?><!doctype html><html><head><title>Cost Worksheet</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include $base ?? "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Repair Cost Worksheet</h1><p>Ticket <b><?=h($r["ticket_no"])?></b> — <?=h($r["client_name"])?></p></div>
    <div>
      <a class="btn secondary" href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>">← Back to Ticket</a>
      <a class="btn secondary" href="deposits.php?ticket=<?=urlencode($r["ticket_no"])?>">💵 Deposits</a>
    </div>
  </div>
  <?php if ($msg): ?><div class="success"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>

  <?php if ($depositAvail > 0): ?>
    <div class="success">💵 Unapplied deposits for this ticket: <b><?=number_format($depositAvail, 2)?></b> — will be auto-deducted from the invoice.</div>
  <?php endif; ?>

  <form method="post" id="cwForm">
    <input type="hidden" name="action" value="save_invoice">
    <input type="hidden" name="report_id" value="<?=$reportId?>">

    <section class="panel">
      <h2>Parts Used (from inventory)</h2>
      <table class="diag" id="partsTable">
        <tr><th>Part</th><th style="width:100px">Qty</th><th style="width:130px">Unit Cost</th><th style="width:130px">Line Total</th><th style="width:40px"></th></tr>
        <?php
        $hasRows = false;
        // no rows initially
        ?>
      </table>
      <div class="actions" style="justify-content:flex-start">
        <button type="button" class="btn secondary" id="addPart">+ Add Part</button>
      </div>
      <p class="hint">Selected parts are deducted from inventory when the invoice is saved.</p>
    </section>

    <section class="panel">
      <h2>Other Line Items (custom)</h2>
      <table class="diag" id="customTable">
        <tr><th>Description</th><th style="width:100px">Qty</th><th style="width:130px">Unit Price</th><th style="width:130px">Line Total</th><th style="width:40px"></th></tr>
      </table>
      <div class="actions" style="justify-content:flex-start">
        <button type="button" class="btn secondary" id="addCustom">+ Add Row</button>
      </div>
    </section>

    <section class="panel">
      <h2>Labour &amp; Totals</h2>
      <div class="grid">
        <label>Labour Hours<input type="number" step="0.25" name="labour_hours" value="0"></label>
        <label>Hourly Rate<input type="number" step="0.01" name="labour_rate" value="<?=h($labourRate)?>"></label>
        <label>Discount<input type="number" step="0.01" name="discount_amount" value="0"></label>
        <label>VAT Rate (%)<input type="number" step="0.01" name="tax_rate" value="<?=h($defaultVat)?>"></label>
        <label>Currency<input name="currency" value="<?=h(get_setting($conn, "invoice_currency", "UGX"))?>"></label>
      </div>
      <label>Notes<textarea name="notes">Cost worksheet for ticket <?=h($r["ticket_no"])?></textarea></label>
    </section>

    <div class="actions">
      <a href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>" class="btn secondary">Cancel</a>
      <button class="btn" type="submit">Create Draft Invoice →</button>
    </div>
  </form>
</main>

<script>
var partsData = <?=json_encode(array_map(function($p){
    return [
        "id" => (int)$p["id"],
        "label" => $p["part_name"] . ($p["part_code"] ? " (".$p["part_code"].")" : ""),
        "cost" => (float)$p["unit_cost"],
        "stock" => (int)$p["quantity"],
    ];
}, iterator_to_array($parts)))?>;

function makeSelect(name, cls) {
  var s = document.createElement('select');
  s.name = name; s.className = cls;
  var opt = document.createElement('option'); opt.value = ""; opt.textContent = "— Select part —";
  s.appendChild(opt);
  partsData.forEach(function(p){
    var o = document.createElement('option');
    o.value = p.id;
    o.textContent = p.label + ' (stock ' + p.stock + ')';
    o.dataset.cost = p.cost;
    s.appendChild(o);
  });
  return s;
}

function recalcPartRow(row) {
  var q = parseFloat(row.querySelector('.pqty').value || 0);
  var sel = row.querySelector('.ppart');
  var opt = sel.options[sel.selectedIndex];
  var c = opt && opt.dataset ? parseFloat(opt.dataset.cost || 0) : 0;
  row.querySelector('.ptotal').textContent = (q * c).toFixed(2);
}

function recalcCustomRow(row) {
  var q = parseFloat(row.querySelector('.cqty').value || 0);
  var p = parseFloat(row.querySelector('.cprice').value || 0);
  row.querySelector('.ctotal').textContent = (q * p).toFixed(2);
}

document.getElementById('addPart').addEventListener('click', function(){
  var tbody = document.getElementById('partsTable');
  var tr = document.createElement('tr');
  tr.className = 'partrow';

  var tdP = document.createElement('td');
  tdP.appendChild(makeSelect('part_id[]', 'ppart'));

  var tdQ = document.createElement('td');
  tdQ.innerHTML = '<input type="number" step="1" name="part_qty[]" class="pqty" value="1">';

  var tdU = document.createElement('td');
  tdU.innerHTML = '<span style="color:#6b7887">auto</span>';

  var tdT = document.createElement('td');
  tdT.className = 'ptotal';
  tdT.style.textAlign = 'right';
  tdT.textContent = '0.00';

  var tdX = document.createElement('td');
  var rm = document.createElement('button');
  rm.type = 'button'; rm.className = 'btn small danger'; rm.textContent = '×';
  rm.addEventListener('click', function(){ tr.remove(); });
  tdX.appendChild(rm);

  tr.appendChild(tdP); tr.appendChild(tdQ); tr.appendChild(tdU); tr.appendChild(tdT); tr.appendChild(tdX);
  tbody.appendChild(tr);

  tr.querySelector('.ppart').addEventListener('change', function(){ recalcPartRow(tr); });
  tr.querySelector('.pqty').addEventListener('input', function(){ recalcPartRow(tr); });
});

document.getElementById('addCustom').addEventListener('click', function(){
  var tbody = document.getElementById('customTable');
  var tr = document.createElement('tr');
  tr.className = 'customrow';
  tr.innerHTML = '<td><input name="custom_desc[]" placeholder="Description"></td>'
    + '<td><input type="number" step="0.01" name="custom_qty[]" class="cqty" value="1"></td>'
    + '<td><input type="number" step="0.01" name="custom_price[]" class="cprice" value="0"></td>'
    + '<td class="ctotal" style="text-align:right">0.00</td>'
    + '<td><button type="button" class="btn small danger">×</button></td>';
  var rb = tr.querySelector('.danger');
  rb.addEventListener('click', function(){ tr.remove(); });
  tr.querySelector('.cqty').addEventListener('input', function(){ recalcCustomRow(tr); });
  tr.querySelector('.cprice').addEventListener('input', function(){ recalcCustomRow(tr); });
  tbody.appendChild(tr);
});
</script>
</body></html>