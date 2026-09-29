<?php
/**
 * Invoice + quotation + parts helper functions.
 * Included automatically from config.php via require_once.
 * Do NOT call directly.
 */

if (!function_exists('next_quote_no')) {
    function next_quote_no($conn) {
        $prefix = get_setting($conn, "quote_prefix", "QUO") ?: "QUO";
        $p = $prefix . "-" . date("Y") . "-";
        $q = $conn->query("SELECT quote_no FROM quotations WHERE quote_no LIKE '" . $conn->real_escape_string($p) . "%' ORDER BY id DESC LIMIT 1");
        $n = 1;
        if ($q && $q->num_rows) $n = (int)substr($q->fetch_assoc()["quote_no"], -5) + 1;
        return $p . str_pad($n, 5, "0", STR_PAD_LEFT);
    }
}

if (!function_exists('compute_quote_totals')) {
    function compute_quote_totals($conn, $quoteId) {
        $rows = $conn->query("SELECT quantity, unit_price FROM quotation_items WHERE quote_id=" . (int)$quoteId);
        $subtotal = 0.0;
        while ($r = $rows->fetch_assoc()) $subtotal += (float)$r["quantity"] * (float)$r["unit_price"];
        $s = $conn->prepare("SELECT discount_amount, tax_rate FROM quotations WHERE id=?");
        $s->bind_param("i", $quoteId); $s->execute();
        $q = $s->get_result()->fetch_assoc() ?: ["discount_amount"=>0, "tax_rate"=>0];
        $discount = (float)$q["discount_amount"];
        $taxRate  = (float)$q["tax_rate"];
        $taxable  = max(0, $subtotal - $discount);
        $tax      = round($taxable * $taxRate / 100, 2);
        $total    = round($taxable + $tax, 2);
        return [
            "subtotal" => round($subtotal, 2),
            "discount" => round($discount, 2),
            "tax_rate" => $taxRate,
            "tax"      => $tax,
            "total"    => $total,
        ];
    }
}

if (!function_exists('update_quote_totals')) {
    function update_quote_totals($conn, $quoteId) {
        $t = compute_quote_totals($conn, $quoteId);
        $s = $conn->prepare("UPDATE quotations SET subtotal=?, tax_amount=?, total=? WHERE id=?");
        $s->bind_param("dddi", $t["subtotal"], $t["tax"], $t["total"], $quoteId);
        $s->execute();
        return $t;
    }
}

if (!function_exists('part_move')) {
    /**
     * Record a parts movement and update the running quantity.
     * $delta is positive for stock in, negative for stock out.
     */
    function part_move($conn, $partId, $delta, $reason = 'adjust', $refType = null, $refId = null, $note = "") {
        $uid = (int)($_SESSION["user_id"] ?? 0);
        $s = $conn->prepare("INSERT INTO part_movements(part_id, delta, reason, reference_type, reference_id, note, user_id) VALUES(?,?,?,?,?,?,?)");
        $s->bind_param("iissisi", $partId, $delta, $reason, $refType, $refId, $note, $uid);
        $s->execute();
        $u = $conn->prepare("UPDATE parts SET quantity = quantity + ? WHERE id=?");
        $u->bind_param("ii", $delta, $partId);
        $u->execute();
    }
}

if (!function_exists('parts_low_stock')) {
    function parts_low_stock($conn) {
        $out = [];
        $q = $conn->query("SELECT * FROM parts WHERE active=1 AND quantity <= reorder_level ORDER BY quantity ASC, part_name ASC");
        while ($r = $q->fetch_assoc()) $out[] = $r;
        return $out;
    }
}

