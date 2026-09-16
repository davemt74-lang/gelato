<?php
declare(strict_types=1);

function sac_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function sac_text(string $path):string{$value=@file_get_contents($path);if($value===false)throw new RuntimeException('Unable to read '.$path);return $value;}

$root=dirname(__DIR__);
$context=sac_text($root.'/js/agent-page-context.js');
$schedulingContext=sac_text($root.'/js/scheduling-agent-context.js');
$loader=sac_text($root.'/js/global-add-canvas.js');
$route=sac_text($root.'/api/agent-workspace.php');
$nodes=sac_text($root.'/includes/agent-node-registry.php');
$endpoint=sac_text($root.'/api/scheduling-agent.php');
$core=sac_text($root.'/includes/scheduling-agent-core.php');
$posLoader=sac_text($root.'/js/pos.js');
$posContext=sac_text($root.'/js/pos-agent-context.js');

sac_assert(str_contains($context,'GelatoAgentPageContext = {register'),'Shared Agent page context must expose a provider registry.');
sac_assert(str_contains($context,'body.pageContext = context'),'Shared Agent transport must attach page context to Agent requests.');
sac_assert(str_contains($context,"file === 'agent-workspace.php' || /-agent\\.php$/i.test(file)"),'Shared context transport must cover routing and routed Agent skills.');
sac_assert(str_contains($context,'/password|secret|token|csrf|cookie|authorization/i'),'Shared context sanitizer must strip credential-like keys.');

sac_assert(str_contains($schedulingContext,"module: 'scheduling'")&&str_contains($schedulingContext,'selectedShiftPublicId')&&str_contains($schedulingContext,'selectedStaffUserId'),'Scheduling context must identify selected shift and employee by identifiers.');
sac_assert(str_contains($schedulingContext,'week: context.week')&&str_contains($schedulingContext,'activeTab: context.activeTab'),'Scheduling context must carry visible week and active tab.');
sac_assert(str_contains($schedulingContext,"document.getElementById('scheduleAgentInput')")&&str_contains($schedulingContext,'bar.remove()'),'Scheduling must retire the legacy page-specific chat bar once the shared Agent loads.');
sac_assert(str_contains($schedulingContext,"result?.skill !== 'schedule.action_confirmed'")&&str_contains($schedulingContext,"document.getElementById('refresh')?.click()"),'Confirmed Scheduling Agent actions must refresh the visible scheduling workspace.');
sac_assert(!str_contains($schedulingContext,'display_name')&&!str_contains($schedulingContext,'email')&&!str_contains($schedulingContext,'phone'),'Scheduling transport must not scrape employee identity/contact fields into Agent context.');

$contextPos=strpos($loader,'agent-page-context.js');$schedulePos=strpos($loader,'scheduling-agent-context.js');$globalPos=strpos($loader,'global-agent.js');
sac_assert($contextPos!==false&&$schedulePos!==false&&$globalPos!==false&&$contextPos<$schedulePos&&$schedulePos<$globalPos,'Global loader must install shared context and page adapter before the Agent transport starts.');
sac_assert(str_contains($nodes,"'scheduling'=>[")&&str_contains($nodes,"'route'=>'api/scheduling-agent.php'")&&str_contains($nodes,"'mode'=>'read_confirmed_write'"),'Scheduling must remain a first-class confirmed-write Agent node.');
sac_assert(str_contains($route,"\$isSchedulingContext")&&str_contains($route,"gaw_node_route('scheduling','scheduling_context')")&&str_contains($route,'$localIntent'),'Agent Workspace must route ambiguous Scheduling page language through the named Scheduling node.');
sac_assert(str_contains($route,'$confirmationIntent')&&str_contains($route,'gaw_pending_action_node($org,$uid)')&&str_contains($route,"gaw_node_route('scheduling','scheduling_confirmation')"),'Scheduling confirmations must route back to their owning node even outside the Scheduling page.');

