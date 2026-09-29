<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$q = trim($_GET["q"] ?? "");
$filter = $_GET["filter"] ?? "";
$like = "%" . $q . "%";

$where = []; $params = []; $types = "";
if ($q !== "") {
    $where[] = "(part_name LIKE ? OR part_code LIKE ? OR supplier LIKE ? OR category LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $types .= "ssss";
}
if ($filter === "low") $where[] = "quantity <= reorder_level";
if ($filter === "out") $where[] = "quantity = 0";
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "SELECT * FROM parts $whereSql ORDER BY part_name ASC LIMIT 500";
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result();

$totals = $conn->query("SELECT COUNT(*) total, COALESCE(SUM(quantity * unit_cost),0) value FROM parts WHERE active=1")->fetch_assoc();
$low = $conn->query("SELECT COUNT(*) c FROM parts WHERE active=1 AND quantity <= reorder_level")->fetch_assoc()["c"];
$out = $conn->query("SELECT COUNT(*) c FROM parts WHERE active=1 AND quantity = 0")->fetch_assoc()["c"];
?><!doctype html><html><head><title>Parts Inventory</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Parts Inventory</h1><p>Track spare parts, stock levels, and movements.</p></div>
    <div>
      <a class="btn secondary" href="parts_movements.php">📋 Movement Log</a>
      <a class="btn" href="parts_new.php">+ Add Part</a>
    </div>
  </div>

  <div class="cards">
    <div class="card"><small>Total Parts</small><b><?=(int)$totals["total"]?></b></div>
    <div class="card"><small>Stock Value</small><b><?=number_format($totals["value"],0)?></b></div>
    <div class="card"><small>Low Stock</small><b style="color:#a12626"><?=(int)$low?></b></div>
    <div class="card"><small>Out of Stock</small><b style="color:#a12626"><?=(int)$out?></b></div>
  </div>

  <?php if ($low > 0): ?>
    <div class="warn">⚠ <?=$low?> part<?=$low===1?"":"s"?> at or below reorder level. <a href="parts.php?filter=low">View low-stock parts →</a></div>
  <?php endif; ?>

  <form class="search">
    <label>Search<input name="q" value="<?=htmlspecialchars($q)?>" placeholder="Name, code, supplier, category"></label>
    <label>Filter
      <select name="filter">
        <option value="">All parts</option>
        <option value="low" <?=$filter==="low"?"selected":""?>>Low stock only</option>
        <option value="out" <?=$filter==="out"?"selected":""?>>Out of stock</option>
      </select>
    </label>
    <button class="btn">Filter</button>
    <a class="btn secondary" href="parts.php">Reset</a>
  </form>

  <div class="panel"><table>
    <tr><th>Code</th><th>Name</th><th>Category</th><th>Qty</th><th>Reorder</th><th>Unit Cost</th><th>Value</th><th>Supplier</th><th>Actions</th></tr>
    <?php if ($rows->num_rows === 0): ?><tr><td colspan="9" style="text-align:center;color:#6b7887;padding:20px">No parts yet. Click <b>+ Add Part</b>.</td></tr><?php endif; ?>
    <?php while ($p = $rows->fetch_assoc()):
      $lowRow = ($p["quantity"] <= $p["reorder_level"]);
    ?>
      <tr <?=$lowRow?'style="background:#fff8f8"':''?>>
        <td><b><?=htmlspecialchars($p["part_code"] ?: "—")?></b></td>
        <td><?=htmlspecialchars($p["part_name"])?></td>
        <td><?=htmlspecialchars($p["category"])?></td>
        <td><b style="color:<?=$p["quantity"]==0?'#a12626':($lowRow?'#7a4a00':'#0a7a2f')?>"><?=(int)$p["quantity"]?></b></td>
        <td><?=(int)$p["reorder_level"]?></td>
        <td><?=number_format($p["unit_cost"], 2)?></td>
        <td><?=number_format($p["quantity"] * $p["unit_cost"], 2)?></td>
        <td><?=htmlspecialchars($p["supplier"])?></td>
        <td class="rowactions">
          <a class="btn small secondary" href="parts_edit.php?id=<?=(int)$p["id"]?>">Edit</a>
          <a class="btn small secondary" href="parts_move.php?id=<?=(int)$p["id"]?>">Stock In/Out</a>
          <a class="btn small danger" href="parts_delete.php?id=<?=(int)$p["id"]?>" onclick="return confirm('Delete this part and its movement history?')">Delete</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </table></div>
</main></body></html>