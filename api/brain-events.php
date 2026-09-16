<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/timeclock-voice-core.php';
require_once __DIR__.'/../includes/agent-brain-orchestrator.php';
require_once __DIR__.'/../includes/live-shift-brain.php';
require_once __DIR__.'/../includes/customer-crm-brain.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!admin_dashboard_allowed($user)||!app_has_permission('agent.proactive',$user))app_json_response(['ok'=>false,'message'=>'Proactive manager Agent access is not available for this account.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);
if((string)($input['action']??'synthesize')!=='synthesize')app_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain event action.'],422);
if(!tv_ready($pdo))app_json_response(['ok'=>false,'message'=>'Proactive Agent migration is not installed. Run upgrade.php.'],503);

try{
    $events=array_merge(agent_brain_manager_proactive_signals($pdo,$user),live_shift_proactive_events($pdo,$user),customer_crm_proactive_events($pdo,$user));
    $unique=[];foreach($events as $event)$unique[(string)$event['key']]=$event;$events=array_values($unique);
    $put=$pdo->prepare("INSERT INTO proactive_agent_events (organization_id,user_id,public_id,event_type,priority,message,action_url,dedupe_key,metadata_json) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE message=VALUES(message),priority=VALUES(priority),action_url=VALUES(action_url),metadata_json=VALUES(metadata_json)");
    foreach($events as $event){$put->execute([$org,$uid,tv_public_id('proactive'),$event['type'],$event['priority'],$event['message'],$event['url']?:null,$event['key'],json_encode($event['meta'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
    app_json_response(['ok'=>true,'eventCount'=>count($events)]);
}catch(Throwable $e){error_log('Agent Brain event bridge failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Gelato could not synthesize proactive manager events.'],500);}