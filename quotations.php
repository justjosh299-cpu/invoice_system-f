<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$status = $_GET["status"] ?? "";
$q = trim($_GET["q"] ?? "");
$where = []; $params = []; $types = "";
if ($status !== "") { $where[] = "q.status = ?"; $params[] = $status; $types .= "s"; }
if ($q !== "") { $where[] = "(q.quote_no LIKE ? OR c.client_name LIKE ?)"; $like = "%".$q."%"; $params[]=$like; $params[]=$like; $types .= "ss"; }
$whereSql = $where ? ("WHERE ".implode(" AND ",$where)) : "";
$sql = "SELECT q.*, COALESCE(c.client_name,'—') client_name FROM quotations q LEFT JOIN clients c ON c.id=q.client_id $whereSql ORDER BY q.id DESC LIMIT 500";
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result();
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Quotations</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Quotations</h1><p>Draft, send, accept, and convert to invoices.</p></div>
    <div>
      <a class="btn secondary" href="export.php?type=quotations">⬇ CSV</a>
      <a class="btn" href="quote_new.php">+ New Quote</a>
    </div>
  </div>
  <?php if (isset($_GET["deleted"])): ?><div class="success">Quotation deleted.</div><?php endif; ?>
  <form class="search">
    <label>Search<input name="q" value="<?=h($q)?>" placeholder="Quote no. or client"></label>
    <label>Status
      <select name="status">
        <option value="">All</option>
        <?php foreach (["DRAFT","SENT","ACCEPTED","REJECTED","EXPIRED","CONVERTED"] as $s): ?>
          <option value="<?=$s?>" <?=$status===$s?"selected":""?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn">Filter</button>
    <a class="btn secondary" href="quotations.php">Reset</a>
  </form>
  <div class="panel"><table>
    <tr><th>Quote</th><th>Client</th><th>Issued</th><th>Valid Until</th><th>Total</th><th>Status</th><th>Actions</th></tr>
    <?php if ($rows->num_rows === 0): ?><tr><td colspan="7" style="text-align:center;color:#6b7887;padding:20px">No quotations yet.</td></tr><?php endif; ?>
    <?php while ($r = $rows->fetch_assoc()): ?>
      <tr>
        <td><b><?=h($r["quote_no"])?></b></td>
        <td><?=h($r["client_name"])?></td>
        <td><?=h($r["issue_date"])?></td>
        <td><?=h($r["valid_until"])?></td>
        <td><?=h($r["currency"])?> <?=number_format($r["total"], 2)?></td>
        <td><span class="pill status-<?=$r["status"]?>"><?=h($r["status"])?></span></td>
        <td class="rowactions">
          <a class="btn small secondary" href="quote_view.php?id=<?=(int)$r["id"]?>">View</a>
          <a class="btn small secondary" href="quote_edit.php?id=<?=(int)$r["id"]?>">Edit</a>
          <a class="btn small danger" href="quote_delete.php?id=<?=(int)$r["id"]?>" onclick="return confirm('Delete this quote?')">Delete</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </table></div>
</main></body></html>