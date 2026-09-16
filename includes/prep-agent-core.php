<?php
declare(strict_types=1);

require_once __DIR__.'/prep-intelligence-core.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class PrepAgentPermissionException extends RuntimeException {}

function pac_require(array $user,string $permission,string $message): void
{
    if(!app_has_permission($permission,$user))throw new PrepAgentPermissionException($message);
}

function pac_service(string $text): string
{
    foreach(['morning','lunch','dinner','closing'] as $service){
        if(preg_match('/\b'.$service.'\b/iu',$text))return $service;
    }
    return 'all';
}

function pac_plan(PDO $pdo,int $org,string $date,string $service): ?array
{
    $plan=prep_plan_for_period($pdo,$org,$date,$service);
    if(!$plan&&$service!=='all')$plan=prep_plan_for_period($pdo,$org,$date,'all');
    return $plan;
}

function pac_plan_from_message(PDO $pdo,int $org,string $message): ?array
{
    return pac_plan($pdo,$org,prep_parse_date_phrase($message),pac_service($message));
}

function pac_snapshot(?array $plan): array
{
    return $plan?[
        'exists'=>true,
        'publicId'=>(string)$plan['public_id'],
        'updatedAt'=>(string)$plan['updated_at'],
        'status'=>(string)$plan['status'],
    ]:[
        'exists'=>false,
        'publicId'=>'',
        'updatedAt'=>'',
        'status'=>'',
    ];
}

function pac_resolve_plan(PDO $pdo,int $org,array $payload,bool $create,int $userId): array
{
    $date=(string)($payload['date']??'');
    $service=prep_service_period((string)($payload['service']??'all'));
    $expected=(array)($payload['expected']??[]);
    $current=pac_plan($pdo,$org,$date,$service);
    if(!empty($expected['exists'])){
        if(!$current
            ||(string)$current['public_id']!==(string)($expected['publicId']??'')
            ||(string)$current['updated_at']!==(string)($expected['updatedAt']??'')
            ||(string)$current['status']!==(string)($expected['status']??'')){
            throw new InvalidArgumentException('That prep plan changed after I proposed the action. Review it and ask again.');
        }
        return $current;
    }
    if($current)throw new InvalidArgumentException('A prep plan was created after I proposed this action. Review the current plan and ask again.');
    if(!$create)throw new InvalidArgumentException('The proposed prep plan no longer exists.');
    return prep_get_or_create_plan($pdo,$org,$date,$service,$userId);
}

function pac_quantity(mixed $value): string
{
    return rtrim(rtrim(number_format((float)$value,3,'.',''),'0'),'.');
}

function pac_word_number(string $word): ?float
{
    $numbers=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,'twenty'=>20,'thirty'=>30,'forty'=>40,'fifty'=>50];
    $key=mb_strtolower($word,'UTF-8');
    return isset($numbers[$key])?(float)$numbers[$key]:null;
}

function pac_parse_item(string $item): array
{
    $original=trim($item);
    $working=$original;
    if(preg_match('/\b(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty)\b/iu',$working,$match)){
        $number=pac_word_number($match[1]);
        if($number!==null)$working=preg_replace('/\b'.preg_quote($match[1],'/').'\b/iu',(string)$number,$working,1)??$working;
    }
    $units='lb|lbs|pound|pounds|oz|ounce|ounces|qt|quart|quarts|gal|gallon|gallons|cup|cups|pan|pans|tray|trays|batch|batches|case|cases|bag|bags|box|boxes|bottle|bottles|container|containers|dozen|each|ea';
    if(preg_match('/^(?:make|prep|prepare)?\s*(\d+(?:\.\d+)?)\s+('.$units.')\b(?:\s+of)?\s*(.+)$/iu',$working,$match)){
        return ['title'=>trim($match[3]),'quantity'=>(float)$match[1],'unit'=>prep_normalized_unit($match[2])];
    }
    if(preg_match('/^(?:make|prep|prepare)?\s*(\d+(?:\.\d+)?)\s+(.+)$/iu',$working,$match)){
        return ['title'=>trim($match[2]),'quantity'=>(float)$match[1],'unit'=>'ea'];
    }
    $title=preg_replace('/^(?:make|prep|prepare)\s+/iu','',$original)??$original;
    return ['title'=>trim($title),'quantity'=>null,'unit'=>''];
}

