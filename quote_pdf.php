<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$id = (int)($_GET["id"] ?? 0);
$dompdfAuto = __DIR__ . '/vendor/dompdf/autoload.inc.php';
if (!file_exists($dompdfAuto)) die("Dompdf not installed.");
require $dompdfAuto;
use Dompdf\Dompdf; use Dompdf\Options;

$s = $conn->prepare("SELECT q.*, c.client_name, c.address, c.phone FROM quotations q LEFT JOIN clients c ON c.id=q.client_id WHERE q.id=?");
$s->bind_param("i",$id); $s->execute();
$q = $s->get_result()->fetch_assoc(); if (!$q) die("Not found.");
$items = $conn->query("SELECT * FROM quotation_items WHERE quote_id=$id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$tot = compute_quote_totals($conn, $id);

$logo = get_setting($conn, "company_logo", ""); $coat = get_setting($conn, "coat_of_arms", "");
function e($x){ return htmlspecialchars($x ?? ""); }
$logoData = $coatData = "";
foreach ([["logo",$logo],["coat",$coat]] as $p) {
    if ($p[1] && file_exists(__DIR__ . '/' . $p[1])) {
        $m = mime_content_type(__DIR__ . '/' . $p[1]) ?: "image/png";
        $u = "data:$m;base64," . base64_encode(file_get_contents(__DIR__ . '/' . $p[1]));
        if ($p[0] === "logo") $logoData = $u; else $coatData = $u;
    }
}
$html = '<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#1c2530}
h1{font-size:22px;text-align:center;letter-spacing:3px;color:#0b2545;margin:8px 0 12px}
.head{display:table;width:100%;border-bottom:2px solid #0b2545;padding-bottom:8px;margin-bottom:12px}
.head .cell{display:table-cell;vertical-align:middle}
.head .logo{width:90px;text-align:center}.head .logo img{max-height:70px;max-width:70px}
.head .center{text-align:center}.head b{font-size:14px;color:#0b2545}.head div{font-size:10px;color:#444;margin-top:4px}
.meta{display:table;width:100%;margin-bottom:10px}.meta .box{display:table-cell;width:50%;padding:8px 10px;background:#f7f9fc;border:1px solid #e2e8ef;vertical-align:top;font-size:10.5px}
.meta .box b{display:block;font-size:9px;text-transform:uppercase;color:#6b7887;margin-bottom:4px}
table{width:100%;border-collapse:collapse;font-size:10px}
th,td{border:1px solid #e2e8ef;padding:5px 6px;text-align:left;vertical-align:top}
th{background:#f7f9fc;font-size:9px;text-transform:uppercase;color:#52616f}
.items td.num,.items th.num{text-align:right}
.totals{width:280px;margin-left:auto;margin-top:10px}.totals table{width:100%}
.totals td{border:none;padding:3px 0;font-size:11px}
.totals td.label{color:#52616f}.totals td.value{text-align:right;font-weight:600;color:#0b2545}
.totals tr.grand td{font-size:13px;padding-top:6px;border-top:2px solid #0b2545;color:#0b2545}
</style></head><body>';
$html .= '<div class="head">';
$html .= '<div class="cell logo">' . ($logoData?'<img src="'.$logoData.'">':'') . '</div>';
$html .= '<div class="cell center"><b>OMG TECHNOLOGIES UGANDA</b><br><strong>IT SUPPORT &amp; COMPUTER SERVICES DEPARTMENT</strong>'
       . '<div>P.O. BOX, 291027 SOROTI- UGANDA<br>TEL: +256 774 610 005 | +256 778 508 802<br>EMAIL: omgtechnologiesusmclimited@gmail.com</div></div>';
$html .= '<div class="cell logo">' . ($coatData?'<img src="'.$coatData.'">':'') . '</div></div>';
$html .= '<h1>QUOTATION</h1>';
$html .= '<div class="meta"><div class="box"><b>Bill To</b>' . e($q["client_name"]) . '<br>' . e($q["address"]) . '<br>' . e($q["phone"]) . '</div>';
$html .= '<div class="box"><b>Quote Details</b>Quote No.: <b>' . e($q["quote_no"]) . '</b><br>Issued: ' . e($q["issue_date"]) . '<br>Valid Until: ' . e($q["valid_until"]) . '<br>Status: ' . e($q["status"]) . '</div></div>';
$html .= '<table class="items"><tr><th style="width:55%">Description</th><th class="num" style="width:12%">Qty</th><th class="num" style="width:16%">Unit Price</th><th class="num" style="width:17%">Line Total</th></tr>';
foreach ($items as $it) {
    $html .= '<tr><td>' . nl2br(e($it["description"])) . '</td><td class="num">' . number_format($it["quantity"],2) . '</td>'
           . '<td class="num">' . number_format($it["unit_price"],2) . '</td><td class="num">' . number_format($it["quantity"]*$it["unit_price"],2) . '</td></tr>';
}
$html .= '</table>';
$html .= '<div class="totals"><table>';
$html .= '<tr><td class="label">Subtotal</td><td class="value">' . e($q["currency"]) . ' ' . number_format($tot["subtotal"],2) . '</td></tr>';
if ($tot["discount"] > 0) $html .= '<tr><td class="label">Discount</td><td class="value">- ' . number_format($tot["discount"],2) . '</td></tr>';
if ($tot["tax_rate"] > 0) $html .= '<tr><td class="label">VAT (' . number_format($tot["tax_rate"],2) . '%)</td><td class="value">' . number_format($tot["tax"],2) . '</td></tr>';
$html .= '<tr class="grand"><td class="label"><b>Total</b></td><td class="value"><b>' . e($q["currency"]) . ' ' . number_format($tot["total"],2) . '</b></td></tr>';
$html .= '</table></div>';
if ($q["notes"]) $html .= '<h2 style="font-size:11px;background:#eef3f9;padding:5px 8px;color:#0b2545">NOTES</h2><p>' . nl2br(e($q["notes"])) . '</p>';
if ($q["terms"]) $html .= '<h2 style="font-size:11px;background:#eef3f9;padding:5px 8px;color:#0b2545">TERMS</h2><p>' . nl2br(e($q["terms"])) . '</p>';
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($q["quote_no"] . ".pdf", ["Attachment" => true]);
exit;