<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

// Brand colour for the animated background
$brand = function_exists('brand_colour') ? brand_colour($conn) : "#0b2545";

// -------- Date range --------
$range = $_GET["range"] ?? "30d";
$from = $_GET["from"] ?? "";
$to   = $_GET["to"] ?? "";

$today = date("Y-m-d");

switch ($range) {
    case "today":   $start = $today;                                $end = $today; break;
    case "7d":      $start = date("Y-m-d", strtotime("-6 days"));   $end = $today; break;
    case "30d":     $start = date("Y-m-d", strtotime("-29 days"));  $end = $today; break;
    case "month":   $start = date("Y-m-01");                        $end = $today; break;
    case "year":    $start = date("Y-01-01");                       $end = $today; break;
    case "custom":
        $start = $from ?: date("Y-m-d", strtotime("-29 days"));
        $end   = $to   ?: $today;
        break;
    default:        $range = "30d"; $start = date("Y-m-d", strtotime("-29 days")); $end = $today;
}
if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }

// -------- Live cards (always current) --------
$statuses = ["OPEN","DIAGNOSIS","AWAITING PARTS","REPAIRED","CLOSED"];
$counts = [];
foreach ($statuses as $s) {
    $q = $conn->prepare("SELECT COUNT(*) c FROM reports WHERE ticket_status=?");
    $q->bind_param("s", $s); $q->execute();
    $counts[$s] = $q->get_result()->fetch_assoc()["c"];
}
$clientsCount = $conn->query("SELECT COUNT(*) c FROM clients")->fetch_assoc()["c"];

$unpaidRow = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(total - amount_paid),0) s FROM invoices WHERE status IN ('SENT','PARTIAL','OVERDUE')")->fetch_assoc();
$unpaidCount = (int)$unpaidRow["c"];
$overdueRow = $conn->query("SELECT COUNT(*) c FROM invoices WHERE status IN ('SENT','PARTIAL') AND due_date IS NOT NULL AND due_date < CURDATE()")->fetch_assoc();
$overdueCount = (int)$overdueRow["c"];

// -------- Range-scoped stats --------
$totalReportsInRange = (int)$conn->query("SELECT COUNT(*) c FROM reports WHERE reported_date BETWEEN '" . $conn->real_escape_string($start) . "' AND '" . $conn->real_escape_string($end) . "'")->fetch_assoc()["c"];

$paidInRange = (float)$conn->query("SELECT COALESCE(SUM(amount),0) s FROM invoice_payments WHERE paid_on BETWEEN '" . $conn->real_escape_string($start) . "' AND '" . $conn->real_escape_string($end) . "'")->fetch_assoc()["s"];

$invoicesInRange = (int)$conn->query("SELECT COUNT(*) c FROM invoices WHERE issue_date BETWEEN '" . $conn->real_escape_string($start) . "' AND '" . $conn->real_escape_string($end) . "'")->fetch_assoc()["c"];

// -------- Chart: revenue over range --------
$days = max(1, (int)((strtotime($end) - strtotime($start)) / 86400) + 1);
$useMonthly = $days > 62;

$revLabels = []; $revValues = [];

if ($useMonthly) {
    $cursor = strtotime(date("Y-m-01", strtotime($start)));
    $stop   = strtotime(date("Y-m-01", strtotime($end)));
    while ($cursor <= $stop) {
        $ym = date("Y-m", $cursor);
        $label = date("M Y", $cursor);
        $s = $conn->prepare("SELECT COALESCE(SUM(amount),0) v FROM invoice_payments WHERE DATE_FORMAT(paid_on,'%Y-%m')=?");
        $s->bind_param("s", $ym); $s->execute();
        $val = (float)$s->get_result()->fetch_assoc()["v"];
        $revLabels[] = $label;
        $revValues[] = $val;
        $cursor = strtotime("+1 month", $cursor);
    }
} else {
    $cursor = strtotime($start);
    while ($cursor <= strtotime($end)) {
        $d = date("Y-m-d", $cursor);
        $label = date("d M", $cursor);
        $s = $conn->prepare("SELECT COALESCE(SUM(amount),0) v FROM invoice_payments WHERE paid_on=?");
        $s->bind_param("s", $d); $s->execute();
        $val = (float)$s->get_result()->fetch_assoc()["v"];
        $revLabels[] = $label;
        $revValues[] = $val;
        $cursor = strtotime("+1 day", $cursor);
    }
}
$revData = [];
foreach ($revLabels as $i => $lbl) $revData[] = ["label" => $lbl, "value" => round($revValues[$i], 0)];

