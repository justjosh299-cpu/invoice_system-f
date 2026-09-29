<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$clients = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name");
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"] ?? "");
    $cid   = (int)$_POST["client_id"];
    $amt   = (float)($_POST["amount"] ?? 0);
    $cur   = trim($_POST["currency"] ?? "UGX");
    $tax   = (float)($_POST["tax_rate"] ?? 0);
    $desc  = trim($_POST["description"] ?? "Recurring service");
    $freq  = $_POST["frequency"] ?? "monthly";
    $next  = $_POST["next_run"] ?? date("Y-m-d");
    if ($title === "" || $cid <= 0 || $amt <= 0) die("Title, client, and amount required.");
    $s = $conn->prepare("INSERT INTO recurring_invoices(title, client_id, amount, currency, tax_rate, description, frequency, next_run, active) VALUES(?,?,?,?,?,?,?,?,1)");
    $s->bind_param("sidsssss", $title, $cid, $amt, $cur, $tax, $desc, $freq, $next);
    $s->execute();
    log_activity($conn, "Created recurring schedule: $title");
    header("Location: recurring.php"); exit;
}
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>New Recurring</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>New Recurring Schedule</h1></div><a class="btn secondary" href="recurring.php">← Back</a></div>
  <form method="post">
    <section class="panel"><h2>Schedule</h2>
      <div class="grid">
        <label>Title<input name="title" required placeholder="e.g. Monthly IT support – Acme Ltd"></label>
        <label>Client
          <select name="client_id" required>
            <option value="">— Select client —</option>
            <?php while ($c = $clients->fetch_assoc()): ?>
              <option value="<?=(int)$c["id"]?>"><?=h($c["client_name"])?></option>
            <?php endwhile; ?>
          </select>
        </label>
        <label>Amount<input type="number" step="0.01" name="amount" required></label>
        <label>Currency<input name="currency" value="UGX"></label>
        <label>VAT Rate (%)<input type="number" step="0.01" name="tax_rate" value="18"></label>
        <label>Frequency
          <select name="frequency">
            <option value="weekly">Weekly</option>
            <option value="monthly" selected>Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="yearly">Yearly</option>
          </select>
        </label>
        <label>First Run Date<input type="date" name="next_run" value="<?=date('Y-m-d')?>"></label>
        <label>Description<input name="description" value="Recurring service"></label>
      </div>
    </section>
    <div class="actions"><a href="recurring.php" class="btn secondary">Cancel</a><button class="btn">Save Schedule</button></div>
  </form>
</main></body></html>