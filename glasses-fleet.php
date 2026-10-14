<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/operational-access.php';
$user=app_require_auth();
if(!app_has_permission('glasses.view',$user)){http_response_code(403);exit('AR glasses permission required.');}
$pdo=app_pdo();
$canManage=app_has_permission('glasses.manage',$user);
$locations=[];
$q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name");
$q->execute([(int)$user['organization_id']]);
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $location){
    if(!$canManage||!operational_location_allowed($pdo,$user,'glasses.manage',(int)$location['id']))continue;
    $locations[]=['id'=>(int)$location['id'],'name'=>(string)$location['name']];
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Glasses Fleet Management · Gelato</title>
<link rel="stylesheet" href="assets/css/glasses-software-manager.css?v=275">
<link rel="stylesheet" href="assets/css/glasses-fleet-page.css?v=276fix">
</head>
<body>
<header class="fleet-header">
 <div><a href="glasses-simulator.php" class="fleet-back">← Back to Glasses Runtime</a>
 <h1>Glasses Fleet Management</h1><p>Pair physical glasses, review devices and manage software versions separately from the kitchen HUD.</p></div>
</header>
<main class="fleet-main">
 <section class="fleet-panel" aria-label="Pair and administer physical glasses">
  <h2>Physical glasses</h2>
  <p>Pairing registers a device at a restaurant. Assign the employee and station by clicking <strong>+</strong> on the Runtime tab after pairing succeeds.</p>
  <?php if($canManage && $locations): ?>
  <div class="fleet-controls">
   <label>Restaurant location
    <select id="fleetPairLocation">
     <?php foreach($locations as $location): ?>
     <option value="<?=(int)$location['id']?>"><?=htmlspecialchars($location['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></option>
     <?php endforeach; ?>
    </select>
   </label>
   <button id="fleetPairStart" type="button">Pair a device</button>
   <button id="fleetPairCancel" type="button" hidden>Cancel pairing</button>
  </div>
  <div id="fleetPairPanel" hidden>
   <p>On the glasses, open Gelato → Pair and enter this one-time code:</p>
   <strong id="fleetPairCode"></strong>
   <p>Code expires after 10 minutes. Closing the panel cancels an unused code.</p>
  </div>
  <?php else: ?>
   <p>To pair glasses, you need glasses management permission at an active restaurant location.</p>
  <?php endif; ?>
  <p role="status" aria-live="polite" id="fleetPairStatus">Select a restaurant to register physical glasses.</p>
  <hr>
  <h3>Device administration</h3>
  <div class="fleet-controls">
   <label>Registered glasses <select id="fleetDeviceSelect"><option value="">Loading devices…</option></select></label>
   <label>Device name <input id="fleetDeviceName" type="text" maxlength="120" placeholder="Kitchen glasses"></label>
   <button id="fleetDeviceRename" type="button" disabled>Rename</button>
   <button id="fleetDeviceRevoke" class="fleet-danger" type="button" disabled>Revoke device</button>
  </div>
  <p id="fleetDeviceStatus" role="status" aria-live="polite">Physical device inventory loads here.</p>
 </section>
 <section id="glassesSoftwareManager" class="glasses-software-manager" aria-label="Software policies and device diagnostics">
  <div class="glasses-software-header">
   <div><small>SOFTWARE &amp; DEVICES</small><strong>Fleet health and update readiness</strong></div>
   <button id="glassesSoftwareRefresh" type="button">Refresh fleet</button>
  </div>
  <label class="glasses-software-pick">Inspect glasses
   <select id="glassesSoftwareDevice"><option value="">Loading glasses…</option></select>
  </label>
  <div class="glasses-software-stats">
   <div><small>Connection</small><strong id="glassesSoftwareOnline">Not selected</strong></div>
   <div><small>Application</small><strong id="glassesSoftwareVersion">Not reported</strong></div>
   <div><small>Compatibility</small><strong id="glassesSoftwareStatus">No policy</strong></div>
   <div><small>Assigned employee</small><strong id="glassesSoftwareRuntime">None</strong></div>
  </div>
  <p class="glasses-software-note">Version policies only assess device compatibility. Native software updates are not installed from this page.</p>
  <div id="glassesSoftwarePolicy" class="glasses-software-policy" hidden>
   <strong>Restaurant software policy</strong>
   <div class="glasses-software-policy-inputs">
    <label>Minimum version <input id="glassesSoftwareMinimum" placeholder="1.2.0" maxlength="48"></label>
    <label>Target version <input id="glassesSoftwareTarget" placeholder="1.3.0" maxlength="48"></label>
    <label>Notes <input id="glassesSoftwareNotes" maxlength="240" placeholder="Update instructions"></label>
    <button id="glassesSoftwareSave" type="button">Save policy</button>
   </div>
  </div>
  <div class="glasses-software-actions">
   <button id="glassesSoftwareDiagnostics" type="button">Device diagnostics</button>
   <span id="glassesSoftwareNotice" role="status" aria-live="polite">Checking fleet…</span>
  </div>
  <div id="glassesSoftwareEvents" hidden></div>
  <details class="glasses-fleet-details">
   <summary>All visible paired glasses <span id="glassesFleetCount"></span></summary>
   <div class="glasses-fleet-scroll"><table><thead><tr><th>Device / location</th><th>Software</th><th>Connection</th><th>Runtime</th><th></th></tr></thead>
    <tbody id="glassesFleetRows"></tbody></table></div>
  </details>
 </section>
</main>
<script>
window.GELATO_GLASSES_SIMULATOR={
 csrf:<?=json_encode(app_csrf_token(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,
 userId:<?=(int)$user['id']?>,runtimePublicId:'',
 canManageMedia:<?=$canManage?'true':'false'?>
};
</script>
<script src="assets/js/glasses-software-manager.js?v=20261013-275"></script>
<script src="assets/js/glasses-fleet-page.js?v=20261013-276fix"></script>
</body></html>
