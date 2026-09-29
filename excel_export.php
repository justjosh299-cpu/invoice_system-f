<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

$type = $_GET["type"] ?? "";

// Set headers for an Excel-format download
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"omg_export_{$type}_" . date("Ymd_His") . ".xls\"");
header("Pragma: no-cache");
header("Expires: 0");

echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
echo '<head><meta charset="utf-8">';
echo '<style>
table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
th { background: #0b2545; color: #fff; padding: 6px 10px; border: 1px solid #0b2545; text-align:left; }
td { padding: 5px 8px; border: 1px solid #cfd8e3; }
.num { text-align:right; }
tr.alt td { background: #f7f9fc; }
.title { font-size: 18px; font-weight: 700; color: #0b2545; margin-bottom: 4px; }
.meta { font-size: 12px; color: #555; margin-bottom: 14px; }
</style></head><body>';

function xls_header($title, $meta = "") {
    echo '<div class="title">' . htmlspecialchars($title) . '</div>';
    if ($meta) echo '<div class="meta">' . htmlspecialchars($meta) . '</div>';
}

switch ($type) {

    case "reports":
        xls_header("Reports Export", "Generated " . date("d M Y H:i"));
        $q = $conn->query("SELECT r.ticket_no, r.device_type, c.client_name, r.computer_make_model,
                           r.serial_number, r.ticket_status, r.reported_date, r.technician_name,
                           r.final_result, r.estimated_cost
                           FROM reports r LEFT JOIN clients c ON c.id=r.client_id
                           ORDER BY r.id DESC");
        echo '<table><tr><th>Ticket</th><th>Device</th><th>Client</th><th>Make/Model</th><th>Serial</th>
              <th>Status</th><th>Reported</th><th>Technician</th><th>Result</th><th class="num">Cost</th></tr>';
        $alt = false;
        while ($r = $q->fetch_assoc()) {
            echo '<tr' . ($alt ? ' class="alt"' : '') . '>';
            echo '<td>' . htmlspecialchars($r["ticket_no"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["device_type"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["client_name"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["computer_make_model"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["serial_number"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["ticket_status"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["reported_date"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["technician_name"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["final_result"]) . '</td>';
            echo '<td class="num">' . number_format((float)$r["estimated_cost"], 2) . '</td>';
            echo '</tr>';
            $alt = !$alt;
        }
        echo '</table>';
        break;

    case "invoices":
        xls_header("Invoices Export", "Generated " . date("d M Y H:i"));
        $q = $conn->query("SELECT i.invoice_no, c.client_name, i.issue_date, i.due_date, i.status,
                           i.currency, i.subtotal, i.tax_amount, i.total, i.amount_paid,
                           (i.total - i.amount_paid) AS balance
                           FROM invoices i LEFT JOIN clients c ON c.id=i.client_id
                           ORDER BY i.id DESC");
        echo '<table><tr><th>Invoice</th><th>Client</th><th>Issued</th><th>Due</th><th>Status</th>
              <th>Currency</th><th class="num">Subtotal</th><th class="num">VAT</th>
              <th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th></tr>';
        $alt = false; $sum = 0;
        while ($r = $q->fetch_assoc()) {
            $sum += (float)$r["balance"];
            echo '<tr' . ($alt ? ' class="alt"' : '') . '>';
            echo '<td>' . htmlspecialchars($r["invoice_no"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["client_name"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["issue_date"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["due_date"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["status"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["currency"]) . '</td>';
            echo '<td class="num">' . number_format($r["subtotal"], 2) . '</td>';
            echo '<td class="num">' . number_format($r["tax_amount"], 2) . '</td>';
            echo '<td class="num">' . number_format($r["total"], 2) . '</td>';
            echo '<td class="num">' . number_format($r["amount_paid"], 2) . '</td>';
            echo '<td class="num">' . number_format($r["balance"], 2) . '</td>';
            echo '</tr>';
            $alt = !$alt;
        }
        echo '<tr><td colspan="10"><b>Total Outstanding</b></td><td class="num"><b>' . number_format($sum, 2) . '</b></td></tr>';
        echo '</table>';
        break;

    case "aging":
        xls_header("Aging Report", "Generated " . date("d M Y H:i"));
        $q = $conn->query("SELECT r.ticket_no, c.client_name, r.device_type, r.computer_make_model,
                           r.ticket_status, r.technician_name, r.reported_date,
                           DATEDIFF(CURDATE(), r.reported_date) AS age_days
                           FROM reports r LEFT JOIN clients c ON c.id=r.client_id
                           WHERE r.ticket_status IN ('OPEN','DIAGNOSIS','AWAITING PARTS','REPAIRED')
                           ORDER BY age_days DESC");
        echo '<table><tr><th>Ticket</th><th>Client</th><th>Device</th><th>Make/Model</th>
              <th>Status</th><th>Technician</th><th>Reported</th><th class="num">Age (days)</th></tr>';
        $alt = false;
        while ($r = $q->fetch_assoc()) {
            echo '<tr' . ($alt ? ' class="alt"' : '') . '>';
            echo '<td>' . htmlspecialchars($r["ticket_no"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["client_name"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["device_type"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["computer_make_model"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["ticket_status"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["technician_name"]) . '</td>';
            echo '<td>' . htmlspecialchars($r["reported_date"]) . '</td>';
            echo '<td class="num">' . (int)$r["age_days"] . '</td>';
            echo '</tr>';
            $alt = !$alt;
        }
        echo '</table>';
        break;

    case "statement":
        $clientId = (int)($_GET["client_id"] ?? 0);
        $from = $_GET["from"] ?? date("Y-m-d", strtotime("-11 months"));
        $to   = $_GET["to"]   ?? date("Y-m-d");

        $s = $conn->prepare("SELECT * FROM clients WHERE id=?");
        $s->bind_param("i", $clientId); $s->execute();
        $c = $s->get_result()->fetch_assoc();
        if (!$c) die("Client not found.");

        xls_header("Statement — " . $c["client_name"],
                   "Period " . date("d M Y", strtotime($from)) . " — " . date("d M Y", strtotime($to)));

        $invs = [];
        $q = $conn->prepare("SELECT invoice_no, issue_date, currency, total FROM invoices WHERE client_id=? AND issue_date BETWEEN ? AND ? ORDER BY issue_date ASC");
        $q->bind_param("iss", $clientId, $from, $to); $q->execute();
        $res = $q->get_result();
        while ($row = $res->fetch_assoc()) $invs[] = ["date"=>$row["issue_date"], "type"=>"Invoice", "ref"=>$row["invoice_no"], "debit"=>(float)$row["total"], "credit"=>0.0, "currency"=>$row["currency"]];

        $q2 = $conn->prepare("SELECT p.paid_on, p.amount, p.method, i.invoice_no, i.currency
                              FROM invoice_payments p LEFT JOIN invoices i ON i.id=p.invoice_id
                              WHERE i.client_id=? AND p.paid_on BETWEEN ? AND ? ORDER BY p.paid_on ASC");
        $q2->bind_param("iss", $clientId, $from, $to); $q2->execute();
        $res2 = $q2->get_result();
        while ($row = $res2->fetch_assoc()) $invs[] = ["date"=>$row["paid_on"], "type"=>"Payment", "ref"=>$row["invoice_no"] . " (" . $row["method"] . ")", "debit"=>0.0, "credit"=>(float)$row["amount"], "currency"=>$row["currency"]];

        usort($invs, function($a,$b){ return strcmp($a["date"], $b["date"]); });

        echo '<table><tr><th>Date</th><th>Type</th><th>Reference</th>
              <th class="num">Invoice</th><th class="num">Payment</th><th class="num">Balance</th></tr>';
        $bal = 0;
        foreach ($invs as $e) {
            $bal += $e["debit"] - $e["credit"];
            echo '<tr>';
            echo '<td>' . htmlspecialchars($e["date"]) . '</td>';
            echo '<td>' . htmlspecialchars($e["type"]) . '</td>';
            echo '<td>' . htmlspecialchars($e["ref"]) . '</td>';
            echo '<td class="num">' . ($e["debit"]>0 ? number_format($e["debit"],2) : "—") . '</td>';
            echo '<td class="num">' . ($e["credit"]>0 ? number_format($e["credit"],2) : "—") . '</td>';
            echo '<td class="num">' . number_format($bal, 2) . '</td>';
            echo '</tr>';
        }
        echo '<tr><td colspan="5"><b>Closing Balance</b></td><td class="num"><b>' . number_format($bal, 2) . '</b></td></tr>';
        echo '</table>';
        break;

    default:
        echo '<p>Unknown export type.</p>';
}
echo '</body></html>';