<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0); if ($id <= 0) die("Invalid invoice.");

$dompdfAuto = __DIR__ . '/vendor/dompdf/autoload.inc.php';
if (!file_exists($dompdfAuto)) die("Dompdf not installed. Visit pdf_report.php?ticket=SETUP first.");
require $dompdfAuto;
use Dompdf\Dompdf;
use Dompdf\Options;

$s = $conn->prepare("SELECT i.*, c.client_name, c.address, c.phone, c.alternate_phone
                     FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id=?");
$s->bind_param("i", $id); $s->execute();
$inv = $s->get_result()->fetch_assoc(); if (!$inv) die("Invoice not found.");

$items = $conn->query("SELECT * FROM invoice_items WHERE invoice_id=" . $id . " ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$payments = $conn->query("SELECT * FROM invoice_payments WHERE invoice_id=" . $id . " ORDER BY paid_on, id")->fetch_all(MYSQLI_ASSOC);
$totals = compute_invoice_totals($conn, $id);
$status = effective_invoice_status($inv);

$logo = get_setting($conn, "company_logo", "");
$coat = get_setting($conn, "coat_of_arms", "");
$banner = get_setting($conn, "letterhead_banner", "");
$useBanner = (get_header_mode($conn) === "banner" && $banner && file_exists(__DIR__ . '/' . $banner));
$cur = $inv["currency"] ?: "UGX";
$ticketNo = "";
if (!empty($inv["report_id"])) {
    $rt = $conn->query("SELECT ticket_no FROM reports WHERE id=" . (int)$inv["report_id"])->fetch_assoc();
    if ($rt) $ticketNo = $rt["ticket_no"];
}

function e($x) { return htmlspecialchars($x ?? ""); }
$logoData = $coatData = $bannerData = "";
foreach ([["logo",$logo],["coat",$coat],["banner",$banner]] as $pair) {
    $k = $pair[0]; $p = $pair[1];
    if ($p && file_exists(__DIR__ . '/' . $p)) {
        $mime = mime_content_type(__DIR__ . '/' . $p) ?: "image/png";
        $uri = "data:" . $mime . ";base64," . base64_encode(file_get_contents(__DIR__ . '/' . $p));
        if ($k === "logo") $logoData = $uri; elseif ($k === "coat") $coatData = $uri; else $bannerData = $uri;
    }
}
$qrHtml = "";
if (function_exists('imagecreatetruecolor')) {
    try {
        $qr = TinyQR::encode(base_url("portal.php?invoice=" . urlencode($inv["invoice_no"])), 'M');
        $uri = $qr->toPngDataUri(3, 2);
        if ($uri) $qrHtml = '<img src="' . $uri . '" width="70" height="70">';
    } catch (Throwable $ex) {}
}

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color:#1c2530; }
h1 { font-size: 22px; text-align:center; letter-spacing: 3px; color:#0b2545; margin: 8px 0 12px; }
h2 { font-size: 11px; background:#eef3f9; padding:5px 8px; margin:14px 0 6px; color:#0b2545; }
table { width:100%; border-collapse: collapse; font-size:10px; }
th, td { border:1px solid #e2e8ef; padding:5px 6px; text-align:left; vertical-align:top; }
th { background:#f7f9fc; font-size:9px; text-transform:uppercase; color:#52616f; }
.banner { width:100%; display:block; margin-bottom:10px; max-height:170px; }
.head { display: table; width:100%; border-bottom:2px solid #0b2545; padding-bottom:8px; margin-bottom:12px; }
.head .cell { display: table-cell; vertical-align: middle; }
.head .logo { width:90px; text-align:center; }
.head .logo img { max-height:80px; max-width:80px; }
.head .center { text-align:center; }
.head b { font-size: 14px; color:#0b2545; }
.head div { font-size:10px; color:#444; margin-top:4px; }
.meta { display:table; width:100%; margin-bottom:10px; }
.meta .box { display:table-cell; width:50%; padding:8px 10px; background:#f7f9fc; border:1px solid #e2e8ef; vertical-align:top; font-size:10.5px; }
.meta .box b { display:block; font-size:9px; text-transform:uppercase; color:#6b7887; margin-bottom:4px; }
.meta td { border:none; padding:2px 0; font-size:10.5px; }
.items td.num, .items th.num { text-align:right; }
.totals { width:280px; margin-left:auto; margin-top:10px; }
.totals table { width:100%; }
.totals td { border:none; padding:3px 0; font-size:11px; }
.totals td.label { color:#52616f; }
.totals td.value { text-align:right; font-weight:600; color:#0b2545; }
.totals tr.grand td { font-size:13px; padding-top:6px; border-top:2px solid #0b2545; color:#0b2545; }
.totals tr.balance td { color:#a12626; }
.totals tr.balance.paid td { color:#0a7a2f; }
.sign { display:table; width:100%; margin-top:20px; }
.sign .c { display:table-cell; width:50%; padding-top:24px; border-top:1px solid #999; font-size:10.5px; }
.qr { text-align:right; }
</style></head><body>';
if ($useBanner && $bannerData) $html .= '<img class="banner" src="' . $bannerData . '">';
else {
    $html .= '<div class="head">';
    $html .= '<div class="cell logo">' . ($logoData ? '<img src="'.$logoData.'">' : '') . '</div>';
    $html .= '<div class="cell center"><b>OMG TECHNOLOGIES UGANDA</b><br><strong>IT SUPPORT &amp; COMPUTER SERVICES DEPARTMENT</strong>'
           . '<div>P.O. BOX, 291027 SOROTI- UGANDA<br>TEL: +256 774 610 005 | +256 778 508 802<br>EMAIL: omgtechnologiesusmclimited@gmail.com</div></div>';
    $html .= '<div class="cell logo">' . ($coatData ? '<img src="'.$coatData.'">' : '') . '</div></div>';
}
$html .= '<h1>INVOICE</h1>';
$html .= '<div class="meta">';
$html .= '<div class="box"><b>Bill To</b>' . e($inv["client_name"]) . '<br>' . e($inv["address"]) . '<br>'
       . ($inv["phone"] ? 'Tel: ' . e($inv["phone"]) . '<br>' : '')
       . ($inv["alternate_phone"] ? 'Alt: ' . e($inv["alternate_phone"]) : '') . '</div>';
$html .= '<div class="box"><b>Invoice Details</b><table>'
       . '<tr><td>Invoice No.:</td><td style="text-align:right"><b>' . e($inv["invoice_no"]) . '</b></td></tr>'
       . '<tr><td>Issue Date:</td><td style="text-align:right">' . e($inv["issue_date"]) . '</td></tr>'
       . '<tr><td>Due Date:</td><td style="text-align:right">' . e($inv["due_date"]) . '</td></tr>'
       . ($ticketNo ? '<tr><td>Ticket:</td><td style="text-align:right">' . e($ticketNo) . '</td></tr>' : '')
       . '<tr><td>Status:</td><td style="text-align:right"><b>' . e($status) . '</b></td></tr>'
       . '</table></div></div>';

$html .= '<table class="items"><tr><th style="width:55%">Description</th><th class="num" style="width:12%">Qty</th>'
       . '<th class="num" style="width:16%">Unit Price</th><th class="num" style="width:17%">Line Total</th></tr>';
foreach ($items as $it) {
    $html .= '<tr><td>' . nl2br(e($it["description"])) . '</td>'
           . '<td class="num">' . number_format($it["quantity"], 2) . '</td>'
           . '<td class="num">' . number_format($it["unit_price"], 2) . '</td>'
           . '<td class="num">' . number_format($it["quantity"] * $it["unit_price"], 2) . '</td></tr>';
}
$html .= '</table>';

$html .= '<div style="display:table; width:100%; margin-top:10px">';
$html .= '<div style="display:table-cell; width:110px; vertical-align:top; text-align:center">' . ($qrHtml ? $qrHtml . '<br><span style="font-size:8px;color:#6b7887">Scan to open</span>' : '') . '</div>';
$html .= '<div style="display:table-cell; vertical-align:top"><div class="totals"><table>';
$html .= '<tr><td class="label">Subtotal</td><td class="value">' . e($cur) . ' ' . number_format($totals["subtotal"], 2) . '</td></tr>';
if ($totals["discount"] > 0) $html .= '<tr><td class="label">Discount</td><td class="value">- ' . number_format($totals["discount"], 2) . '</td></tr>';
if ($totals["tax_rate"] > 0) $html .= '<tr><td class="label">VAT (' . number_format($totals["tax_rate"], 2) . '%)</td><td class="value">' . number_format($totals["tax"], 2) . '</td></tr>';
$html .= '<tr class="grand"><td class="label"><b>Total</b></td><td class="value"><b>' . e($cur) . ' ' . number_format($totals["total"], 2) . '</b></td></tr>';
$html .= '<tr><td class="label">Amount Paid</td><td class="value">' . e($cur) . ' ' . number_format($totals["paid"], 2) . '</td></tr>';
$balCls = ($totals["balance"] <= 0.009) ? 'paid' : '';
$html .= '<tr class="balance ' . $balCls . '"><td class="label"><b>Balance Due</b></td><td class="value"><b>' . e($cur) . ' ' . number_format($totals["balance"], 2) . '</b></td></tr>';
$html .= '</table></div></div></div>';

if (!empty($payments)) {
    $html .= '<h2>PAYMENT HISTORY</h2><table><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr>';
    foreach ($payments as $p) {
        $html .= '<tr><td>' . e($p["paid_on"]) . '</td><td>' . e($p["method"]) . '</td><td>' . e($p["reference"]) . '</td>'
               . '<td class="num">' . e($cur) . ' ' . number_format($p["amount"], 2) . '</td></tr>';
    }
    $html .= '</table>';
}
if ($inv["notes"]) $html .= '<h2>NOTES</h2><p>' . nl2br(e($inv["notes"])) . '</p>';
if ($inv["terms"]) $html .= '<h2>TERMS &amp; CONDITIONS</h2><p>' . nl2br(e($inv["terms"])) . '</p>';

$html .= '<div class="sign"><div class="c">Prepared By<br><br>' . e($inv["technician_name"]) . '<br>Signature: ____________________</div>'
       . '<div class="c">Received By (Client)<br><br>Name: ____________________<br>Signature: ____________________</div></div>';
$html .= '<p style="margin-top:16px;font-size:9px;color:#6b7887;text-align:center">Thank you for your business. For questions call +256 774 610 005.</p>';
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($inv["invoice_no"] . ".pdf", ["Attachment" => true]);
exit;