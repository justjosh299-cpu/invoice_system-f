<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if (!is_admin()) { header("Location:index.php"); exit; }
$qUser = (int)($_GET["user"] ?? 0); $qAction = trim($_GET["action"] ?? "");
$qTicket = trim($_GET["ticket"] ?? ""); $qFrom = trim($_GET["from"] ?? ""); $qTo = trim($_GET["to"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1)); $perPage = 25; $offset = ($page - 1) * $perPage;
$where = []; $params = []; $types = "";
if ($qUser > 0) { $where[] = "a.user_id = ?"; $params[] = $qUser; $types .= "i"; }
if ($qAction !== "") { $where[] = "a.action LIKE ?"; $params[] = "%" . $qAction . "%"; $types .= "s"; }
if ($qTicket !== "") { $where[] = "a.ticket_no LIKE ?"; $params[] = "%" . $qTicket . "%"; $types .= "s"; }
if ($qFrom !== "") { $where[] = "DATE(a.created_at) >= ?"; $params[] = $qFrom; $types .= "s"; }
if ($qTo !== "") { $where[] = "DATE(a.created_at) <= ?"; $params[] = $qTo; $types .= "s"; }
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
$countSt = $conn->prepare("SELECT COUNT(*) c FROM activity_log a $whereSql");
if ($types !== "") $countSt->bind_param($types, ...$params); $countSt->execute();
$totalRows = (int)$countSt->get_result()->fetch_assoc()["c"];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$sql = "SELECT a.*, COALESCE(u.full_name,'—') AS user_name, u.username AS user_login FROM activity_log a LEFT JOIN users u ON u.id = a.user_id $whereSql ORDER BY a.id DESC LIMIT $perPage OFFSET $offset";
$st = $conn->prepare($sql);
if ($types !== "") $st->bind_param($types, ...$params); $st->execute(); $rows = $st->get_result();
$users = $conn->query("SELECT id, full_name, username FROM users ORDER BY full_name");
function action_class($action) {
    $a = strtolower($action);
    if (strpos($a, "delet") !== false) return "delete";
    if (strpos($a, "updat") !== false || strpos($a, "reset") !== false || strpos($a, "status") !== false) return "update";
    if (strpos($a, "creat") !== false) return "create";
    if (strpos($a, "sign")  !== false) return "login";
    if (strpos($a, "email") !== false || strpos($a, "sent") !== false) return "email";
    if (strpos($a, "backup") !== false) return "backup";
    if (strpos($a, "sms")   !== false) return "sms";
    return "";
}
?><!doctype html><html><head><title>Activity Log</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Activity Log</h1><p>Everything that has happened in the system, newest first.</p></div></div>
  <form class="search" method="get">
    <label>User<select name="user"><option value="0">All users</option>
      <?php while ($u = $users->fetch_assoc()): ?>
        <option value="<?=(int)$u["id"]?>" <?=$qUser===(int)$u["id"]?"selected":""?>><?=htmlspecialchars($u["full_name"])?> (<?=htmlspecialchars($u["username"])?>)</option>
      <?php endwhile; ?>
    </select></label>
    <label>Action<input name="action" value="<?=htmlspecialchars($qAction)?>" placeholder="e.g. Created, Status, SMS"></label>
    <label>Ticket<input name="ticket" value="<?=htmlspecialchars($qTicket)?>" placeholder="OMG-2026-..."></label>
    <label>From<input type="date" name="from" value="<?=htmlspecialchars($qFrom)?>"></label>
    <label>To<input type="date" name="to" value="<?=htmlspecialchars($qTo)?>"></label>
    <button class="btn">Filter</button><a href="activity.php" class="btn secondary">Reset</a>
  </form>
  <div class="panel">
    <div class="panelhead"><h2><?=$totalRows?> entr<?=$totalRows===1?"y":"ies"?></h2><span class="hint">Page <?=$page?> of <?=$totalPages?></span></div>
    <table>
      <tr><th>When</th><th>User</th><th>Action</th><th>Ticket</th></tr>
      <?php if ($rows->num_rows === 0): ?><tr><td colspan="4" style="text-align:center;color:#6b7887;padding:20px">No matching activity.</td></tr><?php endif; ?>
      <?php while ($r = $rows->fetch_assoc()): ?>
        <tr>
          <td><?=htmlspecialchars($r["created_at"])?></td>
          <td><b><?=htmlspecialchars($r["user_name"])?></b><br><small style="color:#6b7887"><?=htmlspecialchars($r["user_login"] ?? "")?></small></td>
          <td><span class="pill action <?=action_class($r["action"])?>"><?=htmlspecialchars($r["action"])?></span></td>
          <td><?php if ($r["ticket_no"]): ?><a href="view_report.php?ticket=<?=urlencode($r["ticket_no"])?>"><?=htmlspecialchars($r["ticket_no"])?></a><?php else: ?>—<?php endif; ?></td>
        </tr>
      <?php endwhile; ?>
    </table>
    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <?php
        $qs = $_GET; unset($qs["page"]); $qsStr = http_build_query($qs);
        $base = "activity.php?" . ($qsStr ? $qsStr . "&" : "") . "page=";
        if ($page > 1): ?><a href="<?=htmlspecialchars($base . ($page-1))?>">« Prev</a><?php else: ?><span class="disabled">« Prev</span><?php endif;
        $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
        if ($start > 1) echo '<a href="' . htmlspecialchars($base . 1) . '">1</a>' . ($start > 2 ? '<span class="disabled">…</span>' : '');
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $page) echo '<span class="current">' . $i . '</span>';
            else echo '<a href="' . htmlspecialchars($base . $i) . '">' . $i . '</a>';
        }
        if ($end < $totalPages) echo ($end < $totalPages - 1 ? '<span class="disabled">…</span>' : '') . '<a href="' . htmlspecialchars($base . $totalPages) . '">' . $totalPages . '</a>';
        if ($page < $totalPages): ?><a href="<?=htmlspecialchars($base . ($page+1))?>">Next »</a><?php else: ?><span class="disabled">Next »</span><?php endif;
        ?>
      </div>
    <?php endif; ?>
  </div>
</main></body></html>