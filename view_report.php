<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$ticket = $_GET["ticket"] ?? "";
$s = $conn->prepare("SELECT r.*, c.client_name, c.address, c.phone, c.alternate_phone
                     FROM reports r LEFT JOIN clients c ON c.id = r.client_id WHERE r.ticket_no=?");
$s->bind_param("s", $ticket); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Report not found.");
$d = $conn->prepare("SELECT * FROM diagnostic_items WHERE report_id=? ORDER BY id");
$d->bind_param("i", $r["id"]); $d->execute(); $diags = $d->get_result();

$hist = $conn->prepare("SELECT sh.*, COALESCE(u.full_name,'—') AS who FROM status_history sh LEFT JOIN users u ON u.id = sh.changed_by WHERE sh.report_id=? ORDER BY sh.id ASC");
$hist->bind_param("i", $r["id"]); $hist->execute(); $history = $hist->get_result();

// Linked invoices for this ticket
$linked = $conn->prepare("SELECT id, invoice_no, status, total, amount_paid, currency, due_date FROM invoices WHERE report_id=? ORDER BY id DESC");
$linked->bind_param("i", $r["id"]);
$linked->execute();
$linkedInvoices = $linked->get_result();

// Deposits for this ticket
$depositTotal = function_exists('deposit_total_for_report') ? deposit_total_for_report($conn, (int)$r["id"]) : 0;

function h($x) { return htmlspecialchars($x ?? ""); }
$deviceLabel = (($r["device_type"] ?? "computer") === "printer") ? "Printer / Photocopier / Scanner" : "Computer / Laptop";
$updated = isset($_GET["updated"]);
$emailed = isset($_GET["emailed"]);
$smsOk   = isset($_GET["sms"]);
$logo   = get_setting($conn, "company_logo", "");
$coat   = get_setting($conn, "coat_of_arms", "");
$banner = get_setting($conn, "letterhead_banner", "");
$mode   = get_header_mode($conn);
$useBanner = ($mode === "banner" && $banner && file_exists(__DIR__ . '/' . $banner));
$qrUrl = base_url("portal.php?t=" . urlencode($ticket));
$statusClass = str_replace(' ', '', $r["ticket_status"]);

// WhatsApp link
$waPhone = preg_replace('/\D+/', '', $r["phone"] ?? "");
$waMsg = "Hello " . ($r["client_name"] ?: "Customer") . ", this is OMG Technologies Uganda. Update on ticket " . $ticket . ": status is now " . $r["ticket_status"] . ".";
$waLink = $waPhone ? "https://wa.me/" . $waPhone . "?text=" . urlencode($waMsg) : "";
?><!doctype html><html><head><title><?=h($ticket)?> - Report</title>
<link rel="stylesheet" href="assets/style.css">
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<style>
.page { position: relative; }
.page .reporthead { display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 2px solid #0b2545; padding-bottom: 12px; margin-bottom: 16px; }
.page .reporthead .logo-left,
.page .reporthead .logo-right { flex: 0 0 110px; width: 110px; max-width: 110px; height: 90px; overflow: hidden; display: flex; align-items: center; justify-content: center; }
.page .reporthead .logo-left img,
.page .reporthead .logo-right img { display: block; width: auto !important; height: auto !important; max-width: 110px !important; max-height: 90px !important; object-fit: contain; }
.page .reporthead .logo-left .placeholder,
.page .reporthead .logo-right .placeholder { width: 90px; height: 90px; background: #f0f4f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #98a4b3; text-align: center; padding: 4px; }
.page .reporthead .center { flex: 1 1 auto; min-width: 0; text-align: center; }
.page .reporthead .center b { font-size: 18px; color: #0b2545; }
.page .reporthead .center div { font-size: 12px; color: #444; margin-top: 6px; }
.page .bannerbox { width: 100%; height: 180px; margin: 0 auto 16px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #fff; border-bottom: 2px solid #0b2545; padding-bottom: 8px; }
.page .bannerbox img { display: block; width: auto !important; height: auto !important; max-width: 100% !important; max-height: 170px !important; object-fit: contain; }
.page .ticket .qrbox { width: 90px; text-align: center; flex: 0 0 90px; }
.page .ticket .qrbox div,
.page .ticket .qrbox canvas,
.page .ticket .qrbox img { width: 90px !important; height: 90px !important; max-width: 90px !important; max-height: 90px !important; display: block; margin: 0 auto; }
.page .ticket .qrbox small { display: block; font-size: 9.5px; color: #6b7887; margin-top: 2px; }
.page .ticket { display: flex; align-items: center; justify-content: space-between; gap: 16px; text-align: left; margin-bottom: 10px; }
@media print {
  .page .bannerbox { height: 150px; padding-bottom: 4px; border-bottom-width: 1px; }
  .page .bannerbox img { max-height: 145px !important; }
}
</style>
</head><body class="reportbody">
<div class="printbar">
  <a href="index.php">← Dashboard</a>
  <a href="reports.php">Reports</a>
  <a href="edit_report.php?ticket=<?=urlencode($ticket)?>">✎ Edit</a>
  <a href="pdf_report.php?ticket=<?=urlencode($ticket)?>">⬇ PDF</a>
  <?php if (file_exists(__DIR__ . '/cost_worksheet.php')): ?>
    <a href="cost_worksheet.php?report_id=<?=(int)$r["id"]?>">💰 Cost Worksheet</a>
  <?php endif; ?>
  <?php if (file_exists(__DIR__ . '/deposits.php')): ?>
    <a href="deposits.php?ticket=<?=urlencode($ticket)?>">💵 Deposits<?php if ($depositTotal > 0): ?> (<?=number_format($depositTotal,0)?>)<?php endif; ?></a>
  <?php endif; ?>
  <a href="invoice_new.php?report_id=<?=(int)$r["id"]?>">🧾 Create Invoice</a>
  <button type="button" onclick="openEmail()">✉ Email</button>
  <button type="button" onclick="openSMS()">📱 SMS</button>
  <?php if ($waLink): ?>
    <a href="<?=h($waLink)?>" target="_blank">💬 WhatsApp</a>
  <?php endif; ?>
  <button type="button" class="btn danger small" onclick="confirmDelete('<?=h($ticket)?>')">🗑 Delete</button>
  <button onclick="window.print()">Print / Save PDF</button>
</div>

<?php if ($updated): ?><div style="max-width:800px;margin:0 auto 12px"><div class="success">Report updated.</div></div><?php endif; ?>
<?php if ($emailed): ?><div style="max-width:800px;margin:0 auto 12px"><div class="success">Report emailed.</div></div><?php endif; ?>
<?php if ($smsOk): ?><div style="max-width:800px;margin:0 auto 12px"><div class="success">SMS sent.</div></div><?php endif; ?>

<div class="page<?=$useBanner?' withbanner':''?>">
  <?php if ($useBanner): ?>
    <div class="bannerbox"><img src="<?=h($banner)?>" alt="Letterhead"></div>
  <?php else: ?>
    <div class="reporthead">
      <div class="logo-left">
        <?php if ($logo && file_exists(__DIR__ . '/' . $logo)): ?><img src="<?=h($logo)?>" alt="Logo"><?php else: ?><div class="placeholder">LOGO</div><?php endif; ?>
      </div>
      <div class="center">
        <b>OMG TECHNOLOGIES UGANDA</b><br>
        <strong>IT SUPPORT &amp; COMPUTER SERVICES DEPARTMENT</strong>
        <div>P.O. BOX, 291027 SOROTI- UGANDA<br>
          TEL: +256 774 610 005 | +256 778 508 802<br>
          EMAIL: omgtechnologiesusmclimited@gmail.com</div>
      </div>
      <div class="logo-right">
        <?php if ($coat && file_exists(__DIR__ . '/' . $coat)): ?><img src="<?=h($coat)?>" alt="Coat of Arms"><?php else: ?><div class="placeholder">COAT</div><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <h1>DIAGNOSTIC &amp; REPAIR REPORT — <?=h(strtoupper($deviceLabel))?></h1>
  <div class="ticket">
    <div><b>Ticket No.:</b> <b><?=h($r["ticket_no"])?></b> &nbsp;
      <span class="pill status-<?=$statusClass?>"><?=h($r["ticket_status"])?></span>
    </div>
    <div class="qrbox">
      <div id="qr"></div>
      <small>Scan to open online</small>
    </div>
  </div>

  <h2>1. CLIENT &amp; EQUIPMENT INFORMATION</h2>
  <div class="rgrid">
    <?php foreach ([
      ["Device Type", $deviceLabel],["Client / Organization",$r["client_name"]],["Address",$r["address"]],
      ["Phone",$r["phone"]],["Alternate Phone",$r["alternate_phone"]],["Date Reported",$r["reported_date"]],
      ["Make & Model",$r["computer_make_model"]],["Serial Number",$r["serial_number"]],["Work Ticket Number",$r["work_ticket_number"]]
    ] as $z): ?>
      <div><b><?=h($z[0])?></b><br><?=h($z[1])?></div>
    <?php endforeach; ?>
  </div>

  <h2>2. REPORTED PROBLEM</h2>
  <p><b>Categories:</b> <?=h($r["problem_categories"])?></p>
  <p><b>Problem Description:</b> <?=nl2br(h($r["problem_description"]))?></p>

  <h2>3. TECHNICIAN DIAGNOSTIC ASSESSMENT (<?=h(strtoupper($deviceLabel))?>)</h2>
  <table>
    <tr><th>Diagnostic Area</th><th>Status / Findings</th><th>Action / Recommendation</th></tr>
    <?php while ($x = $diags->fetch_assoc()): ?>
      <tr>
        <td><?=h($x["diagnostic_area"])?></td>
        <td><b><?=h($x["status"])?></b><br><?=nl2br(h($x["findings"]))?></td>
        <td><?=nl2br(h($x["recommendation"]))?></td>
      </tr>
    <?php endwhile; ?>
  </table>

  <h2>4. SUGGESTED REPAIR / FIX</h2>
  <p><b>Recommended Fix:</b> <?=nl2br(h($r["recommended_fix"]))?></p>
  <p><b>Estimated Cost:</b> <?=$r["estimated_cost"] !== null ? "UGX " . number_format($r["estimated_cost"], 2) : "—"?>
     &nbsp; <b>Estimated Completion:</b> <?=h($r["estimated_completion"])?></p>

  <h2>5. PARTS / EQUIPMENT RECEIVED</h2>
  <p><?=h($r["items_received"])?></p>
</div>

<?php if ($linkedInvoices->num_rows > 0): ?>
<div class="panel" style="max-width:800px;margin:0 auto 20px">
  <div class="panelhead">
    <h2>Linked Invoices</h2>
    <a href="invoice_new.php?report_id=<?=(int)$r["id"]?>">+ New Invoice</a>
  </div>
  <table>
    <tr><th>Invoice</th><th>Issued / Due</th><th>Status</th><th>Total</th><th>Balance</th><th></th></tr>
    <?php while ($li = $linkedInvoices->fetch_assoc()):
      $lst = $li["status"];
      if (in_array($lst, ["SENT","PARTIAL"], true) && !empty($li["due_date"]) && strtotime($li["due_date"]) < strtotime(date("Y-m-d"))) $lst = "OVERDUE";
    ?>
      <tr>
        <td><b><?=h($li["invoice_no"])?></b></td>
        <td><?=h($li["due_date"])?></td>
        <td><span class="pill status-<?=$lst?>"><?=$lst?></span></td>
        <td><?=h($li["currency"])?> <?=number_format($li["total"], 2)?></td>
        <td><?=h($li["currency"])?> <?=number_format($li["total"] - $li["amount_paid"], 2)?></td>
        <td class="rowactions">
          <a class="btn small secondary" href="invoice_view.php?id=<?=(int)$li["id"]?>">View</a>
          <a class="btn small secondary" href="invoice_pdf.php?id=<?=(int)$li["id"]?>">PDF</a>
        </td>
      </tr>
    <?php endwhile; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($depositTotal > 0): ?>
<div class="success" style="max-width:800px;margin:0 auto 20px">
  💵 <b>Deposits on this ticket: UGX <?=number_format($depositTotal, 2)?></b> — will be auto-deducted when you create the invoice.
  <a href="deposits.php?ticket=<?=urlencode($ticket)?>" style="color:inherit;text-decoration:underline">View deposits</a>
</div>
<?php endif; ?>

<div class="page">
  <h2>6. DATA &amp; SOFTWARE AUTHORIZATION</h2>
  <p>I authorize the technician to perform necessary diagnostic and repair procedures, including software
     installation/removal, driver installation, hardware removal/replacement, operating-system repair or
     reinstallation, and storage formatting where necessary.</p>
  <p><b>Client Initials:</b> <?=h($r["client_initials"])?></p>

  <h2>7. REPAIR COMPLETION RECORD</h2>
  <div class="rgrid">
    <?php foreach ([["Technician Name",$r["technician_name"]],["Date Repaired",$r["repaired_date"]],["Verified By",$r["verified_by"]],["Final Result",$r["final_result"]]] as $z): ?>
      <div><b><?=h($z[0])?></b><br><?=h($z[1])?></div>
    <?php endforeach; ?>
  </div>
  <?php foreach ([["Action Taken",$r["action_taken"]],["Parts Replaced / Installed",$r["parts_replaced"]],["Software Installed / Removed",$r["software_installed"]],["Technician Remarks",$r["technician_remarks"]]] as $z): ?>
    <p><b><?=h($z[0])?>:</b><br><?=nl2br(h($z[1]))?></p>
  <?php endforeach; ?>

  <h2>8. CLIENT VERIFICATION &amp; SIGNATURES</h2>
  <div class="sign">
    <div>CLIENT / CUSTOMER<br><br>Signature: __________________________<br>Date: ______________________________</div>
    <div>TECHNICIAN<br><br><?=h($r["technician_name"])?><br>Signature: __________________________<br>Date: ______________________________</div>
  </div>

  <h2>9. DISCLAIMER &amp; SERVICE TERMS</h2>
  <ul>
    <li>I certify that I am the authorized owner or user of the equipment described in this report.</li>
    <li>I understand that repair work may affect manufacturer warranty coverage.</li>
    <li>I understand that technical support does not guarantee that every problem will be resolved.</li>
    <li>I authorize reasonable diagnostic procedures and understand that repair work can involve hardware, software or data risks.</li>
    <li>I am responsible for backing up personal files before repair.</li>
  </ul>

  <h2>10. TECHNICIAN USE ONLY</h2>
  <table>
    <tr><td>Diagnostic Start: <?=h($r["diagnostic_start"])?></td><td>Diagnostic End: <?=h($r["diagnostic_end"])?></td></tr>
    <tr><td>Repair Start: <?=h($r["repair_start"])?></td><td>Operation / Repair End: <?=h($r["repair_end"])?></td></tr>
    <tr><td>Ticket Status: <?=h($r["ticket_status"])?></td><td>Technician ID: OMG/T/002</td></tr>
    <tr><td colspan="2">Print Date: <?=date("d M Y")?></td></tr>
  </table>
</div>

<?php if ($history->num_rows > 0): ?>
<div class="panel" style="max-width:800px;margin:0 auto 20px">
  <h2>Status History</h2>
  <div class="timeline">
    <?php while ($hh = $history->fetch_assoc()): ?>
      <div class="item">
        <div class="dot"></div>
        <div class="what">
          <?php if ($hh["old_status"]): ?><b><?=h($hh["old_status"])?></b> → <b><?=h($hh["new_status"])?></b><?php else: ?>Created as <b><?=h($hh["new_status"])?></b><?php endif; ?>
        </div>
        <div class="meta"><?=h($hh["created_at"])?> · <?=h($hh["who"])?></div>
      </div>
    <?php endwhile; ?>
  </div>
</div>
<?php endif; ?>

<div class="modal" id="deleteModal"><div class="box"><h3>Delete report?</h3>
  <p>This will permanently delete ticket <b id="delTicket"></b> and all its diagnostic items.</p>
  <form method="post" action="delete_report.php">
    <input type="hidden" name="ticket" id="delTicketInput"><input type="hidden" name="confirm" value="DELETE">
    <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('deleteModal')">Cancel</button><button type="submit" class="btn danger">Delete permanently</button></div>
  </form>
</div></div>

<div class="modal" id="emailModal"><div class="box"><h3>Email Report</h3>
  <p>Send ticket <b><?=h($ticket)?></b> as a PDF attachment.</p>
  <form method="post" action="email_report.php">
    <input type="hidden" name="ticket" value="<?=h($ticket)?>">
    <label>To<input type="email" name="to" required></label>
    <label>Subject<input type="text" name="subject" value="Your Service Report - <?=h($ticket)?>"></label>
    <label>Message<textarea name="message" rows="4">Dear <?=h($r["client_name"])?>,

Please find attached your diagnostic and repair report for ticket <?=h($ticket)?>.

Regards,
OMG Technologies Uganda</textarea></label>
    <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('emailModal')">Cancel</button><button class="btn">Send Email</button></div>
  </form>
</div></div>

<div class="modal" id="smsModal"><div class="box"><h3>Send SMS</h3>
  <p>Ticket <b><?=h($ticket)?></b></p>
  <form method="post" action="sms.php">
    <input type="hidden" name="ticket" value="<?=h($ticket)?>">
    <label>Phone<input name="phone" value="<?=h($r["phone"])?>" required></label>
    <label>Message<textarea name="message" rows="3">OMG Technologies: Your ticket <?=h($ticket)?> is now <?=h($r["ticket_status"])?>. Call +256774610005 for updates.</textarea></label>
    <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('smsModal')">Cancel</button><button class="btn">Send SMS</button></div>
  </form>
</div></div>

<script>
function confirmDelete(ticket) {
  document.getElementById('delTicket').textContent = ticket;
  document.getElementById('delTicketInput').value = ticket;
  document.getElementById('deleteModal').classList.add('show');
}
function openEmail(){ document.getElementById('emailModal').classList.add('show'); }
function openSMS(){ document.getElementById('smsModal').classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal').forEach(function(m){
  m.addEventListener('click', function(e){ if (e.target === this) this.classList.remove('show'); });
});
if (document.getElementById('qr')) {
  new QRCode(document.getElementById('qr'), {
    text: <?=json_encode($qrUrl)?>,
    width: 90, height: 90,
    colorDark: "#0b2545", colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.M
  });
}
</script>
</body></html>