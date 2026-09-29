<?php
$db_host = "localhost";
$db_name = "omg_diagnostics";
$db_user = "root";
$db_pass = "";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Database connection failed: " . htmlspecialchars($conn->connect_error));
}
$conn->set_charset("utf8mb4");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- v12.2 helpers (quotations, parts, recurring) ---------- */
if (file_exists(__DIR__ . '/config_invoice_helpers.php')) {
    require_once __DIR__ . '/config_invoice_helpers.php';
}

/* ---------- v12.3 helpers (2FA, branches, notifications, theme, API) ---------- */
if (file_exists(__DIR__ . '/config_v123.php')) {
    require_once __DIR__ . '/config_v123.php';
}

/* ---------- v14 helpers (deposits, aging, cost worksheet, login audit) ---------- */
if (file_exists(__DIR__ . '/config_v14.php')) {
    require_once __DIR__ . '/config_v14.php';
}

/* ---------- v14.2 helpers (brand colour, theme, login audit) ---------- */
if (file_exists(__DIR__ . '/config_v142.php')) {
    require_once __DIR__ . '/config_v142.php';
}

/* ---------- Settings helpers ---------- */
function get_setting($conn, $key, $default = "") {
    $s = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
    if (!$s) return $default;
    $s->bind_param("s", $key); $s->execute();
    $r = $s->get_result()->fetch_assoc();
    return $r ? $r["setting_value"] : $default;
}
function set_setting($conn, $key, $value) {
    $s = $conn->prepare("INSERT INTO settings(setting_key, setting_value) VALUES(?,?)
                         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $s->bind_param("ss", $key, $value); $s->execute();
}
function log_activity($conn, $action, $ticket = null) {
    if (!isset($_SESSION["user_id"])) return;
    $uid = (int)$_SESSION["user_id"];
    $s = $conn->prepare("INSERT INTO activity_log(user_id, action, ticket_no) VALUES(?,?,?)");
    $s->bind_param("iss", $uid, $action, $ticket); $s->execute();
}
function is_admin() { return (($_SESSION["role"] ?? "") === "admin"); }

/**
 * Absolute path to the backups folder (outside webroot).
 * Falls back to a local /backup folder if the outside path isn't writable.
 */
function backup_dir() {
    $outside = dirname(__DIR__, 2) . '/backups';
    @mkdir($outside, 0777, true);
    if (is_dir($outside) && is_writable($outside)) return $outside;

    $local = __DIR__ . '/backup';
    @mkdir($local, 0777, true);
    return $local;
}

function base_url($path = "") {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . "://" . $host . $dir . "/" . ltrim($path, "/");
}

function get_header_mode($conn) {
    $m = get_setting($conn, "header_mode", "classic");
    return $m === "banner" ? "banner" : "classic";
}
function has_upload($conn, $key) {
    $p = get_setting($conn, $key, "");
    return $p && file_exists(__DIR__ . "/" . $p);
}

/* ---------- Report status workflow ---------- */
function allowed_transitions() {
    return [
        "OPEN"           => ["DIAGNOSIS","CLOSED"],
        "DIAGNOSIS"      => ["AWAITING PARTS","REPAIRED","CLOSED"],
        "AWAITING PARTS" => ["DIAGNOSIS","REPAIRED","CLOSED"],
        "REPAIRED"       => ["CLOSED","DIAGNOSIS"],
        "CLOSED"         => [],
    ];
}
function can_transition($from, $to, $isAdmin) {
    if ($from === $to) return true;
    if ($isAdmin && $from === "CLOSED" && $to === "DIAGNOSIS") return true;
    $map = allowed_transitions();
    return isset($map[$from]) && in_array($to, $map[$from], true);
}
function valid_next_statuses($from, $isAdmin) {
    $map = allowed_transitions();
    $list = $map[$from] ?? [];
    if ($isAdmin && $from === "CLOSED") $list[] = "DIAGNOSIS";
    if (empty($list)) $list = [$from];
    array_unshift($list, $from);
    return array_values(array_unique($list));
}
function record_status_change($conn, $reportId, $old, $new, $note = "") {
    if ($old === $new) return;
    $uid = (int)($_SESSION["user_id"] ?? 0);
    $s = $conn->prepare("INSERT INTO status_history(report_id, old_status, new_status, changed_by, note) VALUES(?,?,?,?,?)");
    $s->bind_param("issis", $reportId, $old, $new, $uid, $note);
    $s->execute();
}

/* ---------- SMS ---------- */
function send_sms($conn, $phone, $message, $ticketNo = null) {
    $enabled = get_setting($conn, "sms_enabled", "0") === "1";
    if (!$enabled) return ["ok"=>false, "err"=>"SMS disabled in settings."];
    if (trim($phone) === "") return ["ok"=>false, "err"=>"No phone number."];
    $provider = get_setting($conn, "sms_provider", "africastalking");
    $username = get_setting($conn, "sms_username", "");
    $apiKey   = get_setting($conn, "sms_api_key", "");
    $sender   = get_setting($conn, "sms_sender", "");
    $urlOverride = get_setting($conn, "sms_url", "");
    if (!function_exists("curl_init")) return ["ok"=>false, "err"=>"cURL unavailable."];
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (strpos($phone, '0') === 0) $phone = '+256' . substr($phone, 1);
    if ($provider === "africastalking") {
        if ($username === "" || $apiKey === "") return ["ok"=>false, "err"=>"AT credentials missing."];
        $url = "https://api.africastalking.com/version1/messaging";
        $post = http_build_query(["username"=>$username,"to"=>$phone,"message"=>$message,"from"=>$sender ?: null]);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["apiKey: $apiKey","Accept: application/json"]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $ok = ($code >= 200 && $code < 300);
        log_activity($conn, ($ok?"SMS sent":"SMS failed")." to $phone", $ticketNo);
        return ["ok"=>$ok, "err"=>$ok?"":("HTTP $code"), "resp"=>$resp];
    }
    if ($urlOverride === "") return ["ok"=>false, "err"=>"Custom SMS URL not set."];
    $payload = json_encode(["to"=>$phone,"message"=>$message,"sender"=>$sender,"username"=>$username,"api_key"=>$apiKey]);
    $ch = curl_init($urlOverride);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $ok = ($code >= 200 && $code < 300);
    log_activity($conn, ($ok?"SMS sent":"SMS failed")." to $phone", $ticketNo);
    return ["ok"=>$ok, "err"=>$ok?"":("HTTP $code"), "resp"=>$resp];
}

function portal_rate_limit($conn, $ip) {
    $key = "portal_rl_" . md5($ip);
    $raw = get_setting($conn, $key, "");
    $now = time();
    $data = $raw ? json_decode($raw, true) : ["count"=>0, "start"=>$now];
    if (!is_array($data) || ($now - ($data["start"] ?? 0)) > 900) $data = ["count"=>0, "start"=>$now];
    if (($data["count"] ?? 0) >= 10) return false;
    $data["count"] = ($data["count"] ?? 0) + 1;
    set_setting($conn, $key, json_encode($data));
    return true;
}

/* ---------- Invoice helpers ---------- */
function next_invoice_no($conn) {
    $prefix = get_setting($conn, "invoice_prefix", "INV") ?: "INV";
    $p = $prefix . "-" . date("Y") . "-";
    $q = $conn->query("SELECT invoice_no FROM invoices WHERE invoice_no LIKE '" . $conn->real_escape_string($p) . "%' ORDER BY id DESC LIMIT 1");
    $n = 1;
    if ($q && $q->num_rows) $n = (int)substr($q->fetch_assoc()["invoice_no"], -5) + 1;
    return $p . str_pad($n, 5, "0", STR_PAD_LEFT);
}

function invoice_line_total($item) {
    return (float)$item["quantity"] * (float)$item["unit_price"];
}

function compute_invoice_totals($conn, $invoiceId) {
    $rows = $conn->query("SELECT quantity, unit_price FROM invoice_items WHERE invoice_id=" . (int)$invoiceId);
    $subtotal = 0.0;
    while ($r = $rows->fetch_assoc()) {
        $subtotal += (float)$r["quantity"] * (float)$r["unit_price"];
    }
    $s = $conn->prepare("SELECT discount_amount, tax_rate FROM invoices WHERE id=?");
    $s->bind_param("i", $invoiceId); $s->execute();
    $inv = $s->get_result()->fetch_assoc() ?: ["discount_amount"=>0, "tax_rate"=>0];
    $discount = (float)$inv["discount_amount"];
    $taxRate  = (float)$inv["tax_rate"];
    $taxable  = max(0, $subtotal - $discount);
    $tax      = round($taxable * $taxRate / 100, 2);
    $total    = round($taxable + $tax, 2);
    $paidRow = $conn->query("SELECT COALESCE(SUM(amount),0) s FROM invoice_payments WHERE invoice_id=" . (int)$invoiceId)->fetch_assoc();
    $paid = (float)$paidRow["s"];
    $balance = round($total - $paid, 2);
    return [
        "subtotal" => round($subtotal, 2),
        "discount" => round($discount, 2),
        "tax_rate" => $taxRate,
        "tax"      => $tax,
        "total"    => $total,
        "paid"     => round($paid, 2),
        "balance"  => $balance,
    ];
}

function update_invoice_totals($conn, $invoiceId) {
    $t = compute_invoice_totals($conn, $invoiceId);
    $s = $conn->prepare("UPDATE invoices SET subtotal=?, tax_amount=?, total=?, amount_paid=? WHERE id=?");
    $s->bind_param("ddddi", $t["subtotal"], $t["tax"], $t["total"], $t["paid"], $invoiceId);
    $s->execute();
    return $t;
}

function effective_invoice_status($row) {
    $s = $row["status"];
    if (in_array($s, ["PAID","CANCELLED","DRAFT"], true)) return $s;
    if (!empty($row["due_date"]) && strtotime($row["due_date"]) < strtotime(date("Y-m-d"))) return "OVERDUE";
    return $s;
}

/* ---------- TinyQR ---------- */
class TinyQR {
    private $size = 0;
    private $modules = [];
    private $version = 0;
    private $codewords = [];
    public static function encode($text, $ecc = 'M') { $q = new self(); $q->make($text, $ecc); return $q; }
    private function make($text, $ecc) {
        $data = array_values(unpack('C*', $text));
        $eccIdx = ['L'=>0,'M'=>1,'Q'=>2,'H'=>3][$ecc] ?? 1;
        $version = 1;
        for ($v = 1; $v <= 40; $v++) {
            $cap = $this->capacityBytes($v, $eccIdx);
            $bitLen = 4 + $this->charCountBits($v);
            if ($bitLen + count($data) * 8 <= $cap * 8) { $version = $v; break; }
        }
        $this->version = $version;
        $size = 17 + 4 * $version;
        $this->size = $size;
        $this->modules = array_fill(0, $size, array_fill(0, $size, 0));
        $bits = [];
        $this->appendBits($bits, 0b0100, 4);
        $this->appendBits($bits, count($data), $this->charCountBits($version));
        foreach ($data as $b) $this->appendBits($bits, $b, 8);
        $dataCapBits = $this->capacityBytes($version, $eccIdx) * 8;
        $terminator = min(4, $dataCapBits - count($bits));
        $this->appendBits($bits, 0, $terminator);
        while (count($bits) % 8 !== 0) $bits[] = 0;
        for ($pad = 0xEC; count($bits) < $dataCapBits; $pad ^= 0xEC ^ 0x11) $this->appendBits($bits, $pad, 8);
        $dataCodewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $b = 0; for ($j = 0; $j < 8; $j++) $b = ($b << 1) | $bits[$i+$j];
            $dataCodewords[] = $b;
        }
        $eccPerBlock = [0,-1,7,10,15,20,26,18,20,24,30,18,20,24,26,30,22,24,28,30,28,28,28,28,30,30,26,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30][$version];
        $numBlocks = [0,-1,1,1,1,1,1,2,2,2,2,4,4,4,4,4,6,6,6,6,7,8,8,9,9,10,12,12,12,13,14,15,16,17,18,19,19,20,21,22,24,25][$version];
        $totalCodewords = intdiv($dataCapBits, 8);
        $dataPerBlock = intdiv($totalCodewords, $numBlocks);
        $blocks = []; $offset = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $block = array_slice($dataCodewords, $offset, $dataPerBlock);
            $offset += $dataPerBlock;
            $blocks[] = ['d'=>$block, 'e'=>$this->rsEncode($block, $eccPerBlock)];
        }
        $final = [];
        $maxD = max(array_map(function($b){return count($b['d']);}, $blocks));
        for ($i = 0; $i < $maxD; $i++) foreach ($blocks as $b) if (isset($b['d'][$i])) $final[] = $b['d'][$i];
        for ($i = 0; $i < $eccPerBlock; $i++) foreach ($blocks as $b) if (isset($b['e'][$i])) $final[] = $b['e'][$i];
        $this->drawFunctionPatterns($eccIdx);
        $this->codewords = $final;
        $this->chooseMask($eccIdx);
    }
    private function capacityBytes($v, $eccIdx) {
        $t = [
            1=>[[19,7,1],[16,10,1],[13,13,1],[9,17,1]],
            2=>[[34,10,1],[28,16,1],[22,22,1],[16,28,1]],
            3=>[[55,15,1],[44,26,1],[34,18,2],[26,22,2]],
            4=>[[80,20,1],[64,18,2],[48,26,2],[36,16,4]],
            5=>[[108,26,1],[86,24,2],[62,18,2,2],[46,22,2,2]],
            6=>[[136,18,2],[108,16,4],[76,24,4],[60,28,4]],
            7=>[[156,20,2],[124,18,4],[88,18,2,4],[66,26,4,1]],
            8=>[[194,24,2],[154,22,2,2],[110,22,4,2],[86,26,4,2]],
            9=>[[232,30,2],[182,22,3,2],[132,20,4,4],[100,24,4,4]],
            10=>[[274,18,2,2],[216,26,4,1],[154,24,6,2],[122,28,6,2]],
        ];
        $v = min($v, 10); $row = $t[$v] ?? $t[10];
        return $row[$eccIdx][0];
    }
    private function appendBits(&$b, $v, $l) { for ($i = $l - 1; $i >= 0; $i--) $b[] = ($v >> $i) & 1; }
    private function charCountBits($v) { return $v <= 9 ? 8 : 16; }
    private function rsEncode($data, $eccLen) {
        $gen = $this->rsGenPoly($eccLen);
        $res = array_fill(0, $eccLen, 0);
        foreach ($data as $b) {
            $factor = $b ^ $res[0]; array_shift($res); $res[] = 0;
            for ($i = 0; $i < $eccLen; $i++) $res[$i] ^= $this->gfMul($gen[$i+1], $factor);
        }
        return $res;
    }
    private function rsGenPoly($deg) {
        $r = [1];
        for ($i = 0; $i < $deg; $i++) {
            $n = array_fill(0, count($r) + 1, 0);
            foreach ($r as $j => $c) { $n[$j] ^= $this->gfMul($c,1); $n[$j+1] ^= $this->gfMul($c, $this->gfPow(2,$i)); }
            $r = $n;
        }
        return $r;
    }
    private function gfMul($a, $b) { $r = 0; for ($i = 7; $i >= 0; $i--) { $r = ($r << 1) ^ (($r >> 7) * 0x11D); $r ^= (($b >> $i) & 1) * $a; } return $r & 0xFF; }
    private function gfPow($x, $n) { $r = 1; for ($i = 0; $i < $n; $i++) $r = $this->gfMul($r, $x); return $r; }
    private function drawFunctionPatterns($eccIdx) {
        $s = $this->size;
        foreach ([[0,0],[1,0],[0,1]] as $p) {
            $x = $p[0]*($s-7); $y = $p[1]*($s-7);
            for ($dy = -1; $dy <= 7; $dy++) for ($dx = -1; $dx <= 7; $dx++) {
                $xx = $x+$dx; $yy = $y+$dy;
                if ($xx < 0 || $xx >= $s || $yy < 0 || $yy >= $s) continue;
                $v = (($dx>=0&&$dx<=6&&($dy===0||$dy===6))||($dy>=0&&$dy<=6&&($dx===0||$dx===6))||($dx>=2&&$dx<=4&&$dy>=2&&$dy<=4))?1:0;
                $this->modules[$yy][$xx] = $v;
            }
        }
        for ($i = 0; $i < $s; $i++) { $this->modules[6][$i] = ($i%2===0)?1:0; $this->modules[$i][6] = ($i%2===0)?1:0; }
    }
    private function chooseMask($eccIdx) {
        $s = $this->size; $bitIdx = 0; $totalBits = count($this->codewords) * 8;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) for ($j = 0; $j < 2; $j++) {
                $x = $right - $j; $upward = (($right + 1) & 2) === 0;
                $y = $upward ? $s - 1 - $vert : $vert;
                if ($this->modules[$y][$x] === 0) {
                    $bit = 0;
                    if ($bitIdx < $totalBits) { $byte = $this->codewords[$bitIdx >> 3]; $bit = ($byte >> (7 - ($bitIdx & 7))) & 1; $bitIdx++; }
                    $mask = (($x + $y) % 2 === 0) ? 1 : 0;
                    $this->modules[$y][$x] = ($bit ^ $mask) ? 2 : 3;
                }
            }
        }
    }
    public function toPngDataUri($scale = 4, $margin = 4) {
        $s = $this->size; $px = ($s + $margin*2) * $scale;
        if (!function_exists('imagecreatetruecolor')) return null;
        $im = imagecreatetruecolor($px, $px);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 11, 37, 69);
        imagefilledrectangle($im, 0, 0, $px, $px, $white);
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) {
            $v = $this->modules[$y][$x];
            if ($v === 2 || $v === 1) {
                $x1 = ($x+$margin)*$scale; $y1 = ($y+$margin)*$scale;
                imagefilledrectangle($im, $x1, $y1, $x1+$scale-1, $y1+$scale-1, $black);
            }
        }
        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        return "data:image/png;base64," . base64_encode($png);
    }
}