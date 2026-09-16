<?php
declare(strict_types=1);

function sac_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function sac_text(string $path):string{$value=@file_get_contents($path);if($value===false)throw new RuntimeException('Unable to read '.$path);return $value;}

$root=dirname(__DIR__);
$context=sac_text($root.'/js/agent-page-context.js');
$schedulingContext=sac_text($root.'/js/scheduling-agent-context.js');
$loader=sac_text($root.'/js/global-add-canvas.js');
$route=sac_text($root.'/api/agent-workspace.php');
$agent=sac_text($root.'/api/scheduling-agent.php');
$posLoader=sac_text($root.'/js/pos.js');
$posContext=sac_text($root.'/js/pos-agent-context.js');

sac_assert(str_contains($context,'GelatoAgentPageContext = {register'),'Shared Agent page context must expose a provider registry.');
sac_assert(str_contains($context,'body.pageContext = context'),'Shared Agent transport must attach page context to Agent requests.');
sac_assert(str_contains($context,"file === 'agent-workspace.php' || /-agent\\.php$/i.test(file)"),'Shared context transport must cover routing and routed Agent skills.');
sac_assert(str_contains($context,'/password|secret|token|csrf|cookie|authorization/i'),'Shared context sanitizer must strip credential-like keys.');

sac_assert(str_contains($schedulingContext,"module: 'scheduling'")&&str_contains($schedulingContext,'selectedShiftPublicId')&&str_contains($schedulingContext,'selectedStaffUserId'),'Scheduling context must identify selected shift and employee by identifiers.');
sac_assert(str_contains($schedulingContext,'week: context.week')&&str_contains($schedulingContext,'activeTab: context.activeTab'),'Scheduling context must carry visible week and active tab.');
sac_assert(str_contains($schedulingContext,"document.getElementById('scheduleAgentInput')")&&str_contains($schedulingContext,'bar.remove()'),'Scheduling must retire the legacy page-specific chat bar once the shared Agent loads.');
sac_assert(!str_contains($schedulingContext,'display_name')&&!str_contains($schedulingContext,'email')&&!str_contains($schedulingContext,'phone'),'Scheduling transport must not scrape employee identity/contact fields into Agent context.');

$contextPos=strpos($loader,'agent-page-context.js');$schedulePos=strpos($loader,'scheduling-agent-context.js');$globalPos=strpos($loader,'global-agent.js');
sac_assert($contextPos!==false&&$schedulePos!==false&&$globalPos!==false&&$contextPos<$schedulePos&&$schedulePos<$globalPos,'Global loader must install shared context and page adapter before the Agent transport starts.');
sac_assert(str_contains($route,"\$isSchedulingContext")&&str_contains($route,"'domain'=>'scheduling_context'")&&str_contains($route,'selectedShiftPublicId'),'Agent Workspace must route ambiguous selected Scheduling context to the Scheduling Agent.');

sac_assert(str_contains($agent,'sa_pending_store')&&str_contains($agent,'sa_pending_get')&&str_contains($agent,'sa_pending_clear'),'Scheduling Agent writes must use server-side pending proposals.');
sac_assert(str_contains($agent,"'expires'=>time()+600"),'Scheduling Agent proposals must expire quickly.');
sac_assert(str_contains($agent,'Reply Confirm to execute this change, or Cancel to discard it.'),'Scheduling Agent must require explicit human confirmation.');
sac_assert(str_contains($agent,"schedule.agent_action_proposed")&&str_contains($agent,"schedule.agent_action_confirmed")&&str_contains($agent,"schedule.agent_action_discarded"),'Scheduling Agent proposal lifecycle must be audited.');
sac_assert(str_contains($agent,"app_has_permission('schedule.manage',\$user)"),'Schedule mutations must remain permission-gated.');
sac_assert(str_contains($agent,"employee.handoffs.manage")&&str_contains($agent,'employee_shift_message_save'),'Employee messages must use the existing permission-gated shift communications system.');
sac_assert(str_contains($agent,'scheduling_availability_check')&&str_contains($agent,'scheduling_shift_conflicts'),'Coverage candidates and confirmed shift writes must respect availability and overlap validation.');
sac_assert(str_contains($agent,"'shift_create'")&&str_contains($agent,"'shift_update'")&&str_contains($agent,"'shift_cancel'")&&str_contains($agent,"'week_publish'")&&str_contains($agent,"'employee_message'"),'Scheduling Agent must support the approved first action set.');

sac_assert(str_contains($posLoader,'js/agent-page-context.js'),'POS must load the shared context layer before its adapter.');
sac_assert(str_contains($posContext,"module: 'pos'")&&str_contains($posContext,'GelatoAgentPageContext?.register?.(provider)'),'POS must register with the same shared context layer.');
sac_assert(!str_contains($posContext,'withAgentContext'),'POS must not maintain a second Agent-request context injector.');

echo "scheduling-agent-context-contract-ok\n";