sac_assert(str_contains($endpoint,"require_once __DIR__.'/../includes/scheduling-agent-core.php'")&&str_contains($endpoint,'scheduling_agent_handle($pdo,$user,$input)'),'Scheduling Agent HTTP endpoint must delegate to the reusable secure action core.');
sac_assert(str_contains($endpoint,'catch(SchedulingAgentPermissionException $e)')&&str_contains($endpoint,'],403)'),'Scheduling Agent endpoint must distinguish permission failures from validation failures.');
sac_assert(str_contains($endpoint,'$hasScheduleVisibility')&&str_contains($endpoint,'$confirmingStaffMessage')&&str_contains($endpoint,'Schedule visibility permission is required for scheduling intelligence.'),'Staff identity access must not silently grant scheduling intelligence; staff-message confirmations are the scoped exception.');

sac_assert(str_contains($core,'sac_pending_store')&&str_contains($core,'sac_pending_get')&&str_contains($core,'sac_pending_clear'),'Scheduling Agent writes must use server-side pending proposals.');
sac_assert(str_contains($core,"'expires'=>time()+600"),'Scheduling Agent proposals must expire quickly.');
sac_assert(str_contains($core,'Reply Confirm to execute this change, or Cancel to discard it.'),'Scheduling Agent must require explicit human confirmation.');
sac_assert(str_contains($core,"schedule.agent_action_proposed")&&str_contains($core,"schedule.agent_action_confirmed")&&str_contains($core,"schedule.agent_action_discarded"),'Scheduling Agent proposal lifecycle must be audited.');
sac_assert(str_contains($core,"sac_require(\$user,'schedule.manage'"),'Schedule mutations must remain permission-gated.');
sac_assert(str_contains($core,"employee.handoffs.manage")&&str_contains($core,'employee_shift_message_save'),'Employee messages must use the existing permission-gated shift communications system.');
sac_assert(str_contains($core,'scheduling_availability_check')&&str_contains($core,'scheduling_shift_conflicts'),'Coverage candidates and shift writes must respect availability and overlap validation.');
sac_assert(str_contains($core,"'shift_create'")&&str_contains($core,"'shift_update'")&&str_contains($core,"'shift_cancel'")&&str_contains($core,"'week_publish'")&&str_contains($core,"'employee_message'"),'Scheduling Agent must support the approved first action set.');
sac_assert(str_contains($core,'expectedUpdatedAt')&&str_contains($core,'sac_guard_shift_version'),'Shift proposals must use optimistic concurrency guards before execution.');
sac_assert(str_contains($core,'sac_week_snapshot')&&str_contains($core,"That schedule week changed after I proposed publishing it."),'Week publication must reject stale proposals.');
sac_assert(str_contains($core,'sac_view_all')&&str_contains($core,'sac_view_self')&&str_contains($core,'Scheduling or staff visibility permission is required.'),'Scheduling Agent permission must not bypass underlying schedule/staff visibility permissions.');
sac_assert(str_contains($core,"Schedule view permission is required for the restaurant roster.")&&str_contains($core,"Schedule view permission is required to compare staff availability."),'Cross-staff roster and availability intelligence must require schedule-wide visibility.');
sac_assert(str_contains($core,"'organizationId'=>\$org")&&str_contains($core,"'userId'=>\$userId")&&str_contains($core,'does not belong to your session'),'Pending actions must be bound to the authenticated organization and user.');

sac_assert(str_contains($posLoader,'js/agent-page-context.js'),'POS must load the shared context layer before its adapter.');
sac_assert(str_contains($posContext,"module: 'pos'")&&str_contains($posContext,'GelatoAgentPageContext?.register?.(provider)'),'POS must register with the same shared context layer.');
sac_assert(!str_contains($posContext,'withAgentContext'),'POS must not maintain a second Agent-request context injector.');

echo "scheduling-agent-context-contract-ok\n";