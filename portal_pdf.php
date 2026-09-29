<?php
require "config.php";

$ticket = $_GET["ticket"] ?? "";
$phone  = preg_replace('/\D+/', '', $_GET["phone"] ?? "");

$dompdfAuto = __DIR__ . '/vendor/dompdf/autoload.inc.php';
if (!file_exists($dompdfAuto)) die("PDF not available right now. Please contact OMG Technologies.");
require $dompdfAuto;
use Dompdf\Dompdf;
use Dompdf\Options;

$s = $conn->prepare("
    SELECT r.*, c.client_name, c.address, c.phone, c.alternate_phone
    FROM reports r
    LEFT JOIN clients c ON c.id = r.client_id
    WHERE r.ticket_no = ?
      AND (
           REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),' ',''),'-',''),'+','') LIKE CONCAT('%', ?, '%')
        OR REPLACE(REPLACE(REPLACE(COALESCE(c.alternate_phone,''),' ',''),'-',''),'+','') LIKE CONCAT('%', ?, '%')
      )
    LIMIT 1
");
$s->bind_param("sss", $ticket, $phone, $phone); $s->execute();
$r = $s->get_result()->fetch_assoc();
if (!$r) die("Ticket not found.");

$d = $conn->prepare("SELECT * FROM diagnostic_items WHERE report_id=? ORDER BY id");
$d->bind_param("i", $r["id"]); $d->execute();
$diags = $d->get_result()->fetch_all(MYSQLI_ASSOC);

function e($x) { return htmlspecialchars($x ?? ""); }
$deviceLabel = (($r["device_type"] ?? "computer") === "printer") ? "Printer / Photocopier / Scanner" : "Computer / Laptop";

$logo = get_setting($conn, "company_logo", "");
$coat = get_setting($conn, "coat_of_arms", "");
$banner = get_setting($conn, "letterhead_banner", "");
$useBanner = (get_header_mode($conn) === "banner" && $banner && file_exists(__DIR__ . '/' . $banner));

$logoData = $coatData = $bannerData = "";
foreach ([["logo",$logo],["coat",$coat],["banner",$banner]] as $pair) {
    $k = $pair[0]; $p = $pair[1];
    if ($p && file_exists(__DIR__ . '/' . $p)) {
        $mime = mime_content_type(__DIR__ . '/' . $p) ?: "image/png";
        $uri = "data:" . $mime . ";base64," . base64_encode(file_get_contents(__DIR__ . '/' . $p));
        if ($k === "logo") $logoData = $uri;
        elseif ($k === "coat") $coatData = $uri;
        else $bannerData = $uri;
    }
}

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color:#1c2530; }
h1 { font-size: 14px; text-align:center; color:#0b2545; margin: 12px 0; }
h2 { font-size: 11px; background:#eef3f9; padding:5px 8px; margin:14px 0 6px; color:#0b2545; }
table { width:100%; border-collapse: collapse; font-size:10px; }
th, td { border:1px solid #e2e8ef; padding:5px 6px; text-align:left; vertical-align:top; }
th { background:#f7f9fc; font-size:9px; text-transform:uppercase; color:#52616f; }
.banner { width:100%; display:block; margin-bottom:10px; }
.head { display: table; width:100%; border-bottom:2px solid #0b2545; padding-bottom:8px; margin-bottom:12px; }
.head .cell { display: table-cell; vertical-align: middle; }
.head .logo { width:90px; text-align:center; }
.head .logo img { max-height:80px; max-width:80px; }
.head .center { text-align:center; }
.head b { font-size: 14px; color:#0b2545; }
.head div { font-size:10px; color:#444; margin-top:4px; }
.rgrid { display:table; width:100%; }
.rgrid .row { display:table-row; }
.rgrid .cell { display:table-cell; width:50%; padding:3px 0; font-size:10.5px; }
.badge { display:inline-block; padding:4px 10px; border-radius:999px; background:#e6eef8; color:#0b2545; font-weight:700; font-size:11px; }
</style></head><body>';

if ($useBanner && $bannerData) {
    $html .= '<img class="banner" src="' . $bannerData . '">';
} else {
    $html .= '<div class="head">';
    $html .= '<div class="cell logo">' . ($logoData ? '<img src="' . $logoData . '">' : '') . '</div>';
    $html .= '<div class="cell center"><b>OMG TECHNOLOGIES UGANDA</b><br><strong>IT SUPPORT &amp; COMPUTER SERVICES DEPARTMENT</strong>'
           . '<div>P.O. BOX, 291027 SOROTI- UGANDA<br>TEL: +256 774 610 005 | +256 778 508 802<br>EMAIL: omgtechnologiesusmclimited@gmail.com</div></div>';
    $html .= '<div class="cell logo">' . ($coatData ? '<img src="' . $coatData . '">' : '') . '</div>';
    $html .= '</div>';
}

$html .= '<h1>TICKET STATUS REPORT — ' . strtoupper(e($deviceLabel)) . '</h1>';
$html .= '<p style="text-align:center;font-size:13px"><span class="badge">' . e($r["ticket_status"]) . '</span></p>';
$html .= '<p style="text-align:center"><b>Ticket No.:</b> ' . e($r["ticket_no"]) . '</p>';

$html .= '<h2>Client &amp; Equipment</h2><div class="rgrid">';
foreach ([
  ["Client", $r["client_name"]], ["Phone", $r["phone"]],
  ["Device", $deviceLabel], ["Make / Model", $r["computer_make_model"]],
  ["Serial", $r["serial_number"]], ["Reported Date", $r["reported_date"]],
  ["Estimated Completion", $r["estimated_completion"]], ["Final Result", $r["final_result"]],
] as $z) {
    $html .= '<div class="row"><div class="cell"><b>' . e($z[0]) . ':</b> ' . e($z[1]) . '</div></div>';
}
$html .= '</div>';

$html .= '<h2>Reported Problem</h2><p><b>Categories:</b> ' . e($r["problem_categories"]) . '</p>';
$html .= '<p><b>Description:</b><br>' . nl2br(e($r["problem_description"])) . '</p>';

$html .= '<h2>Diagnostic Summary</h2><table><tr><th>Area</th><th>Status</th><th>Findings</th></tr>';
foreach ($diags as $x) {
    $html .= '<tr><td>' . e($x["diagnostic_area"]) . '</td><td><b>' . e($x["status"]) . '</b></td><td>' . nl2br(e($x["findings"])) . '</td></tr>';
}
$html .= '</table>';

$html .= '<h2>Recommended Fix</h2><p>' . nl2br(e($r["recommended_fix"])) . '</p>';
$html .= '<p><b>Estimated Cost:</b> ' . ($r["estimated_cost"] !== null ? "UGX " . number_format($r["estimated_cost"], 2) : "—") . '</p>';
$html .= '<p style="margin-top:20px;font-size:10px;color:#6b7887">This is a client-facing copy of ticket ' . e($r["ticket_no"]) . '. For questions call +256 774 610 005.</p>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("OMG-" . preg_replace('/[^A-Za-z0-9\-_]/', '_', $ticket) . "-status.pdf", ["Attachment" => true]);
exit;