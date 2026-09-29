<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? ($_POST["id"] ?? 0)); if ($id<=0) die("Invalid part.");
$s = $conn->prepare("SELECT * FROM parts WHERE id=?"); $s->bind_param("i",$id); $s->execute();
$p = $s->get_result()->fetch_assoc(); if (!$p) die("Part not found.");
$msg = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $delta = (int)($_POST["delta"] ?? 0);
    $reason = $_POST["reason"] ?? "adjust";
    $note = trim($_POST["note"] ?? "");
    if ($delta === 0) $msg = "Enter a non-zero quantity.";
    else {
        part_move($conn, $id, $delta, $reason, "part", $id, $note);
        log_activity($conn, "Part movement: " . ($delta > 0 ? "+" : "") . $delta . " on " . $p["part_name"]);
        header("Location: parts.php"); exit;
    }
}
function h($x){ return htmlspecialchars($x ?? ""); }

$movs = $conn->query("SELECT m.*, COALESCE(u.full_name,'—') who FROM part_movements m LEFT JOIN users u ON u.id=m.user_id WHERE m.part_id=$id ORDER BY m.id DESC LIMIT 50");
?><!doctype html><html><head><title>Stock Movement</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Stock Movement</h1><p><?=h($p["part_name"])?> — current qty <b><?=(int)$p["quantity"]?></b></p></div><a class="btn secondary" href="parts.php">← Back</a></div>
  <?php if ($msg): ?><div class="error"><?=h($msg)?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="id" value="<?=$id?>">
    <section class="panel"><h2>Record Movement</h2>
      <div class="grid">
        <label>Quantity (use - for stock out)<input type="number" name="delta" value="1" required></label>
        <label>Reason
          <select name="reason">
            <option value="purchase">Purchase / restock</option>
            <option value="used">Used on repair</option>
            <option value="adjust">Adjustment</option>
            <option value="return">Return to supplier</option>
            <option value="damage">Damaged / lost</option>
          </select>
        </label>
        <label>Note<input name="note" placeholder="e.g. Used on ticket OMG-2026-00042"></label>
      </div>
      <div class="actions"><a href="parts.php" class="btn secondary">Cancel</a><button class="btn">Save Movement</button></div>
    </section>
  </form>
  <div class="panel"><h2>History</h2>
    <table>
      <tr><th>When</th><th>Delta</th><th>Reason</th><th>Note</th><th>By</th></tr>
      <?php while ($m = $movs->fetch_assoc()): ?>
        <tr>
          <td><?=h($m["created_at"])?></td>
          <td><b style="color:<?=$m["delta"]>0?'#0a7a2f':'#a12626'?>"><?=$m["delta"]>0?'+':'')?><?=h($m["delta"])?></b></td>
          <td><?=h($m["reason"])?></td>
          <td><?=h($m["note"])?></td>
          <td><?=h($m["who"])?></td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>
</main></body></html>