if (!function_exists('run_due_recurring_invoices')) {
    /**
     * Called from index.php (or a cron). Generates invoices for any
     * recurring schedules whose next_run <= today.
     * Returns number of invoices generated.
     */
    function run_due_recurring_invoices($conn) {
        $today = date("Y-m-d");
        $q = $conn->prepare("SELECT * FROM recurring_invoices WHERE active=1 AND next_run <= ?");
        $q->bind_param("s", $today); $q->execute();
        $rows = $q->get_result();
        $count = 0;
        while ($rec = $rows->fetch_assoc()) {
            // Build a fresh invoice
            $invoiceNo = next_invoice_no($conn);
            $issueDate = $today;
            $dueDate = date("Y-m-d", strtotime("+14 days"));
            $currency = $rec["currency"] ?: "UGX";
            $taxRate = (float)$rec["tax_rate"];
            $uid = 0;

            $ins = $conn->prepare("INSERT INTO invoices(invoice_no, report_id, client_id, issue_date, due_date, status, currency,
                                   discount_amount, tax_rate, notes, terms, technician_name, created_by)
                                   VALUES(?,NULL,?,?,?, 'SENT', ?, 0, ?, ?, ?, ?, ?)");
            $notes = "Auto-generated from recurring schedule: " . $rec["title"];
            $terms = get_setting($conn, "invoice_terms", "");
            $tech = "Recurring";
            $ins->bind_param("sissdsssi", $invoiceNo, $rec["client_id"], $issueDate, $dueDate, $currency, $taxRate, $notes, $terms, $tech, $uid);
            $ins->execute();
            $invoiceId = $ins->insert_id;

            $desc = $rec["description"] ?: $rec["title"];
            $qty = 1; $price = (float)$rec["amount"]; $line = $price;
            $it = $conn->prepare("INSERT INTO invoice_items(invoice_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
            $it->bind_param("isdid", $invoiceId, $desc, $qty, $price, $line);
            $it->execute();

            update_invoice_totals($conn, $invoiceId);

            // Next run
            $next = date("Y-m-d", strtotime($rec["next_run"] . " +1 " . $rec["frequency"]));
            $up = $conn->prepare("UPDATE recurring_invoices SET next_run=?, last_run=? WHERE id=?");
            $up->bind_param("ssi", $next, $today, $rec["id"]);
            $up->execute();

            log_activity($conn, "Auto-generated invoice $invoiceNo from recurring: " . $rec["title"]);
            $count++;
        }
        return $count;
    }
}

if (!function_exists('quote_to_invoice')) {
    /**
     * Convert an accepted quotation to an invoice. Returns new invoice id.
     */
    function quote_to_invoice($conn, $quoteId) {
        $s = $conn->prepare("SELECT * FROM quotations WHERE id=?");
        $s->bind_param("i", $quoteId); $s->execute();
        $q = $s->get_result()->fetch_assoc();
        if (!$q) return null;

        $items = $conn->query("SELECT * FROM quotation_items WHERE quote_id=" . (int)$quoteId)->fetch_all(MYSQLI_ASSOC);
        $tot = compute_quote_totals($conn, $quoteId);
        $invoiceNo = next_invoice_no($conn);
        $issueDate = date("Y-m-d");
        $dueDate = date("Y-m-d", strtotime("+14 days"));
        $uid = (int)($_SESSION["user_id"] ?? 0);
        $tech = $_SESSION["name"] ?? "";
        $notes = "Converted from quotation " . $q["quote_no"] . ($q["notes"] ? (" — " . $q["notes"]) : "");
        $terms = $q["terms"] ?: get_setting($conn, "invoice_terms", "");

        $ins = $conn->prepare("INSERT INTO invoices(invoice_no, report_id, client_id, issue_date, due_date, status, currency,
                               discount_amount, tax_rate, notes, terms, technician_name, created_by)
                               VALUES(?,?,?,?,?,'SENT',?,?,?,?,?,?,?)");
        $ins->bind_param("siisssddsssi",
            $invoiceNo, $q["report_id"], $q["client_id"], $issueDate, $dueDate, $q["currency"],
            $q["discount_amount"], $q["tax_rate"], $notes, $terms, $tech, $uid);
        $ins->execute();
        $invoiceId = $ins->insert_id;

        foreach ($items as $it) {
            $d = $it["description"]; $qty = (float)$it["quantity"]; $p = (float)$it["unit_price"];
            $ln = $qty * $p;
            $x = $conn->prepare("INSERT INTO invoice_items(invoice_id, description, quantity, unit_price, line_total) VALUES(?,?,?,?,?)");
            $x->bind_param("isdid", $invoiceId, $d, $qty, $p, $ln);
            $x->execute();
        }
        update_invoice_totals($conn, $invoiceId);

        $u = $conn->prepare("UPDATE quotations SET status='CONVERTED', invoice_id=? WHERE id=?");
        $u->bind_param("ii", $invoiceId, $quoteId); $u->execute();

        log_activity($conn, "Converted quote " . $q["quote_no"] . " to invoice " . $invoiceNo);
        return $invoiceId;
    }
}