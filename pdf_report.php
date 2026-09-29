<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$ticket = $_GET["ticket"] ?? "";
$dompdfAuto = __DIR__ . '/vendor/dompdf/autoload.inc.php';
function dompdf_looks_installed($p) { return file_exists($p); }
function dompdf_try_fetch($vendorRoot, $dompdfRoot) {
    if (!function_exists("curl_init")) return "cURL unavailable.";
    if (!is_writable($vendorRoot)) return "/vendor not writable.";
    $urls = [
        "https://github.com/dompdf/dompdf/releases/download/v3.0.0/dompdf_3-0-0.zip",
        "https://github.com/dompdf/dompdf/releases/download/v2.0.8/dompdf_2-0-8.zip",
        "https://github.com/dompdf/dompdf/releases/download/v2.0.4/dompdf_2-0-4.zip",
    ];
    foreach ($urls as $url) {
        $zipPath = $vendorRoot . "/dompdf.zip";
        $fp = @fopen($zipPath, "w+"); if (!$fp) return "Cannot open $zipPath.";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        if (!$ok || $code !== 200) { @unlink($zipPath); continue; }
        if (!class_exists("ZipArchive")) return "ZipArchive missing.";
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) { @unlink($zipPath); continue; }
        @mkdir($dompdfRoot, 0777, true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $parts = explode("/", $name, 2);
            if (count($parts) < 2) continue;
            $rel = $parts[1]; if ($rel === "") continue;
            $t = $dompdfRoot . "/" . $rel;
            if (substr($name,-1) === "/") { @mkdir($t, 0777, true); continue; }
            @mkdir(dirname($t), 0777, true);
            file_put_contents($t, $zip->getFromIndex($i));
        }
        $zip->close(); @unlink($zipPath);
        if (file_exists($dompdfRoot . "/autoload.inc.php")) return null;
    }
    return "All download attempts failed.";
}
if ($ticket === "SETUP") {
    if (dompdf_looks_installed($dompdfAuto)) die("Already installed. <a href='settings.php'>Back to Settings</a>");
    $err = dompdf_try_fetch(__DIR__ . '/vendor', __DIR__ . '/vendor/dompdf');
    if ($err) die("Failed: " . htmlspecialchars($err) . "<br><a href='settings.php'>Back</a>");
    die("✔ Dompdf installed. <a href='settings.php'>Back to Settings</a>");
}
if (!dompdf_looks_installed($dompdfAuto)) die("Dompdf not installed. Visit pdf_report.php?ticket=SETUP first.");
require $dompdfAuto;
use Dompdf\Dompdf;
use Dompdf\Options;

$s = $conn->prepare("SELECT r.*, c.client_name, c.address, c.phone, c.alternate_phone FROM reports r LEFT JOIN clients c ON c.id = r.client_id WHERE r.ticket_no=?");
$s->bind_param("s", $ticket); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Report not found.");
$d = $conn->prepare("SELECT * FROM diagnostic_items WHERE report_id=? ORDER BY id");
$d->bind_param("i", $r["id"]); $d->execute();
$diags = $d->get_result()->fetch_all(MYSQLI_ASSOC);

