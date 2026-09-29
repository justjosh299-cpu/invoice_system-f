<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$ticket = $_GET["ticket"] ?? ($_POST["ticket"] ?? "");
if ($ticket === "") die("No ticket specified.");

$s = $conn->prepare("SELECT r.*, c.client_name, c.address, c.phone, c.alternate_phone
                     FROM reports r LEFT JOIN clients c ON c.id = r.client_id WHERE r.ticket_no=?");
$s->bind_param("s", $ticket); $s->execute();
$r = $s->get_result()->fetch_assoc(); if (!$r) die("Report not found.");

$d = $conn->prepare("SELECT * FROM diagnostic_items WHERE report_id=? ORDER BY id");
$d->bind_param("i", $r["id"]); $d->execute();
$existingDiags = $d->get_result()->fetch_all(MYSQLI_ASSOC);

$computerAreas = ["Power / Battery","RAM / Memory","Storage Drive","Operating System","Device Drivers","CPU / Processor","Display / Graphics","Network / Wi-Fi","Ports / USB"];
$printerAreas = ["Power / Cabling","Print Head / Cartridge","Toner / Ink System","Paper Feed / Rollers","Fuser Unit","Scanner / ADF Glass","Photocopy Module","Control Panel / Display","Connectivity (USB / Network / Wi-Fi)","Drivers / Software"];
$statuses = ["Functional","Fault","Needs Attention","Not Checked","N/A"];
$allStatuses = ["OPEN","DIAGNOSIS","AWAITING PARTS","REPAIRED","CLOSED"];
$problems = ["CD/DVD-ROM","USB / Removable Drive","Application / Software","Won’t Boot – Hardware","Won’t Boot – Software","Hard Drive / SSD","Printer","Virus / Malware","Network / Wi-Fi","No Power","Operating System","Display / Screen","Keyboard / Touchpad","Battery / Charging","Overheating","Paper Jam","Print Quality Issue","Toner / Ink Problem","Scanner Not Working","Photocopy Fault","Error Code Displayed","Connectivity Fault"];
$selectedProblems = array_map('trim', explode(",", $r["problem_categories"] ?? ""));
$selectedItems    = array_map('trim', explode(",", $r["items_received"] ?? ""));
$oldStatus = $r["ticket_status"];

$strict = get_setting($conn, "workflow_strict", "1") === "1";
$allowed = $strict ? valid_next_statuses($oldStatus, is_admin()) : $allStatuses;
if (!in_array($oldStatus, $allowed, true)) array_unshift($allowed, $oldStatus);

$msg = ""; $err = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $deviceType = ($_POST["device_type"] ?? "computer") === "printer" ? "printer" : "computer";
    $areas = ($deviceType === "printer") ? $printerAreas : $computerAreas;
    $newStatus = $_POST["ticket_status"] ?? $oldStatus;

    // Enforce workflow
    if ($strict && !can_transition($oldStatus, $newStatus, is_admin())) {
        $err = "Invalid status transition: $oldStatus → $newStatus is not allowed.";
    } else {
        $cid = (int)$r["client_id"];
        if ($cid > 0) {
            $up = $conn->prepare("UPDATE clients SET client_name=?, address=?, phone=?, alternate_phone=? WHERE id=?");
            $client = trim($_POST["client_name"] ?? ""); $addr = $_POST["address"] ?? ""; $ph = $_POST["phone"] ?? ""; $aph = $_POST["alternate_phone"] ?? "";
            $up->bind_param("ssssi", $client, $addr, $ph, $aph, $cid); $up->execute();
        } else {
            $client = trim($_POST["client_name"] ?? "");
            $ins = $conn->prepare("INSERT INTO clients(client_name,address,phone,alternate_phone) VALUES(?,?,?,?)");
            $addr = $_POST["address"] ?? ""; $ph = $_POST["phone"] ?? ""; $aph = $_POST["alternate_phone"] ?? "";
            $ins->bind_param("ssss", $client, $addr, $ph, $aph); $ins->execute();
            $cid = $ins->insert_id;
        }
        $sql = "UPDATE reports SET device_type=?, client_id=?, reported_date=?, computer_make_model=?, serial_number=?,
                work_ticket_number=?, problem_categories=?, problem_description=?, recommended_fix=?,
                estimated_cost=?, estimated_completion=?, items_received=?, client_initials=?,
                authorization=?, technician_name=?, repaired_date=?, verified_by=?, final_result=?,
                action_taken=?, parts_replaced=?, software_installed=?, technician_remarks=?,
                diagnostic_start=?, diagnostic_end=?, repair_start=?, repair_end=?, ticket_status=?
                WHERE id=?";
        $s = $conn->prepare($sql);
        $cost = ($_POST["estimated_cost"] ?? "") === "" ? null : $_POST["estimated_cost"];
        $auth = isset($_POST["authorization"]) ? 1 : 0;
        $cats  = implode(", ", $_POST["problems"] ?? []); $items = implode(", ", $_POST["items_received"] ?? []);
        $rd = $_POST["reported_date"] ?? date("Y-m-d"); $cmm = $_POST["computer_make_model"] ?? ""; $sn = $_POST["serial_number"] ?? ""; $wtn = $_POST["work_ticket_number"] ?? "";
        $pd = $_POST["problem_description"] ?? ""; $rf = $_POST["recommended_fix"] ?? ""; $ec = $_POST["estimated_completion"] ?? ""; $ci = $_POST["client_initials"] ?? "";
        $repd = ($_POST["repaired_date"] ?? "") === "" ? null : $_POST["repaired_date"];
        $vb = $_POST["verified_by"] ?? ""; $fr = $_POST["final_result"] ?? ""; $at = $_POST["action_taken"] ?? "";
        $pr = $_POST["parts_replaced"] ?? ""; $si = $_POST["software_installed"] ?? ""; $tr = $_POST["technician_remarks"] ?? "";
        $ds = ($_POST["diagnostic_start"] ?? "") === "" ? null : $_POST["diagnostic_start"];
        $de = ($_POST["diagnostic_end"]   ?? "") === "" ? null : $_POST["diagnostic_end"];
        $rs = ($_POST["repair_start"]     ?? "") === "" ? null : $_POST["repair_start"];
        $re = ($_POST["repair_end"]       ?? "") === "" ? null : $_POST["repair_end"];
        $tech = $_SESSION["name"]; $rid = $r["id"];
        $s->bind_param("sisssssssdsssisssssssssssssi",
            $deviceType, $cid, $rd, $cmm, $sn, $wtn, $cats, $pd, $rf, $cost, $ec, $items, $ci, $auth, $tech,
            $repd, $vb, $fr, $at, $pr, $si, $tr, $ds, $de, $rs, $re, $newStatus, $rid);
        $s->execute();
        $conn->query("DELETE FROM diagnostic_items WHERE report_id=" . (int)$rid);
        foreach ($areas as $i => $a) {
            $d = $conn->prepare("INSERT INTO diagnostic_items(report_id,diagnostic_area,status,findings,recommendation) VALUES(?,?,?,?,?)");
            $st = $_POST["diag_status"][$i] ?? "Not Checked"; $fi = $_POST["diag_findings"][$i] ?? ""; $rc = $_POST["diag_recommendation"][$i] ?? "";
            $d->bind_param("issss", $rid, $a, $st, $fi, $rc); $d->execute();
        }

        log_activity($conn, "Updated diagnostic report", $ticket);

        if ($oldStatus !== $newStatus) {
            log_activity($conn, "Status changed: $oldStatus → $newStatus", $ticket);
            record_status_change($conn, $rid, $oldStatus, $newStatus, "");
            // Optional SMS
            $clientPhone = $_POST["phone"] ?: ($r["phone"] ?? "");
            if ($clientPhone && get_setting($conn, "sms_enabled", "0") === "1") {
                $shortMsg = "OMG Technologies: Ticket " . $ticket . " is now " . $newStatus . ". Reply or call +256774610005.";
                @send_sms($conn, $clientPhone, $shortMsg, $ticket);
            }
        }

        header("Location:view_report.php?ticket=" . urlencode($ticket) . "&updated=1");
        exit;
    }
}

