<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$rows = $conn->query("SELECT r.*, COALESCE(c.client_name,'—') client_name FROM recurring_invoices r LEFT JOIN clients c ON c.id = r.client_id ORDER BY r.next_run ASC");
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Recurring Invoices</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Recurring Invoices</h1><p>Auto-generate invoices for retainer clients on a schedule.</p></div>
    <a class="btn" href="recurring_new.php">+ New Schedule</a>
  </div>
  <?php if (isset($_GET["created"])): ?><div class="success"><?=(int)$_GET["created"]?> invoice(s) generated.</div><?php endif; ?>
  <div class="panel">
    <div class="panelhead">
      <h2>Active Schedules</h2>
      <a href="recurring.php?run=1" class="btn secondary" onclick="return confirm('Run all due schedules now?')">▶ Run due now</a>
    </div>
    <table>
      <tr><th>Title</th><th>Client</th><th>Amount</th><th>Frequency</th><th>Next Run</th><th>Last Run</th><th>Status</th><th>Actions</th></tr>
      <?php if ($rows->num_rows === 0): ?><tr><td colspan="8" style="text-align:center;color:#6b7887;padding:20px">No recurring schedules yet.</td></tr><?php endif; ?>
      <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><b><?=h($r["title"])?></b></td>
          <td><?=h($r["client_name"])?></td>
          <td><?=h($r["currency"])?> <?=number_format($r["amount"], 2)?></td>
          <td><?=h($r["frequency"])?></td>
          <td><?=h($r["next_run"])?></td>
          <td><?=h($r["last_run"])?></td>
          <td><span class="pill <?=$r["active"]?"active":"inactive"?>"><?=$r["active"]?"active":"paused"?></span></td>
          <td class="rowactions">
            <a class="btn small secondary" href="recurring_edit.php?id=<?=(int)$r["id"]?>">Edit</a>
            <a class="btn small danger" href="recurring_delete.php?id=<?=(int)$r["id"]?>" onclick="return confirm('Delete this schedule?')">Delete</a>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>
</main></body></html>