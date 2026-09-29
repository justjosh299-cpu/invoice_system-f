<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0); if ($id <= 0) die("Invalid invoice.");
$s = $conn->prepare("SELECT i.*, c.client_name, c.address, c.phone, c.alternate_phone
                     FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id=?");
$s->bind_param("i", $id); $s->execute();
$inv = $s->get_result()->fetch_assoc(); if (!$inv) die("Invoice not found.");

$items = $conn->query("SELECT * FROM invoice_items WHERE invoice_id=" . $id . " ORDER BY id");
$payments = $conn->query("SELECT p.*, COALESCE(u.full_name,'—') who FROM invoice_payments p LEFT JOIN users u ON u.id=p.created_by WHERE p.invoice_id=" . $id . " ORDER BY p.paid_on ASC, p.id ASC");

$totals = compute_invoice_totals($conn, $id);
$status = effective_invoice_status($inv);

$logo = get_setting($conn, "company_logo", "");
$coat = get_setting($conn, "coat_of_arms", "");
$banner = get_setting($conn, "letterhead_banner", "");
$useBanner = (get_header_mode($conn) === "banner" && $banner && file_exists(__DIR__ . '/' . $banner));
$qrUrl = base_url("portal.php?invoice=" . urlencode($inv["invoice_no"]));
$cur = $inv["currency"] ?: "UGX";

function h($x) { return htmlspecialchars($x ?? ""); }
?><!doctype html><html><head><title><?=h($inv["invoice_no"])?> - Invoice</title>
<link rel="stylesheet" href="assets/style.css">
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head><body class="reportbody">
<div class="printbar">
  <a href="invoices.php">← Invoices</a>
  <a href="invoice_edit.php?id=<?=$id?>">✎ Edit</a>
  <a href="invoice_pdf.php?id=<?=$id?>">⬇ PDF</a>
  <button type="button" onclick="openEmail()">✉ Email</button>
  <button type="button" onclick="openPay()">💰 Record Payment</button>
  <button type="button" class="btn danger small" onclick="confirmDelete()">🗑 Delete</button>
  <button onclick="window.print()">Print / Save PDF</button>
</div>

<?php if (isset($_GET["updated"])): ?><div style="max-width:800px;margin:0 auto 12px"><div class="success">Invoice updated.</div></div><?php endif; ?>
<?php if (isset($_GET["paid"])): ?><div style="max-width:800px;margin:0 auto 12px"><div class="success">Payment recorded.</div></div><?php endif; ?>
<?php if (isset($_GET["emailed"])): ?><div style="max-width:800px;margin:0 auto 12px"><div class="success">Invoice emailed.</div></div><?php endif; ?>

<div class="page invoice<?=$useBanner?' withbanner':''?>">
  <?php if ($useBanner): ?>
    <div class="bannerbox"><img src="<?=h($banner)?>" alt="Letterhead"></div>
  <?php else: ?>
    <div class="inv-head">
      <div class="logo-left">
        <?php if ($logo && file_exists(__DIR__ . '/' . $logo)): ?><img src="<?=h($logo)?>" alt="Logo"><?php else: ?><div class="placeholder">LOGO</div><?php endif; ?>
      </div>
      <div class="center">
        <b>OMG TECHNOLOGIES UGANDA</b><br>
        <strong>IT SUPPORT &amp; COMPUTER SERVICES DEPARTMENT</strong>
        <div>P.O. BOX, 291027 SOROTI- UGANDA<br>
          TEL: +256 774 610 005 | +256 778 508 802 | +256 751 182 744<br>
          EMAIL: omgtechnologiesusmclimited@gmail.com</div>
      </div>
      <div class="logo-right">
        <?php if ($coat && file_exists(__DIR__ . '/' . $coat)): ?><img src="<?=h($coat)?>" alt="Coat of Arms"><?php else: ?><div class="placeholder">COAT</div><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="inv-title">INVOICE</div>

  <div class="inv-meta">
    <div class="box">
      <b>Bill To</b>
      <?=h($inv["client_name"])?><br>
      <?=h($inv["address"])?><br>
      <?php if ($inv["phone"]): ?>Tel: <?=h($inv["phone"])?><br><?php endif; ?>
      <?php if ($inv["alternate_phone"]): ?>Alt: <?=h($inv["alternate_phone"])?><br><?php endif; ?>
    </div>
    <div class="box">
      <b>Invoice Details</b>
      <table style="width:100%; border:none">
        <tr><td style="border:none;padding:2px 0">Invoice No.:</td><td style="border:none;padding:2px 0;text-align:right"><b><?=h($inv["invoice_no"])?></b></td></tr>
        <tr><td style="border:none;padding:2px 0">Issue Date:</td><td style="border:none;padding:2px 0;text-align:right"><?=h($inv["issue_date"])?></td></tr>
        <tr><td style="border:none;padding:2px 0">Due Date:</td><td style="border:none;padding:2px 0;text-align:right"><?=h($inv["due_date"])?></td></tr>
        <?php if (!empty($inv["report_id"])): ?>
          <?php
            $rt = $conn->query("SELECT ticket_no FROM reports WHERE id=" . (int)$inv["report_id"])->fetch_assoc();
          ?>
          <?php if ($rt): ?>
            <tr><td style="border:none;padding:2px 0">Ticket:</td><td style="border:none;padding:2px 0;text-align:right"><?=h($rt["ticket_no"])?></td></tr>
          <?php endif; ?>
        <?php endif; ?>
        <tr><td style="border:none;padding:2px 0">Status:</td><td style="border:none;padding:2px 0;text-align:right"><span class="pill status-<?=$status?>"><?=$status?></span></td></tr>
      </table>
    </div>
  </div>

  <table class="items">
    <tr>
      <th style="width:55%">Description</th>
      <th class="num" style="width:12%">Qty</th>
      <th class="num" style="width:16%">Unit Price</th>
      <th class="num" style="width:17%">Line Total</th>
    </tr>
    <?php while ($it = $items->fetch_assoc()): ?>
      <tr>
        <td><?=nl2br(h($it["description"]))?></td>
        <td class="num"><?=number_format($it["quantity"], 2)?></td>
        <td class="num"><?=number_format($it["unit_price"], 2)?></td>
        <td class="num"><?=number_format($it["quantity"] * $it["unit_price"], 2)?></td>
      </tr>
    <?php endwhile; ?>
  </table>

  <div style="display:flex; gap:20px; align-items:flex-start; margin-top:12px">
    <div class="qrbox" style="flex:0 0 90px">
      <div id="qr"></div>
      <small>Scan to open online</small>
    </div>
    <div class="totals">
      <table>
        <tr><td class="label">Subtotal</td><td class="value"><?=h($cur)?> <?=number_format($totals["subtotal"], 2)?></td></tr>
        <?php if ($totals["discount"] > 0): ?>
          <tr><td class="label">Discount</td><td class="value">- <?=number_format($totals["discount"], 2)?></td></tr>
        <?php endif; ?>
        <?php if ($totals["tax_rate"] > 0): ?>
          <tr><td class="label">VAT (<?=number_format($totals["tax_rate"],2)?>%)</td><td class="value"><?=number_format($totals["tax"], 2)?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td class="label"><b>Total</b></td><td class="value"><b><?=h($cur)?> <?=number_format($totals["total"], 2)?></b></td></tr>
        <tr><td class="label">Amount Paid</td><td class="value"><?=h($cur)?> <?=number_format($totals["paid"], 2)?></td></tr>
        <tr class="balance <?=($totals['balance'] <= 0.009) ? 'paid' : ''?>">
          <td class="label"><b>Balance Due</b></td>
          <td class="value"><b><?=h($cur)?> <?=number_format($totals["balance"], 2)?></b></td>
        </tr>
      </table>
    </div>
  </div>

  <?php if ($payments->num_rows > 0): ?>
    <h2>Payment History</h2>
    <table class="items">
      <tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Recorded By</th></tr>
      <?php while ($p = $payments->fetch_assoc()): ?>
        <tr>
          <td><?=h($p["paid_on"])?></td>
          <td><?=h($p["method"])?></td>
          <td><?=h($p["reference"])?></td>
          <td class="num"><?=h($cur)?> <?=number_format($p["amount"], 2)?></td>
          <td><?=h($p["who"])?></td>
        </tr>
      <?php endwhile; ?>
    </table>
  <?php endif; ?>

  <?php if ($inv["notes"]): ?>
    <h2>Notes</h2>
    <p><?=nl2br(h($inv["notes"]))?></p>
  <?php endif; ?>
  <?php if ($inv["terms"]): ?>
    <h2>Terms &amp; Conditions</h2>
    <p><?=nl2br(h($inv["terms"]))?></p>
  <?php endif; ?>

  <div class="sign">
    <div>Prepared By<br><br><?=h($inv["technician_name"])?><br>Signature: __________________________</div>
    <div>Received By (Client)<br><br>Name: ______________________________<br>Signature: __________________________</div>
  </div>

  <p style="margin-top:20px;font-size:10px;color:#6b7887;text-align:center">
    Thank you for your business. For questions call +256 774 610 005.
  </p>
</div>

<div class="modal" id="payModal"><div class="box"><h3>Record Payment</h3>
  <form method="post" action="invoice_payments.php">
    <input type="hidden" name="invoice_id" value="<?=$id?>">
    <input type="hidden" name="return" value="view">
    <label>Date Paid<input type="date" name="paid_on" value="<?=date('Y-m-d')?>" required></label>
    <label>Amount (<?=h($cur)?>)<input type="number" step="0.01" name="amount" value="<?=number_format($totals['balance'], 2, '.', '')?>" required></label>
    <label>Method
      <select name="method">
        <option>CASH</option><option>MOBILE_MONEY</option><option>BANK</option><option>CHEQUE</option><option>CARD</option><option>OTHER</option>
      </select>
    </label>
    <label>Reference<input name="reference" placeholder="e.g. MoMo ref, cheque no."></label>
    <label>Note<input name="note"></label>
    <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('payModal')">Cancel</button><button class="btn">Save Payment</button></div>
  </form>
</div></div>

<div class="modal" id="emailModal"><div class="box"><h3>Email Invoice</h3>
  <form method="post" action="invoice_email.php">
    <input type="hidden" name="id" value="<?=$id?>">
    <label>To<input type="email" name="to" required placeholder="client@example.com"></label>
    <label>Subject<input name="subject" value="Invoice <?=h($inv['invoice_no'])?> - OMG Technologies Uganda"></label>
    <label>Message<textarea name="message" rows="5">Dear <?=h($inv["client_name"])?>,

Please find attached your invoice <?=h($inv["invoice_no"])?> for UGX <?=number_format($totals["balance"], 2)?>.

Thank you for your business.

OMG Technologies Uganda
+256 774 610 005</textarea></label>
    <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('emailModal')">Cancel</button><button class="btn">Send Email</button></div>
  </form>
</div></div>

<div class="modal" id="deleteModal"><div class="box"><h3>Delete invoice?</h3>
  <p>This will permanently delete <b><?=h($inv["invoice_no"])?></b>.</p>
  <form method="post" action="invoice_delete.php">
    <input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="confirm" value="DELETE">
    <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('deleteModal')">Cancel</button><button class="btn danger">Delete permanently</button></div>
  </form>
</div></div>

<script>
function openPay(){document.getElementById('payModal').classList.add('show');}
function openEmail(){document.getElementById('emailModal').classList.add('show');}
function confirmDelete(){document.getElementById('deleteModal').classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal').forEach(function(m){m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});});
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