<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }

function nextTicket($c) {
    $p = "OMG-" . date("Y") . "-";
    $q = $c->query("SELECT ticket_no FROM reports WHERE ticket_no LIKE '" . $p . "%' ORDER BY id DESC LIMIT 1");
    $n = 1;
    if ($q && $q->num_rows) { $n = (int)substr($q->fetch_assoc()["ticket_no"], -5) + 1; }
    return $p . str_pad($n, 5, "0", STR_PAD_LEFT);
}

$ticket = nextTicket($conn);

$computerAreas = [
  "Power / Battery","RAM / Memory","Storage Drive","Operating System","Device Drivers",
  "CPU / Processor","Display / Graphics","Network / Wi-Fi","Ports / USB"
];
$printerAreas = [
  "Power / Cabling","Print Head / Cartridge","Toner / Ink System",
  "Paper Feed / Rollers","Fuser Unit","Scanner / ADF Glass",
  "Photocopy Module","Control Panel / Display",
  "Connectivity (USB / Network / Wi-Fi)","Drivers / Software"
];
$statuses = ["Functional","Fault","Needs Attention","Not Checked","N/A"];
$problems = ["CD/DVD-ROM","USB / Removable Drive","Application / Software","Won’t Boot – Hardware",
             "Won’t Boot – Software","Hard Drive / SSD","Printer","Virus / Malware","Network / Wi-Fi",
             "No Power","Operating System","Display / Screen","Keyboard / Touchpad",
             "Battery / Charging","Overheating",
             "Paper Jam","Print Quality Issue","Toner / Ink Problem","Scanner Not Working",
             "Photocopy Fault","Error Code Displayed","Connectivity Fault"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $deviceType = ($_POST["device_type"] ?? "computer") === "printer" ? "printer" : "computer";
    $areas = ($deviceType === "printer") ? $printerAreas : $computerAreas;

    $clientId = (int)($_POST["existing_client_id"] ?? 0);
    if ($clientId > 0) {
        $up = $conn->prepare("UPDATE clients SET client_name=?, address=?, phone=?, alternate_phone=? WHERE id=?");
        $client = trim($_POST["client_name"] ?? "");
        $addr = $_POST["address"] ?? ""; $ph = $_POST["phone"] ?? ""; $aph = $_POST["alternate_phone"] ?? "";
        $up->bind_param("ssssi", $client, $addr, $ph, $aph, $clientId); $up->execute();
        $cid = $clientId;
    } else {
        $client = trim($_POST["client_name"] ?? "");
        $s = $conn->prepare("INSERT INTO clients(client_name,address,phone,alternate_phone) VALUES(?,?,?,?)");
        $addr = $_POST["address"] ?? ""; $ph = $_POST["phone"] ?? ""; $aph = $_POST["alternate_phone"] ?? "";
        $s->bind_param("ssss", $client, $addr, $ph, $aph); $s->execute();
        $cid = $s->insert_id;
    }

    $sql = "INSERT INTO reports(ticket_no,device_type,client_id,reported_date,computer_make_model,serial_number,
            work_ticket_number,problem_categories,problem_description,recommended_fix,estimated_cost,
            estimated_completion,items_received,client_initials,authorization,technician_name,repaired_date,
            verified_by,final_result,action_taken,parts_replaced,software_installed,technician_remarks,
            diagnostic_start,diagnostic_end,repair_start,repair_end,ticket_status,print_date)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $s = $conn->prepare($sql);
    $cost = ($_POST["estimated_cost"] ?? "") === "" ? null : $_POST["estimated_cost"];
    $auth = isset($_POST["authorization"]) ? 1 : 0;
    $cats  = implode(", ", $_POST["problems"] ?? []);
    $items = implode(", ", $_POST["items_received"] ?? []);
    $rd = $_POST["reported_date"] ?? date("Y-m-d");
    $cmm = $_POST["computer_make_model"] ?? ""; $sn = $_POST["serial_number"] ?? ""; $wtn = $_POST["work_ticket_number"] ?? "";
    $pd = $_POST["problem_description"] ?? ""; $rf = $_POST["recommended_fix"] ?? ""; $ec = $_POST["estimated_completion"] ?? "";
    $ci = $_POST["client_initials"] ?? "";
    $repd = ($_POST["repaired_date"] ?? "") === "" ? null : $_POST["repaired_date"];
    $vb = $_POST["verified_by"] ?? ""; $fr = $_POST["final_result"] ?? ""; $at = $_POST["action_taken"] ?? "";
    $pr = $_POST["parts_replaced"] ?? ""; $si = $_POST["software_installed"] ?? ""; $tr = $_POST["technician_remarks"] ?? "";
    $ds = ($_POST["diagnostic_start"] ?? "") === "" ? null : $_POST["diagnostic_start"];
    $de = ($_POST["diagnostic_end"]   ?? "") === "" ? null : $_POST["diagnostic_end"];
    $rs = ($_POST["repair_start"]     ?? "") === "" ? null : $_POST["repair_start"];
    $re = ($_POST["repair_end"]       ?? "") === "" ? null : $_POST["repair_end"];
    $ts = $_POST["ticket_status"] ?? "OPEN";
    $tech = $_SESSION["name"]; $pdate = date("Y-m-d");

    $s->bind_param(
        "ssisssssssdsssissssssssssssss",
        $ticket, $deviceType, $cid, $rd, $cmm, $sn, $wtn, $cats, $pd, $rf, $cost, $ec, $items, $ci, $auth, $tech,
        $repd, $vb, $fr, $at, $pr, $si, $tr, $ds, $de, $rs, $re, $ts, $pdate
    );
    $s->execute();
    $rid = $s->insert_id;

    foreach ($areas as $i => $a) {
        $d = $conn->prepare("INSERT INTO diagnostic_items(report_id,diagnostic_area,status,findings,recommendation) VALUES(?,?,?,?,?)");
        $st = $_POST["diag_status"][$i] ?? "Not Checked";
        $fi = $_POST["diag_findings"][$i] ?? "";
        $rc = $_POST["diag_recommendation"][$i] ?? "";
        $d->bind_param("issss", $rid, $a, $st, $fi, $rc);
        $d->execute();
    }

    // Record initial status in history
    $uid = (int)$_SESSION["user_id"];
    $note = "Initial status on report creation";
    $sh = $conn->prepare("INSERT INTO status_history(report_id, old_status, new_status, changed_by, note) VALUES(?,?,?,?,?)");
    $zero = "";
    $sh->bind_param("issis", $rid, $zero, $ts, $uid, $note);
    $sh->execute();

    log_activity($conn, "Created diagnostic report ($deviceType)", $ticket);
    header("Location:view_report.php?ticket=" . urlencode($ticket));
    exit;
}
?><!doctype html><html><head><title>New Diagnostic Report</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero">
    <div><h1>New Diagnostic Report</h1>
      <p>Complete the form once and generate a print-ready two-page report.</p></div>
    <div class="ticketbox">Ticket<br><b><?=htmlspecialchars($ticket)?></b></div>
  </div>
  <form method="post" id="reportForm" autocomplete="off">
    <section class="panel"><h2>1. Client &amp; Equipment Information</h2>
      <div class="grid">
        <label>Device Type
          <select name="device_type" id="device_type">
            <option value="computer">Computer / Laptop</option>
            <option value="printer">Printer / Photocopier / Scanner</option>
          </select>
        </label>
        <label>Client / Organization
          <span class="clientsearch">
            <input name="client_name" id="client_name" required autocomplete="off">
            <div class="results" id="clientResults"></div>
          </span>
        </label>
        <input type="hidden" name="existing_client_id" id="existing_client_id" value="">
        <label>Date Reported<input type="date" name="reported_date" value="<?=date('Y-m-d')?>" required></label>
        <label>Address<input name="address" id="address"></label>
        <label>Phone<input name="phone" id="phone"></label>
        <label>Alternate Phone<input name="alternate_phone" id="alternate_phone"></label>
        <label>Make &amp; Model<input name="computer_make_model"></label>
        <label>Serial Number<input name="serial_number"></label>
        <label>Work Ticket Number<input name="work_ticket_number"></label>
      </div>
      <div class="chosen" id="chosenClient"></div>
      <div class="devicehint" id="devicehint">
        Checklist shown below: <b>Computer / Laptop</b> diagnostic areas.
      </div>
      <p class="hint">Tip: start typing a client name or phone — matches from previous reports appear.</p>
    </section>
    <section class="panel"><h2>2. Reported Problem</h2>
      <div class="checks">
        <?php foreach ($problems as $p): ?>
          <label><input type="checkbox" name="problems[]" value="<?=htmlspecialchars($p)?>"> <?=htmlspecialchars($p)?></label>
        <?php endforeach; ?>
      </div>
      <label>Problem Description<textarea name="problem_description"></textarea></label>
    </section>

    <section class="panel">
      <h2 id="diag-heading">3. Technician Diagnostic Assessment (Computer / Laptop)</h2>
      <div id="diag-computer">
        <table class="diag">
          <tr><th>Diagnostic Area</th><th>Status</th><th>Findings</th><th>Action / Recommendation</th></tr>
          <?php foreach ($computerAreas as $i => $a): ?>
            <tr>
              <td><b><?=htmlspecialchars($a)?></b></td>
              <td><select name="diag_status[]">
                <?php foreach ($statuses as $x): ?><option><?=htmlspecialchars($x)?></option><?php endforeach; ?>
              </select></td>
              <td><input name="diag_findings[]"></td>
              <td><input name="diag_recommendation[]"></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div id="diag-printer" style="display:none">
        <table class="diag">
          <tr><th>Diagnostic Area</th><th>Status</th><th>Findings</th><th>Action / Recommendation</th></tr>
          <?php foreach ($printerAreas as $i => $a): ?>
            <tr>
              <td><b><?=htmlspecialchars($a)?></b></td>
              <td><select name="diag_status[]">
                <?php foreach ($statuses as $x): ?><option><?=htmlspecialchars($x)?></option><?php endforeach; ?>
              </select></td>
              <td><input name="diag_findings[]"></td>
              <td><input name="diag_recommendation[]"></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </section>

    <section class="panel"><h2>4. Suggested Repair / Fix</h2>
      <div class="grid">
        <label>Recommended Fix<textarea name="recommended_fix"></textarea></label>
        <label>Estimated Cost (UGX)<input type="number" step="0.01" name="estimated_cost"></label>
        <label>Estimated Completion<input name="estimated_completion"></label>
      </div>
    </section>
    <section class="panel"><h2>5. Parts / Equipment Received</h2>
      <div class="checks">
        <?php foreach (["Desktop/CPU","Laptop Power Adapter","Laptop Case","Printer","Photocopier","Scanner","Mouse/Keyboard","Recovery Media","Driver Software","Application Software","Toner / Cartridge","Paper Tray"] as $x): ?>
          <label><input type="checkbox" name="items_received[]" value="<?=htmlspecialchars($x)?>"> <?=htmlspecialchars($x)?></label>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="panel"><h2>6–10. Completion &amp; Authorization</h2>
      <div class="grid">
        <label>Client Initials<input name="client_initials"></label>
        <label>Date Repaired<input type="date" name="repaired_date"></label>
        <label>Verified By<input name="verified_by"></label>
        <label>Final Result<select name="final_result">
          <?php foreach (["Repaired","Partially Repaired","Not Repairable","Awaiting Parts","Referred"] as $x): ?>
            <option><?=htmlspecialchars($x)?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Ticket Status<select name="ticket_status">
          <?php foreach (["OPEN","DIAGNOSIS","AWAITING PARTS","REPAIRED","CLOSED"] as $x): ?>
            <option><?=htmlspecialchars($x)?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Action Taken<textarea name="action_taken"></textarea></label>
        <label>Parts Replaced / Installed<textarea name="parts_replaced"></textarea></label>
        <label>Software Installed / Removed<textarea name="software_installed"></textarea></label>
        <label>Technician Remarks<textarea name="technician_remarks"></textarea></label>
        <label>Diagnostic Start<input type="date" name="diagnostic_start"></label>
        <label>Diagnostic End<input type="date" name="diagnostic_end"></label>
        <label>Repair Start<input type="date" name="repair_start"></label>
        <label>Repair End<input type="date" name="repair_end"></label>
      </div>
      <label class="auth"><input type="checkbox" name="authorization" required>
        I authorize necessary diagnostic and repair procedures.</label>
    </section>
    <div class="actions"><button class="btn" type="submit">Save &amp; Generate Report →</button></div>
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
  sel.addEventListener('change', apply); apply();
  form.addEventListener('submit', function () {
    var activeId = (sel.value === 'printer') ? 'diag-printer' : 'diag-computer';
    ['diag-computer', 'diag-printer'].forEach(function (id) {
      var disable = (id !== activeId);
      document.getElementById(id).querySelectorAll('input, select, textarea')
        .forEach(function (el) { el.disabled = disable; });
    });
  });
})();
(function () {
  var input = document.getElementById('client_name');
  var results = document.getElementById('clientResults');
  var hiddenId = document.getElementById('existing_client_id');
  var addr = document.getElementById('address');
  var phone = document.getElementById('phone');
  var aph = document.getElementById('alternate_phone');
  var chosen = document.getElementById('chosenClient');
  var timer = null;
  function hideResults(){ results.classList.remove('show'); results.innerHTML=''; }
  function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function showChosen(name){
    chosen.innerHTML = '✔ Using existing client: <b>' + esc(name) + '</b> <button type="button" id="clearClient">change</button>';
    chosen.classList.add('show');
    document.getElementById('clearClient').addEventListener('click', function(){
      hiddenId.value=''; chosen.classList.remove('show'); chosen.innerHTML=''; input.focus();
    });
  }
  input.addEventListener('input', function(){
    hiddenId.value=''; chosen.classList.remove('show');
    var q=input.value.trim();
    if (q.length<2){ hideResults(); return; }
    clearTimeout(timer);
    timer=setTimeout(function(){
      fetch('client_search_ajax.php?q='+encodeURIComponent(q))
        .then(function(r){return r.json();})
        .then(function(list){
          if(!list.length){ hideResults(); return; }
          results.innerHTML='';
          list.forEach(function(c){
            var div=document.createElement('div');
            div.innerHTML='<b>'+esc(c.client_name)+'</b><small>'+(c.phone?'Phone: '+esc(c.phone):'')+(c.address?' · '+esc(c.address):'')+'</small>';
            div.addEventListener('click', function(){
              input.value=c.client_name; addr.value=c.address||''; phone.value=c.phone||''; aph.value=c.alternate_phone||'';
              hiddenId.value=c.id; hideResults(); showChosen(c.client_name);
            });
            results.appendChild(div);
          });
          results.classList.add('show');
        }).catch(function(){ hideResults(); });
    },200);
  });
  document.addEventListener('click', function(e){ if(!results.contains(e.target) && e.target!==input) hideResults(); });
})();
</script>
</body></html>