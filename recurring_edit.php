<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? ($_POST["id"] ?? 0));
$s = $conn->prepare("SELECT * FROM recurring_invoices WHERE id=?"); $s->bind_param("i",$id); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Not found.");
$clients = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name");
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"] ?? "");
    $cid   = (int)$_POST["client_id"];
    $amt   = (float)($_POST["amount"] ?? 0);
    $cur   = trim($_POST["currency"] ?? "UGX");
    $tax   = (float)($_POST["tax_rate"] ?? 0);
    $desc  = trim($_POST["description"] ?? "");
    $freq  = $_POST["frequency"] ?? "monthly";
    $next  = $_POST["next_run"] ?? date("Y-m-d");
    $active = isset($_POST["active"]) ? 1 : 0;
    $u = $conn->prepare("UPDATE recurring_invoices SET title=?, client_id=?, amount=?, currency=?, tax_rate=?, description=?, frequency=?, next_run=?, active=? WHERE id=?");
    $u->bind_param("sidsssssii", $title, $cid, $amt, $cur, $tax, $desc, $freq, $next, $active, $id);
    $u->execute();
    log_activity($conn, "Updated recurring schedule: $title");
    header("Location: recurring.php"); exit;
}
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Edit Recurring</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Edit Schedule</h1></div><a class="btn secondary" href="recurring.php">← Back</a></div>
  <form method="post">
    <input type="hidden" name="id" value="<?=$id?>">
    <section class="panel">
      <div class="grid">
        <label>Title<input name="title" value="<?=h($r["title"])?>" required></label>
        <label>Client
          <select name="client_id" required>
            <?php while ($c = $clients->fetch_assoc()): ?>
              <option value="<?=(int)$c["id"]?>" <?=$r["client_id"]==$c["id"]?"selected":""?>><?=h($c["client_name"])?></option>
            <?php endwhile; ?>
          </select>
        </label>
        <label>Amount<input type="number" step="0.01" name="amount" value="<?=h($r["amount"])?>" required></label>
        <label>Currency<input name="currency" value="<?=h($r["currency"])?>"></label>
        <label>VAT Rate (%)<input type="number" step="0.01" name="tax_rate" value="<?=h($r["tax_rate"])?>"></label>
        <label>Frequency
          <select name="frequency">
            <?php foreach (["weekly","monthly","quarterly","yearly"] as $f): ?>
              <option value="<?=$f?>" <?=$r["frequency"]===$f?"selected":""?>><?=ucfirst($f)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Next Run<input type="date" name="next_run" value="<?=h($r["next_run"])?>"></label>
        <label>Description<input name="description" value="<?=h($r["description"])?>"></label>
      </div>
      <label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="active" <?=$r["active"]?"checked":""?> style="width:auto"> Active</label>
    </section>
    <div class="actions"><a href="recurring.php" class="btn secondary">Cancel</a><button class="btn">Save</button></div>
  </form>
</main></body></html>