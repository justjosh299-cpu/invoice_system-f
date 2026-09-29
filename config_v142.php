<?php
/**
 * v14.2 helpers — brand colour, dark mode helpers.
 */

if (!function_exists('brand_colour')) {
    function brand_colour($conn) {
        return get_setting($conn, "brand_colour", "#0b2545") ?: "#0b2545";
    }
}
if (!function_exists('brand_colour_dark')) {
    function brand_colour_dark($conn) {
        return get_setting($conn, "brand_colour_dark", "#12345f") ?: "#12345f";
    }
}
if (!function_exists('current_theme')) {
    function current_theme($conn) {
        if (!isset($_SESSION["user_id"])) return "light";
        if (function_exists('get_user_theme')) return get_user_theme($conn, (int)$_SESSION["user_id"]);
        return "light";
    }
}
if (!function_exists('login_audit_recent')) {
    function login_audit_recent($conn, $limit = 200) {
        $lim = max(1, min(1000, (int)$limit));
        return $conn->query("SELECT la.*, COALESCE(u.full_name,'—') full_name
                             FROM login_audit la LEFT JOIN users u ON u.id=la.user_id
                             ORDER BY la.id DESC LIMIT $lim");
    }
}