<?php
declare(strict_types=1);

require_once __DIR__.'/admin-dashboard-core.php';
require_once __DIR__.'/purchasing-receiving.php';
require_once __DIR__.'/scheduling-core.php';
require_once __DIR__.'/prep-intelligence-core.php';
require_once __DIR__.'/operations-core.php';
require_once __DIR__.'/customer-crm-core.php';
require_once __DIR__.'/agent-node-registry.php';

function abo_severity_rank(string $severity): int
{
    return match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
}

function abo_signal(string $key,string $node,string $severity,int $score,string $title,string $detail,string $href,array $evidence,string $prompt,string $mode='review'): array
{
    $nodeInfo=gaw_agent_node($node)??[];
    return [
        'key'=>$key,
        'node'=>$node,
        'nodeLabel'=>(string)($nodeInfo['label']??$node),
        'severity'=>$severity,
        'score'=>max(0,min(100,$score)),
        'title'=>$title,
        'detail'=>$detail,
        'href'=>$href,
        'evidence'=>$evidence,
        'nextMove'=>[
            'node'=>$node,
            'nodeLabel'=>(string)($nodeInfo['label']??$node),
            'mode'=>$mode,
            'label'=>$mode==='propose'?'Propose Fix':'Review',
            'prompt'=>$prompt,
        ],
    ];
}

function abo_query_count(PDO $pdo,string $sql,array $args): int
{
    try{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}catch(Throwable){return 0;}
}

function abo_operations_signals(PDO $pdo,array $user): array
{
    $org=(int)$user['organization_id'];$signals=[];
    if(!operations_core_ready($pdo))return $signals;
    if(app_has_permission('tasks.view',$user)){
        $overdue=abo_query_count($pdo,"SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=? AND archived_at IS NULL AND status NOT IN ('completed','verified','cancelled') AND due_at IS NOT NULL AND due_at<NOW(6)",[$org]);
        $dueSoon=abo_query_count($pdo,"SELECT COUNT(*) FROM restaurant_tasks WHERE organization_id=? AND archived_at IS NULL AND status NOT IN ('completed','verified','cancelled') AND due_at BETWEEN NOW(6) AND DATE_ADD(NOW(6),INTERVAL 2 HOUR)",[$org]);
        if($overdue>0)$signals[]=abo_signal('operations:overdue','operations','high',88,min($overdue,99).' overdue restaurant task'.($overdue===1?'':'s'),'Overdue work is carrying operational risk into the current shift.','operations.php',['overdueTasks'=>$overdue],'Show me the overdue restaurant tasks and the best order to clear them.','review');
        elseif($dueSoon>0)$signals[]=abo_signal('operations:due-soon','operations','normal',62,$dueSoon.' task'.($dueSoon===1?' is':'s are').' due within two hours','Near-term work should be checked against current staffing and kitchen load.','operations.php',['dueSoon'=>$dueSoon],'Show the tasks due in the next two hours and who owns them.','review');
    }
    if(app_has_permission('inventory.view',$user)){
        $low=operations_inventory_list($pdo,$org,'',true,100);
        if($low){$names=array_values(array_filter(array_map(static fn($r)=>(string)($r['name']??''),array_slice($low,0,4))));$signals[]=abo_signal('operations:low-stock','operations','high',84,count($low).' inventory item'.(count($low)===1?' is':'s are').' at or below reorder point','Low stock includes '.implode(', ',$names).(count($low)>4?' and more':'').'.','operations.php',['lowStockCount'=>count($low),'items'=>$names],'Review the low-stock inventory items and tell me which ones need action first.','review');}
    }
    return $signals;
}

