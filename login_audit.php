<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if (!is_admin()) { header("Location:index.php"); exit; }

$rows = login_audit_recent($conn, 500);

function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Login Audit</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Login Audit Log</h1><p>Every login attempt — successful and failed.</p></div>
  </div>

  <?php
  $ok = 0; $fail = 0; $list = [];
  while ($r = $rows->fetch_assoc()) {
    $list[] = $r;
    if ((int)$r["success"] === 1) $ok++; else $fail++;
  }
  ?>

  <div class="cards">
    <div class="card"><small>Successful</small><b><?=$ok?></b></div>
    <div class="card"><small>Failed</small><b style="color:#a12626"><?=$fail?></b></div>
    <div class="card"><small>Total (last 500)</small><b><?=count($list)?></b></div>
  </div>

  <div class="panel">
    <table>
      <tr><th>When</th><th>Username</th><th>Full Name</th><th>Result</th><th>IP</th><th>User Agent</th></tr>
      <?php if (empty($list)): ?><tr><td colspan="6" style="text-align:center;color:#6b7887;padding:20px">No entries.</td></tr><?php endif; ?>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><?=h($a["created_at"])?></td>
          <td><b><?=h($a["username"])?></b></td>
          <td><?=h($a["full_name"])?></td>
          <td>
            <?php if ((int)$a["success"] === 1): ?>
              <span class="pill active">Success</span>
            <?php else: ?>
              <span class="pill inactive">Failed</span>
            <?php endif; ?>
          </td>
          <td><?=h($a["ip"])?></td>
          <td style="font-size:12px;color:#52616f"><?=h(substr($a["user_agent"] ?? "", 0, 60))?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</main></body></html>