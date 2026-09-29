<?php
require "config.php";
if (!isset($_SESSION["user_id"])) { header("Location:login.php"); exit; }
$err = ""; $summary = null;
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_FILES["csv"]["name"])) {
    $mode = $_POST["mode"] ?? "skip";
    if ($_FILES["csv"]["error"] !== UPLOAD_ERR_OK) $err = "Upload failed.";
    else {
        $fh = fopen($_FILES["csv"]["tmp_name"], "r");
        if (!$fh) $err = "Could not read file.";
        else {
            $first = fread($fh, 3); if ($first !== "\xEF\xBB\xBF") rewind($fh);
            $header = fgetcsv($fh);
            if (!$header) $err = "Empty CSV.";
            else {
                $header = array_map(function($h){ return strtolower(trim($h)); }, $header);
                $idx = [];
                foreach (["client_name","address","phone","alternate_phone"] as $col) {
                    $pos = array_search($col, $header);
                    if ($pos === false) { $err = "Missing column: $col"; break; }
                    $idx[$col] = $pos;
                }
                if ($err === "") {
                    $imported = 0; $updated = 0; $skipped = 0;
                    $check = $conn->prepare("SELECT id FROM clients WHERE client_name=? AND (phone=? OR (phone IS NULL AND ?=''))");
                    $upd   = $conn->prepare("UPDATE clients SET address=?, phone=?, alternate_phone=? WHERE id=?");
                    $ins   = $conn->prepare("INSERT INTO clients(client_name,address,phone,alternate_phone) VALUES(?,?,?,?)");
                    while (($r = fgetcsv($fh)) !== false) {
                        $name = trim($r[$idx["client_name"]] ?? ""); if ($name === "") { $skipped++; continue; }
                        $addr = trim($r[$idx["address"]] ?? ""); $ph = trim($r[$idx["phone"]] ?? ""); $aph = trim($r[$idx["alternate_phone"]] ?? "");
                        $check->bind_param("sss", $name, $ph, $ph); $check->execute();
                        $existing = $check->get_result()->fetch_assoc();
                        if ($existing) {
                            if ($mode === "update") { $upd->bind_param("sssi", $addr, $ph, $aph, $existing["id"]); $upd->execute(); $updated++; }
                            else $skipped++;
                        } else { $ins->bind_param("ssss", $name, $addr, $ph, $aph); $ins->execute(); $imported++; }
                    }
                    log_activity($conn, "Imported clients CSV: $imported added, $updated updated, $skipped skipped");
                    $summary = ["imported"=>$imported, "updated"=>$updated, "skipped"=>$skipped];
                }
            }
            fclose($fh);
        }
    }
}
?><!doctype html><html><head><title>Import Clients</title>
<link rel="stylesheet" href="assets/style.css"></head><body>
<?php include "nav.php"; ?>
<main>
  <div class="hero"><div><h1>Import Clients (CSV)</h1><p>Bulk-upload a client list from a CSV file.</p></div>
    <a class="btn secondary" href="clients.php">← Back to Clients</a></div>
  <?php if ($err): ?><div class="error"><?=htmlspecialchars($err)?></div><?php endif; ?>
  <?php if ($summary): ?><div class="success">✅ Imported: <b><?=$summary["imported"]?></b> added, <b><?=$summary["updated"]?></b> updated, <b><?=$summary["skipped"]?></b> skipped.</div><?php endif; ?>
  <div class="panel"><h2>CSV Format</h2>
    <div class="csvhelp"><p>Header: <code>client_name,address,phone,alternate_phone</code></p>
      <pre><code>client_name,address,phone,alternate_phone
Acme Ltd,Soroti Main Street,+256774000001,+256778000002
Jane Doe,Kampala Road,+256701000003,
</code></pre></div></div>
  <div class="panel"><h2>Upload</h2>
    <form method="post" enctype="multipart/form-data">
      <div class="grid">
        <label>CSV File<input type="file" name="csv" accept=".csv" required></label>
        <label>Duplicate behavior<select name="mode">
          <option value="skip">Skip existing</option>
          <option value="update">Update existing</option>
        </select></label>
      </div>
      <div class="actions"><button class="btn">Import</button></div>
    </form>
  </div>
</main></body></html>