function abo_schedule_signals(PDO $pdo,array $user): array
{
    if(!scheduling_core_ready($pdo)||!(app_has_permission('schedule.view',$user)||app_has_permission('schedule.manage',$user)))return [];
    $summary=scheduling_summary($pdo,(int)$user['organization_id'],scheduling_week_start());$signals=[];
    if((int)$summary['coverageGaps']>0)$signals[]=abo_signal('scheduling:coverage','scheduling','high',90,(int)$summary['coverageGaps'].' staffing coverage gap'.((int)$summary['coverageGaps']===1?'':'s').' this week','Minimum staffing rules are not fully covered.','scheduling.php',['coverageGaps'=>$summary['coverageGaps'],'coverage'=>$summary['coverage']],'Show the staffing coverage gaps and identify the best available coverage candidates.','review');
    if((int)$summary['openShifts']>0)$signals[]=abo_signal('scheduling:open-shifts','scheduling','normal',67,(int)$summary['openShifts'].' open shift'.((int)$summary['openShifts']===1?'':'s').' remain unassigned','Unassigned shifts can create execution risk as service approaches.','scheduling.php',['openShifts'=>$summary['openShifts'],'weekStart'=>$summary['weekStart']],'Show me the open shifts this week and the best available people for each.','review');
    return $signals;
}

function abo_purchasing_signals(PDO $pdo,array $user): array
{
    if(!app_has_permission('purchasing.view',$user)||!purchasing_ready($pdo))return [];
    $summary=purchasing_summary($pdo,(int)$user['organization_id']);$suggestions=purchasing_suggestions($pdo,(int)$user['organization_id']);$signals=[];
    if((int)($summary['unmapped']??0)>0)$signals[]=abo_signal('purchasing:unmapped','purchasing','high',86,(int)$summary['unmapped'].' purchasing suggestion'.((int)$summary['unmapped']===1?' has':'s have').' no vendor map','Gelato cannot safely turn unmapped demand into a vendor PO until the mapping is reviewed.','purchasing.php',['unmapped'=>$summary['unmapped']],'Show the purchasing suggestions without vendor mappings and what needs to be mapped.','review');
    if($suggestions){$top=$suggestions[0];$need=(float)($top['needQuantity']??0);$name=(string)($top['name']??'inventory item');$signals[]=abo_signal('purchasing:pressure','purchasing',$need>0?'high':'normal',$need>0?82:60,count($suggestions).' purchasing suggestion'.(count($suggestions)===1?'':'s').' need review','Highest current pressure: '.$name.' needs about '.round($need,2).' '.(string)($top['unit']??'units').'.','purchasing.php',['suggestionCount'=>count($suggestions),'top'=>$top],'Review current purchasing suggestions and tell me what should be ordered first.','review');}
    if((int)($summary['openOrders']??0)>0)$signals[]=abo_signal('purchasing:open-orders','purchasing','normal',58,(int)$summary['openOrders'].' purchase order'.((int)$summary['openOrders']===1?' is':'s are').' still open','$'.number_format((float)($summary['committed']??0),2).' is currently committed to submitted/open purchasing.','purchasing.php',['openOrders'=>$summary['openOrders'],'committed'=>$summary['committed']??0],'Show me open purchase orders, expected delivery status, and anything that needs receiving follow-up.','review');
    return $signals;
}

