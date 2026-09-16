<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/admin-dashboard-core.php';
require_once __DIR__.'/../includes/purchasing-receiving.php';
require_once __DIR__.'/../includes/agent-brain-orchestrator.php';

$user=app_require_auth();
if(!admin_dashboard_allowed($user))app_json_response(['ok'=>false,'message'=>'Restaurant command-center access is not available for this account.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();
app_verify_request_csrf($input);
if((string)($input['action']??'')!=='ask')app_json_response(['ok'=>false,'message'=>'Unsupported command-dashboard Agent action.'],422);

function admin_dashboard_agent_money(float $value): string{return '$'.number_format($value,2);}

function admin_dashboard_agent_location_id(PDO $pdo,array $user,string $message): ?int
{
    $text=mb_strtolower($message,'UTF-8');
    $matches=[];
    foreach(admin_dashboard_locations($pdo,(int)$user['organization_id']) as $location){
        $name=mb_strtolower(trim((string)$location['name']),'UTF-8');
        if($name!==''&&mb_strpos($text,$name)!==false)$matches[]=$location;
    }
    return count($matches)===1?(int)$matches[0]['id']:null;
}

function admin_dashboard_agent_purchasing(PDO $pdo,array $user): ?array
{
    if(!app_has_permission('purchasing.view',$user)||!purchasing_ready($pdo))return null;
    $summary=purchasing_summary($pdo,(int)$user['organization_id']);
    $suggestions=array_slice(purchasing_suggestions($pdo,(int)$user['organization_id']),0,5);
    return ['summary'=>$summary,'suggestions'=>$suggestions];
}

function admin_dashboard_agent_answer(array $dashboard,string $message,?array $purchasing=null): array
{
    $text=mb_strtolower($message,'UTF-8');
    $t=$dashboard['totals'];
    $scope=(string)($dashboard['scope']['locationName']??'All locations');
    $cap=$dashboard['capabilities']??[];
    $sources=[];$answer='';$data=[];

    if(preg_match('/\bwholesale\b/u',$text)){
        $w=$dashboard['wholesale'];
        if(empty($cap['wholesale']))$answer='Your account does not have Wholesale dashboard access.';
        elseif(empty($w['available']))$answer='Wholesale dashboard data is not installed yet.';
        else{
            $sources[]='Wholesale';
            $answer='Wholesale currently has '.number_format((int)$w['activeAccounts']).' active account'.((int)$w['activeAccounts']===1?'':'s').', '.number_format((int)$w['pipelineLeads']).' open pipeline lead'.((int)$w['pipelineLeads']===1?'':'s').', and '.number_format((int)$w['openOrders']).' order'.((int)$w['openOrders']===1?'':'s').' in progress worth '.admin_dashboard_agent_money((float)$w['openOrderValue']).'. Delivered Wholesale revenue this month is '.admin_dashboard_agent_money((float)$w['monthDelivered']).'. Outstanding A/R is '.admin_dashboard_agent_money((float)$w['outstandingReceivables']).', with '.number_format((int)$w['overdueInvoices']).' overdue invoice'.((int)$w['overdueInvoices']===1?'':'s').'.';
            $data=$w;
        }
    }elseif(preg_match('/\b(catering|event readiness|events?)\b/u',$text)){
        $c=$dashboard['catering'];
        if(empty($cap['catering']))$answer='Your account does not have Catering dashboard access.';
        elseif(empty($c['available']))$answer='Catering Operations dashboard data is not installed yet.';
        else{
            $sources[]='Catering Operations';
            $answer='Catering has '.number_format((int)$c['activeEvents']).' active event'.((int)$c['activeEvents']===1?'':'s').', '.number_format((int)$c['next7Days']).' in the next 7 days, and '.number_format((int)$c['atRisk']).' upcoming event'.((int)$c['atRisk']===1?'':'s').' below 80% readiness. Average active-event readiness is '.number_format((int)$c['averageReadiness']).'%.';
            $data=$c;
        }
    }elseif(preg_match('/\b(purchasing|inventory pressure|purchase orders?|vendors?|receiving)\b/u',$text)){
        if(!$purchasing)$answer='Your account does not have Purchasing dashboard access, or Purchasing + Receiving is not installed.';
        else{
            $p=$purchasing['summary'];$sources[]='Purchasing + Inventory';
            $answer='Purchasing has '.number_format((int)$p['suggestions']).' current suggestion'.((int)$p['suggestions']===1?'':'s').', '.number_format((int)$p['unmapped']).' without a vendor map, '.number_format((int)$p['drafts']).' draft PO'.((int)$p['drafts']===1?'':'s').', and '.number_format((int)$p['openOrders']).' submitted/open order'.((int)$p['openOrders']===1?'':'s').' with '.admin_dashboard_agent_money((float)$p['committed']).' committed.';
            if($purchasing['suggestions']){$top=$purchasing['suggestions'][0];$answer.=' Highest current purchasing pressure: '.$top['name'].' needs about '.round((float)$top['needQuantity'],2).' '.$top['unit'].'.';}
            $answer.=' Purchasing changes are delegated to the Purchasing + Inventory node and remain confirmation-gated.';$data=$purchasing;
        }
    }elseif(preg_match('/\bonline\s+orders?|pickup\s+orders?\b/u',$text)){
        if(empty($cap['sales']))$answer='Your account does not have POS sales and online-order dashboard access.';
        else{$o=$dashboard['onlineOrders'];$sources[]='Online Ordering';$answer=$scope.' has '.number_format((int)$o['open']).' open online order'.((int)$o['open']===1?'':'s').' and '.number_format((int)$o['today']).' submitted today.';$data=$o;}
    }elseif(preg_match('/\b(active tables?|active tickets?|open checks?|ready tickets?|kds|expo|floor)\b/u',$text)){
        $parts=[];
        if(!empty($cap['tables'])){$parts[]=number_format((int)$t['activeTables']).' active table'.((int)$t['activeTables']===1?'':'s');$data['activeTables']=$t['activeTables'];$sources[]='Table Service';}
        if(!empty($cap['sales'])){$parts[]=number_format((int)$t['activeTickets']).' open POS ticket'.((int)$t['activeTickets']===1?'':'s').' representing '.admin_dashboard_agent_money((float)$t['openValue']);$data['activeTickets']=$t['activeTickets'];$data['openValue']=$t['openValue'];$data['tickets']=$dashboard['activeTickets'];$sources[]='Native POS';}
        if(!empty($cap['kds'])){$parts[]=number_format((int)$t['readyTickets']).' READY kitchen ticket'.((int)$t['readyTickets']===1?'':'s');$data['readyTickets']=$t['readyTickets'];$sources[]='KDS';}
        $answer=$parts?$scope.' currently has '.implode(', ',$parts).'.':'Your account does not have table-service, POS sales, or KDS dashboard access.';
    }elseif(preg_match('/\b(compare locations?|location performance|by location|which location|locations? doing)\b/u',$text)){
        if(!$dashboard['locations'])$answer='No active restaurant locations are available in the dashboard.';
        else{
            $parts=[];foreach($dashboard['locations'] as $location){$metrics=[];if(!empty($cap['sales'])){$metrics[]=admin_dashboard_agent_money((float)$location['salesToday']).' today';$metrics[]=number_format((int)$location['ticketsToday']).' paid ticket'.((int)$location['ticketsToday']===1?'':'s');$metrics[]=number_format((int)$location['activeTickets']).' open';}if(!empty($cap['tables']))$metrics[]=number_format((int)$location['activeTables']).' active table'.((int)$location['activeTables']===1?'':'s');if(!empty($cap['kds']))$metrics[]=number_format((int)$location['readyTickets']).' READY';$parts[]=$location['name'].': '.($metrics?implode(', ',$metrics):'no permitted operating metrics');}
            if(!empty($cap['sales']))$sources[]='Native POS';if(!empty($cap['tables']))$sources[]='Table Service';if(!empty($cap['kds']))$sources[]='KDS';$answer='Location performance — '.implode('. ',$parts).'.';$data=$dashboard['locations'];
        }
    }elseif(preg_match('/\b(sales|revenue|average check|avg check|daily|weekly|monthly|today|this week|this month)\b/u',$text)){
        if(empty($cap['sales']))$answer='Your account does not have Sales dashboard access.';
        else{$sources[]='Native POS';$answer=$scope.' sales are '.admin_dashboard_agent_money((float)$t['salesToday']).' today, '.admin_dashboard_agent_money((float)$t['salesWeek']).' this week, and '.admin_dashboard_agent_money((float)$t['salesMonth']).' this month. Today has '.number_format((int)$t['ticketsToday']).' paid ticket'.((int)$t['ticketsToday']===1?'':'s').' and '.number_format((int)$t['coversToday']).' cover'.((int)$t['coversToday']===1?'':'s').', with an average check of '.admin_dashboard_agent_money((float)$t['avgCheckToday']).'.';$data=['salesToday'=>$t['salesToday'],'salesWeek'=>$t['salesWeek'],'salesMonth'=>$t['salesMonth'],'ticketsToday'=>$t['ticketsToday'],'coversToday'=>$t['coversToday'],'avgCheckToday'=>$t['avgCheckToday']];}
    }else{
        $parts=[];
        if(!empty($cap['sales'])){$parts[]=admin_dashboard_agent_money((float)$t['salesToday']).' sales today across '.number_format((int)$t['ticketsToday']).' paid ticket'.((int)$t['ticketsToday']===1?'':'s');$parts[]=number_format((int)$t['activeTickets']).' open POS ticket'.((int)$t['activeTickets']===1?'':'s');$parts[]=number_format((int)$dashboard['onlineOrders']['open']).' open online order'.((int)$dashboard['onlineOrders']['open']===1?'':'s');$sources[]='Native POS';$sources[]='Online Ordering';}
        if(!empty($cap['tables'])){$parts[]=number_format((int)$t['activeTables']).' active table'.((int)$t['activeTables']===1?'':'s');$sources[]='Table Service';}
        if(!empty($cap['kds'])){$parts[]=number_format((int)$t['readyTickets']).' READY kitchen ticket'.((int)$t['readyTickets']===1?'':'s');$sources[]='KDS';}
        if(!empty($cap['wholesale'])){$w=$dashboard['wholesale'];$parts[]='Wholesale has '.number_format((int)$w['openOrders']).' open order'.((int)$w['openOrders']===1?'':'s').' worth '.admin_dashboard_agent_money((float)$w['openOrderValue']);$sources[]='Wholesale';}
        if(!empty($cap['catering'])){$c=$dashboard['catering'];$parts[]='Catering has '.number_format((int)$c['next7Days']).' event'.((int)$c['next7Days']===1?'':'s').' in the next 7 days, with '.number_format((int)$c['atRisk']).' at risk';$sources[]='Catering Operations';}
        if(!empty($cap['crm'])){$customers=$dashboard['customers'];$parts[]=number_format((int)$customers['activeCustomers']).' active CRM customer'.((int)$customers['activeCustomers']===1?'':'s');$sources[]='Customer CRM';}
        if($purchasing){$p=$purchasing['summary'];$parts[]=number_format((int)$p['suggestions']).' purchasing suggestion'.((int)$p['suggestions']===1?'':'s').' and '.number_format((int)$p['openOrders']).' open PO'.((int)$p['openOrders']===1?'':'s');$sources[]='Purchasing + Inventory';}
        $answer=$scope.' command-center snapshot: '.($parts?implode('; ',$parts).'.':'no operating metrics are available to this account.');
        if($dashboard['priorities'])$answer.=' Highest dashboard priority: '.$dashboard['priorities'][0]['title'].'.';
        $data=['totals'=>$t,'onlineOrders'=>$dashboard['onlineOrders'],'wholesale'=>$dashboard['wholesale'],'catering'=>$dashboard['catering'],'customers'=>$dashboard['customers'],'purchasing'=>$purchasing,'priorities'=>$dashboard['priorities']];
    }
    return ['answer'=>$answer,'data'=>$data,'sources'=>array_values(array_unique($sources))];
}

try{
    $message=trim((string)($input['message']??''));if($message==='')throw new InvalidArgumentException('Enter a restaurant dashboard question.');
    $pdo=app_pdo();
    $brainIntent=preg_match('/\b(what needs (?:my |our )?attention|what should (?:we|i) do|what do we do|next moves?|action plan|restaurant priorities|operating priorities|biggest risks?|top risks?|what is happening right now|what\x27s happening right now)\b/iu',$message)===1;
    if($brainIntent){
        $snapshot=agent_brain_orchestration_snapshot($pdo,$user,null);$result=agent_brain_orchestration_answer($snapshot,$message);
        app_audit($pdo,(int)$user['organization_id'],(int)$user['id'],'agent.brain_orchestration_used','agent_brain','manager',null,['signalCount'=>count($snapshot['signals']),'nextMoveCount'=>count($snapshot['nextMoves'])]);
        app_json_response(['ok'=>true,'skill'=>'agent.brain.orchestration','answer'=>$result['answer'],'data'=>$result['data'],'sources'=>$result['sources'],'node'=>'brain']);
    }
    $locationId=admin_dashboard_agent_location_id($pdo,$user,$message);$dashboard=admin_dashboard_snapshot($pdo,$user,$locationId);$purchasing=admin_dashboard_agent_purchasing($pdo,$user);$result=admin_dashboard_agent_answer($dashboard,$message,$purchasing);
    app_json_response(['ok'=>true,'skill'=>'admin.dashboard','answer'=>$result['answer'],'data'=>$result['data'],'sources'=>$result['sources'],'node'=>'command_center']);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('Admin dashboard Agent failed: '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Gelato could not load the restaurant command-center context.'],500);}