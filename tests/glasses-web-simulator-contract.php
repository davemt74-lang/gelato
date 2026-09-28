<?php
declare(strict_types=1);

$root=dirname(__DIR__);
function gws_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function gws_file(string $path): string {global $root;$c=file_get_contents($root.'/'.$path);if($c===false)throw new RuntimeException('Missing '.$path);return $c;}

$api=gws_file('api/glasses-simulator.php');
$core=gws_file('includes/glasses-simulator.php');
$page=gws_file('glasses-simulator.php');
$js=gws_file('assets/js/glasses-web-simulator.js');
$css=gws_file('assets/css/glasses-web-simulator.css');

gws_assert(str_contains($api,"app_require_auth"),'Simulator API must require authenticated Gelato user.');
gws_assert(str_contains($api,"app_verify_request_csrf"),'Simulator mutations must verify CSRF.');
gws_assert(str_contains($core,"operational_location_allowed"),'Simulator must preserve location-scoped access.');
gws_assert(str_contains($core,"app_has_permission('glasses.manage'"),'Live writes must require glasses management.');
gws_assert(str_contains($core,"app_has_permission('kds.update'"),'Live writes must require KDS update permission.');
gws_assert(str_contains($core,'glasses_current_work'),'Simulator must use canonical glasses work projection.');
gws_assert(str_contains($core,'glasses_build_start'),'Simulator must use canonical build sessions.');
gws_assert(str_contains($core,'glasses_build_observe'),'Simulator detections must use canonical observation ledger.');
gws_assert(str_contains($core,'glasses_validation_evaluate'),'Simulator must use real product validation.');
gws_assert(str_contains($core,'glasses_handoff_to_expo'),'Simulator must preserve controlled Expo handoff.');
gws_assert(!str_contains($core,'UPDATE kds_order_items'),'Simulator core must not bypass KDS lifecycle with direct updates.');
gws_assert(str_contains($page,'lens-projection'),'Page must have a dedicated lens projection surface.');
gws_assert(str_contains($page,'detectionLayer'),'Page must expose ingredient outline layer.');
gws_assert(str_contains($js,"mode==='mock'"),'Simulator must have isolated mock mode.');
gws_assert(str_contains($js,"mode==='live'"),'Simulator must have real Gelato live mode.');
gws_assert(str_contains($js,"metadata:{source:'web_glasses_simulator'"),'Live simulated observations must be source-labelled.');
gws_assert(str_contains($js,"validation.status!=='ready_for_finishing'"),'Expo control must remain gated by product validation.');
gws_assert(str_contains($js,'function selectedItem()'),'Live simulator must allow explicit KDS work selection.');
gws_assert(str_contains($js,'resetSimulator'),'Simulator must support releasing local build UI state for the next ticket.');
gws_assert(str_contains($page,'workItemSelect'),'Page must expose KDS item selector.');
gws_assert(str_contains($css,'.hud-right'),'HUD must keep persistent projection on the right side.');
gws_assert(str_contains($css,'.detection-box'),'HUD must support ingredient bounding boxes.');

echo "glasses-web-simulator-ok\n";
