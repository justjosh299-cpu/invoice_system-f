<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$msg = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $code = trim($_POST["part_code"] ?? "");
    $name = trim($_POST["part_name"] ?? "");
    $cat  = trim($_POST["category"] ?? "");
    $qty  = (int)($_POST["quantity"] ?? 0);
    $reo  = (int)($_POST["reorder_level"] ?? 2);
    $cost = (float)($_POST["unit_cost"] ?? 0);
    $sup  = trim($_POST["supplier"] ?? "");
    $notes = trim($_POST["notes"] ?? "");
    if ($name === "") $msg = "Part name is required.";
    else {
        $s = $conn->prepare("INSERT INTO parts(part_code, part_name, category, quantity, reorder_level, unit_cost, supplier, notes) VALUES(?,?,?,?,?,?,?,?)");
        $s->bind_param("sssiidss", $code, $name, $cat, $qty, $reo, $cost, $sup, $notes);
        $s->execute();
        $newId = $s->insert_id;
        if ($qty != 0) part_move($conn, $newId, 0, "initial", "part", $newId, "Initial stock set to $qty");
        log_activity($conn, "Added part: $name");
        header("Location: parts.php"); exit;
    }
}
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>New Part</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Add Part</h1></div><a class="btn secondary" href="parts.php">← Back</a></div>
  <?php if ($msg): ?><div class="error"><?=h($msg)?></div><?php endif; ?>
  <form method="post">
    <section class="panel"><h2>Part Details</h2>
      <div class="grid">
        <label>Part Code<input name="part_code" placeholder="e.g. RAM-DDR4-8"></label>
        <label>Part Name *<input name="part_name" required></label>
        <label>Category<input name="category" placeholder="e.g. RAM, SSD, Toner"></label>
        <label>Opening Quantity<input type="number" name="quantity" value="0"></label>
        <label>Reorder Level<input type="number" name="reorder_level" value="2"></label>
        <label>Unit Cost<input type="number" step="0.01" name="unit_cost" value="0"></label>
        <label>Supplier<input name="supplier"></label>
      </div>
      <label>Notes<textarea name="notes"></textarea></label>
    </section>
    <div class="actions"><a href="parts.php" class="btn secondary">Cancel</a><button class="btn">Save Part</button></div>
  </form>
</main></body></html>