function abo_prep_signals(PDO $pdo,array $user): array
{
    if(!prep_intelligence_ready($pdo)||!app_has_permission('prep.intelligence.view',$user))return [];
    $org=(int)$user['organization_id'];$today=(new DateTimeImmutable('today'))->format('Y-m-d');$signals=[];
    try{
        $q=$pdo->prepare("SELECT * FROM prep_plans WHERE organization_id=? AND plan_date=? ORDER BY FIELD(service_period,'all','lunch','dinner','morning','closing') LIMIT 1");$q->execute([$org,$today]);$plan=$q->fetch();
        if(!$plan){if(app_has_permission('prep.intelligence.manage',$user)&&(int)date('G')>=7)$signals[]=abo_signal('prep:missing','prep','normal',68,'Today does not have a Prep Intelligence plan','A current prep plan has not been generated for today.','prep-intelligence.php',['date'=>$today],'Generate today’s prep recommendations from current history and demand signals.','propose');return $signals;}
        $detail=prep_plan_detail($pdo,$org,(string)$plan['public_id']);
        $flagged=array_values(array_filter((array)($detail['forecasts']??[]),static fn(array $f):bool=>(float)($f['shortage_quantity']??0)>0||(float)($f['restock_quantity']??0)>0));
        if($flagged){$names=array_slice(array_values(array_filter(array_map(static fn($f)=>(string)($f['inventory_name']??''),$flagged))),0,4);$signals[]=abo_signal('prep:shortage','prep','high',92,count($flagged).' prep/inventory forecast item'.(count($flagged)===1?' needs':'s need').' attention','Forecast pressure includes '.implode(', ',$names).(count($flagged)>4?' and more':'').'.','prep-intelligence.php',['planId'=>$plan['public_id'],'count'=>count($flagged),'items'=>$names],'Show today’s prep shortages and tell me what should be prepped or restocked first.','review');}
        $pending=count(array_filter((array)($detail['recommendations']??[]),static fn(array $r):bool=>in_array((string)($r['status']??''),['proposed','accepted'],true)));
        if((string)$plan['status']==='draft'&&$pending>0)$signals[]=abo_signal('prep:ready','prep','normal',65,$pending.' prep recommendation'.($pending===1?' is':'s are').' ready for review','Today’s plan is still draft and has actionable recommendations.','prep-intelligence.php',['planId'=>$plan['public_id'],'recommendations'=>$pending],'Review today’s prep recommendations and explain what should be published.','review');
    }catch(Throwable){}
    return $signals;
}

function abo_crm_signals(PDO $pdo,array $user): array
{
    if(!app_has_permission('crm.view',$user)||!crm_ready($pdo))return [];
    $org=(int)$user['organization_id'];
    try{
        $q=$pdo->prepare("SELECT c.public_id,c.display_name,MAX(p.closed_at) last_visit,COUNT(p.id) visits,COALESCE(SUM(GREATEST(0,p.total_amount-p.tip_amount)),0) spend FROM crm_customers c JOIN pos_checks p ON p.organization_id=c.organization_id AND p.customer_id=c.id AND p.status='paid' WHERE c.organization_id=? AND c.status='active' GROUP BY c.id,c.public_id,c.display_name HAVING last_visit<DATE_SUB(NOW(),INTERVAL 45 DAY) AND spend>=100 ORDER BY spend DESC LIMIT 10");$q->execute([$org]);$rows=$q->fetchAll();
        if(!$rows)return [];$top=$rows[0];
        return [abo_signal('crm:lapsed-value','crm','normal',61,count($rows).' higher-value customer'.(count($rows)===1?' has':'s have').' gone quiet for 45+ days',(string)$top['display_name'].' is the highest-value lapsed relationship in this group at $'.number_format((float)$top['spend'],2).' lifetime paid spend.','crm.php',['count'=>count($rows),'topCustomerPublicId'=>$top['public_id'],'topCustomer'=>$top['display_name'],'topSpend'=>(float)$top['spend']],'Review higher-value customers who have not visited in 45 days and identify the best relationship follow-ups.','review')];
    }catch(Throwable){return [];}
}

