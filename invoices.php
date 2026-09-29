<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$q       = trim($_GET["q"] ?? "");
$status  = $_GET["status"] ?? "";
$paid    = $_GET["paid"] ?? ""; // "" | "paid" | "unpaid"
$from    = trim($_GET["from"] ?? "");
$to      = trim($_GET["to"] ?? "");
$amtMin  = $_GET["amt_min"] ?? "";
$amtMax  = $_GET["amt_max"] ?? "";

$where = []; $params = []; $types = "";
if ($q !== "") {
    $where[] = "(i.invoice_no LIKE ? OR c.client_name LIKE ? OR i.notes LIKE ?)";
    $like = "%" . $q . "%"; $params[] = $like; $params[] = $like; $params[] = $like; $types .= "sss";
}
if ($status !== "") { $where[] = "i.status = ?"; $params[] = $status; $types .= "s"; }
if ($paid === "paid")   $where[] = "(i.total - i.amount_paid) <= 0.009";
if ($paid === "unpaid") $where[] = "(i.total - i.amount_paid) > 0.009";
if ($from !== "") { $where[] = "i.issue_date >= ?"; $params[] = $from; $types .= "s"; }
if ($to !== "") { $where[] = "i.issue_date <= ?"; $params[] = $to; $types .= "s"; }
if ($amtMin !== "" && is_numeric($amtMin)) { $where[] = "i.total >= ?"; $params[] = (float)$amtMin; $types .= "d"; }
if ($amtMax !== "" && is_numeric($amtMax)) { $where[] = "i.total <= ?"; $params[] = (float)$amtMax; $types .= "d"; }
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "SELECT i.*, COALESCE(c.client_name,'—') client_name
        FROM invoices i LEFT JOIN clients c ON c.id = i.client_id
        $whereSql ORDER BY i.id DESC LIMIT 500";
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result();

