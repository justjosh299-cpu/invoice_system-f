<?php
require "config.php";
log_activity($conn, "Signed out", null);
session_destroy();
header("Location:login.php");
exit;