function abo_dashboard_signals(array $dashboard): array
{
    $signals=[];$totals=$dashboard['totals']??[];$wholesale=$dashboard['wholesale']??[];$catering=$dashboard['catering']??[];$scope=(string)($dashboard['scope']['locationName']??'All locations');
    if((int)($totals['readyTickets']??0)>0)$signals[]=abo_signal('kds:ready','kds','high',96,(int)$totals['readyTickets'].' kitchen ticket'.((int)$totals['readyTickets']===1?' is':'s are').' READY','Expo-ready tickets are waiting to leave the kitchen at '.$scope.'.','kds.php',['readyTickets'=>$totals['readyTickets']],'Read the ready kitchen orders and tell me which ticket should move first.','review');
    $oldest=0;$oldestCheck=null;foreach((array)($dashboard['activeTickets']??[]) as $check){$age=(int)($check['ageMinutes']??0);if($age>$oldest){$oldest=$age;$oldestCheck=$check;}}
    if($oldest>=25)$signals[]=abo_signal('pos:old-check','pos',$oldest>=40?'high':'normal',$oldest>=40?90:72,'Oldest open POS check is '.$oldest.' minutes old','Check '.(string)($oldestCheck['number']??'').' has been open longer than expected and may need service follow-up.','pos.php',['ageMinutes'=>$oldest,'checkPublicId'=>$oldestCheck['id']??null,'checkNumber'=>$oldestCheck['number']??null],'Review the oldest open check and tell me what is holding it up.','review');
    if((int)($dashboard['onlineOrders']['open']??0)>0)$signals[]=abo_signal('online:open','command_center','normal',55,(int)$dashboard['onlineOrders']['open'].' online order'.((int)$dashboard['onlineOrders']['open']===1?' is':'s are').' still open','Pickup and kitchen progression should be checked against current service load.','online-orders-admin.php',['openOnlineOrders'=>$dashboard['onlineOrders']['open']],'Show the open online orders and identify any that need attention.','review');
    if((int)($wholesale['overdueInvoices']??0)>0)$signals[]=abo_signal('wholesale:overdue','command_center','high',91,(int)$wholesale['overdueInvoices'].' wholesale invoice'.((int)$wholesale['overdueInvoices']===1?' is':'s are').' overdue','Outstanding Wholesale A/R is $'.number_format((float)($wholesale['outstandingReceivables']??0),2).'.','wholesale-accounts.php',['overdueInvoices'=>$wholesale['overdueInvoices'],'outstandingReceivables'=>$wholesale['outstandingReceivables']??0],'Review overdue wholesale receivables and tell me which account needs follow-up first.','review');
    if((int)($catering['atRisk']??0)>0)$signals[]=abo_signal('catering:risk','command_center','high',93,(int)$catering['atRisk'].' upcoming catering event'.((int)$catering['atRisk']===1?' is':'s are').' below 80% readiness','Catering readiness risk is inside the next seven-day operating window.','catering-operations.php',['atRisk'=>$catering['atRisk'],'next7Days'=>$catering['next7Days']??0,'averageReadiness'=>$catering['averageReadiness']??0],'Show the at-risk catering events and what is missing for each one.','review');
    return $signals;
}

function abo_cross_node_signals(array $signals): array
{
    $keys=array_fill_keys(array_column($signals,'key'),true);$out=[];
    if(isset($keys['kds:ready'])&&isset($keys['scheduling:coverage']))$out[]=abo_signal('brain:kitchen-staffing','command_center','critical',100,'Kitchen pressure and staffing gaps are happening together','The Brain sees kitchen tickets waiting while minimum staffing coverage is also below target.','workspace.php',['signals'=>['kds:ready','scheduling:coverage']],'Explain the kitchen pressure and staffing gaps together, then give me the safest sequence of next actions.','review');
    if(isset($keys['prep:shortage'])&&isset($keys['purchasing:pressure']))$out[]=abo_signal('brain:prep-purchasing','command_center','critical',99,'Prep shortages and purchasing pressure overlap','Prep forecast shortages line up with current purchasing demand, so tomorrow/today readiness may depend on ordering or substitution.','workspace.php',['signals'=>['prep:shortage','purchasing:pressure']],'Connect the prep shortages to the purchasing suggestions and tell me exactly what should be ordered or substituted first.','review');
    if(isset($keys['catering:risk'])&&(isset($keys['purchasing:pressure'])||isset($keys['operations:low-stock'])))$out[]=abo_signal('brain:catering-inventory','command_center','critical',98,'Catering readiness is exposed to inventory pressure','An upcoming at-risk catering event overlaps with current purchasing or low-stock pressure.','workspace.php',['signals'=>array_values(array_filter(['catering:risk',isset($keys['purchasing:pressure'])?'purchasing:pressure':null,isset($keys['operations:low-stock'])?'operations:low-stock':null]))],'Connect the at-risk catering event to inventory and purchasing needs, then tell me what should happen first.','review');
    return $out;
}

