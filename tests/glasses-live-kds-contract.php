<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$src=(string)file_get_contents($root.'/includes/glasses-simulator.php');
$api=(string)file_get_contents($root.'/api/glasses-simulator.php');
$js=(string)file_get_contents($root.'/assets/js/glasses-web-simulator.js');
$voice=(string)file_get_contents($root.'/assets/js/glasses-simulator-voice.js');
$page=(string)file_get_contents($root.'/glasses-simulator.php');
$check=static function(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FAIL Glasses Live KDS: $msg\n");exit(1);}};
$check(str_contains($api,'app_verify_request_csrf($in);'),'Every POST must require CSRF');
$check(str_contains($api,"'csrfToken'=>app_csrf_token()"),'Authenticated GET must expose a fresh session token');
$check(str_contains($api,"'Cache-Control: no-store, max-age=0'"),'Bootstrap must not be cached');
$check(str_contains($api,'glasses_simulator_work_with_notes($pdo,$device)'),'Live work refresh must return saved notes');
foreach(['kds.ticket.action','kds.item.transition','kds.item.note'] as $action)
  $check(str_contains($src,"'".$action."'"),'Missing action '.$action);
$check(str_contains($src,"app_has_permission('kds.view'")&&str_contains($src,"app_has_permission('kds.update'"),'Both KDS view and update required');
$check(str_contains($src,'operational_location_allowed($pdo,$user,\'kds.update\',$locationId)'),'Kitchen writes must be location-scoped');
$check(str_contains($src,'(int)$item[\'location_id\']!==$locationId'),'Cross-location items must be forbidden');
$check(str_contains($src,'(int)$item[\'station_id\']!==$stationId'),'Cross-station items must be forbidden');
$check(str_contains($src,'hash_equals($expected,(string)$item[\'status\'])'),'Client must supply the current item status as a stale-state guard');
$check(str_contains($src,'kds_production_ticket_action('),'Ticket start/ready/bump must use canonical KDS transitions');
$check(str_contains($src,'kds_transition('),'Item start/ready must use canonical KDS transitions');
$check(str_contains($src,"'glasses_item_note'"),'Added item must be a persisted operator note');
$check(str_contains($src,'kds_event(')&&str_contains($src,'app_audit('),'Notes must have event and audit provenance');
$check(!str_contains($src,'UPDATE kds_order_items'),'Simulator must never hand-code KDS status updates');
$check(!str_contains($src,'INSERT INTO pos_check_items'),'Operator notes cannot create POS sales');
$check(str_contains($js,'if(!SIM_READ_ONLY_ACTIONS.has(action))'),'No consequential replay after a stale CSRF response');
$check(str_contains($js,'if(action===\'finish_order\')')&&str_contains($js,'expires:now+12000'),'Ticket completion must require repeated explicit confirmation');
$check(str_contains($js,"state.device?.kitchenWritable"),'Controls must be gated by location-authorized KDS permission');
$check(str_contains($js,"state.mode==='mock'"),'Mock must remain isolated from live operations');
$check(str_contains($js,'POS and ingredient verification were not changed.'),'Operator notes must not claim ingredient validation');
$check(str_contains($voice,"document.addEventListener('click'"),'Manual HUD controls must use the same dispatcher as voice');
foreach(['start_order','start_item','ready_item','ready_order','finish_order','next_item','item_added'] as $command)
  $check(str_contains($page,'data-glasses-command="'.$command.'"'),'Missing manual HUD button '.$command);
$check(str_contains($voice,"provider.dispatch(command,{spoken})"),'Voice and manual inputs must share KDS action path');
echo "glasses-live-kds-contract=ok\n";
