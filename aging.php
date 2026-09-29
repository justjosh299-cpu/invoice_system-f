<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if (!is_admin()) { header("Location:index.php"); exit; }

$device = $_GET["device"] ?? "";
$tech   = $_GET["tech"]   ?? "";

$where = ["r.ticket_status IN ('OPEN','DIAGNOSIS','AWAITING PARTS','REPAIRED')"];
$params = []; $types = "";
if ($device !== "") { $where[] = "r.device_type = ?"; $params[] = $device; $types .= "s"; }
if ($tech !== "")   { $where[] = "r.technician_name = ?"; $params[] = $tech; $types .= "s"; }
$whereSql = "WHERE " . implode(" AND ", $where);

$sql = "SELECT r.*, COALESCE(c.client_name,'—') client_name,
        DATEDIFF(CURDATE(), r.reported_date) AS age_days
        FROM reports r LEFT JOIN clients c ON c.id = r.client_id
        $whereSql ORDER BY age_days DESC, r.id DESC";
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result();

$buckets = aging_buckets();
$counts = [];
$listByBucket = [];
foreach ($buckets as $k => $b) { $counts[$k] = 0; $listByBucket[$k] = []; }

$allRows = [];
while ($r = $rows->fetch_assoc()) {
    $allRows[] = $r;
    $d = (int)$r["age_days"];
    foreach ($buckets as $k => $b) {
        if ($d >= $b["min"] && $d <= $b["max"]) { $counts[$k]++; $listByBucket[$k][] = $r; break; }
    }
}

$techs = $conn->query("SELECT DISTINCT technician_name FROM reports WHERE technician_name<>'' ORDER BY technician_name");
function h($x){ return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Aging Report</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Ticket Aging Report</h1><p>How long tickets have been open, oldest first.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="excel_export.php?type=aging">⬇ Excel</a>
      <a class="btn secondary" href="export.php?type=reports">⬇ CSV (all)</a>
    </div>
  </div>

  <form class="search">
    <label>Device<select name="device">
      <option value="">All</option>
      <option value="computer" <?=$device==="computer"?"selected":""?>>Computer</option>
      <option value="printer"  <?=$device==="printer"?"selected":""?>>Printer / Copier / Scanner</option>
    </select></label>
    <label>Technician<select name="tech">
      <option value="">All</option>
      <?php while ($t = $techs->fetch_assoc()): ?>
        <option value="<?=h($t["technician_name"])?>" <?=$tech===$t["technician_name"]?"selected":""?>><?=h($t["technician_name"])?></option>
      <?php endwhile; ?>
    </select></label>
    <button class="btn">Apply</button>
    <a class="btn secondary" href="aging.php">Reset</a>
  </form>

  <div class="cards">
    <?php foreach ($buckets as $k => $b): ?>
      <div class="card"><small><?=h($b["label"])?></small><b style="color:<?=$k==='30+'?'#a12626':($k==='15-30'?'#7a4a00':'#0b2545')?>"><?=$counts[$k]?></b></div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($buckets as $k => $b): if (empty($listByBucket[$k])) continue; ?>
    <div class="panel">
      <h2><?=h($b["label"])?> — <?=$counts[$k]?> ticket<?=$counts[$k]===1?"":"s"?></h2>
      <table>
        <tr><th>Ticket</th><th>Client</th><th>Device</th><th>Make / Model</th><th>Status</th><th>Tech</th><th>Reported</th><th>Age</th><th></th></tr>
        <?php foreach ($listByBucket[$k] as $r): ?>
          <tr>
            <td><b><?=h($r["ticket_no"])?></b></td>
            <td><?=h($r["client_name"])?></td>
            <td><span class="pill <?=h($r["device_type"] ?: "computer")?>"><?=h(ucfirst($r["device_type"] ?: "computer"))?></span></td>
            <td><?=h($r["computer_make_model"])?></td>
            <td><span class="pill status-<?=str_replace(' ','',h($r["ticket_status"]))?>"><?=h($r["ticket_status"])?></span></td>
            <td><?=h($r["technician_name"])?></td>
            <td><?=h($r["reported_date"])?></td>
            <td><b><?=(int)$r["age_days"]?>d</b></td>
            <td><a class="btn small secondary" href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; ?>

  <?php if (empty($allRows)): ?>
    <div class="panel"><p class="hint">No open tickets. 🎉</p></div>
  <?php endif; ?>
</main></body></html>