<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$uid = (int)$_SESSION["user_id"];

// Handle remove
if (isset($_GET["remove"])) {
    $s = $conn->prepare("SELECT avatar FROM users WHERE id=?");
    $s->bind_param("i", $uid); $s->execute();
    $row = $s->get_result()->fetch_assoc();
    if ($row && $row["avatar"]) {
        @unlink(__DIR__ . '/' . $row["avatar"]);
    }
    $conn->query("UPDATE users SET avatar=NULL WHERE id=$uid");
    log_activity($conn, "Removed profile photo");
    header("Location: profile.php?avatar=removed"); exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" || empty($_FILES["avatar"]["name"])) {
    header("Location: profile.php"); exit;
}

$f = $_FILES["avatar"];
$err = "";
$allowed = ["png"=>"image/png","jpg"=>"image/jpeg","jpeg"=>"image/jpeg","webp"=>"image/webp","gif"=>"image/gif"];

if ($f["error"] !== UPLOAD_ERR_OK) {
    $err = "Upload failed (code " . $f["error"] . ").";
} elseif ($f["size"] > 1024 * 1024) {
    $err = "File too large (max 1 MB).";
} else {
    $info = @getimagesize($f["tmp_name"]);
    if (!$info) {
        $err = "Not a valid image.";
    } else {
        $ext = strtolower(pathinfo($f["name"], PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            $err = "Format not allowed (png / jpg / webp / gif).";
        } else {
            @mkdir(__DIR__ . '/assets/uploads/avatars', 0777, true);
            $filename = "avatar_u" . $uid . "_" . time() . "." . $ext;
            $dest = "assets/uploads/avatars/" . $filename;
            $full = __DIR__ . "/" . $dest;

            // Try to make a 200x200 square using GD
            $made = false;
            if (function_exists("imagecreatetruecolor")) {
                $src = null;
                switch ($info[2]) {
                    case IMAGETYPE_PNG:  $src = @imagecreatefrompng($f["tmp_name"]); break;
                    case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($f["tmp_name"]); break;
                    case IMAGETYPE_GIF:  $src = @imagecreatefromgif($f["tmp_name"]); break;
                    case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($f["tmp_name"]); break;
                }
                if ($src) {
                    $w = imagesx($src); $h = imagesy($src);
                    $side = min($w, $h);
                    $sx = (int)(($w - $side) / 2);
                    $sy = (int)(($h - $side) / 2);
                    $dst = imagecreatetruecolor(200, 200);
                    // preserve transparency for PNG
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                    imagefilledrectangle($dst, 0, 0, 200, 200, $transparent);
                    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 200, 200, $side, $side);
                    if ($info[2] === IMAGETYPE_PNG) imagepng($dst, $full);
                    elseif ($info[2] === IMAGETYPE_GIF) imagegif($dst, $full);
                    elseif ($info[2] === IMAGETYPE_WEBP) imagewebp($dst, $full, 90);
                    else imagejpeg($dst, $full, 90);
                    imagedestroy($src); imagedestroy($dst);
                    $made = true;
                }
            }

            // Fallback: just move the file
            if (!$made) {
                if (!move_uploaded_file($f["tmp_name"], $full)) {
                    $err = "Could not save the file.";
                }
            }
        }
    }
}

if ($err === "") {
    // Remove old avatar
    $s = $conn->prepare("SELECT avatar FROM users WHERE id=?");
    $s->bind_param("i", $uid); $s->execute();
    $old = $s->get_result()->fetch_assoc();
    if ($old && $old["avatar"] && $old["avatar"] !== $dest) {
        @unlink(__DIR__ . '/' . $old["avatar"]);
    }
    // Save new
    $u = $conn->prepare("UPDATE users SET avatar=? WHERE id=?");
    $u->bind_param("si", $dest, $uid); $u->execute();
    log_activity($conn, "Updated profile photo");
    header("Location: profile.php?avatar=1"); exit;
}

header("Location: profile.php?err=" . urlencode($err)); exit;