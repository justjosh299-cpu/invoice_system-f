<?php
require "config.php";

$err = "";
$report = null;
$invoice = null;
$mode = $_POST["mode"] ?? (isset($_GET["invoice"]) ? "invoice" : "ticket");
if (isset($_GET["invoice"])) $mode = "invoice";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $ip = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";
    if (!portal_rate_limit($conn, $ip)) {
        $err = "Too many attempts. Try again in 15 minutes.";
    } else {
        $phone = trim($_POST["phone"] ?? "");
        $key   = trim($_POST["key"] ?? "");
        if ($phone === "" || $key === "") {
            $err = "Please enter both your phone number and the reference.";
        } else {
            $digits = preg_replace('/\D+/', '', $phone);
            if ($mode === "invoice") {
                $s = $conn->prepare("
                    SELECT i.*, c.client_name, c.phone, c.alternate_phone
                    FROM invoices i LEFT JOIN clients c ON c.id = i.client_id
                    WHERE i.invoice_no = ?
                      AND (REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),' ',''),'-',''),'+','') LIKE CONCAT('%', ?, '%')
                        OR REPLACE(REPLACE(REPLACE(COALESCE(c.alternate_phone,''),' ',''),'-',''),'+','') LIKE CONCAT('%', ?, '%'))
                    LIMIT 1
                ");
                $s->bind_param("sss", $key, $digits, $digits); $s->execute();
                $invoice = $s->get_result()->fetch_assoc();
                if (!$invoice) $err = "No matching invoice found.";
            } else {
                $s = $conn->prepare("
                    SELECT r.*, c.client_name, c.phone, c.alternate_phone
                    FROM reports r LEFT JOIN clients c ON c.id = r.client_id
                    WHERE r.ticket_no = ?
                      AND (REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),' ',''),'-',''),'+','') LIKE CONCAT('%', ?, '%')
                        OR REPLACE(REPLACE(REPLACE(COALESCE(c.alternate_phone,''),' ',''),'-',''),'+','') LIKE CONCAT('%', ?, '%'))
                    LIMIT 1
                ");
                $s->bind_param("sss", $key, $digits, $digits); $s->execute();
                $report = $s->get_result()->fetch_assoc();
                if (!$report) $err = "No matching ticket found.";
            }
        }
    }
}