function pac_items(string $text): array
{
    $text=preg_replace('/\b(?:and then|then)\b/iu',';',$text)??$text;
    $parts=preg_split('/[;\n,]+/u',$text)?:[];
    $items=[];
    foreach(array_slice($parts,0,30) as $part){
        $part=trim($part," \t\n\r\0\x0B.-");
        if($part==='')continue;
        $parsed=pac_parse_item($part);
        if($parsed['title']!=='')$items[]=$parsed;
    }
    return $items;
}

function pac_visible(array $detail,bool $canForecast): array
{
    if(!$canForecast)$detail['forecasts']=[];
    return $detail;
}

function pac_execute(PDO $pdo,int $org,int $uid,array $user,array $proposal,bool $canForecast): array
{
    if((int)($proposal['organizationId']??0)!==$org||(int)($proposal['userId']??0)!==$uid)throw new PrepAgentPermissionException('This Prep Agent proposal does not belong to your session.');
    pac_require($user,'prep.intelligence.manage','Prep Intelligence management permission is required for this action.');
    $payload=(array)$proposal['payload'];
    $type=(string)$proposal['type'];

    if($type==='add_items'){
        $plan=pac_resolve_plan($pdo,$org,$payload,true,$uid);
        $created=[];
        foreach((array)($payload['items']??[]) as $item){
            if(trim((string)($item['title']??''))==='')continue;
            $created[]=prep_attach_manual_task($pdo,$org,$plan,[
                'title'=>$item['title'],
                'quantity'=>$item['quantity']??null,
                'unit'=>$item['unit']??'',
                'transcript'=>$payload['transcript']??'',
                'aiConfidence'=>1.0,
            ],$uid,(string)($payload['source']??'agent'));
        }
        if(!$created)throw new InvalidArgumentException('The pending prep action no longer contains valid items.');
        return ['ok'=>true,'skill'=>'prep.action_confirmed','answer'=>'Confirmed. Added '.count($created).' item(s) to '.ucfirst((string)$plan['service_period']).' prep: '.implode(', ',array_column($created,'title')).'.','data'=>['action'=>'add_items','plan'=>$plan,'tasks'=>$created],'sources'=>array_column($created,'public_id')];
    }

    if($type==='publish_plan'){
        $plan=pac_resolve_plan($pdo,$org,$payload,false,$uid);
        $detail=pac_visible(prep_publish_plan($pdo,$org,$plan,$uid),$canForecast);
        return ['ok'=>true,'skill'=>'prep.action_confirmed','answer'=>'Confirmed. Published the '.$plan['plan_date'].' '.ucfirst((string)$plan['service_period']).' prep plan with '.count($detail['tasks']).' linked task(s).','data'=>['action'=>'publish_plan']+$detail,'sources'=>array_column($detail['tasks'],'public_id')];
    }

    if($type==='generate_recommendations'){
        $plan=pac_resolve_plan($pdo,$org,$payload,true,$uid);
        $detail=pac_visible(prep_generate_recommendations($pdo,$org,$plan,$uid),$canForecast);
        $active=array_values(array_filter($detail['recommendations'],static fn(array $row):bool=>$row['status']!=='dismissed'));
        return ['ok'=>true,'skill'=>'prep.action_confirmed','answer'=>'Confirmed. Generated '.count($active).' prep recommendation(s) for '.$plan['plan_date'].'.','data'=>['action'=>'generate_recommendations','plan'=>$detail['plan'],'recommendations'=>$active,'forecasts'=>$detail['forecasts']],'sources'=>array_column($active,'public_id')];
    }

    throw new InvalidArgumentException('That pending Prep Agent action is no longer supported.');
}