function inv_status_badge($row) {
    $s = $row["status"];
    if (in_array($s, ["SENT","PARTIAL"], true) && !empty($row["due_date"]) && strtotime($row["due_date"]) < strtotime(date("Y-m-d"))) $s = "OVERDUE";
    return $s;
}
?><!doctype html><html><head><title>Invoices</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
    <div class="hero">
    <div><h1>Invoices</h1><p>Create, manage, print, and batch-print invoices.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if (file_exists(__DIR__ . '/recurring.php')): ?>
        <a class="btn secondary" href="recurring.php">🔁 Recurring</a>
      <?php endif; ?>
      <a class="btn secondary" href="export.php?type=invoices">⬇ CSV</a>
      <a class="btn" href="invoice_new.php">+ New Invoice</a>
    </div>
  </div>
  <?php if (isset($_GET["deleted"])): ?><div class="success">Invoice deleted.</div><?php endif; ?>

  <form class="search">
    <label>Search<input name="q" value="<?=htmlspecialchars($q)?>" placeholder="Invoice no., client, notes"></label>
    <label>Status
      <select name="status">
        <option value="">All</option>
        <?php foreach (["DRAFT","SENT","PARTIAL","PAID","OVERDUE","CANCELLED"] as $s): ?>
          <option value="<?=$s?>" <?=$status===$s?"selected":""?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Paid?
      <select name="paid">
        <option value="" <?=$paid===""?"selected":""?>>All</option>
        <option value="paid" <?=$paid==="paid"?"selected":""?>>Fully paid</option>
        <option value="unpaid" <?=$paid==="unpaid"?"selected":""?>>Unpaid / Partial</option>
      </select>
    </label>
    <label>Issued From<input type="date" name="from" value="<?=htmlspecialchars($from)?>"></label>
    <label>To<input type="date" name="to" value="<?=htmlspecialchars($to)?>"></label>
    <label>Amount ≥<input type="number" step="0.01" name="amt_min" value="<?=htmlspecialchars($amtMin)?>" style="max-width:110px"></label>
    <label>Amount ≤<input type="number" step="0.01" name="amt_max" value="<?=htmlspecialchars($amtMax)?>" style="max-width:110px"></label>
    <button class="btn">Filter</button>
    <a class="btn secondary" href="invoices.php">Reset</a>
  </form>

  <form method="post" action="invoice_batch_pdf.php" id="batchForm">
    <div class="panel">
      <div class="panelhead">
        <h2><?=$rows->num_rows?> invoice<?=$rows->num_rows===1?"":"s"?></h2>
        <div>
          <button type="submit" class="btn secondary" id="batchPrintBtn" disabled>🖨 Batch print selected</button>
        </div>
      </div>
      <table>
        <tr>
          <th style="width:30px"><input type="checkbox" id="checkAll" style="width:auto"></th>
          <th>Invoice</th><th>Client</th><th>Issued</th><th>Due</th><th>Total</th><th>Balance</th><th>Status</th><th>Actions</th>
        </tr>
        <?php if ($rows->num_rows === 0): ?><tr><td colspan="9" style="text-align:center;color:#6b7887;padding:20px">No invoices match your filters.</td></tr><?php endif; ?>
        <?php while ($r = $rows->fetch_assoc()): $st = inv_status_badge($r); ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?=(int)$r["id"]?>" class="rowcheck" style="width:auto"></td>
            <td><b><?=htmlspecialchars($r["invoice_no"])?></b></td>
            <td><?=htmlspecialchars($r["client_name"])?></td>
            <td><?=htmlspecialchars($r["issue_date"])?></td>
            <td><?=htmlspecialchars($r["due_date"])?></td>
            <td><?=htmlspecialchars($r["currency"])?> <?=number_format($r["total"], 2)?></td>
            <td><?=htmlspecialchars($r["currency"])?> <?=number_format($r["total"] - $r["amount_paid"], 2)?></td>
            <td><span class="pill status-<?=$st?>"><?=$st?></span></td>
            <td class="rowactions">
              <a class="btn small secondary" href="invoice_view.php?id=<?=(int)$r["id"]?>">View</a>
              <a class="btn small secondary" href="invoice_pdf.php?id=<?=(int)$r["id"]?>">PDF</a>
              <a class="btn small secondary" href="invoice_edit.php?id=<?=(int)$r["id"]?>">Edit</a>
              <button type="button" class="btn small danger" onclick="confirmDelete('<?=(int)$r["id"]?>','<?=htmlspecialchars($r["invoice_no"], ENT_QUOTES)?>')">Delete</button>
            </td>
          </tr>
        <?php endwhile; ?>
      </table>
    </div>
  </form>

  <div class="modal" id="deleteModal"><div class="box"><h3>Delete invoice?</h3>
    <p>This will permanently delete invoice <b id="delNo"></b> and all its items and payments.</p>
    <form method="post" action="invoice_delete.php">
      <input type="hidden" name="id" id="delId">
      <input type="hidden" name="confirm" value="DELETE">
      <div class="actions"><button type="button" class="btn secondary" onclick="closeModal()">Cancel</button><button type="submit" class="btn danger">Delete permanently</button></div>
    </form>
  </div></div>
</main>
<script>
function confirmDelete(id, no){document.getElementById('delId').value=id;document.getElementById('delNo').textContent=no;document.getElementById('deleteModal').classList.add('show');}
function closeModal(){document.getElementById('deleteModal').classList.remove('show');}
document.getElementById('deleteModal').addEventListener('click',function(e){if(e.target===this)closeModal();});

var checkAll = document.getElementById('checkAll');
var rowchecks = document.querySelectorAll('.rowcheck');
var batchBtn = document.getElementById('batchPrintBtn');
function refreshBatchBtn() {
  var n = document.querySelectorAll('.rowcheck:checked').length;
  batchBtn.disabled = n === 0;
  batchBtn.textContent = '🖨 Batch print selected' + (n > 0 ? ' (' + n + ')' : '');
}
if (checkAll) {
  checkAll.addEventListener('change', function(){
    rowchecks.forEach(function(c){ c.checked = checkAll.checked; });
    refreshBatchBtn();
  });
}
rowchecks.forEach(function(c){ c.addEventListener('change', refreshBatchBtn); });
refreshBatchBtn();
</script>
</body></html>