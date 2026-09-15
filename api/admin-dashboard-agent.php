<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/admin-dashboard-core.php';

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

function admin_dashboard_agent_answer(array $dashboard,string $message): array
{
    $text=mb_strtolower($message,'UTF-8');$t=$dashboard['totals'];$scope=(string)($dashboard['scope']['locationName']??'All locations');
    $sources=['Native POS','Table Service','KDS','Online Ordering'];
    $answer='';$data=[];

    if(preg_match('/\bwholesale\b/u',$text)){
        $w=$dashboard['wholesale'];$sources[]='Wholesale';
        if(empty($dashboard['capabilities']['wholesale']))$answer='Your account does not have Wholesale dashboard access.';
        elseif(empty($w['available']))$answer='Wholesale dashboard data is not installed yet.';
        else{
            $answer='Wholesale currently has '.number_format((int)$w['activeAccounts']).' active account'.((int)$w['activeAccounts']===1?'':'s').', '.number_format((int)$w['pipelineLeads']).' open pipeline lead'.((int)$w['pipelineLeads']===1?'':'s').', and '.number_format((int)$w['openOrders']).' order'.((int)$w['openOrders']===1?'':'s').' in progress worth '.admin_dashboard_agent_money((float)$w['openOrderValue']).'. Delivered Wholesale revenue this month is '.admin_dashboard_agent_money((float)$w['monthDelivered']).'. Outstanding A/R is '.admin_dashboard_agent_money((float)$w['outstandingReceivables']).', with '.number_format((int)$w['overdueInvoices']).' overdue invoice'.((int)$w['overdueInvoices']===1?'':'s').'.';
            $data=$w;
        }
    }elseif(preg_match('/\b(catering|event readiness|events?)\b/u',$text)){
        $c=$dashboard['catering'];$sources[]='Catering Operations';
        if(empty($dashboard['capabilities']['catering']))$answer='Your account does not have Catering dashboard access.';
        elseif(empty($c['available']))$answer='Catering Operations dashboard data is not installed yet.';
        else{
            $answer='Catering has '.number_format((int)$c['activeEvents']).' active event'.((int)$c['activeEvents']===1?'':'s').', '.number_format((int)$c['next7Days']).' in the next 7 days, and '.number_format((int)$c['atRisk']).' upcoming event'.((int)$c['atRisk']===1?'':'s').' below 80% readiness. Average active-event readiness is '.number_format((int)$c['averageReadiness']).'%.';
            $data=$c;
        }
    }elseif(preg_match('/\bonline\s+orders?|pickup\s+orders?\b/u',$text)){
        $o=$dashboard['onlineOrders'];$sources[]='Online Ordering';
        $answer=$scope.' has '.number_format((int)$o['open']).' open online order'.((int)$o['open']===1?'':'s').' and '.number_format((int)$o['today']).' submitted today.';
        $data=$o;
    }elseif(preg_match('/\b(active tables?|active tickets?|open checks?|ready tickets?|kds|expo|floor)\b/u',$text)){
        $answer=$scope.' currently has '.number_format((int)$t['activeTables']).' active table'.((int)$t['activeTables']===1?'':'s').', '.number_format((int)$t['activeTickets']).' open POS ticket'.((int)$t['activeTickets']===1?'':'s').', and '.number_format((int)$t['readyTickets']).' READY kitchen ticket'.((int)$t['readyTickets']===1?'':'s').'. Open checks currently represent '.admin_dashboard_agent_money((float)$t['openValue']).'.';
        $data=['activeTables'=>$t['activeTables'],'activeTickets'=>$t['activeTickets'],'readyTickets'=>$t['readyTickets'],'openValue'=>$t['openValue'],'tickets'=>$dashboard['activeTickets']];
    }elseif(preg_match('/\b(compare locations?|location performance|by location|which location|locations? doing)\b/u',$text)){
        if(!$dashboard['locations'])$answer='No active restaurant locations are available in the dashboard.';
        else{
            $parts=[];
            foreach($dashboard['locations'] as $location){
                $parts[]=$location['name'].': '.admin_dashboard_agent_money((float)$location['salesToday']).' today, '.number_format((int)$location['ticketsToday']).' paid ticket'.((int)$location['ticketsToday']===1?'':'s').', '.number_format((int)$location['activeTickets']).' open, '.number_format((int)$location['activeTables']).' active table'.((int)$location['activeTables']===1?'':'s').'.';
            }
            $answer='Location performance — '.implode(' ',$parts);
            $data=$dashboard['locations'];
        }
    }elseif(preg_match('/\b(sales|revenue|average check|avg check|daily|weekly|monthly|today|this week|this month)\b/u',$text)){
        $answer=$scope.' sales are '.admin_dashboard_agent_money((float)$t['salesToday']).' today, '.admin_dashboard_agent_money((float)$t['salesWeek']).' this week, and '.admin_dashboard_agent_money((float)$t['salesMonth']).' this month. Today has '.number_format((int)$t['ticketsToday']).' paid ticket'.((int)$t['ticketsToday']===1?'':'s').' and '.number_format((int)$t['coversToday']).' cover'.((int)$t['coversToday']===1?'':'s').', with an average check of '.admin_dashboard_agent_money((float)$t['avgCheckToday']).'.';
        $data=['salesToday'=>$t['salesToday'],'salesWeek'=>$t['salesWeek'],'salesMonth'=>$t['salesMonth'],'ticketsToday'=>$t['ticketsToday'],'coversToday'=>$t['coversToday'],'avgCheckToday'=>$t['avgCheckToday']];
    }else{
        $w=$dashboard['wholesale'];$c=$dashboard['catering'];$o=$dashboard['onlineOrders'];
        $answer=$scope.' command-center snapshot: '.admin_dashboard_agent_money((float)$t['salesToday']).' sales today across '.number_format((int)$t['ticketsToday']).' paid ticket'.((int)$t['ticketsToday']===1?'':'s').'; '.number_format((int)$t['activeTickets']).' open POS ticket'.((int)$t['activeTickets']===1?'':'s').'; '.number_format((int)$t['activeTables']).' active table'.((int)$t['activeTables']===1?'':'s').'; '.number_format((int)$t['readyTickets']).' READY kitchen ticket'.((int)$t['readyTickets']===1?'':'s').'; and '.number_format((int)$o['open']).' open online order'.((int)$o['open']===1?'':'s').'.';
        if(!empty($dashboard['capabilities']['wholesale'])){$answer.=' Wholesale has '.number_format((int)$w['openOrders']).' open order'.((int)$w['openOrders']===1?'':'s').' worth '.admin_dashboard_agent_money((float)$w['openOrderValue']).'.';$sources[]='Wholesale';}
        if(!empty($dashboard['capabilities']['catering'])){$answer.=' Catering has '.number_format((int)$c['next7Days']).' event'.((int)$c['next7Days']===1?'':'s').' in the next 7 days, with '.number_format((int)$c['atRisk']).' currently at risk.';$sources[]='Catering Operations';}
        if($dashboard['priorities'])$answer.=' Highest dashboard priority: '.$dashboard['priorities'][0]['title'].'.';
        $data=['totals'=>$t,'onlineOrders'=>$o,'wholesale'=>$w,'catering'=>$c,'priorities'=>$dashboard['priorities']];
    }
    return ['answer'=>$answer,'data'=>$data,'sources'=>array_values(array_unique($sources))];
}

try{
    $message=trim((string)($input['message']??''));
    if($message==='')throw new InvalidArgumentException('Enter a restaurant dashboard question.');
    $pdo=app_pdo();$locationId=admin_dashboard_agent_location_id($pdo,$user,$message);
    $dashboard=admin_dashboard_snapshot($pdo,$user,$locationId);
    $result=admin_dashboard_agent_answer($dashboard,$message);
    app_json_response(['ok'=>true,'skill'=>'admin.dashboard','answer'=>$result['answer'],'data'=>$result['data'],'sources'=>$result['sources']]);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Admin dashboard Agent failed: '.$e->getMessage());
    app_json_response(['ok'=>false,'message'=>'Gelato could not load the restaurant command-center context.'],500);
}
