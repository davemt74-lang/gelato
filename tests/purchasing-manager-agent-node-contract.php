<?php
declare(strict_types=1);

function pman_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function pman_text(string $path):string{$value=@file_get_contents($path);if($value===false)throw new RuntimeException('Unable to read '.$path);return $value;}

$root=dirname(__DIR__);
$nodes=pman_text($root.'/includes/agent-node-registry.php');
$route=pman_text($root.'/api/agent-workspace.php');
$core=pman_text($root.'/includes/purchasing-agent-core.php');
$endpoint=pman_text($root.'/api/purchasing-agent.php');
$context=pman_text($root.'/js/purchasing-agent-context.js');
$loader=pman_text($root.'/js/global-add-canvas.js');
$manager=pman_text($root.'/api/admin-dashboard-agent.php');

pman_assert(str_contains($nodes,"'command_center'=>[")&&str_contains($nodes,"'purchasing'=>["),'Command Center and Purchasing must be registered as first-class Agent nodes.');
pman_assert(str_contains($nodes,"'mode'=>'read_orchestrator'")&&str_contains($nodes,"'mode'=>'read_confirmed_write'"),'Agent node registry must distinguish manager orchestration from confirmed domain writes.');
pman_assert(str_contains($nodes,'function gaw_node_route')&&str_contains($nodes,"'node'=>\$key"),'Main Agent routes must expose canonical node identity.');

pman_assert(str_contains($route,"gaw_node_route('command_center')")&&str_contains($route,"gaw_node_route('purchasing')"),'Main Agent chat must route directly into Command Center and Purchasing nodes.');
pman_assert(strpos($route,'$purchasingIntent')!==false&&strpos($route,'$costIntent')!==false&&strpos($route,'$purchasingIntent')<strpos($route,'$costIntent'),'Purchasing-specific intent must be resolved before broad cost intelligence.');
pman_assert(str_contains($route,"\$isPurchasingContext")&&str_contains($route,"gaw_node_route('purchasing','purchasing_context')"),'Purchasing page context must route ambiguous selected-PO language back to the Purchasing node.');
pman_assert(str_contains($route,'$confirmationIntent'),'Main Agent must route Purchasing Confirm/Cancel responses back to the domain node.');
pman_assert(str_contains($route,'gaw_normalize_node_route($fallback)'),'Legacy Agent routes must normalize into named nodes where registered.');

pman_assert(str_contains($context,"module: 'purchasing'")&&str_contains($context,'selectedPurchaseOrderPublicId'),'Purchasing page provider must identify the selected PO.');
pman_assert(str_contains($context,'function transportSnapshot()'),'Purchasing page context must have a minimized transport snapshot.');
pman_assert(!str_contains($context,'vendor_name')&&!str_contains($context,'pricePerPack')&&!str_contains($context,'invoice'),'Purchasing browser context must not send vendor pricing or invoice content.');
pman_assert(str_contains($context,'GelatoAgentPageContext?.register?.(provider)'),'Purchasing must register with the shared main Agent context layer.');
pman_assert(str_contains($loader,"page === 'purchasing.php'")&&str_contains($loader,'purchasing-agent-context.js'),'Shared Agent loader must install Purchasing context before Agent use.');

pman_assert(str_contains($core,'pac_pending_store')&&str_contains($core,"'expires'=>time()+600"),'Purchasing writes must use short-lived server-side proposals.');
pman_assert(str_contains($core,'Reply Confirm to execute this change, or Cancel to discard it.'),'Purchasing writes must require explicit confirmation.');
pman_assert(str_contains($core,"purchasing.agent_action_proposed")&&str_contains($core,"purchasing.agent_action_confirmed")&&str_contains($core,"purchasing.agent_action_discarded"),'Purchasing proposal lifecycle must be audited.');
pman_assert(str_contains($core,"pac_require(\$user,'purchasing.manage'"),'PO writes must require purchasing.manage.');
pman_assert(str_contains($core,"receiving.manage")&&str_contains($core,"receive_po_full"),'Receiving writes must require receiving.manage and a distinct confirmed action.');
pman_assert(str_contains($core,'pac_guard_order')&&str_contains($core,'updatedAt'),'Selected PO writes must use optimistic concurrency protection.');
pman_assert(str_contains($core,'purchasing_create_order')&&str_contains($core,'purchasing_submit')&&str_contains($core,'purchasing_receive'),'Confirmed actions must reuse canonical Purchasing functions.');
pman_assert(str_contains($core,'no damage or shortage exceptions'),'Full receiving must explicitly state that damage/shortage exceptions are not being recorded.');
pman_assert(str_contains($core,"'organizationId'=>\$org")&&str_contains($core,"'userId'=>\$uid")&&str_contains($core,'does not belong to your session'),'Pending Purchasing actions must be bound to the authenticated organization and user.');

pman_assert(str_contains($endpoint,"require_once __DIR__.'/../includes/purchasing-agent-core.php'")&&str_contains($endpoint,'purchasing_agent_handle($pdo,$user,$input)'),'Purchasing endpoint must delegate to the confirmed-action core.');
pman_assert(!str_contains($endpoint,'purchasing_create_order('),'HTTP endpoint must not directly create a PO before the confirmed-action core runs.');

pman_assert(str_contains($manager,'admin_dashboard_agent_purchasing')&&str_contains($manager,"app_has_permission('purchasing.view',\$user)"),'Command Center may only include Purchasing context when the user has domain visibility.');
pman_assert(str_contains($manager,'Purchasing changes are delegated to the Purchasing + Inventory node and remain confirmation-gated.'),'Command Center must delegate Purchasing mutations to the Purchasing node.');
pman_assert(!str_contains($manager,'purchasing_create_order(')&&!str_contains($manager,'purchasing_submit(')&&!str_contains($manager,'purchasing_receive('),'Read-orchestrator Command Center must not perform Purchasing writes.');

echo "purchasing-manager-agent-node-contract-ok\n";