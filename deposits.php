<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$ticket = $_GET["ticket"] ?? ($_POST["ticket"] ?? "");
if ($ticket === "") die("No ticket.");

$s = $conn->prepare("SELECT r.*, c.client_name FROM reports r LEFT JOIN clients c ON c.id=r.client_id WHERE r.ticket_no=?");
$s->bind_param("s", $ticket); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Report not found.");

$msg = ""; $err = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (($_POST["action"] ?? "") === "add") {
        $amount = (float)($_POST["amount"] ?? 0);
        $paidOn = $_POST["paid_on"] ?? date("Y-m-d");
        $method = $_POST["method"] ?? "CASH";
        $ref    = trim($_POST["reference"] ?? "");
        $note   = trim($_POST["note"] ?? "");
        if ($amount <= 0) $err = "Amount must be greater than zero.";
        else {
            $uid = (int)$_SESSION["user_id"];
            $ins = $conn->prepare("INSERT INTO deposits(report_id, client_id, amount, paid_on, method, reference, note, created_by) VALUES(?,?,?,?,?,?,?,?)");
            $ins->bind_param("iidssssi", $r["id"], $r["client_id"], $amount, $paidOn, $method, $ref, $note, $uid);
            $ins->execute();
            log_activity($conn, "Recorded deposit of " . number_format($amount, 2) . " on ticket " . $ticket, $ticket);
            $msg = "Deposit recorded.";
        }
    }
    if (($_POST["action"] ?? "") === "delete") {
        $id = (int)($_POST["id"] ?? 0);
        $conn->query("DELETE FROM deposits WHERE id=" . $id . " AND report_id=" . (int)$r["id"] . " AND applied=0");
        log_activity($conn, "Deleted deposit #$id on ticket " . $ticket, $ticket);
        $msg = "Deposit removed.";
    }
}

$list = $conn->prepare("SELECT d.*, COALESCE(u.full_name,'—') who FROM deposits d LEFT JOIN users u ON u.id=d.created_by WHERE d.report_id=? ORDER BY d.paid_on ASC, d.id ASC");
$list->bind_param("i", $r["id"]); $list->execute();
$deposits = $list->get_result();

$tot = deposit_total_for_report($conn, (int)$r["id"]);
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Deposits — <?=h($ticket)?></title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Deposits / Advances</h1><p>Ticket <b><?=h($ticket)?></b> — client <b><?=h($r["client_name"])?></b></p></div>
    <div>
      <a class="btn secondary" href="view_report.php?ticket=<?=urlencode($ticket)?>">← Back to Ticket</a>
      <?php if (file_exists(__DIR__ . '/cost_worksheet.php')): ?>
        <a class="btn" href="cost_worksheet.php?report_id=<?=(int)$r["id"]?>">💰 Cost Worksheet</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($msg): ?><div class="success"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>

  <div class="cards">
    <div class="card"><small>Unapplied Deposits</small><b><?=number_format($tot, 0)?></b></div>
    <div class="card"><small>Deposit Count</small><b><?=$deposits->num_rows?></b></div>
  </div>

  <div class="panel">
    <h2>Record New Deposit</h2>
    <form method="post">
      <input type="hidden" name="ticket" value="<?=h($ticket)?>">
      <input type="hidden" name="action" value="add">
      <div class="grid">
        <label>Amount<input type="number" step="0.01" name="amount" required min="0.01"></label>
        <label>Date Paid<input type="date" name="paid_on" value="<?=date('Y-m-d')?>" required></label>
        <label>Method<select name="method">
          <option>CASH</option><option>MOBILE_MONEY</option><option>BANK</option>
          <option>CHEQUE</option><option>CARD</option><option>OTHER</option>
        </select></label>
        <label>Reference<input name="reference" placeholder="e.g. MoMo code, receipt no."></label>
      </div>
      <label>Note<textarea name="note" rows="2"></textarea></label>
      <div class="actions"><button class="btn">Record Deposit</button></div>
    </form>
  </div>

  <div class="panel">
    <h2>Deposit History</h2>
    <table>
      <tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Note</th><th>By</th><th>Status</th><th></th></tr>
      <?php if ($deposits->num_rows === 0): ?>
        <tr><td colspan="8" style="text-align:center;color:#6b7887;padding:20px">No deposits yet.</td></tr>
      <?php endif; ?>
      <?php while ($d = $deposits->fetch_assoc()): ?>
        <tr>
          <td><?=h($d["paid_on"])?></td>
          <td><b><?=number_format($d["amount"], 2)?></b></td>
          <td><?=h($d["method"])?></td>
          <td><?=h($d["reference"])?></td>
          <td><?=h($d["note"])?></td>
          <td><?=h($d["who"])?></td>
          <td><?=$d["applied"] ? '<span class="pill active">applied</span>' : '<span class="pill">unapplied</span>'?></td>
          <td>
            <?php if (!$d["applied"]): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this deposit?')">
                <input type="hidden" name="ticket" value="<?=h($ticket)?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=(int)$d["id"]?>">
                <button class="btn small danger">Remove</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>
    <p class="hint" style="margin-top:12px">Deposits are automatically deducted from the next invoice you create for this ticket.</p>
  </div>
</main></body></html>