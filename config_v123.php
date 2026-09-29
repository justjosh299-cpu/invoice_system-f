<?php
/**
 * v12.3 helper bundle — loaded automatically from config.php.
 */

/* ---------- TOTP (Google Authenticator compatible) ---------- */
if (!class_exists('TOTP')) {
    class TOTP {
        private static $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        public static function randomSecret($length = 20) {
            $bytes = random_bytes($length);
            return self::base32Encode($bytes);
        }

        public static function base32Encode($data) {
            $out = '';
            $bits = 0; $value = 0;
            for ($i = 0; $i < strlen($data); $i++) {
                $value = ($value << 8) | ord($data[$i]);
                $bits += 8;
                while ($bits >= 5) {
                    $bits -= 5;
                    $out .= self::$base32[($value >> $bits) & 31];
                }
            }
            if ($bits > 0) $out .= self::$base32[($value << (5 - $bits)) & 31];
            return $out;
        }

        public static function base32Decode($secret) {
            $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
            $bits = 0; $value = 0; $out = '';
            for ($i = 0; $i < strlen($secret); $i++) {
                $idx = strpos(self::$base32, $secret[$i]);
                if ($idx === false) continue;
                $value = ($value << 5) | $idx;
                $bits += 5;
                if ($bits >= 8) {
                    $bits -= 8;
                    $out .= chr(($value >> $bits) & 0xFF);
                }
            }
            return $out;
        }

        public static function code($secret, $timeSlice = null) {
            if ($timeSlice === null) $timeSlice = floor(time() / 30);
            $key = self::base32Decode($secret);
            $data = pack('N*', 0) . pack('N*', $timeSlice);
            $hash = hash_hmac('sha1', $data, $key, true);
            $offset = ord($hash[19]) & 0x0F;
            $code = (
                ((ord($hash[$offset])   & 0x7F) << 24) |
                ((ord($hash[$offset+1]) & 0xFF) << 16) |
                ((ord($hash[$offset+2]) & 0xFF) << 8)  |
                ( ord($hash[$offset+3]) & 0xFF)
            ) % 1000000;
            return str_pad($code, 6, '0', STR_PAD_LEFT);
        }

        public static function verify($secret, $code, $window = 1) {
            $code = preg_replace('/\D/', '', $code);
            if (strlen($code) !== 6) return false;
            $now = floor(time() / 30);
            for ($i = -$window; $i <= $window; $i++) {
                if (hash_equals(self::code($secret, $now + $i), $code)) return true;
            }
            return false;
        }

        public static function otpauthURL($issuer, $account, $secret) {
            $issuerEnc = rawurlencode($issuer);
            $accountEnc = rawurlencode($account);
            return "otpauth://totp/{$issuerEnc}:{$accountEnc}?secret={$secret}&issuer={$issuerEnc}&algorithm=SHA1&digits=6&period=30";
        }
    }
}

/* ---------- Branch helpers ---------- */
if (!function_exists('current_branch_id')) {
    function current_branch_id() { return (int)($_SESSION['branch_id'] ?? 0); }
}
if (!function_exists('list_branches')) {
    function list_branches($conn, $onlyActive = true) {
        $sql = "SELECT * FROM branches " . ($onlyActive ? "WHERE active=1 " : "") . "ORDER BY name";
        $q = $conn->query($sql);
        $out = [];
        while ($r = $q->fetch_assoc()) $out[] = $r;
        return $out;
    }
}
if (!function_exists('branch_name')) {
    function branch_name($conn, $id) {
        if (!$id) return "—";
        $s = $conn->prepare("SELECT name FROM branches WHERE id=?");
        $s->bind_param("i", $id); $s->execute();
        $r = $s->get_result()->fetch_assoc();
        return $r ? $r["name"] : "—";
    }
}

/* ---------- Notifications ---------- */
if (!function_exists('notify')) {
    function notify($conn, $userId, $message, $link = null) {
        $s = $conn->prepare("INSERT INTO notifications(user_id, message, link) VALUES(?,?,?)");
        $s->bind_param("iss", $userId, $message, $link);
        $s->execute();
    }
}
if (!function_exists('notify_admins')) {
    function notify_admins($conn, $message, $link = null) {
        $q = $conn->query("SELECT id FROM users WHERE role='admin' AND active=1");
        while ($u = $q->fetch_assoc()) notify($conn, (int)$u["id"], $message, $link);
    }
}
if (!function_exists('unread_notifications')) {
    function unread_notifications($conn, $userId) {
        $s = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND seen=0");
        $s->bind_param("i", $userId); $s->execute();
        return (int)$s->get_result()->fetch_assoc()["c"];
    }
}

/* ---------- Theme ---------- */
if (!function_exists('get_user_theme')) {
    function get_user_theme($conn, $userId) {
        $uid = (int)$userId;
        $s = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
        $k = "theme_u$uid";
        $s->bind_param("s", $k); $s->execute();
        $r = $s->get_result()->fetch_assoc();
        return $r ? $r["setting_value"] : "light";
    }
}
if (!function_exists('set_user_theme')) {
    function set_user_theme($conn, $userId, $theme) {
        $theme = ($theme === "dark") ? "dark" : "light";
        $k = "theme_u" . (int)$userId;
        $s = $conn->prepare("INSERT INTO settings(setting_key, setting_value) VALUES(?,?)
                             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $s->bind_param("ss", $k, $theme);
        $s->execute();
    }
}

/* ---------- API tokens (stubs, so nothing breaks if not used) ---------- */
if (!function_exists('api_token_create')) {
    function api_token_create($conn, $userId, $label = "") {
        $raw = bin2hex(random_bytes(24));
        $hash = hash('sha256', $raw);
        $s = $conn->prepare("INSERT INTO api_tokens(user_id, token_hash, label, active) VALUES(?,?,?,1)");
        $s->bind_param("iss", $userId, $hash, $label);
        $s->execute();
        return $raw;
    }
}
if (!function_exists('api_token_verify')) {
    function api_token_verify($conn, $raw) {
        if (!$raw) return null;
        $hash = hash('sha256', $raw);
        $s = $conn->prepare("SELECT t.*, u.full_name, u.role, u.username FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.active=1 LIMIT 1");
        $s->bind_param("s", $hash); $s->execute();
        $r = $s->get_result()->fetch_assoc();
        if (!$r) return null;
        $conn->query("UPDATE api_tokens SET last_used=NOW() WHERE id=" . (int)$r["id"]);
        return $r;
    }
}