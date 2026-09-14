<?php
declare(strict_types=1);

function fpv2_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function fpv2_source(string $path): string { $value=file_get_contents(__DIR__.'/../'.$path); if($value===false) throw new RuntimeException('Unable to read '.$path); return $value; }

$entry=fpv2_source('floor-planner.php');
$redirect=fpv2_source('floor-planner-ops.php');
$legacy=fpv2_source('floor-planner-ops-legacy.php');
$shell=fpv2_source('floor-planner-v2.php');
$api=fpv2_source('floor-plan-api.php');
$module=fpv2_source('js/floor-planner-module.js');
$js=fpv2_source('js/floor-planner-v2.js');
$runtime=fpv2_source('js/floor-planner-v2-runtime.js');

fpv2_assert(str_contains($entry,'floor-planner-v2.php'),'Primary Floor Planner entry must route to v2.');
fpv2_assert(str_contains($redirect,'floor-planner-v2.php'),'Legacy operational planner URL must redirect to v2.');
fpv2_assert(str_contains($legacy,'Operational Floor Planner'),'Internal canonical planner shell must remain available to the v2 wrapper.');
fpv2_assert(str_contains($shell,'floor-planner-ops-legacy.php'),'V2 shell must wrap the internal canonical operational planner.');
fpv2_assert(str_contains($shell,'floor-planner-v2.js?v=20260914-2'),'V2 shell must load the current interaction layer.');
fpv2_assert(str_contains($shell,'. $needle'),'V2 shell must preserve the canonical planner script between its preloader and post-runtime hardening.');
fpv2_assert(str_contains($shell,'floor-planner-v2-runtime.js?v=20260914-2'),'V2 shell must load current post-runtime hardening after the canonical planner.');
fpv2_assert(str_contains($shell,'Triple-click item = controls'),'V2 shell must make the slide-out item panel gesture discoverable.');
fpv2_assert(str_contains($shell,'drag-select = group move'),'V2 shell must make grouped movement discoverable.');
fpv2_assert(str_contains($api,"require __DIR__ . '/api/floor-plans.php'"),'Stable Floor Planner API entry must delegate to the canonical API.');
fpv2_assert(str_contains($module,"'floor-planner-v2.php'"),'Admin navigation must launch Floor Planner 2.0.');

fpv2_assert(str_contains($js,"'floor-plan-api.php'"),'Planner must rewrite canonical floor-plan requests to the stable application-relative route.');
fpv2_assert(str_contains($js,'fp-marquee'),'Planner must provide drag-box marquee selection.');
fpv2_assert(str_contains($js,'function startGroupDrag'),'Planner must support grouped drag movement.');
fpv2_assert(str_contains($js,'selection.size < 2'),'Grouped drag must operate on a multi-selection.');
fpv2_assert(!preg_match('/startGroupDrag[\s\S]{0,2200}locked/', $js),'Grouped movement must not exclude selected equipment because it is locked.');
fpv2_assert(str_contains($js,"'ArrowLeft','ArrowRight','ArrowUp','ArrowDown'"),'Planner must support arrow-key nudging.');
fpv2_assert(str_contains($js,"event.shiftKey?.5:1/12") || str_contains($js,"event.shiftKey ? .5 : 1/12"),'Planner must support larger Shift+Arrow nudges.');
fpv2_assert(str_contains($js,"key.toLowerCase()==='z'"),'Planner must provide keyboard undo.');
fpv2_assert(str_contains($js,"key.toLowerCase()==='y'"),'Planner must provide keyboard redo.');
fpv2_assert(str_contains($js,'↶ Undo') && str_contains($js,'↷ Redo'),'Planner must expose visible undo/redo controls.');

fpv2_assert(str_contains($js,"semanticType:'counter'"),'Counter must persist as the semantic replacement for the legacy aisle object.');
fpv2_assert(str_contains($js,'counterPoints'),'Counter transform nodes must persist with plan data.');
fpv2_assert(str_contains($js,'Add Node') && str_contains($js,'Transform Shape') && str_contains($js,'Reset Shape'),'Counter right-click menu must expose node and transform controls.');
fpv2_assert(str_contains($js,"event.detail>=3"),'Triple-click must open the item control panel.');
fpv2_assert(str_contains($js,'.inspect.fp-open'),'Inspector must be a closed-by-default slide-out panel.');

fpv2_assert(str_contains($js,'fpEquipmentNode'),'Structure palette must include an Equipment node.');
fpv2_assert(str_contains($js,"api/equipment.php?asset="),'Equipment inspector must load the canonical Equipment Catalog record.');
fpv2_assert(str_contains($js,'Service contracts & contacts'),'Equipment control panel must include service contract/contact information.');
fpv2_assert(str_contains($js,'Service history'),'Equipment control panel must include service history.');
fpv2_assert(str_contains($js,'Operating knowledge'),'Equipment control panel must include operational/maintenance knowledge.');

fpv2_assert(str_contains($runtime,'event.stopImmediatePropagation()'),'V2 runtime must own delegated structural drag/resize events so Undo-restored items remain interactive.');
fpv2_assert(str_contains($runtime,'floor-planner-v2.php'),'Opening a canonical Equipment Record must return to Floor Planner 2.0.');
fpv2_assert(str_contains($runtime,"window.addEventListener('beforeunload'"),'V2 group/counter edits must participate in unsaved-change protection.');
fpv2_assert(str_contains($runtime,"floor-plan-api\\.php|api\\/floor-plans\\.php"),'A successful Floor Plan save must clear the v2 dirty state.');

echo "floor-planner-v2-contract-ok\n";
