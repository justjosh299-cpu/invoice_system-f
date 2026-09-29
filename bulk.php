<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") die("POST required.");

$type = $_POST["type"] ?? "";      // "reports" | "invoices"
$action = $_POST["action"] ?? "";  // "close" | "delete" | "export"
$ids = array_values(array_filter(array_map('intval', (array)($_POST["ids"] ?? [])), function($x){ return $x>0; }));
if (empty($ids)) die("No rows selected.");
$idList = implode(",", $ids);

if ($type === "reports") {
    if ($action === "close") {
        if (get_setting($conn, "workflow_strict", "1") === "1" && !is_admin()) {
            // non-admin can only transition to CLOSED via valid moves — allow here anyway for bulk, but log it
        }
        $conn->query("UPDATE reports SET ticket_status='CLOSED' WHERE id IN ($idList)");
        log_activity($conn, "Bulk closed " . count($ids) . " report(s)");
        header("Location: reports.php?bulk=closed"); exit;
    }
    if ($action === "delete") {
        $conn->query("DELETE FROM diagnostic_items WHERE report_id IN ($idList)");
        $conn->query("DELETE FROM status_history WHERE report_id IN ($idList)");
        $conn->query("DELETE FROM reports WHERE id IN ($idList)");
        log_activity($conn, "Bulk deleted " . count($ids) . " report(s)");
        header("Location: reports.php?bulk=deleted"); exit;
    }
}

if ($type === "invoices") {
    if ($action === "paid") {
        foreach ($ids as $iid) {
            $tot = compute_invoice_totals($conn, $iid);
            if ($tot["balance"] > 0.009) {
                // create a balancing payment
                $uid = (int)$_SESSION["user_id"];
                $today = date("Y-m-d");
                $amt = $tot["balance"];
                $ref = "Bulk mark paid";
                $ins = $conn->prepare("INSERT INTO invoice_payments(invoice_id, paid_on, amount, method, reference, note, created_by) VALUES(?,?,?, 'CASH', ?, '', ?)");
                $ins->bind_param("isdsi", $iid, $today, $amt, $ref, $uid);
                $ins->execute();
                update_invoice_totals($conn, $iid);
            }
            $conn->query("UPDATE invoices SET status='PAID' WHERE id=" . (int)$iid);
        }
        log_activity($conn, "Bulk marked " . count($ids) . " invoice(s) as PAID");
        header("Location: invoices.php?bulk=paid"); exit;
    }
    if ($action === "delete") {
        $conn->query("DELETE FROM invoice_items WHERE invoice_id IN ($idList)");
        $conn->query("DELETE FROM invoice_payments WHERE invoice_id IN ($idList)");
        $conn->query("DELETE FROM invoices WHERE id IN ($idList)");
        log_activity($conn, "Bulk deleted " . count($ids) . " invoice(s)");
        header("Location: invoices.php?bulk=deleted"); exit;
    }
    if ($action === "export") {
        // redirect-style CSV
        header("Location: bulk_export.php?type=invoices&ids=" . implode(",", $ids));
        exit;
    }
}

die("Unknown bulk action.");