function prep_agent_handle(PDO $pdo,array $user,array $input): array
{
    $org=(int)$user['organization_id'];
    $uid=(int)$user['id'];
    pac_require($user,'prep.intelligence.view','Prep Intelligence view permission is required.');
    pac_require($user,'prep.intelligence.agent','Prep Intelligence Agent permission is required.');
    $canForecast=app_has_permission('inventory.forecast.view',$user)||app_has_permission('inventory.view',$user);
    $message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>4000)throw new InvalidArgumentException('Enter a prep request no longer than 4,000 characters.');
    $text=trim((string)(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message));
    $lower=mb_strtolower($text,'UTF-8');
    $pending=gac_pending_get('prep',$org,$uid);

    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Prep Agent action to confirm.');
        try{$result=pac_execute($pdo,$org,$uid,$user,$pending,$canForecast);}catch(Throwable $error){gac_pending_clear('prep',$org,$uid);throw $error;}
        gac_pending_clear('prep',$org,$uid);
        app_audit($pdo,$org,$uid,'prep.agent_action_confirmed','prep_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return $result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('prep',$org,$uid);
        app_audit($pdo,$org,$uid,'prep.agent_action_discarded','prep_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);
        return ['ok'=>true,'skill'=>'prep.action_cancelled','answer'=>'Cancelled. I did not change the prep plan.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Prep + Inventory Intelligence']];
    }

    if(preg_match('/\b(?:add|put)\b.*\bprep\s+list\b/iu',$lower)||preg_match('/\badd(?:\s+these|\s+the following)?(?:\s+items?)?\s+to\s+(?:the\s+)?prep\s+list\b/iu',$lower)){
        pac_require($user,'prep.intelligence.manage','Prep Intelligence management permission is required to add prep items.');
        $payloadText=$text;
        if(preg_match('/\badd(?:\s+these|\s+the following)?(?:\s+items?)?\s+to\s+(?:the\s+)?prep\s+list\s*[:,-]?\s*(.+)$/iu',$text,$match))$payloadText=trim($match[1]);
        elseif(preg_match('/\b(?:add|put)\s+(.+?)\s+(?:to|on)\s+(?:the\s+)?prep\s+list\b/iu',$text,$match))$payloadText=trim($match[1]);
        $items=pac_items($payloadText);
        if(!$items)throw new InvalidArgumentException('Tell me which prep items to add.');
        $date=prep_parse_date_phrase($text);
        $service=pac_service($text);
        $existing=pac_plan($pdo,$org,$date,$service);
        $labels=array_map(static function(array $row): string {
            $prefix=($row['quantity']??null)!==null?pac_quantity($row['quantity']).' '.($row['unit']?:'ea').' ':'';
            return $prefix.$row['title'];
        },$items);
        $proposal=gac_pending_store('prep',$org,$uid,'add_items',['date'=>$date,'service'=>$service,'expected'=>pac_snapshot($existing),'items'=>$items,'transcript'=>$message,'source'=>!empty($input['voice'])?'voice':'agent'],'Proposed action: add '.count($items).' item(s) to '.ucfirst($service).' prep for '.$date.': '.implode(', ',$labels).'.');
        app_audit($pdo,$org,$uid,'prep.agent_action_proposed','prep_agent_proposal',(string)$proposal['id'],null,['type'=>'add_items','count'=>count($items)]);
        return gac_proposal_result($proposal,'prep.action_proposal',['Prep Plan','Restaurant Tasks']);
    }

    if(preg_match('/\b(?:publish|release)\b.*\bprep\b/iu',$lower)){
        pac_require($user,'prep.intelligence.manage','Prep Intelligence management permission is required to publish a prep plan.');
        $date=prep_parse_date_phrase($text);
        $service=pac_service($text);
        $plan=pac_plan($pdo,$org,$date,$service);
        if(!$plan)throw new InvalidArgumentException('There is no prep plan for that period to publish.');
        $proposal=gac_pending_store('prep',$org,$uid,'publish_plan',['date'=>(string)$plan['plan_date'],'service'=>(string)$plan['service_period'],'expected'=>pac_snapshot($plan)],'Proposed action: publish the '.$plan['plan_date'].' '.ucfirst((string)$plan['service_period']).' prep plan into the employee task system.');
        app_audit($pdo,$org,$uid,'prep.agent_action_proposed','prep_agent_proposal',(string)$proposal['id'],null,['type'=>'publish_plan']);
        return gac_proposal_result($proposal,'prep.action_proposal',['Prep Plan','Restaurant Tasks']);
    }

    if(preg_match('/\b(?:generate|build|regenerate|recalculate)\b.*\b(?:prep|recommendations?)\b/iu',$lower)){
        pac_require($user,'prep.intelligence.manage','Prep Intelligence management permission is required to generate recommendations.');
        $date=prep_parse_date_phrase($text);
        $service=pac_service($text);
        $plan=pac_plan($pdo,$org,$date,$service);
        $proposal=gac_pending_store('prep',$org,$uid,'generate_recommendations',['date'=>$date,'service'=>$service,'expected'=>pac_snapshot($plan)],'Proposed action: generate/regenerate Prep Intelligence recommendations and inventory forecasts for '.$date.' '.ucfirst($service).'.');
        app_audit($pdo,$org,$uid,'prep.agent_action_proposed','prep_agent_proposal',(string)$proposal['id'],null,['type'=>'generate_recommendations']);
        return gac_proposal_result($proposal,'prep.action_proposal',['Prep History','Demand Signals','Inventory Forecast']);
    }

    if(preg_match('/\b(?:what should (?:we|i) prep|prep recommendations?|prep plan)\b/iu',$lower)){
        $plan=pac_plan_from_message($pdo,$org,$text);
        if(!$plan)return ['ok'=>true,'skill'=>'prep.recommend','answer'=>'There is no prep plan for that period yet. Ask me to generate the prep recommendations if you want me to create one; I will show a confirmation before writing anything.','data'=>null,'sources'=>[]];
        $detail=pac_visible(prep_plan_detail($pdo,$org,(string)$plan['public_id']),$canForecast);
        $active=array_values(array_filter($detail['recommendations'],static fn(array $row):bool=>$row['status']!=='dismissed'));
        $shortages=$canForecast?array_values(array_filter($detail['forecasts'],static fn(array $row):bool=>(float)$row['shortage_quantity']>0||(float)$row['restock_quantity']>0)):[];
        $top=array_slice(array_map(static fn(array $row):string=>$row['title'].' '.pac_quantity($row['recommended_quantity']).' '.$row['unit'],$active),0,6);
        $answer=count($active).' existing recommendation(s) for '.$plan['plan_date'].'.';
        if($top)$answer.=' Top prep: '.implode('; ',$top).'.';
        if(!$active)$answer.=' No generated recommendations are stored yet; ask me to generate them if you want a new recommendation set.';
        if($canForecast)$answer.=count($shortages)?' Inventory forecast flags '.count($shortages).' ingredient(s) for shortage/restock.':' Inventory coverage has no stored flagged shortages from the current forecast.';
        return ['ok'=>true,'skill'=>'prep.recommend','answer'=>$answer,'data'=>['plan'=>$detail['plan'],'recommendations'=>$active,'forecasts'=>$shortages,'commitments'=>$detail['commitments']],'sources'=>array_column($active,'public_id')];
    }

    if(preg_match('/\b(?:prep history|what did (?:we|i) prep|show .*prep.*(?:history|last))\b/iu',$lower)){
        $date=prep_parse_date_phrase($text);
        $tasks=prep_task_history_for_date($pdo,$org,$date);
        $summary=$tasks?implode('; ',array_slice(array_map(static fn(array $task):string=>$task['title'].($task['quantity']!==null?' '.$task['quantity'].' '.$task['unit']:''),$tasks),0,10)):'No prep tasks were recorded.';
        return ['ok'=>true,'skill'=>'prep.history','answer'=>'Prep history for '.$date.': '.$summary,'data'=>['date'=>$date,'tasks'=>$tasks],'sources'=>array_column($tasks,'public_id')];
    }

    if(preg_match('/\bhow much\s+(.+?)\s+(?:do|did)\s+(?:we|i)\s+(?:normally\s+)?prep\b/iu',$text,$match)||preg_match('/\b(?:normally|usually)\s+prep\s+(.+?)(?:\s+on\s+|\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)s?\b|$)/iu',$text,$match)){
        $query=trim($match[1]);
        $weekday=prep_weekday_from_text($text);
        $history=prep_item_history_summary($pdo,$org,$query,$weekday);
        $day=$weekday!==null?['','Mondays','Tuesdays','Wednesdays','Thursdays','Fridays','Saturdays','Sundays'][$weekday]:'recent prep';
        $answer=$history['sampleCount']?('Based on '.$history['sampleCount'].' completed sample(s), '.$query.' averages '.$history['average'].' '.$history['unit'].' and the median is '.$history['median'].' '.$history['unit'].' for '.$day.'.'):('I do not have enough completed prep history for '.$query.' yet.');
        return ['ok'=>true,'skill'=>'prep.normal_history','answer'=>$answer,'data'=>$history,'sources'=>[]];
    }

    if(preg_match('/\b(?:inventory forecast|forecast.*(?:inventory|shortage)|shortage forecast|what (?:are|is) (?:we|i) short|what do (?:we|i) need to order)\b/iu',$lower)){
        if(!$canForecast)throw new PrepAgentPermissionException('Inventory forecast permission is required for shortage and restock intelligence.');
        $plan=pac_plan_from_message($pdo,$org,$text);
        if(!$plan)return ['ok'=>true,'skill'=>'prep.inventory_forecast','answer'=>'There is no prep plan for that period yet.','data'=>[],'sources'=>[]];
        $detail=prep_plan_detail($pdo,$org,(string)$plan['public_id']);
        $flagged=array_values(array_filter($detail['forecasts'],static fn(array $row):bool=>(float)$row['shortage_quantity']>0||(float)$row['restock_quantity']>0));
        $top=array_slice(array_map(static fn(array $row):string=>$row['inventory_name'].' (short '.pac_quantity($row['shortage_quantity']).', restock '.pac_quantity($row['restock_quantity']).' '.$row['unit'].')',$flagged),0,8);
        $answer=$flagged?('Forecast flags '.count($flagged).' item(s): '.implode('; ',$top).'.'):'No stored forecast shortages or restock-to-par gaps are currently flagged. Ask me to generate prep recommendations if you need the forecast recalculated.';
        return ['ok'=>true,'skill'=>'prep.inventory_forecast','answer'=>$answer,'data'=>$flagged,'sources'=>array_column($flagged,'public_id')];
    }

    $plan=pac_plan_from_message($pdo,$org,$text);
    if(!$plan)return ['ok'=>true,'skill'=>'prep.status','answer'=>'No prep plan exists for that period yet. Ask me to generate the prep recommendations when you are ready; I will require confirmation before creating it.','data'=>null,'sources'=>[]];
    $detail=pac_visible(prep_plan_detail($pdo,$org,(string)$plan['public_id']),$canForecast);
    $open=count(array_filter($detail['tasks'],static fn(array $task):bool=>!in_array($task['status'],['completed','verified','cancelled'],true)));
    $done=count(array_filter($detail['tasks'],static fn(array $task):bool=>in_array($task['status'],['completed','verified'],true)));
    $answer='Prep plan '.$plan['plan_date'].' '.$plan['service_period'].': '.$open.' open task(s), '.$done.' completed, '.count($detail['recommendations']).' recommendation(s)';
    if($canForecast)$answer.=', and '.count(array_filter($detail['forecasts'],static fn(array $row):bool=>(float)$row['shortage_quantity']>0)).' forecast shortage(s)';
    $answer.='.';
    return ['ok'=>true,'skill'=>'prep.status','answer'=>$answer,'data'=>$detail,'sources'=>array_column($detail['tasks'],'public_id')];
}
