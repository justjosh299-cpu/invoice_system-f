<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$q       = trim($_GET["q"] ?? "");
$filter  = $_GET["device"] ?? "";
$status  = $_GET["status"] ?? "";
$tech    = $_GET["tech"] ?? "";
$from    = trim($_GET["from"] ?? "");
$to      = trim($_GET["to"] ?? "");

$where = []; $params = []; $types = "";

if ($q !== "") {
    $where[] = "(r.ticket_no LIKE ? OR c.client_name LIKE ? OR r.serial_number LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}
if ($filter !== "") { $where[] = "r.device_type = ?"; $params[] = $filter; $types .= "s"; }
if ($status !== "") { $where[] = "r.ticket_status = ?"; $params[] = $status; $types .= "s"; }
if ($tech !== "")   { $where[] = "r.technician_name = ?"; $params[] = $tech; $types .= "s"; }
if ($from !== "")   { $where[] = "r.reported_date >= ?"; $params[] = $from; $types .= "s"; }
if ($to !== "")     { $where[] = "r.reported_date <= ?"; $params[] = $to; $types .= "s"; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "SELECT r.*, COALESCE(c.client_name,'') client_name
        FROM reports r
        LEFT JOIN clients c ON c.id = r.client_id
        $whereSql
        ORDER BY r.id DESC";
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params);
$st->execute();
$rows = $st->get_result();

$techs = $conn->query("SELECT DISTINCT technician_name FROM reports WHERE technician_name<>'' ORDER BY technician_name");
$statuses = ["OPEN","DIAGNOSIS","AWAITING PARTS","REPAIRED","CLOSED"];
?><!doctype html><html><head><title>Reports</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Diagnostic Reports</h1><p>Advanced search, filter, view, edit, or delete service tickets.</p></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if (file_exists(__DIR__ . '/my_tickets.php')): ?>
        <a class="btn secondary" href="my_tickets.php">👷 My Tickets</a>
      <?php endif; ?>
      <?php if (is_admin() && file_exists(__DIR__ . '/aging.php')): ?>
        <a class="btn secondary" href="aging.php">⏰ Aging Report</a>
      <?php endif; ?>
      <?php if (file_exists(__DIR__ . '/excel_export.php')): ?>
        <a class="btn secondary" href="excel_export.php?type=reports">⬇ Excel</a>
      <?php endif; ?>
      <a class="btn" href="new_report.php">+ New Report</a>
    </div>
  </div>
  <?php if (isset($_GET["deleted"])): ?><div class="success">Report deleted.</div><?php endif; ?>
  <form class="search">
    <label>Search<input name="q" value="<?=htmlspecialchars($q)?>" placeholder="Ticket, client or serial"></label>
    <label>Device<select name="device"><option value="" <?=$filter===""?"selected":""?>>All</option><option value="computer" <?=$filter==="computer"?"selected":""?>>Computer</option><option value="printer" <?=$filter==="printer"?"selected":""?>>Printer / Copier / Scanner</option></select></label>
    <label>Status<select name="status"><option value="" <?=$status===""?"selected":""?>>All</option><?php foreach ($statuses as $s): ?><option value="<?=htmlspecialchars($s)?>" <?=$status===$s?"selected":""?>><?=htmlspecialchars($s)?></option><?php endforeach; ?></select></label>
    <label>Technician<select name="tech"><option value="" <?=$tech===""?"selected":""?>>All</option><?php while ($t = $techs->fetch_assoc()): ?><option value="<?=htmlspecialchars($t["technician_name"])?>" <?=$tech===$t["technician_name"]?"selected":""?>><?=htmlspecialchars($t["technician_name"])?></option><?php endwhile; ?></select></label>
    <label>From<input type="date" name="from" value="<?=htmlspecialchars($from)?>"></label>
    <label>To<input type="date" name="to" value="<?=htmlspecialchars($to)?>"></label>
    <button class="btn">Apply</button><a class="btn secondary" href="reports.php">Reset</a>
  </form>
  <div class="panel"><table>
    <tr><th>Ticket</th><th>Device</th><th>Client</th><th>Make / Model</th><th>Serial</th><th>Status</th><th>Tech</th><th>Date</th><th>Actions</th></tr>
    <?php if ($rows->num_rows === 0): ?><tr><td colspan="9" style="text-align:center;color:#6b7887;padding:20px">No reports match your filters.</td></tr><?php endif; ?>
    <?php while ($r = $rows->fetch_assoc()): ?>
      <tr>
        <td><b><?=htmlspecialchars($r["ticket_no"])?></b></td>
        <td><span class="pill <?=htmlspecialchars($r["device_type"] ?: "computer")?>"><?=htmlspecialchars(ucfirst($r["device_type"] ?: "computer"))?></span></td>
        <td><?=htmlspecialchars($r["client_name"])?></td>
        <td><?=htmlspecialchars($r["computer_make_model"])?></td>
        <td><?=htmlspecialchars($r["serial_number"])?></td>
        <td><span class="pill status-<?=str_replace(' ','',htmlspecialchars($r["ticket_status"]))?>"><?=htmlspecialchars($r["ticket_status"])?></span></td>
        <td><?=htmlspecialchars($r["technician_name"])?></td>
        <td><?=htmlspecialchars($r["reported_date"])?></td>
        <td class="rowactions">
          <a href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>" class="btn small secondary">View</a>
          <a href="pdf_report.php?ticket=<?=urlencode($r["ticket_no"])?>" class="btn small secondary">PDF</a>
          <a href="edit_report.php?ticket=<?=urlencode($r["ticket_no"])?>" class="btn small secondary">Edit</a>
          <button type="button" class="btn small danger" onclick="confirmDelete('<?=htmlspecialchars($r["ticket_no"], ENT_QUOTES)?>')">Delete</button>
        </td>
      </tr>
    <?php endwhile; ?>
  </table></div>
  <div class="modal" id="deleteModal"><div class="box"><h3>Delete report?</h3>
    <p>This will permanently delete ticket <b id="delTicket"></b> and all its diagnostic items.</p>
    <form method="post" action="delete_report.php">
      <input type="hidden" name="ticket" id="delTicketInput"><input type="hidden" name="confirm" value="DELETE">
      <div class="actions"><button type="button" class="btn secondary" onclick="closeModal()">Cancel</button><button type="submit" class="btn danger">Delete permanently</button></div>
    </form>
  </div></div>
</main>
<script>
function confirmDelete(ticket){document.getElementById('delTicket').textContent=ticket;document.getElementById('delTicketInput').value=ticket;document.getElementById('deleteModal').classList.add('show');}
function closeModal(){document.getElementById('deleteModal').classList.remove('show');}
document.getElementById('deleteModal').addEventListener('click',function(e){if(e.target===this)closeModal();});
</script>
</body></html>