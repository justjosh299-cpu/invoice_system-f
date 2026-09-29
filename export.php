<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$type = $_GET["type"] ?? "";
$filename = "export_" . $type . "_" . date("Ymd_His") . ".csv";

function csv_out($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

switch ($type) {
    case "reports":
        $q = $conn->query("SELECT r.ticket_no, r.device_type, c.client_name, r.computer_make_model, r.serial_number,
                                  r.ticket_status, r.reported_date, r.technician_name, r.final_result, r.estimated_cost,
                                  r.repaired_date, r.created_at
                           FROM reports r LEFT JOIN clients c ON c.id = r.client_id
                           ORDER BY r.id DESC");
        $rows = [];
        while ($r = $q->fetch_assoc()) $rows[] = $r;
        csv_out($filename,
          ["Ticket","Device","Client","Make/Model","Serial","Status","Reported","Technician","Result","Est. Cost","Repaired","Created"],
          array_map('array_values', $rows));
        break;

    case "invoices":
        $q = $conn->query("SELECT i.invoice_no, c.client_name, i.issue_date, i.due_date, i.status, i.currency,
                                  i.subtotal, i.discount_amount, i.tax_rate, i.tax_amount, i.total, i.amount_paid,
                                  (i.total - i.amount_paid) AS balance
                           FROM invoices i LEFT JOIN clients c ON c.id = i.client_id
                           ORDER BY i.id DESC");
        $rows = [];
        while ($r = $q->fetch_assoc()) $rows[] = $r;
        csv_out($filename,
          ["Invoice","Client","Issued","Due","Status","Currency","Subtotal","Discount","VAT %","VAT","Total","Paid","Balance"],
          array_map('array_values', $rows));
        break;

    case "activity":
        if (!is_admin()) die("Admin only.");
        $q = $conn->query("SELECT a.created_at, COALESCE(u.full_name,'—') AS user, u.username, a.action, COALESCE(a.ticket_no,'') AS ticket
                           FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
                           ORDER BY a.id DESC LIMIT 5000");
        $rows = [];
        while ($r = $q->fetch_assoc()) $rows[] = $r;
        csv_out($filename, ["When","User","Username","Action","Ticket"], array_map('array_values', $rows));
        break;

    case "clients":
        $q = $conn->query("SELECT c.id, c.client_name, c.address, c.phone, c.alternate_phone,
                                  (SELECT COUNT(*) FROM reports r WHERE r.client_id=c.id) AS tickets,
                                  (SELECT COUNT(*) FROM invoices i WHERE i.client_id=c.id) AS invoices,
                                  c.created_at
                           FROM clients c ORDER BY c.id DESC");
        $rows = [];
        while ($r = $q->fetch_assoc()) $rows[] = $r;
        csv_out($filename, ["ID","Name","Address","Phone","Alt Phone","Tickets","Invoices","Created"], array_map('array_values', $rows));
        break;

    case "payments":
        $q = $conn->query("SELECT p.paid_on, i.invoice_no, COALESCE(c.client_name,'—') AS client,
                                  p.amount, i.currency, p.method, p.reference, COALESCE(u.full_name,'—') AS recorded_by
                           FROM invoice_payments p
                           LEFT JOIN invoices i ON i.id = p.invoice_id
                           LEFT JOIN clients c ON c.id = i.client_id
                           LEFT JOIN users u ON u.id = p.created_by
                           ORDER BY p.paid_on DESC, p.id DESC");
        $rows = [];
        while ($r = $q->fetch_assoc()) $rows[] = $r;
        csv_out($filename, ["Paid On","Invoice","Client","Amount","Currency","Method","Reference","Recorded By"], array_map('array_values', $rows));
        break;

    default:
        die("Unknown export type.");
}