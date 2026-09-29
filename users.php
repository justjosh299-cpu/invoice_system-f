<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
if (!is_admin()) { header("Location:index.php"); exit; }
$msg = ""; $err = ""; $selfId = (int)$_SESSION["user_id"];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    if ($action === "add") {
        $u = trim($_POST["username"] ?? ""); $fn = trim($_POST["full_name"] ?? "");
        $role = ($_POST["role"] ?? "technician") === "admin" ? "admin" : "technician";
        $pwd = $_POST["password"] ?? "";
        if ($u === "" || $fn === "" || strlen($pwd) < 6) $err = "All fields required; password min 6 chars.";
        else {
            $chk = $conn->prepare("SELECT id FROM users WHERE username=?"); $chk->bind_param("s", $u); $chk->execute();
            if ($chk->get_result()->num_rows > 0) $err = "Username already exists.";
            else {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $s = $conn->prepare("INSERT INTO users(username,password_hash,full_name,role,active) VALUES(?,?,?,?,1)");
                $s->bind_param("ssss", $u, $hash, $fn, $role); $s->execute();
                log_activity($conn, "Created user: $u ($role)");
                $msg = "User '$u' created.";
            }
        }
    } elseif ($action === "update") {
        $id = (int)($_POST["id"] ?? 0); $fn = trim($_POST["full_name"] ?? "");
        $role = ($_POST["role"] ?? "technician") === "admin" ? "admin" : "technician";
        $active = isset($_POST["active"]) ? 1 : 0;
        if ($id === $selfId && $active === 0) $err = "You cannot deactivate your own account.";
        else {
            $s = $conn->prepare("UPDATE users SET full_name=?, role=?, active=? WHERE id=?");
            $s->bind_param("ssii", $fn, $role, $active, $id); $s->execute();
            log_activity($conn, "Updated user #$id"); $msg = "User updated.";
        }
    } elseif ($action === "reset") {
        $id = (int)($_POST["id"] ?? 0); $pwd = $_POST["new_password"] ?? "";
        if (strlen($pwd) < 6) $err = "New password must be at least 6 characters.";
        else {
            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $s = $conn->prepare("UPDATE users SET password_hash=? WHERE id=?");
            $s->bind_param("si", $hash, $id); $s->execute();
            log_activity($conn, "Reset password for user #$id"); $msg = "Password reset.";
        }
    }
}
$users = $conn->query("SELECT * FROM users ORDER BY id");
?><!doctype html><html><head><title>User Management</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>User Management</h1><p>Add technicians, reset passwords, activate or deactivate accounts.</p></div></div>
  <?php if ($msg): ?><div class="success"><?=htmlspecialchars($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="error"><?=htmlspecialchars($err)?></div><?php endif; ?>
  <div class="panel"><h2>Add New User</h2>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <div class="grid">
        <label>Username<input name="username" required></label>
        <label>Full Name<input name="full_name" required></label>
        <label>Role<select name="role"><option value="technician">Technician</option><option value="admin">Admin</option></select></label>
        <label>Password<input type="password" name="password" minlength="6" required></label>
      </div>
      <div class="actions"><button class="btn">Add User</button></div>
    </form>
  </div>
  <div class="panel"><h2>All Users</h2>
    <table>
      <tr><th>#</th><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
      <?php while ($u = $users->fetch_assoc()): ?>
        <tr>
          <td><?=(int)$u["id"]?></td>
          <td><b><?=htmlspecialchars($u["username"])?></b></td>
          <td><?=htmlspecialchars($u["full_name"])?></td>
          <td><span class="pill role-<?=htmlspecialchars($u["role"])?>"><?=htmlspecialchars($u["role"])?></span></td>
          <td><span class="pill <?=$u["active"]?"active":"inactive"?>"><?=$u["active"]?"active":"inactive"?></span></td>
          <td><?=htmlspecialchars($u["created_at"])?></td>
          <td>
            <button class="btn small secondary" type="button" onclick='openEdit(<?=json_encode($u, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Edit</button>
            <button class="btn small secondary" type="button" onclick='openReset(<?=(int)$u["id"]?>, <?=json_encode($u["username"], JSON_HEX_APOS|JSON_HEX_QUOT)?>)'>Reset Password</button>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>
  <div class="modal" id="editModal"><div class="box"><h3>Edit User</h3>
    <form method="post">
      <input type="hidden" name="action" value="update"><input type="hidden" name="id" id="editId">
      <label>Username<input id="editUsername" disabled></label>
      <label>Full Name<input name="full_name" id="editFullName" required></label>
      <label>Role<select name="role" id="editRole"><option value="technician">Technician</option><option value="admin">Admin</option></select></label>
      <label style="display:flex;align-items:center;gap:8px;margin-top:12px"><input type="checkbox" name="active" id="editActive" style="width:auto"> Active</label>
      <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('editModal')">Cancel</button><button class="btn">Save Changes</button></div>
    </form>
  </div></div>
  <div class="modal" id="resetModal"><div class="box"><h3>Reset Password</h3>
    <p>Set a new password for <b id="resetWho"></b>.</p>
    <form method="post">
      <input type="hidden" name="action" value="reset"><input type="hidden" name="id" id="resetId">
      <label>New Password<input type="password" name="new_password" minlength="6" required></label>
      <div class="actions"><button type="button" class="btn secondary" onclick="closeModal('resetModal')">Cancel</button><button class="btn">Reset</button></div>
    </form>
  </div></div>
</main>
<script>
function openEdit(u){document.getElementById('editId').value=u.id;document.getElementById('editUsername').value=u.username;document.getElementById('editFullName').value=u.full_name;document.getElementById('editRole').value=u.role;document.getElementById('editActive').checked=(u.active==1);document.getElementById('editModal').classList.add('show');}
function openReset(id,username){document.getElementById('resetId').value=id;document.getElementById('resetWho').textContent=username;document.getElementById('resetModal').classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
document.querySelectorAll('.modal').forEach(function(m){m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});});
</script>
</body></html>