// -------- Status donut (live) --------
$statusData = [];
$statusColors = ["OPEN"=>"#0b2545","DIAGNOSIS"=>"#e89c2a","AWAITING PARTS"=>"#f5b942","REPAIRED"=>"#1b8f4a","CLOSED"=>"#98a4b3"];
foreach ($statuses as $s) $statusData[] = ["label"=>$s, "value"=>(int)$counts[$s], "color"=>$statusColors[$s]];

// -------- Technician workload (in range) --------
$techData = [];
$tq = $conn->prepare("SELECT technician_name, COUNT(*) c FROM reports WHERE technician_name<>'' AND reported_date BETWEEN ? AND ? GROUP BY technician_name ORDER BY c DESC LIMIT 5");
$tq->bind_param("ss", $start, $end); $tq->execute();
$tres = $tq->get_result();
$techColors = ["#0b2545","#1b8f4a","#e89c2a","#7a4a00","#4c1d95"];
$ti = 0;
while ($t = $tres->fetch_assoc()) {
    $techData[] = ["label"=>substr($t["technician_name"], 0, 12), "value"=>(int)$t["c"], "color"=>$techColors[$ti++ % count($techColors)]];
}

// -------- Recent lists (5 each) --------
$recent = $conn->query("SELECT r.ticket_no, r.reported_date, r.computer_make_model, r.ticket_status,
                        r.device_type, COALESCE(c.client_name,'') client_name
                        FROM reports r LEFT JOIN clients c ON c.id = r.client_id
                        ORDER BY r.id DESC LIMIT 5");
$recentInvoices = $conn->query("SELECT i.id, i.invoice_no, i.issue_date, i.due_date, i.status, i.total, i.amount_paid, i.currency,
                        COALESCE(c.client_name,'—') client_name
                        FROM invoices i LEFT JOIN clients c ON c.id = i.client_id
                        ORDER BY i.id DESC LIMIT 5");

$otherActivity = null;
if (is_admin()) {
    $me = (int)$_SESSION["user_id"];
    $s = $conn->prepare("SELECT a.action, a.ticket_no, a.created_at, COALESCE(u.full_name,'—') AS user_name, u.username AS user_login
        FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
        WHERE a.user_id IS NULL OR a.user_id <> ? ORDER BY a.id DESC LIMIT 5");
    $s->bind_param("i", $me); $s->execute(); $otherActivity = $s->get_result();
}
function inv_status_badge($row) {
    $s = $row["status"];
    if (in_array($s, ["SENT","PARTIAL"], true) && !empty($row["due_date"]) && strtotime($row["due_date"]) < strtotime(date("Y-m-d"))) $s = "OVERDUE";
    return $s;
}

$rangeLabel = [
    "today" => "Today",
    "7d"    => "Last 7 days",
    "30d"   => "Last 30 days",
    "month" => "This month",
    "year"  => "This year",
    "custom"=> "Custom: " . date("d M Y", strtotime($start)) . " → " . date("d M Y", strtotime($end)),
][$range] ?? "Last 30 days";
?><!doctype html><html><head><title>OMG Dashboard</title>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/charts.js"></script>
<style>
/* ============================================================ */
/* ANIMATED BACKGROUND                                           */
/* ============================================================ */
.animated-bg { position: fixed; inset: 0; overflow: hidden; z-index: -1; pointer-events: none; background: #f4f6f8; }
.animated-bg .blob { position: absolute; border-radius: 50%; filter: blur(70px); opacity: 0.32; will-change: transform; animation: blobFloat 40s ease-in-out infinite; }
.animated-bg .blob.b1 { width: 420px; height: 420px; background: <?=htmlspecialchars($brand)?>; top: -100px; left: -80px; animation-duration: 45s; animation-delay: -5s; }
.animated-bg .blob.b2 { width: 340px; height: 340px; background: #4d9dff; top: 20%; right: -60px; animation-duration: 55s; animation-delay: -12s; }
.animated-bg .blob.b3 { width: 300px; height: 300px; background: #e89c2a; bottom: -80px; left: 25%; animation-duration: 60s; animation-delay: -25s; }
.animated-bg .blob.b4 { width: 380px; height: 380px; background: #1b8f4a; bottom: 10%; right: 18%; animation-duration: 50s; animation-delay: -35s; }
.animated-bg .blob.b5 { width: 280px; height: 280px; background: #7a4a00; top: 45%; left: 38%; animation-duration: 65s; animation-delay: -10s; }
@keyframes blobFloat {
  0%   { transform: translate(0, 0) scale(1); }
  25%  { transform: translate(60px, -40px) scale(1.08); }
  50%  { transform: translate(-30px, 50px) scale(0.95); }
  75%  { transform: translate(-60px, -30px) scale(1.05); }
  100% { transform: translate(0, 0) scale(1); }
}

/* Frosted-glass panels */
main > .panel,
main > .cards > .card,
main > .grid .panel {
  background: rgba(255,255,255,0.88);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
}

/* Fade-in for panels */
main > .panel, main > .cards, main > .grid { animation: fadeInUp .5s ease-out backwards; }
main > .cards { animation-delay: .05s; }
main > .panel:nth-of-type(1) { animation-delay: .1s; }
main > .panel:nth-of-type(2) { animation-delay: .15s; }
main > .panel:nth-of-type(3) { animation-delay: .2s; }
main > .panel:nth-of-type(4) { animation-delay: .25s; }
main > .panel:nth-of-type(5) { animation-delay: .3s; }
main > .grid { animation-delay: .15s; }
@keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

/* Cards: float + pulse */
@keyframes cardFloat {
  0%, 100% { transform: translateY(0)    scale(1); }
  50%      { transform: translateY(-4px) scale(1.01); }
}
main > .cards > .card {
  animation: cardFloat 6s ease-in-out infinite;
  animation-fill-mode: both;
  transition: transform .2s ease, box-shadow .2s ease;
}
main > .cards > .card:hover {
  animation-play-state: paused;
  transform: translateY(-6px) scale(1.02) !important;
  box-shadow: 0 10px 24px rgba(11,37,69,0.15);
}

/* Range filter bar */
.rangebar {
  display: flex; gap: 6px; flex-wrap: wrap; align-items: center;
  margin-bottom: 14px;
  background: rgba(255,255,255,0.88);
  backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  border: 1px solid #e2e8ef; border-radius: 8px; padding: 10px 12px;
}
.rangebar a { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; color: #0b2545; background: #eef3f9; }
.rangebar a:hover { background: #dbe6f3; }
.rangebar a.active { background: #0b2545; color: #fff; font-weight: 600; }
.rangebar form { display: flex; gap: 6px; align-items: center; margin-left: auto; }
.rangebar input[type=date] { padding: 6px 8px; font-size: 13px; margin: 0; }
.rangebar button { padding: 7px 12px; font-size: 13px; }
.rangebar .label { font-size: 12px; color: #6b7887; margin-right: 8px; }

/* Compact activity feed */
.adminactivity .activityrow { padding: 9px 0; }
.adminactivity .activityrow:last-child { border-bottom: none; }
.adminactivity .what { font-size: 12.5px; }
.adminactivity .when { font-size: 11px; }

@media (prefers-reduced-motion: reduce) {
  .animated-bg .blob,
  main > .panel, main > .cards, main > .grid, main > .cards > .card { animation: none; transition: none; }
}
</style>
</head><body>
<div class="animated-bg" aria-hidden="true">
  <div class="blob b1"></div>
  <div class="blob b2"></div>
  <div class="blob b3"></div>
  <div class="blob b4"></div>
  <div class="blob b5"></div>
</div>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div>
      <h1>Diagnostic Dashboard</h1>
      <p>Welcome back, <?=htmlspecialchars($_SESSION["name"] ?? "")?>. <span class="hint">Showing: <b><?=htmlspecialchars($rangeLabel)?></b></span></p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="export.php?type=reports">⬇ Reports CSV</a>
      <a class="btn secondary" href="export.php?type=invoices">⬇ Invoices CSV</a>
      <a class="btn" href="new_report.php">+ New Report</a>
    </div>
  </div>

  <!-- RANGE FILTER -->
  <div class="rangebar">
    <span class="label">Range:</span>
    <a href="?range=today"  class="<?=$range==='today'?'active':''?>">Today</a>
    <a href="?range=7d"     class="<?=$range==='7d'?'active':''?>">7 days</a>
    <a href="?range=30d"    class="<?=$range==='30d'?'active':''?>">30 days</a>
    <a href="?range=month"  class="<?=$range==='month'?'active':''?>">This month</a>
    <a href="?range=year"   class="<?=$range==='year'?'active':''?>">This year</a>
    <form method="get">
      <input type="hidden" name="range" value="custom">
      <input type="date" name="from" value="<?=htmlspecialchars($start)?>">
      <input type="date" name="to" value="<?=htmlspecialchars($end)?>">
      <button class="btn secondary" type="submit">Apply</button>
    </form>
  </div>

  <div class="cards">
    <div class="card"><small>Reports in Range</small><b data-count="<?=$totalReportsInRange?>">0</b></div>
    <div class="card"><small>Invoices in Range</small><b data-count="<?=$invoicesInRange?>">0</b></div>
    <div class="card"><small>Paid in Range</small><b data-count="<?=number_format($paidInRange, 0, '.', '')?>">0</b></div>
    <div class="card"><small>Clients (all time)</small><b data-count="<?=$clientsCount?>">0</b></div>
    <div class="card"><small>Unpaid Invoices</small><b data-count="<?=$unpaidCount?>">0</b></div>
    <div class="card"><small>Overdue Invoices</small><b data-count="<?=$overdueCount?>">0</b></div>
    <?php foreach ($statuses as $s): ?>
      <div class="card"><small><?=htmlspecialchars($s)?></small><b data-count="<?=$counts[$s]?>">0</b></div>
    <?php endforeach; ?>
  </div>

  <div class="grid" style="grid-template-columns: 2fr 1fr; gap:14px; margin-bottom:18px">
    <div class="panel" style="margin-bottom:0">
      <div class="panelhead"><h2>Revenue — <?=htmlspecialchars($rangeLabel)?></h2></div>
      <div id="chartRevenue"></div>
    </div>
    <div class="panel" style="margin-bottom:0">
      <div class="panelhead"><h2>Ticket Status</h2></div>
      <div id="chartStatus" style="display:flex; justify-content:center"></div>
      <div style="margin-top:8px;font-size:12px;color:#52616f;text-align:center">
        <?php foreach ($statusData as $d): ?>
          <span style="display:inline-block;margin:0 6px">
            <span style="display:inline-block;width:10px;height:10px;background:<?=$d["color"]?>;border-radius:2px;vertical-align:middle"></span>
            <?=htmlspecialchars($d["label"])?> (<?=$d["value"]?>)
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panelhead"><h2>Technician Workload — <?=htmlspecialchars($rangeLabel)?></h2></div>
    <div id="chartTech"></div>
  </div>

  <?php if (is_admin() && $otherActivity): ?>
    <div class="panel adminactivity">
      <div class="panelhead"><h2>Recent activity by other users</h2><a href="activity.php">View full log →</a></div>
      <?php if ($otherActivity->num_rows === 0): ?>
        <p class="hint">No activity recorded by other users yet.</p>
      <?php else: ?>
        <?php while ($a = $otherActivity->fetch_assoc()): ?>
          <div class="activityrow">
            <div><span class="who"><?=htmlspecialchars($a["user_name"])?></span>
              <?php if (!empty($a["user_login"])): ?><span class="when">(<?=htmlspecialchars($a["user_login"])?>)</span><?php endif; ?>
              <div class="what"><?=htmlspecialchars($a["action"])?>
                <?php if ($a["ticket_no"]): ?> · <a href="view_report.php?ticket=<?=urlencode($a["ticket_no"])?>"><?=htmlspecialchars($a["ticket_no"])?></a><?php endif; ?>
              </div>
            </div>
            <div class="when"><?=htmlspecialchars($a["created_at"])?></div>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panelhead"><h2>Recent Reports</h2><a href="reports.php">View all →</a></div>
    <table>
      <tr><th>Ticket</th><th>Device</th><th>Client</th><th>Make / Model</th><th>Date</th><th>Status</th><th></th></tr>
      <?php if ($recent->num_rows === 0): ?>
        <tr><td colspan="7" style="text-align:center;color:#6b7887;padding:20px">No reports yet.</td></tr>
      <?php endif; ?>
      <?php while ($r = $recent->fetch_assoc()): ?>
        <tr>
          <td><b><?=htmlspecialchars($r["ticket_no"])?></b></td>
          <td><span class="pill <?=htmlspecialchars($r["device_type"] ?: "computer")?>"><?=htmlspecialchars(ucfirst($r["device_type"] ?: "computer"))?></span></td>
          <td><?=htmlspecialchars($r["client_name"])?></td>
          <td><?=htmlspecialchars($r["computer_make_model"])?></td>
          <td><?=htmlspecialchars($r["reported_date"])?></td>
          <td><span class="pill status-<?=str_replace(' ','',htmlspecialchars($r["ticket_status"]))?>"><?=htmlspecialchars($r["ticket_status"])?></span></td>
          <td><a href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>">Open</a></td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>

  <div class="panel">
    <div class="panelhead"><h2>Recent Invoices</h2><a href="invoices.php">View all →</a></div>
    <table>
      <tr><th>Invoice</th><th>Client</th><th>Issued</th><th>Due</th><th>Total</th><th>Balance</th><th>Status</th><th></th></tr>
      <?php if ($recentInvoices->num_rows === 0): ?>
        <tr><td colspan="8" style="text-align:center;color:#6b7887;padding:20px">No invoices yet.</td></tr>
      <?php endif; ?>
      <?php while ($inv = $recentInvoices->fetch_assoc()): $st = inv_status_badge($inv); ?>
        <tr>
          <td><b><?=htmlspecialchars($inv["invoice_no"])?></b></td>
          <td><?=htmlspecialchars($inv["client_name"])?></td>
          <td><?=htmlspecialchars($inv["issue_date"])?></td>
          <td><?=htmlspecialchars($inv["due_date"])?></td>
          <td><?=htmlspecialchars($inv["currency"])?> <?=number_format($inv["total"], 0)?></td>
          <td><?=htmlspecialchars($inv["currency"])?> <?=number_format($inv["total"] - $inv["amount_paid"], 0)?></td>
          <td><span class="pill status-<?=$st?>"><?=$st?></span></td>
          <td><a href="invoice_view.php?id=<?=(int)$inv["id"]?>">Open</a></td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>
</main>
<script src="assets/effects.js"></script>
<script>
OMGChart.bar(document.getElementById('chartRevenue'), <?=json_encode($revData)?>, { color: '#0b2545', width: 640, height: 200 });
OMGChart.donut(document.getElementById('chartStatus'), <?=json_encode($statusData)?>, { size: 180 });
OMGChart.bar(document.getElementById('chartTech'), <?=json_encode($techData)?>, { color: '#1b8f4a', width: 640, height: 180 });

/* Count-up animation on card numbers */
document.querySelectorAll('main > .cards > .card b[data-count]').forEach(function(el){
  var target = parseFloat(el.getAttribute('data-count')) || 0;
  var isInt = target % 1 === 0;
  var duration = 900;
  var startTime = null;

  function tick(ts){
    if (!startTime) startTime = ts;
    var p = Math.min(1, (ts - startTime) / duration);
    var e = 1 - Math.pow(1 - p, 3);
    var val = target * e;
    el.textContent = isInt ? Math.round(val).toLocaleString() : Math.round(val).toLocaleString();
    if (p < 1) requestAnimationFrame(tick);
    else el.textContent = Math.round(target).toLocaleString();
  }
  requestAnimationFrame(tick);
});
</script>
</body></html>