$diagLookup = [];
foreach ($existingDiags as $row) { $diagLookup[$row["diagnostic_area"]] = $row; }
$deviceType = $r["device_type"] ?: "computer";
?><!doctype html><html><head><title>Edit Report <?=htmlspecialchars($ticket)?></title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>Edit Report</h1><p>Ticket <b><?=htmlspecialchars($ticket)?></b> — update any section and save.</p></div>
    <div class="ticketbox">Editing<br><b><?=htmlspecialchars($ticket)?></b></div>
  </div>
  <?php if ($err): ?><div class="error"><?=htmlspecialchars($err)?></div><?php endif; ?>

  <form method="post" id="reportForm" autocomplete="off">
    <input type="hidden" name="ticket" value="<?=htmlspecialchars($ticket)?>">
    <section class="panel"><h2>1. Client &amp; Equipment Information</h2>
      <div class="grid">
        <label>Device Type
          <select name="device_type" id="device_type">
            <option value="computer" <?=$deviceType==="computer"?"selected":""?>>Computer / Laptop</option>
            <option value="printer"  <?=$deviceType==="printer" ?"selected":""?>>Printer / Photocopier / Scanner</option>
          </select>
        </label>
        <label>Client / Organization<input name="client_name" required value="<?=htmlspecialchars($r["client_name"] ?? "")?>"></label>
        <label>Date Reported<input type="date" name="reported_date" value="<?=htmlspecialchars($r["reported_date"])?>" required></label>
        <label>Address<input name="address" value="<?=htmlspecialchars($r["address"] ?? "")?>"></label>
        <label>Phone<input name="phone" value="<?=htmlspecialchars($r["phone"] ?? "")?>"></label>
        <label>Alternate Phone<input name="alternate_phone" value="<?=htmlspecialchars($r["alternate_phone"] ?? "")?>"></label>
        <label>Make &amp; Model<input name="computer_make_model" value="<?=htmlspecialchars($r["computer_make_model"] ?? "")?>"></label>
        <label>Serial Number<input name="serial_number" value="<?=htmlspecialchars($r["serial_number"] ?? "")?>"></label>
        <label>Work Ticket Number<input name="work_ticket_number" value="<?=htmlspecialchars($r["work_ticket_number"] ?? "")?>"></label>
      </div>
      <div class="devicehint" id="devicehint">
        Checklist shown below: <b><?=$deviceType==="printer"?"Printer / Photocopier / Scanner":"Computer / Laptop"?></b> diagnostic areas.
      </div>
    </section>
    <section class="panel"><h2>2. Reported Problem</h2>
      <div class="checks">
        <?php foreach ($problems as $p): ?>
          <label><input type="checkbox" name="problems[]" value="<?=htmlspecialchars($p)?>" <?=in_array($p, $selectedProblems) ? "checked" : ""?>> <?=htmlspecialchars($p)?></label>
        <?php endforeach; ?>
      </div>
      <label>Problem Description<textarea name="problem_description"><?=htmlspecialchars($r["problem_description"] ?? "")?></textarea></label>
    </section>
    <section class="panel">
      <h2 id="diag-heading">3. Technician Diagnostic Assessment (<?=$deviceType==="printer"?"Printer / Photocopier / Scanner":"Computer / Laptop"?>)</h2>
      <div id="diag-computer" <?=$deviceType==="printer"?'style="display:none"':''?>>
        <table class="diag">
          <tr><th>Diagnostic Area</th><th>Status</th><th>Findings</th><th>Action / Recommendation</th></tr>
          <?php foreach ($computerAreas as $i => $a): $row = $diagLookup[$a] ?? null; ?>
            <tr><td><b><?=htmlspecialchars($a)?></b></td>
              <td><select name="diag_status[]"><?php foreach ($statuses as $x): ?><option <?=($row && $row["status"]===$x)?"selected":""?>><?=htmlspecialchars($x)?></option><?php endforeach; ?></select></td>
              <td><input name="diag_findings[]" value="<?=htmlspecialchars($row["findings"] ?? "")?>"></td>
              <td><input name="diag_recommendation[]" value="<?=htmlspecialchars($row["recommendation"] ?? "")?>"></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div id="diag-printer" <?=$deviceType==="printer"?'':'style="display:none"'?>>
        <table class="diag">
          <tr><th>Diagnostic Area</th><th>Status</th><th>Findings</th><th>Action / Recommendation</th></tr>
          <?php foreach ($printerAreas as $i => $a): $row = $diagLookup[$a] ?? null; ?>
            <tr><td><b><?=htmlspecialchars($a)?></b></td>
              <td><select name="diag_status[]"><?php foreach ($statuses as $x): ?><option <?=($row && $row["status"]===$x)?"selected":""?>><?=htmlspecialchars($x)?></option><?php endforeach; ?></select></td>
              <td><input name="diag_findings[]" value="<?=htmlspecialchars($row["findings"] ?? "")?>"></td>
              <td><input name="diag_recommendation[]" value="<?=htmlspecialchars($row["recommendation"] ?? "")?>"></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </section>
    <section class="panel"><h2>4. Suggested Repair / Fix</h2>
      <div class="grid">
        <label>Recommended Fix<textarea name="recommended_fix"><?=htmlspecialchars($r["recommended_fix"] ?? "")?></textarea></label>
        <label>Estimated Cost (UGX)<input type="number" step="0.01" name="estimated_cost" value="<?=htmlspecialchars($r["estimated_cost"] ?? "")?>"></label>
        <label>Estimated Completion<input name="estimated_completion" value="<?=htmlspecialchars($r["estimated_completion"] ?? "")?>"></label>
      </div>
    </section>
    <section class="panel"><h2>5. Parts / Equipment Received</h2>
      <div class="checks">
        <?php foreach (["Desktop/CPU","Laptop Power Adapter","Laptop Case","Printer","Photocopier","Scanner","Mouse/Keyboard","Recovery Media","Driver Software","Application Software","Toner / Cartridge","Paper Tray"] as $x): ?>
          <label><input type="checkbox" name="items_received[]" value="<?=htmlspecialchars($x)?>" <?=in_array($x, $selectedItems) ? "checked" : ""?>> <?=htmlspecialchars($x)?></label>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="panel"><h2>6–10. Completion &amp; Authorization</h2>
      <div class="grid">
        <label>Client Initials<input name="client_initials" value="<?=htmlspecialchars($r["client_initials"] ?? "")?>"></label>
        <label>Date Repaired<input type="date" name="repaired_date" value="<?=htmlspecialchars($r["repaired_date"] ?? "")?>"></label>
        <label>Verified By<input name="verified_by" value="<?=htmlspecialchars($r["verified_by"] ?? "")?>"></label>
        <label>Final Result<select name="final_result"><?php foreach (["Repaired","Partially Repaired","Not Repairable","Awaiting Parts","Referred"] as $x): ?><option <?=($r["final_result"]===$x)?"selected":""?>><?=htmlspecialchars($x)?></option><?php endforeach; ?></select></label>
        <label>Ticket Status
          <?php if ($strict): ?>
            <select name="ticket_status">
              <?php foreach ($allowed as $x): ?>
                <option value="<?=htmlspecialchars($x)?>" <?=($r["ticket_status"]===$x)?"selected":""?>><?=htmlspecialchars($x)?></option>
              <?php endforeach; ?>
            </select>
            <span class="hint">Workflow is strict — only valid next states are shown.</span>
          <?php else: ?>
            <select name="ticket_status"><?php foreach ($allStatuses as $x): ?><option <?=($r["ticket_status"]===$x)?"selected":""?>><?=htmlspecialchars($x)?></option><?php endforeach; ?></select>
            <span class="hint">Workflow is loose — any status allowed.</span>
          <?php endif; ?>
        </label>
        <label>Action Taken<textarea name="action_taken"><?=htmlspecialchars($r["action_taken"] ?? "")?></textarea></label>
        <label>Parts Replaced / Installed<textarea name="parts_replaced"><?=htmlspecialchars($r["parts_replaced"] ?? "")?></textarea></label>
        <label>Software Installed / Removed<textarea name="software_installed"><?=htmlspecialchars($r["software_installed"] ?? "")?></textarea></label>
        <label>Technician Remarks<textarea name="technician_remarks"><?=htmlspecialchars($r["technician_remarks"] ?? "")?></textarea></label>
        <label>Diagnostic Start<input type="date" name="diagnostic_start" value="<?=htmlspecialchars($r["diagnostic_start"] ?? "")?>"></label>
        <label>Diagnostic End<input type="date" name="diagnostic_end" value="<?=htmlspecialchars($r["diagnostic_end"] ?? "")?>"></label>
        <label>Repair Start<input type="date" name="repair_start" value="<?=htmlspecialchars($r["repair_start"] ?? "")?>"></label>
        <label>Repair End<input type="date" name="repair_end" value="<?=htmlspecialchars($r["repair_end"] ?? "")?>"></label>
      </div>
      <label class="auth"><input type="checkbox" name="authorization" <?=$r["authorization"]?"checked":""?>> I authorize necessary diagnostic and repair procedures.</label>
    </section>
    <div class="actions">
      <a href="view_report.php?ticket=<?=urlencode($ticket)?>" class="btn secondary">Cancel</a>
      <button class="btn" type="submit">Save Changes →</button>
    </div>
  </form>
</main>
<script>
(function () {
  var sel = document.getElementById('device_type');
  var heading = document.getElementById('diag-heading');
  var hint = document.getElementById('devicehint');
  var boxComp = document.getElementById('diag-computer');
  var boxPrint = document.getElementById('diag-printer');
  var form = document.getElementById('reportForm');
  function apply() {
    var isPrinter = sel.value === 'printer';
    boxComp.style.display  = isPrinter ? 'none' : '';
    boxPrint.style.display = isPrinter ? ''     : 'none';
    heading.textContent = '3. Technician Diagnostic Assessment (' + (isPrinter ? 'Printer / Photocopier / Scanner' : 'Computer / Laptop') + ')';
    hint.innerHTML = 'Checklist shown below: <b>' + (isPrinter ? 'Printer / Photocopier / Scanner' : 'Computer / Laptop') + '</b> diagnostic areas.';
  }
  sel.addEventListener('change', apply);
  form.addEventListener('submit', function () {
    var activeId = (sel.value === 'printer') ? 'diag-printer' : 'diag-computer';
    ['diag-computer', 'diag-printer'].forEach(function (id) {
      var disable = (id !== activeId);
      document.getElementById(id).querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = disable; });
    });
  });
})();
</script>
</body></html>