$logo = get_setting($conn, "company_logo", "");
$coat = get_setting($conn, "coat_of_arms", "");
$banner = get_setting($conn, "letterhead_banner", "");
$useBanner = (get_header_mode($conn) === "banner" && $banner && file_exists(__DIR__ . '/' . $banner));
function e($x) { return htmlspecialchars($x ?? ""); }
$deviceLabel = (($r["device_type"] ?? "computer") === "printer") ? "Printer / Photocopier / Scanner" : "Computer / Laptop";

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
$qrHtml = "";
if (function_exists('imagecreatetruecolor')) {
    try {
        $qr = TinyQR::encode(base_url("portal.php?t=" . urlencode($ticket)), 'M');
        $uri = $qr->toPngDataUri(3, 2);
        if ($uri) $qrHtml = '<img src="' . $uri . '" width="70" height="70">';
    } catch (Throwable $ex) {}
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
.ticketrow { display:table; width:100%; }
.ticketrow .left { display:table-cell; vertical-align:middle; font-size:11px; }
.ticketrow .right { display:table-cell; width:80px; text-align:right; vertical-align:top; }
.rgrid { display:table; width:100%; }
.rgrid .row { display:table-row; }
.rgrid .cell { display:table-cell; width:50%; padding:3px 0; font-size:10.5px; }
.pagebreak { page-break-after: always; }
.sign { display:table; width:100%; margin-top:12px; }
.sign .c { display:table-cell; width:50%; padding-top:24px; border-top:1px solid #999; font-size:10.5px; }
</style></head><body>';
if ($useBanner && $bannerData) $html .= '<img class="banner" src="' . $bannerData . '">';
else {
    $html .= '<div class="head">';
    $html .= '<div class="cell logo">' . ($logoData ? '<img src="'.$logoData.'">' : '') . '</div>';
    $html .= '<div class="cell center"><b>OMG TECHNOLOGIES UGANDA</b><br><strong>IT SUPPORT &amp; COMPUTER SERVICES DEPARTMENT</strong>'
           . '<div>P.O. BOX, 291027 SOROTI- UGANDA<br>TEL: +256 774 610 005 | +256 778 508 802<br>EMAIL: omgtechnologiesusmclimited@gmail.com</div></div>';
    $html .= '<div class="cell logo">' . ($coatData ? '<img src="'.$coatData.'">' : '') . '</div></div>';
}
$html .= '<h1>DIAGNOSTIC &amp; REPAIR REPORT — ' . strtoupper(e($deviceLabel)) . '</h1>';
$html .= '<div class="ticketrow"><div class="left"><b>Ticket No.:</b> <b>' . e($r["ticket_no"]) . '</b></div>';
$html .= '<div class="right">' . $qrHtml . '</div></div>';
$html .= '<h2>1. CLIENT &amp; EQUIPMENT INFORMATION</h2><div class="rgrid">';
foreach ([
  ["Device Type",$deviceLabel],["Client / Organization",$r["client_name"]],["Address",$r["address"]],
  ["Phone",$r["phone"]],["Alternate Phone",$r["alternate_phone"]],["Date Reported",$r["reported_date"]],
  ["Make & Model",$r["computer_make_model"]],["Serial Number",$r["serial_number"]],["Work Ticket Number",$r["work_ticket_number"]]
] as $z) $html .= '<div class="row"><div class="cell"><b>'.e($z[0]).':</b> '.e($z[1]).'</div></div>';
$html .= '</div>';
$html .= '<h2>2. REPORTED PROBLEM</h2><p><b>Categories:</b> '.e($r["problem_categories"]).'</p>';
$html .= '<p><b>Description:</b><br>'.nl2br(e($r["problem_description"])).'</p>';
$html .= '<h2>3. TECHNICIAN DIAGNOSTIC ASSESSMENT (' . strtoupper(e($deviceLabel)) . ')</h2>';
$html .= '<table><tr><th style="width:28%">Diagnostic Area</th><th style="width:36%">Status / Findings</th><th>Action / Recommendation</th></tr>';
foreach ($diags as $x) $html .= '<tr><td>'.e($x["diagnostic_area"]).'</td><td><b>'.e($x["status"]).'</b><br>'.nl2br(e($x["findings"])).'</td><td>'.nl2br(e($x["recommendation"])).'</td></tr>';
$html .= '</table>';
$html .= '<h2>4. SUGGESTED REPAIR / FIX</h2><p><b>Recommended Fix:</b><br>'.nl2br(e($r["recommended_fix"])).'</p>';
$html .= '<p><b>Estimated Cost:</b> ' . ($r["estimated_cost"]!==null?"UGX ".number_format($r["estimated_cost"],2):"—") . ' &nbsp; <b>Estimated Completion:</b> '.e($r["estimated_completion"]).'</p>';
$html .= '<h2>5. PARTS / EQUIPMENT RECEIVED</h2><p>'.e($r["items_received"]).'</p>';
$html .= '<div class="pagebreak"></div>';
$html .= '<h2>6. DATA &amp; SOFTWARE AUTHORIZATION</h2><p>I authorize the technician to perform necessary diagnostic and repair procedures.</p>';
$html .= '<p><b>Client Initials:</b> '.e($r["client_initials"]).'</p>';
$html .= '<h2>7. REPAIR COMPLETION RECORD</h2><div class="rgrid">';
foreach ([["Technician Name",$r["technician_name"]],["Date Repaired",$r["repaired_date"]],["Verified By",$r["verified_by"]],["Final Result",$r["final_result"]]] as $z)
    $html .= '<div class="row"><div class="cell"><b>'.e($z[0]).':</b> '.e($z[1]).'</div></div>';
$html .= '</div>';
foreach ([["Action Taken",$r["action_taken"]],["Parts Replaced / Installed",$r["parts_replaced"]],["Software Installed / Removed",$r["software_installed"]],["Technician Remarks",$r["technician_remarks"]]] as $z)
    $html .= '<p><b>'.e($z[0]).':</b><br>'.nl2br(e($z[1])).'</p>';
$html .= '<h2>8. CLIENT VERIFICATION &amp; SIGNATURES</h2>';
$html .= '<div class="sign"><div class="c">CLIENT / CUSTOMER<br><br>Signature: ____________________<br>Date: ____________________</div>';
$html .= '<div class="c">TECHNICIAN<br><br>'.e($r["technician_name"]).'<br>Signature: ____________________<br>Date: ____________________</div></div>';
$html .= '<h2>9. DISCLAIMER &amp; SERVICE TERMS</h2><ul><li>I certify that I am the authorized owner of the equipment described.</li><li>Warranty may be affected by repair.</li><li>No guarantee every problem will be resolved.</li><li>Back up personal files before repair.</li></ul>';
$html .= '<h2>10. TECHNICIAN USE ONLY</h2><table>'
       . '<tr><td>Diagnostic Start: '.e($r["diagnostic_start"]).'</td><td>Diagnostic End: '.e($r["diagnostic_end"]).'</td></tr>'
       . '<tr><td>Repair Start: '.e($r["repair_start"]).'</td><td>Repair End: '.e($r["repair_end"]).'</td></tr>'
       . '<tr><td>Ticket Status: '.e($r["ticket_status"]).'</td><td>Technician ID: OMG/T/002</td></tr>'
       . '<tr><td colspan="2">Print Date: '.date("d M Y").'</td></tr></table>';
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("OMG-" . preg_replace('/[^A-Za-z0-9\-_]/','_', $ticket) . ".pdf", ["Attachment" => true]);
exit;