function agent_brain_orchestration_snapshot(PDO $pdo,array $user,?int $locationId=null): array
{
    $dashboard=admin_dashboard_snapshot($pdo,$user,$locationId);$signals=abo_dashboard_signals($dashboard);
    $signals=array_merge($signals,abo_purchasing_signals($pdo,$user),abo_schedule_signals($pdo,$user),abo_prep_signals($pdo,$user),abo_operations_signals($pdo,$user),abo_crm_signals($pdo,$user));
    $signals=array_merge($signals,abo_cross_node_signals($signals));
    usort($signals,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?: (abo_severity_rank($b['severity'])<=>abo_severity_rank($a['severity'])) ?: strcmp($a['title'],$b['title']));
    $signals=array_slice($signals,0,20);
    $nextMoves=[];
    foreach($signals as $signal){$move=$signal['nextMove'];$nextMoves[]=['key'=>$signal['key'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'summary'=>$signal['detail'],'node'=>$move['node'],'nodeLabel'=>$move['nodeLabel'],'mode'=>$move['mode'],'label'=>$move['label'],'prompt'=>$move['prompt'],'href'=>$signal['href']];if(count($nextMoves)>=8)break;}
    return ['generatedAt'=>(new DateTimeImmutable('now'))->format(DATE_ATOM),'scope'=>$dashboard['scope'],'signals'=>$signals,'nextMoves'=>$nextMoves,'dashboard'=>$dashboard];
}

function agent_brain_orchestration_answer(array $snapshot,string $message): array
{
    $text=mb_strtolower(trim($message),'UTF-8');$signals=$snapshot['signals'];$moves=$snapshot['nextMoves'];$sources=['Agent Brain','Manager Command Center'];
    foreach(array_unique(array_column($signals,'nodeLabel')) as $source)if($source!=='')$sources[]=$source;
    if(!$signals)return ['answer'=>'The Agent Brain does not see an urgent cross-node operating issue in the signals available to your account right now.','data'=>['signals'=>[],'nextMoves'=>[],'scope'=>$snapshot['scope']],'sources'=>$sources];
    $top=array_slice($signals,0,5);$lines=[];foreach($top as $i=>$signal)$lines[]=($i+1).'. '.$signal['title'].' — '.$signal['detail'];
    if(preg_match('/\b(what should we do|what should i do|next moves?|recommend(?:ed|ation)?|action plan|what do we do)\b/u',$text)){
        $moveLines=[];foreach(array_slice($moves,0,5) as $i=>$move)$moveLines[]=($i+1).'. '.$move['title'].' → '.$move['nodeLabel'].': '.$move['prompt'];
        $answer="Recommended Next Moves:\n".implode("\n",$moveLines)."\n\nThe Command Center is only orchestrating. Any consequential change remains owned and permission-gated by the destination node.";
    }elseif(preg_match('/\b(why|explain|evidence|because)\b/u',$text)){
        $answer='Highest-priority Brain signal: '.$signals[0]['title'].'. '.$signals[0]['detail'].' Evidence: '.json_encode($signals[0]['evidence'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'.';
    }else{
        $answer="What needs attention now:\n".implode("\n",$lines)."\n\nI also built a Next Moves queue from these signals. Ask “what should we do?” to walk through the actions in priority order.";
    }
    return ['answer'=>$answer,'data'=>['signals'=>$signals,'nextMoves'=>$moves,'scope'=>$snapshot['scope']],'sources'=>array_values(array_unique($sources))];
}

function agent_brain_manager_proactive_signals(PDO $pdo,array $user): array
{
    if(!admin_dashboard_allowed($user))return [];
    $snapshot=agent_brain_orchestration_snapshot($pdo,$user,null);$events=[];
    foreach(array_slice($snapshot['signals'],0,6) as $signal){if(!in_array($signal['severity'],['critical','high'],true))continue;$events[]=['type'=>'brain.'.str_replace(':','.',$signal['key']),'priority'=>'high','message'=>$signal['title'].'. '.$signal['detail'],'key'=>'brain:'.$signal['key'].':'.date('Y-m-d-H'),'url'=>$signal['href'],'meta'=>['node'=>$signal['node'],'score'=>$signal['score'],'nextMove'=>$signal['nextMove']]];}
    return $events;
}
