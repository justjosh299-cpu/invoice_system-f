<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? ($_POST["id"] ?? 0)); if ($id<=0) die("Invalid part.");
$s = $conn->prepare("SELECT * FROM parts WHERE id=?"); $s->bind_param("i",$id); $s->execute();
$p = $s->get_result()->fetch_assoc(); if (!$p) die("Part not found.");
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $code = trim($_POST["part_code"] ?? "");
    $name = trim($_POST["part_name"] ?? "");
    $cat  = trim($_POST["category"] ?? "");
    $reo  = (int)($_POST["reorder_level"] ?? 2);
    $cost = (float)($_POST["unit_cost"] ?? 0);
    $sup  = trim($_POST["supplier"] ?? "");
    $notes = trim($_POST["notes"] ?? "");
    $active = isset($_POST["active"]) ? 1 : 0;
    $u = $conn->prepare("UPDATE parts SET part_code=?, part_name=?, category=?, reorder_level=?, unit_cost=?, supplier=?, notes=?, active=? WHERE id=?");
    $u->bind_param("sssiddsii", $code, $name, $cat, $reo, $cost, $sup, $notes, $active, $id);
    $u->execute();
    log_activity($conn, "Updated part: $name");
    header("Location: parts.php"); exit;
}
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Edit Part</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Edit Part</h1><p><?=h($p["part_name"])?></p></div><a class="btn secondary" href="parts.php">← Back</a></div>
  <form method="post">
    <input type="hidden" name="id" value="<?=$id?>">
    <section class="panel"><h2>Details</h2>
      <div class="grid">
        <label>Part Code<input name="part_code" value="<?=h($p["part_code"])?>"></label>
        <label>Part Name *<input name="part_name" value="<?=h($p["part_name"])?>" required></label>
        <label>Category<input name="category" value="<?=h($p["category"])?>"></label>
        <label>Current Qty<input value="<?=(int)$p["quantity"]?>" disabled></label>
        <label>Reorder Level<input type="number" name="reorder_level" value="<?=(int)$p["reorder_level"]?>"></label>
        <label>Unit Cost<input type="number" step="0.01" name="unit_cost" value="<?=h($p["unit_cost"])?>"></label>
        <label>Supplier<input name="supplier" value="<?=h($p["supplier"])?>"></label>
      </div>
      <label>Notes<textarea name="notes"><?=h($p["notes"])?></textarea></label>
      <label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="active" <?=$p["active"]?"checked":""?> style="width:auto"> Active</label>
    </section>
    <div class="actions"><a href="parts.php" class="btn secondary">Cancel</a><button class="btn">Save Changes</button></div>
  </form>
</main></body></html>