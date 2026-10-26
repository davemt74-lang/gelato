<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/food-monster-multi-node.php';
$n=0;
function fm7test(bool $yes,string $reason):void {
  global $n;
  if(!$yes){fwrite(STDERR,"FM-G7 FAIL: ".$reason."\n");exit(1);}
  $n++;
}
$ask="Check today's orders, inventory and employee schedule; tell me what needs attention.";
$requested=fm7_requested_domains($ask);
fm7test(in_array('kitchen',$requested,true),'Orders detected');
fm7test(in_array('inventory',$requested,true),'Inventory detected');
fm7test(in_array('scheduling',$requested,true),'Schedule detected');
fm7test(fm7_requested_domains('Explain how yeast works')===[],'General prompts stay with existing LLM');
fm7test(fm7_requested_domains('How are the tickets today?')===[],'Single-domain prompts still use the original tool');
fm7test(fm7_requested_domains('Approve a purchase order and update shifts')===[],'Mutating intents excluded');
fm7test(fm7_requested_domains('Confirm it')===[],'Existing approval flow retained');
fm7test(count(fm7_requested_domains('Give me a full operations overview'))>=3,'Explicit complete overview');
$snapshot=['generatedAt'=>'2026-10-14T17:00:00-07:00','signals'=>[
 ['key'=>'kds:ready','node'=>'kds','severity'=>'high','score'=>90,'title'=>'Ticket #2 ready','detail'=>'Waiting for expo','nextMove'=>['prompt'=>'Review ready ticket']],
 ['key'=>'operations:low-stock','node'=>'operations','severity'=>'critical','score'=>99,'title'=>'Low cheese','detail'=>'Reorder point reached','nextMove'=>['prompt'=>'Review stock']],
 ['key'=>'scheduling:coverage','node'=>'scheduling','severity'=>'normal','score'=>70,'title'=>'Shift gap','detail'=>'One uncovered shift','nextMove'=>['prompt'=>'Review schedule']],
 ['key'=>'crm:lapsed','node'=>'crm','severity'=>'high','score'=>95,'title'=>'Private customer','detail'=>'Restricted CRM context']
]];
$result=fm7_briefing($snapshot,$requested);
fm7test(count($result['data']['steps'])===3,'Three read-only source steps');
fm7test($result['data']['mode']==='read_only','Read-only contract');
fm7test(count($result['data']['findings'])===3,'Irrelevant source filtered');
fm7test(!str_contains($result['answer'],'Private customer'),'No unrelated CRM data');
fm7test($result['data']['findings'][0]['severity']==='critical','Priority sorting');
fm7test(str_contains($result['answer'],'No orders, inventory'),'No execution claim');
fm7test(count($result['sources'])>=3,'Source labels attached');
$empty=fm7_briefing(['signals'=>[]],['inventory','scheduling']);
fm7test(str_contains($empty['answer'],'does NOT certify'),'Empty signals are not false green claims');
$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/api/food-monster-orchestrate.php');
$route=(string)file_get_contents($root.'/api/agent-workspace.php');
$front=(string)file_get_contents($root.'/js/global-agent.js');
fm7test(str_contains($api,'app_require_auth()'),'Logged-in user required');
fm7test(str_contains($api,'admin_dashboard_allowed($user)'),'Manager Brain permission required');
fm7test(str_contains($api,'app_verify_request_csrf($input)'),'CSRF verification');
fm7test(str_contains($api,'gaw_thread($pdo,$org,$uid,$threadId)'),'User and organization scoped thread');
fm7test(str_contains($api,'$last[\'public_id\']'),'Message-level replay key');
fm7test(str_contains($api,"'fm_g7_briefing'")||str_contains($api,'fm_g7_briefing'),'Replay protection');
fm7test(str_contains($api,'agent_brain_orchestration_snapshot'),'Use existing Brain data source');
fm7test(!str_contains($api,'curl_exec(')&&!str_contains($api,'apiKey'),'No custom model or URL execution');
fm7test(str_contains($route,"'nodeMode'=>'read_orchestrator'"),'Planner route cannot write');
fm7test(str_contains($front,'window.GelatoFoodMonsterLLM.ask({text,threadId:S.thread,csrf:S.csrf,privateTurnToken}'),'Authenticated model-first conversation from footer');
fm7test(str_contains($front,'const delegation=result?.data?.delegation')&&str_contains($front,'serverMessage!==String(submitted?.message?.id'), 'Existing tool routes require a verified model handoff');
echo "FM-G7 safe multi-node orchestration: PASS ($n checks)\n";
