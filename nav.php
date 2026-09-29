<?php
$navLogo = function_exists('get_setting') ? get_setting($conn, "company_logo", "") : "";
$admin   = function_exists('is_admin') ? is_admin() : false;

function nf($f) { return file_exists(__DIR__ . '/' . $f); }

// Brand colour
$brand      = function_exists('brand_colour')      ? brand_colour($conn)      : "#0b2545";
$brandHover = function_exists('brand_colour_dark') ? brand_colour_dark($conn) : "#12345f";

// Unread notifications
$bellCount = 0;
if (isset($_SESSION["user_id"]) && function_exists('unread_notifications')) {
    $bellCount = unread_notifications($conn, (int)$_SESSION["user_id"]);
}

// Current user's avatar + name
$userAvatar = "";
$userInitial = "";
if (isset($_SESSION["user_id"])) {
    $uid = (int)$_SESSION["user_id"];
    $aq = $conn->prepare("SELECT avatar, full_name FROM users WHERE id=?");
    if ($aq) {
        $aq->bind_param("i", $uid); $aq->execute();
        $ar = $aq->get_result()->fetch_assoc();
        if ($ar && !empty($ar["avatar"]) && file_exists(__DIR__ . '/' . $ar["avatar"])) {
            $userAvatar = $ar["avatar"];
        }
        if ($ar && !empty($ar["full_name"])) {
            $userInitial = strtoupper(substr($ar["full_name"], 0, 1));
        }
    }
}

$currentQ = $_GET["q"] ?? "";
?><style>
@keyframes bellPulse {
  0%, 100% { transform: rotate(0deg); }
  15%      { transform: rotate(12deg); }
  30%      { transform: rotate(-10deg); }
  45%      { transform: rotate(6deg); }
  60%      { transform: rotate(-4deg); }
  75%      { transform: rotate(0deg); }
}
.bell.has-new { animation: bellPulse 1.6s ease-in-out infinite; transform-origin: top center; display:inline-block; }
#navClock { user-select: none; }
@media (max-width: 1100px) { #navClock { display: none; } }
</style>
<header style="background:<?=htmlspecialchars($brand)?>;color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;position:relative">
  <div style="font-weight:700;display:flex;align-items:center;gap:12px">
    <?php if ($navLogo && file_exists(__DIR__ . '/' . $navLogo)): ?>
      <img src="<?=htmlspecialchars($navLogo)?>" alt="Logo" style="height:42px;width:auto;border-radius:4px;background:#fff;padding:2px">
    <?php endif; ?>
    <span>OMG TECHNOLOGIES UGANDA <span style="display:block;font-size:11px;opacity:.75;font-weight:400">IT SUPPORT &amp; COMPUTER SERVICES</span></span>
  </div>
  <nav style="display:flex;align-items:center;flex-wrap:wrap">
    <a href="index.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Dashboard</a>
    <a href="reports.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Reports</a>
    <?php if (nf('invoices.php')): ?>
      <a href="invoices.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Invoices</a>
    <?php endif; ?>
    <?php if (nf('quotations.php')): ?>
      <a href="quotations.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Quotations</a>
    <?php endif; ?>
    <?php if (nf('clients.php')): ?>
      <a href="clients.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Clients</a>
    <?php endif; ?>
    <?php if (nf('parts.php')): ?>
      <a href="parts.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Parts</a>
    <?php endif; ?>
    <?php if (!$admin && nf('my_tickets.php')): ?>
      <a href="my_tickets.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">My Tickets</a>
    <?php endif; ?>
    <?php if ($admin): ?>
      <?php if (nf('branches.php')): ?>
        <a href="branches.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Branches</a>
      <?php endif; ?>
      <?php if (nf('feedback_list.php')): ?>
        <a href="feedback_list.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Feedback</a>
      <?php endif; ?>
      <?php if (nf('users.php')): ?>
        <a href="users.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Users</a>
      <?php endif; ?>
      <?php if (nf('activity.php')): ?>
        <a href="activity.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Activity</a>
      <?php endif; ?>
      <?php if (nf('login_audit.php')): ?>
        <a href="login_audit.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Login Audit</a>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (nf('notifications.php')): ?>
      <a href="notifications.php" class="bell<?php if ($bellCount > 0): ?> has-new<?php endif; ?>"
         style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px;position:relative">
        🔔<?php if ($bellCount > 0): ?><span style="display:inline-block;background:#a12626;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;vertical-align:middle;font-weight:700"><?=$bellCount?></span><?php endif; ?>
      </a>
    <?php endif; ?>
    <?php if (nf('portal.php')): ?>
      <a href="portal.php" target="_blank" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Portal</a>
    <?php endif; ?>
    <?php if (nf('settings.php')): ?>
      <a href="settings.php" style="color:#dbe6f3;text-decoration:none;margin-left:18px;font-size:14px">Settings</a>
    <?php endif; ?>
    <?php if (nf('profile.php')): ?>
      <a href="profile.php" style="display:inline-flex;align-items:center;gap:6px;margin-left:18px;text-decoration:none;color:#dbe6f3;font-size:14px">
        <?php if ($userAvatar): ?>
          <img src="<?=htmlspecialchars($userAvatar)?>?v=<?=time()?>" alt="Me" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:2px solid #fff;background:#e2e8ef">
        <?php elseif ($userInitial): ?>
          <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#e2e8ef;color:#0b2545;font-weight:700;font-size:13px"><?=htmlspecialchars($userInitial)?></span>
        <?php endif; ?>
        <span>Profile</span>
      </a>
    <?php endif; ?>
    <?php if (nf('search.php')): ?>
      <form action="search.php" method="get" style="display:inline-flex;align-items:center;margin-left:18px">
        <input type="text" name="q" placeholder="Search…" value="<?=htmlspecialchars($currentQ)?>"
               style="padding:5px 10px;border-radius:6px;border:none;font-size:13px;width:150px;color:#1c2530">
      </form>
    <?php endif; ?>
  </nav>
  <div id="navClock" style="position:absolute;bottom:6px;right:16px;font-size:11px;color:#dbe6f3;opacity:.8;font-family:monospace;letter-spacing:.5px;pointer-events:none"></div>
</header>
<script>
(function(){
  var el = document.getElementById('navClock');
  if (!el) return;
  function tick(){
    var now = new Date();
    var hh = String(now.getHours()).padStart(2,'0');
    var mm = String(now.getMinutes()).padStart(2,'0');
    var ss = String(now.getSeconds()).padStart(2,'0');
    el.textContent = hh + ':' + mm + ':' + ss;
  }
  tick();
  setInterval(tick, 1000);
})();
</script>