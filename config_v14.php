<?php
/**
 * v14 helpers — deposits, aging buckets, cost worksheet math.
 * Loaded automatically from config.php.
 */

if (!function_exists('next_cost_worksheet_ref')) {
    function next_cost_worksheet_ref() {
        return "CW-" . date("Ymd-His") . "-" . substr(bin2hex(random_bytes(3)), 0, 5);
    }
}

if (!function_exists('deposits_for_report')) {
    function deposits_for_report($conn, $reportId) {
        $out = [];
        $s = $conn->prepare("SELECT * FROM deposits WHERE report_id=? AND applied=0 ORDER BY paid_on ASC, id ASC");
        $s->bind_param("i", $reportId); $s->execute();
        $r = $s->get_result();
        while ($row = $r->fetch_assoc()) $out[] = $row;
        return $out;
    }
}
if (!function_exists('deposit_total_for_report')) {
    function deposit_total_for_report($conn, $reportId) {
        $s = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM deposits WHERE report_id=? AND applied=0");
        $s->bind_param("i", $reportId); $s->execute();
        return (float)$s->get_result()->fetch_assoc()["s"];
    }
}
if (!function_exists('deposit_total_unapplied_for_client')) {
    function deposit_total_unapplied_for_client($conn, $clientId) {
        $s = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM deposits WHERE client_id=? AND applied=0");
        $s->bind_param("i", $clientId); $s->execute();
        return (float)$s->get_result()->fetch_assoc()["s"];
    }
}
if (!function_exists('apply_deposits_to_invoice')) {
    /**
     * After a new invoice is created, apply any unapplied deposits for the
     * same report/client as payment rows on that invoice.
     */
    function apply_deposits_to_invoice($conn, $invoiceId, $reportId, $clientId) {
        $applied = 0.0;
        // First: deposits tied to this report
        if ($reportId) {
            $s = $conn->prepare("SELECT * FROM deposits WHERE report_id=? AND applied=0");
            $s->bind_param("i", $reportId); $s->execute();
            $rows = $s->get_result();
            while ($d = $rows->fetch_assoc()) {
                $uid = (int)($d["created_by"] ?? 0);
                $ref = "Deposit #" . $d["id"];
                $ins = $conn->prepare("INSERT INTO invoice_payments(invoice_id, paid_on, amount, method, reference, note, created_by) VALUES(?,?,?,?,?,'Auto-applied deposit',?)");
                $ins->bind_param("isdssi", $invoiceId, $d["paid_on"], $d["amount"], $d["method"], $ref, $uid);
                $ins->execute();
                $conn->query("UPDATE deposits SET applied=1, invoice_id=" . (int)$invoiceId . " WHERE id=" . (int)$d["id"]);
                $applied += (float)$d["amount"];
            }
        }
        // Then: client-level deposits not tied to any report
        if ($clientId) {
            $s = $conn->prepare("SELECT * FROM deposits WHERE client_id=? AND applied=0 AND (report_id IS NULL OR report_id=0)");
            $s->bind_param("i", $clientId); $s->execute();
            $rows = $s->get_result();
            while ($d = $rows->fetch_assoc()) {
                $uid = (int)($d["created_by"] ?? 0);
                $ref = "Deposit #" . $d["id"];
                $ins = $conn->prepare("INSERT INTO invoice_payments(invoice_id, paid_on, amount, method, reference, note, created_by) VALUES(?,?,?,?,?,'Auto-applied deposit',?)");
                $ins->bind_param("isdssi", $invoiceId, $d["paid_on"], $d["amount"], $d["method"], $ref, $uid);
                $ins->execute();
                $conn->query("UPDATE deposits SET applied=1, invoice_id=" . (int)$invoiceId . " WHERE id=" . (int)$d["id"]);
                $applied += (float)$d["amount"];
            }
        }
        if ($applied > 0) update_invoice_totals($conn, $invoiceId);
        return $applied;
    }
}

if (!function_exists('aging_buckets')) {
    function aging_buckets() {
        return [
            "0-3"   => ["label" => "0 – 3 days",   "min" => 0,   "max" => 3],
            "4-7"   => ["label" => "4 – 7 days",   "min" => 4,   "max" => 7],
            "8-14"  => ["label" => "8 – 14 days",  "min" => 8,   "max" => 14],
            "15-30" => ["label" => "15 – 30 days", "min" => 15,  "max" => 30],
            "30+"   => ["label" => "30+ days",     "min" => 31,  "max" => 99999],
        ];
    }
}

if (!function_exists('log_login_attempt')) {
    function log_login_attempt($conn, $userId, $username, $success) {
        $ip = $_SERVER["REMOTE_ADDR"] ?? "";
        $ua = substr($_SERVER["HTTP_USER_AGENT"] ?? "", 0, 250);
        $s = $conn->prepare("INSERT INTO login_audit(user_id, username, ip, user_agent, success) VALUES(?,?,?,?,?)");
        $s->bind_param("isssi", $userId, $username, $ip, $ua, $success);
        $s->execute();
    }
}