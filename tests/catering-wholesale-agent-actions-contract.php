<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$failures=[];
function cw_assert(bool $condition,string $message): void {global $failures;if(!$condition)$failures[]=$message;}
function cw_file(string $path): string {global $root;$full=$root.'/'.$path;cw_assert(is_file($full),'Missing '.$path);return is_file($full)?(string)file_get_contents($full):'';}

$registry=cw_file('includes/agent-node-registry.php');
$workspace=cw_file('api/agent-workspace.php');
$catering=cw_file('includes/catering-agent-actions.php');
$wholesale=cw_file('includes/wholesale-agent-core.php');
$cateringApi=cw_file('api/catering-agent.php');
$wholesaleApi=cw_file('api/wholesale-agent.php');
$cateringContext=cw_file('js/catering-agent-context.js');
$wholesaleContext=cw_file('js/wholesale-agent-context.js');
$loader=cw_file('js/global-add-canvas.js');

cw_assert(str_contains($registry,"'catering'=>[")&&str_contains($registry,"'route'=>'api/catering-agent.php'")&&str_contains($registry,"'mode'=>'read_confirmed_write'"),'Catering node is not registered as confirmed-write.');
cw_assert(str_contains($registry,"'wholesale'=>[")&&str_contains($registry,"'route'=>'api/wholesale-agent.php'"),'Wholesale node is not registered.');
cw_assert(str_contains($workspace,'$isCateringContext=')&&str_contains($workspace,'$isWholesaleContext='),'Main Agent lacks Catering/Wholesale page context routing.');
cw_assert(str_contains($workspace,"\$pendingNode==='catering'")&&str_contains($workspace,"\$pendingNode==='wholesale'"),'Main Agent confirmation routing is missing Catering/Wholesale.');
cw_assert(str_contains($workspace,"gaw_node_route('catering','catering_context')")&&str_contains($workspace,"gaw_node_route('wholesale','wholesale_context')"),'Main Agent contextual node routes are missing.');

cw_assert(str_contains($catering,"gac_pending_store('catering'")&&str_contains($catering,"gac_pending_get('catering'"),'Catering actions do not use shared confirmation storage.');
foreach(['task_add','task_status','requirement_status','requirements_rebuild'] as $action)cw_assert(str_contains($catering,"'{$action}'"),'Catering action missing: '.$action);
cw_assert(str_contains($catering,"'catering.manage'")&&str_contains($catering,'expectedUpdatedAt')&&str_contains($catering,'menuSignature'),'Catering permission/concurrency guards are incomplete.');
cw_assert(str_contains($cateringApi,'catering_agent_actions_handle'),'Catering endpoint does not use confirmed-action handler.');

cw_assert(str_contains($wholesale,"gac_pending_store('wholesale'")&&str_contains($wholesale,"gac_pending_get('wholesale'"),'Wholesale actions do not use shared confirmation storage.');
foreach(['create_remaining_batch','batch_ready','batch_dispatch','batch_cancel','batch_deliver'] as $action)cw_assert(str_contains($wholesale,"'{$action}'"),'Wholesale action missing: '.$action);
cw_assert(str_contains($wholesale,"'wholesale.manage'")&&str_contains($wholesale,"'inventory.manage'")&&str_contains($wholesale,'progressSignature')&&str_contains($wholesale,'expectedBatch'),'Wholesale permission/concurrency guards are incomplete.');
cw_assert(str_contains($wholesaleApi,'wholesale_agent_handle'),'Wholesale endpoint does not use staff Wholesale Agent handler.');

cw_assert(str_contains($cateringContext,"module: 'catering'")&&str_contains($cateringContext,'selectedOperationPublicId'),'Catering page context is incomplete.');
cw_assert(str_contains($wholesaleContext,"module: 'wholesale'")&&str_contains($wholesaleContext,'selectedWholesaleOrderPublicId')&&str_contains($wholesaleContext,'selectedWholesaleBatchPublicId'),'Wholesale page context is incomplete.');
cw_assert(str_contains($loader,'catering-agent-context.js')&&str_contains($loader,'wholesale-agent-context.js'),'Global Agent loader does not load Catering/Wholesale context adapters.');

foreach([['Catering',$cateringContext],['Wholesale',$wholesaleContext]] as [$label,$source]){
    if(preg_match('/function transportSnapshot\(\)\s*\{(.+?)\n\s*\}/s',$source,$m)){
        cw_assert(!preg_match('/password|secret|token|csrf|cookie|authorization|email|phone/i',$m[1]),$label.' transport context contains sensitive fields.');
    }else $failures[]=$label.' transportSnapshot function not found.';
}

if($failures){fwrite(STDERR,"Catering + Wholesale Agent contract failed:\n- ".implode("\n- ",$failures)."\n");exit(1);}
echo "Catering + Wholesale Agent contract passed.\n";
