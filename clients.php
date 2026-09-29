<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$q = trim($_GET["q"] ?? ""); $like = "%" . $q . "%";
$s = $conn->prepare("SELECT c.*, (SELECT COUNT(*) FROM reports r WHERE r.client_id=c.id) AS report_count FROM clients c WHERE c.client_name LIKE ? OR c.phone LIKE ? OR c.alternate_phone LIKE ? ORDER BY c.id DESC");
$s->bind_param("sss", $like, $like, $like); $s->execute(); $rows = $s->get_result();
?><!doctype html><html><head><title>Clients</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Clients</h1><p>All clients who have submitted equipment for service.</p></div>
    <div><a class="btn secondary" href="clients_import.php">Import CSV</a> <a class="btn" href="new_report.php">+ New Report</a></div></div>
  <form class="search"><input name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search by name or phone"><button class="btn">Search</button></form>
  <div class="panel"><table>
    <tr><th>Name</th><th>Phone</th><th>Alt. Phone</th><th>Address</th><th>Reports</th><th>Added</th><th></th></tr>
    <?php while ($r = $rows->fetch_assoc()): ?>
      <tr>
        <td><b><?=htmlspecialchars($r["client_name"])?></b></td>
        <td><?=htmlspecialchars($r["phone"])?></td>
        <td><?=htmlspecialchars($r["alternate_phone"])?></td>
        <td><?=htmlspecialchars($r["address"])?></td>
        <td><?=htmlspecialchars($r["report_count"])?></td>
        <td><?=htmlspecialchars($r["created_at"])?></td>
        <td><a class="btn small secondary" href="client_view.php?id=<?=(int)$r["id"]?>">Open</a></td>
      </tr>
    <?php endwhile; ?>
  </table></div>
</main></body></html>