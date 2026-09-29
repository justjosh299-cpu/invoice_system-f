<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$type = $_GET["type"] ?? "";
$ids = array_values(array_filter(array_map('intval', explode(",", $_GET["ids"] ?? "")), function($x){ return $x>0; }));
if (empty($ids)) die("No rows.");
$idList = implode(",", $ids);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_export_' . date("Ymd_His") . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

if ($type === "invoices") {
    fputcsv($out, ["Invoice","Client","Issued","Due","Status","Currency","Subtotal","VAT","Total","Paid","Balance"]);
    $q = $conn->query("SELECT i.invoice_no, c.client_name, i.issue_date, i.due_date, i.status, i.currency, i.subtotal, i.tax_amount, i.total, i.amount_paid
                       FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.id IN ($idList) ORDER BY i.id DESC");
    while ($r = $q->fetch_assoc()) {
        $r["balance"] = $r["total"] - $r["amount_paid"];
        fputcsv($out, array_values($r));
    }
} elseif ($type === "reports") {
    fputcsv($out, ["Ticket","Device","Client","Make/Model","Status","Date","Technician"]);
    $q = $conn->query("SELECT r.ticket_no, r.device_type, c.client_name, r.computer_make_model, r.ticket_status, r.reported_date, r.technician_name
                       FROM reports r LEFT JOIN clients c ON c.id=r.client_id WHERE r.id IN ($idList) ORDER BY r.id DESC");
    while ($r = $q->fetch_assoc()) fputcsv($out, array_values($r));
}
fclose($out);
exit;