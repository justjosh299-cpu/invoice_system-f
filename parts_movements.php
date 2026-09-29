<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$rows = $conn->query("SELECT m.*, p.part_name, p.part_code, COALESCE(u.full_name,'—') who
                      FROM part_movements m
                      LEFT JOIN parts p ON p.id = m.part_id
                      LEFT JOIN users u ON u.id = m.user_id
                      ORDER BY m.id DESC LIMIT 500");
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Parts Movements</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Parts Movement Log</h1><p>Last 500 stock movements.</p></div>
    <div><a class="btn secondary" href="export.php?type=parts_movements">⬇ Export CSV</a><a class="btn" href="parts.php">← Parts</a></div>
  </div>
  <div class="panel"><table>
    <tr><th>When</th><th>Part</th><th>Delta</th><th>Reason</th><th>Note</th><th>By</th></tr>
    <?php while ($m = $rows->fetch_assoc()): ?>
      <tr>
        <td><?=h($m["created_at"])?></td>
        <td><b><?=h($m["part_name"])?></b> <?=h($m["part_code"] ? "({$m["part_code"]})" : "")?></td>
        <td><b style="color:<?=$m["delta"]>0?'#0a7a2f':'#a12626'?>"><?=h($m["delta"])?></b></td>
        <td><?=h($m["reason"])?></td>
        <td><?=h($m["note"])?></td>
        <td><?=h($m["who"])?></td>
      </tr>
    <?php endwhile; ?>
    </table>
  </div>
</main></body></html>