function h($x) { return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title>Client Portal — OMG</title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="portal">

<canvas id="portalCanvas"></canvas>

<?php if ($report || $invoice): ?>
  <div class="portalwrap">
    <?php if ($report): ?>
      <?php
        $statusClass = str_replace(' ', '', $report["ticket_status"]);
        $steps = ["OPEN","DIAGNOSIS","AWAITING PARTS","REPAIRED","CLOSED"];
        $curIdx = array_search($report["ticket_status"], $steps);
        if ($curIdx === false) $curIdx = 0;
      ?>
      <div class="portalcard" style="text-align:center">
        <div style="font-size:12px;font-weight:700;color:#0b2545;letter-spacing:1px">OMG TECHNOLOGIES UGANDA</div>
        <h1 style="margin:8px 0 4px">Ticket <?=h($report["ticket_no"])?></h1>
        <p style="color:#52616f;margin:0 0 16px">Hello, <?=h($report["client_name"])?></p>
        <div class="statuspill <?=$statusClass?>"><?=h($report["ticket_status"])?></div>
        <p style="color:#52616f;margin-top:12px;font-size:13px">Last updated: <?=h($report["updated_at"])?></p>
      </div>
      <div class="portalcard"><h2 style="margin-top:0">Progress</h2>
        <div class="timeline" style="padding-left:20px">
          <?php foreach ($steps as $i => $s): ?>
            <div class="item" style="border-left-color: <?=$i <= $curIdx ? '#0b2545' : '#e2e8ef'?>">
              <div class="dot" style="background: <?=$i <= $curIdx ? '#0b2545' : '#cfd8e3'?>"></div>
              <div class="what"><b><?=htmlspecialchars($s)?></b></div>
              <?php if ($i === $curIdx): ?><div class="meta">Current stage</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="portalcard"><h2 style="margin-top:0">Equipment &amp; Service</h2>
        <div class="clientmeta">
          <div><b>Device</b><?=h(ucfirst($report["device_type"] ?: "computer"))?></div>
          <div><b>Make / Model</b><?=h($report["computer_make_model"])?></div>
          <div><b>Serial</b><?=h($report["serial_number"])?></div>
          <div><b>Date Reported</b><?=h($report["reported_date"])?></div>
          <div><b>Final Result</b><?=h($report["final_result"]) ?: "Pending"?></div>
          <div><b>Estimated Completion</b><?=h($report["estimated_completion"]) ?: "—"?></div>
        </div>
      </div>
      <div class="portalcard" style="text-align:center">
        <a class="btn" href="portal_pdf.php?ticket=<?=urlencode($report["ticket_no"])?>&phone=<?=urlencode($digits ?? '')?>">⬇ Download Report PDF</a>
        <p class="hint" style="margin-top:12px">Questions? Call +256 774 610 005</p>
        <p class="hint"><a href="portal.php">← Check another</a></p>
      </div>
    <?php elseif ($invoice): ?>
      <?php
        $status = $invoice["status"];
        if (in_array($status, ["SENT","PARTIAL"], true) && !empty($invoice["due_date"]) && strtotime($invoice["due_date"]) < strtotime(date("Y-m-d"))) $status = "OVERDUE";
        $balance = (float)$invoice["total"] - (float)$invoice["amount_paid"];
      ?>
      <div class="portalcard" style="text-align:center">
        <div style="font-size:12px;font-weight:700;color:#0b2545;letter-spacing:1px">OMG TECHNOLOGIES UGANDA</div>
        <h1 style="margin:8px 0 4px">Invoice <?=h($invoice["invoice_no"])?></h1>
        <p style="color:#52616f;margin:0 0 16px">Hello, <?=h($invoice["client_name"])?></p>
        <div class="statuspill <?=$status?>"><?=$status?></div>
      </div>
      <div class="portalcard">
        <h2 style="margin-top:0">Invoice Summary</h2>
        <div class="clientmeta">
          <div><b>Issued</b><?=h($invoice["issue_date"])?></div>
          <div><b>Due</b><?=h($invoice["due_date"])?></div>
          <div><b>Total</b><?=h($invoice["currency"])?> <?=number_format($invoice["total"], 2)?></div>
          <div><b>Paid</b><?=h($invoice["currency"])?> <?=number_format($invoice["amount_paid"], 2)?></div>
          <div><b>Balance Due</b><span style="color:#a12626;font-weight:700"><?=h($invoice["currency"])?> <?=number_format($balance, 2)?></span></div>
        </div>
      </div>
      <div class="portalcard" style="text-align:center">
        <p class="hint">Contact us at +256 774 610 005 for payment details (Mobile Money, bank transfer, cash).</p>
        <p class="hint"><a href="portal.php?invoice=1">← Check another invoice</a></p>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <form method="post" class="portallogin">
    <div class="brand">OMG TECHNOLOGIES UGANDA</div>
    <h1>Client Portal</h1>
    <p class="sub">Check the status of your equipment or your invoice.</p>
    <?php if ($err): ?><div class="error"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <label>I want to check
      <select name="mode" id="mode">
        <option value="ticket" <?=$mode==="ticket"?"selected":""?>>A repair ticket</option>
        <option value="invoice" <?=$mode==="invoice"?"selected":""?>>An invoice</option>
      </select>
    </label>
    <label>Phone Number<input name="phone" required placeholder="+256 7XX XXX XXX"></label>
    <label>Reference
      <input name="key" required placeholder="<?=$mode==="invoice"?"INV-2026-00001":"OMG-2026-00042"?>">
    </label>
    <button class="btn">Check Status</button>
    <p class="hint" style="text-align:center">Staff? <a href="login.php">Sign in here</a></p>
  </form>
<?php endif; ?>

<script src="assets/portal-network.js"></script>
</body></html>