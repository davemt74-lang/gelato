<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/prep-intelligence-core.php';
require_once __DIR__ . '/../includes/agent-confirmation-core.php';

$user=app_require_auth();
$pdo=app_pdo();
$organizationId=(int)$user['organization_id'];
$userId=(int)$user['id'];
if(!prep_intelligence_ready($pdo))app_json_response(['ok'=>false,'message'=>'Prep + Inventory Intelligence migration is not installed. Run upgrade.php.'],503);
$canView=app_has_permission('prep.intelligence.view',$user);
$canManage=app_has_permission('prep.intelligence.manage',$user);
$canAgent=app_has_permission('prep.intelligence.agent',$user);
$canForecast=app_has_permission('inventory.forecast.view',$user)||app_has_permission('inventory.view',$user);
if(!$canView||!$canAgent)app_json_response(['ok'=>false,'message'=>'Prep Intelligence Agent permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();
app_verify_request_csrf($input);
$message=trim((string)($input['message']??''));
if($message===''||mb_strlen($message,'UTF-8')>4000)app_json_response(['ok'=>false,'message'=>'Enter a prep request no longer than 4,000 characters.'],422);
$text=trim((string)(preg_replace('/^hey\s+gelato[,\s]*/iu','',$message)??$message));
$normalized=mb_strtolower($text,'UTF-8');

function pia_word_number(string $word): ?float
{
    $map=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,'twenty'=>20,'thirty'=>30,'forty'=>40,'fifty'=>50];
    $key=mb_strtolower($word,'UTF-8');
    return isset($map[$key])?(float)$map[$key]:null;
}

function pia_parse_item(string $item): array
{
    $original=trim($item);$working=$original;
    if(preg_match('/\b(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty)\b/iu',$working,$m)){
        $n=pia_word_number($m[1]);
        if($n!==null)$working=preg_replace('/\b'.preg_quote($m[1],'/').'\b/iu',(string)$n,$working,1)??$working;
    }
    $unit='lb|lbs|pound|pounds|oz|ounce|ounces|qt|quart|quarts|gal|gallon|gallons|cup|cups|pan|pans|tray|trays|batch|batches|case|cases|bag|bags|box|boxes|bottle|bottles|container|containers|dozen|each|ea';
    if(preg_match('/^(?:make|prep|prepare)?\s*(\d+(?:\.\d+)?)\s+('.$unit.')\b(?:\s+of)?\s*(.+)$/iu',$working,$m))return ['title'=>trim($m[3]),'quantity'=>(float)$m[1],'unit'=>prep_normalized_unit($m[2])];
    if(preg_match('/^(?:make|prep|prepare)?\s*(\d+(?:\.\d+)?)\s+(.+)$/iu',$working,$m))return ['title'=>trim($m[2]),'quantity'=>(float)$m[1],'unit'=>'ea'];
    $title=preg_replace('/^(?:make|prep|prepare)\s+/iu','',$original)??$original;
    return ['title'=>trim($title),'quantity'=>null,'unit'=>''];
}

function pia_items(string $text): array
{
    $text=preg_replace('/\b(?:and then|then)\b/iu',';',$text)??$text;
    $parts=preg_split('/[;\n,]+/u',$text)?:[];
    return array_values(array_filter(array_map(static fn(string $v):string=>trim($v," \t\n\r\0\x0B.-"),$parts),static fn(string $v):bool=>$v!==''));
}

function pia_service(string $text): string
{
    foreach(['morning','lunch','dinner','closing'] as $service)if(preg_match('/\b'.$service.'\b/iu',$text))return $service;
    return 'all';
}

function pia_plan(PDO $pdo,int $org,string $message,bool $create,?int $userId): ?array
{
    $date=prep_parse_date_phrase($message);$service=pia_service($message);
    $plan=prep_plan_for_period($pdo,$org,$date,$service);
    if(!$plan&&$service!=='all')$plan=prep_plan_for_period($pdo,$org,$date,'all');
    if(!$plan&&$create)$plan=prep_get_or_create_plan($pdo,$org,$date,$service,$userId);
    return $plan;
}

function pia_plan_snapshot(?array $plan): array
{
    return $plan?['exists'=>true,'publicId'=>(string)$plan['public_id'],'updatedAt'=>(string)$plan['updated_at'],'status'=>(string)$plan['status']]:['exists'=>false,'publicId'=>'','updatedAt'=>'','status'=>''];
}

function pia_resolve_proposal_plan(PDO $pdo,int $org,array $payload,bool $create,int $userId): array
{
    $date=(string)($payload['date']??'');$service=prep_service_period((string)($payload['service']??'all'));$expected=(array)($payload['expected']??[]);
    $current=prep_plan_for_period($pdo,$org,$date,$service);
    if(!$current&&$service!=='all')$current=prep_plan_for_period($pdo,$org,$date,'all');
    if(!empty($expected['exists'])){
        if(!$current||(string)$current['public_id']!==(string)($expected['publicId']??'')||(string)$current['updated_at']!==(string)($expected['updatedAt']??'')||(string)$current['status']!==(string)($expected['status']??''))throw new InvalidArgumentException('That prep plan changed after I proposed the action. Review it and ask again.');
        return $current;
    }
    if($current)throw new InvalidArgumentException('A prep plan was created after I proposed this action. Review the current plan and ask again.');
    if(!$create)throw new InvalidArgumentException('The proposed prep plan no longer exists.');
    return prep_get_or_create_plan($pdo,$org,$date,$service,$userId);
}

function pia_compact_quantity(mixed $value): string
{
    return rtrim(rtrim(number_format((float)$value,3,'.',''),'0'),'.');
}

function pia_visible_detail(array $detail,bool $canForecast): array
{
    if(!$canForecast)$detail['forecasts']=[];
    return $detail;
}

try{
    $pending=gac_pending_get('prep',$organizationId,$userId);
    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Prep Agent action to confirm.');
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Prep Intelligence management permission is required to confirm this action.'],403);
        $payload=(array)$pending['payload'];$type=(string)$pending['type'];
        try{
            if($type==='add_items'){
                $plan=pia_resolve_proposal_plan($pdo,$organizationId,$payload,true,$userId);$created=[];
                foreach((array)($payload['items']??[]) as $parsed){
                    if(trim((string)($parsed['title']??''))==='')continue;
                    $created[]=prep_attach_manual_task($pdo,$organizationId,$plan,['title'=>$parsed['title'],'quantity'=>$parsed['quantity']??null,'unit'=>$parsed['unit']??'','transcript'=>$payload['transcript']??'','aiConfidence'=>1.0],$userId,(string)($payload['source']??'agent'));
                }
                if(!$created)throw new InvalidArgumentException('The pending prep action no longer contains valid items.');
                $result=['ok'=>true,'skill'=>'prep.action_confirmed','answer'=>'Confirmed. Added '.count($created).' item(s) to '.ucfirst((string)$plan['service_period']).' prep: '.implode(', ',array_map(static fn($task)=>(string)$task['title'],$created)).'.','data'=>['action'=>'add_items','plan'=>$plan,'tasks'=>$created],'sources'=>array_column($created,'public_id')];
            }elseif($type==='publish_plan'){
                $plan=pia_resolve_proposal_plan($pdo,$organizationId,$payload,false,$userId);$detail=pia_visible_detail(prep_publish_plan($pdo,$organizationId,$plan,$userId),$canForecast);
                $result=['ok'=>true,'skill'=>'prep.action_confirmed','answer'=>'Confirmed. Published the '.$plan['plan_date'].' '.ucfirst((string)$plan['service_period']).' prep plan with '.count($detail['tasks']).' linked task(s).','data'=>['action'=>'publish_plan']+$detail,'sources'=>array_column($detail['tasks'],'public_id')];
            }elseif($type==='generate_recommendations'){
                $plan=pia_resolve_proposal_plan($pdo,$organizationId,$payload,true,$userId);$detail=pia_visible_detail(prep_generate_recommendations($pdo,$organizationId,$plan,$userId),$canForecast);$active=array_values(array_filter($detail['recommendations'],static fn(array $r):bool=>$r['status']!=='dismissed'));
                $result=['ok'=>true,'skill'=>'prep.action_confirmed','answer'=>'Confirmed. Generated '.count($active).' prep recommendation(s) for '.$plan['plan_date'].'.','data'=>['action'=>'generate_recommendations','plan'=>$detail['plan'],'recommendations'=>$active,'forecasts'=>$detail['forecasts']],'sources'=>array_column($active,'public_id')];
            }else throw new InvalidArgumentException('That pending Prep Agent action is no longer supported.');
        }catch(Throwable $e){gac_pending_clear('prep',$organizationId,$userId);throw $e;}
        gac_pending_clear('prep',$organizationId,$userId);app_audit($pdo,$organizationId,$userId,'prep.agent_action_confirmed','prep_agent_proposal',(string)$pending['id'],null,['type'=>$type]);app_json_response($result);
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('prep',$organizationId,$userId);app_audit($pdo,$organizationId,$userId,'prep.agent_action_discarded','prep_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']]);app_json_response(['ok'=>true,'skill'=>'prep.action_cancelled','answer'=>'Cancelled. I did not change the prep plan.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Prep + Inventory Intelligence']]);
    }

    if(preg_match('/\b(?:add|put)\b.*\bprep\s+list\b/iu',$normalized)||preg_match('/\badd(?:\s+these|\s+the following)?(?:\s+items?)?\s+to\s+(?:the\s+)?prep\s+list\b/iu',$normalized)){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Prep Intelligence management permission is required to add prep items.'],403);
        $payloadText=$text;
        if(preg_match('/\badd(?:\s+these|\s+the following)?(?:\s+items?)?\s+to\s+(?:the\s+)?prep\s+list\s*[:,-]?\s*(.+)$/iu',$text,$m))$payloadText=trim($m[1]);
        elseif(preg_match('/\b(?:add|put)\s+(.+?)\s+(?:to|on)\s+(?:the\s+)?prep\s+list\b/iu',$text,$m))$payloadText=trim($m[1]);
        $items=[];foreach(array_slice(pia_items($payloadText),0,30) as $raw){$parsed=pia_parse_item($raw);if($parsed['title']!=='')$items[]=$parsed;}
        if(!$items)app_json_response(['ok'=>false,'message'=>'Tell me which prep items to add.'],422);
        $date=prep_parse_date_phrase($text);$service=pia_service($text);$existing=prep_plan_for_period($pdo,$organizationId,$date,$service);if(!$existing&&$service!=='all')$existing=prep_plan_for_period($pdo,$organizationId,$date,'all');
        $summary='Proposed action: add '.count($items).' item(s) to '.ucfirst($service).' prep for '.$date. '.implode(', ',array_map(static fn($row)=>(($row['quantity']??null)!==null?pia_compact_quantity($row['quantity']).' '.($row['unit']?:'ea').' ':'').$row['title'],$items)).'.';
        $proposal=gac_pending_store('prep',$organizationId,$userId,'add_items',['date'=>$date,'service'=>$service,'expected'=>pia_plan_snapshot($existing),'items'=>$items,'transcript'=>$message,'source'=>!empty($input['voice'])?'voice':'agent'],$summary);
        app_audit($pdo,$organizationId,$userId,'prep.agent_action_proposed','prep_agent_proposal',(string)$proposal['id'],null,['type'=>'add_items','count'=>count($items)]);app_json_response(gac_proposal_result($proposal,'prep.action_proposal',['Prep Plan','Restaurant Tasks']));
    }

    if(preg_match('/\b(?:publish|release)\b.*\bprep\b/iu',$normalized)){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Prep Intelligence management permission is required to publish a prep plan.'],403);
        $date=prep_parse_date_phrase($text);$service=pia_service($text);$plan=prep_plan_for_period($pdo,$organizationId,$date,$service);if(!$plan&&$service!=='all')$plan=prep_plan_for_period($pdo,$organizationId,$date,'all');
        if(!$plan)throw new InvalidArgumentException('There is no prep plan for that period to publish.');
        $proposal=gac_pending_store('prep',$organizationId,$userId,'publish_plan',['date'=>(string)$plan['plan_date'],'service'=>(string)$plan['service_period'],'expected'=>pia_plan_snapshot($plan)],'Proposed action: publish the '.$plan['plan_date'].' '.ucfirst((string)$plan['service_period']).' prep plan into the employee task system.');
        app_audit($pdo,$organizationId,$userId,'prep.agent_action_proposed','prep_agent_proposal',(string)$proposal['id'],null,['type'=>'publish_plan']);app_json_response(gac_proposal_result($proposal,'prep.action_proposal',['Prep Plan','Restaurant Tasks']));
    }

    if(preg_match('/\b(?:generate|build|regenerate|recalculate)\b.*\b(?:prep|recommendations?)\b/iu',$normalized)){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Prep Intelligence management permission is required to generate recommendations.'],403);
        $date=prep_parse_date_phrase($text);$service=pia_service($text);$plan=prep_plan_for_period($pdo,$organizationId,$date,$service);if(!$plan&&$service!=='all')$plan=prep_plan_for_period($pdo,$organizationId,$date,'all');
        $proposal=gac_pending_store('prep',$organizationId,$userId,'generate_recommendations',['date'=>$date,'service'=>$service,'expected'=>pia_plan_snapshot($plan)],'Proposed action: generate/regenerate Prep Intelligence recommendations and inventory forecasts for '.$date.' '.ucfirst($service).'.');
        app_audit($pdo,$organizationId,$userId,'prep.agent_action_proposed','prep_agent_proposal',(string)$proposal['id'],null,['type'=>'generate_recommendations']);app_json_response(gac_proposal_result($proposal,'prep.action_proposal',['Prep History','Demand Signals','Inventory Forecast']));
    }

    if(preg_match('/\b(?:what should (?:we|i) prep|prep recommendations?|prep plan)\b/iu',$normalized)){
        $plan=pia_plan($pdo,$organizationId,$text,false,$userId);
        if(!$plan)app_json_response(['ok'=>true,'skill'=>'prep.recommend','answer'=>'There is no prep plan for that period yet. Ask me to generate the prep recommendations if you want me to create one; I will show a confirmation before writing anything.','data'=>null,'sources'=>[]]);
        $detail=pia_visible_detail(prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']),$canForecast);
        $active=array_values(array_filter($detail['recommendations'],static fn(array $r):bool=>$r['status']!=='dismissed'));
        $shortages=$canForecast?array_values(array_filter($detail['forecasts'],static fn(array $f):bool=>(float)$f['shortage_quantity']>0||(float)$f['restock_quantity']>0)):[];
        $top=array_slice(array_map(static fn(array $r):string=>$r['title'].' '.pia_compact_quantity($r['recommended_quantity']).' '.$r['unit'],$active),0,6);
        $answer=count($active).' existing recommendation(s) for '.$plan['plan_date'].'.';if($top)$answer.=' Top prep: '.implode('; ',$top).'.';if(!$active)$answer.=' No generated recommendations are stored yet; ask me to generate them if you want a new recommendation set.';
        if($canForecast)$answer.=count($shortages)?' Inventory forecast flags '.count($shortages).' ingredient(s) for shortage/restock.':' Inventory coverage has no stored flagged shortages from the current forecast.';
        app_json_response(['ok'=>true,'skill'=>'prep.recommend','answer'=>$answer,'data'=>['plan'=>$detail['plan'],'recommendations'=>$active,'forecasts'=>$shortages,'commitments'=>$detail['commitments']],'sources'=>array_column($active,'public_id')]);
    }

    if(preg_match('/\b(?:prep history|what did (?:we|i) prep|show .*prep.*(?:history|last))\b/iu',$normalized)){
        $date=prep_parse_date_phrase($text);$tasks=prep_task_history_for_date($pdo,$organizationId,$date);
        $summary=$tasks?implode('; ',array_slice(array_map(static fn(array $t):string=>$t['title'].($t['quantity']!==null?' '.$t['quantity'].' '.$t['unit']:''),$tasks),0,10)):'No prep tasks were recorded.';
        app_json_response(['ok'=>true,'skill'=>'prep.history','answer'=>'Prep history for '.$date.': '.$summary,'data'=>['date'=>$date,'tasks'=>$tasks],'sources'=>array_column($tasks,'public_id')]);
    }

    if(preg_match('/\bhow much\s+(.+?)\s+(?:do|did)\s+(?:we|i)\s+(?:normally\s+)?prep\b/iu',$text,$m)||preg_match('/\b(?:normally|usually)\s+prep\s+(.+?)(?:\s+on\s+|\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)s?\b|$)/iu',$text,$m)){
        $query=trim($m[1]);$weekday=prep_weekday_from_text($text);$h=prep_item_history_summary($pdo,$organizationId,$query,$weekday);
        $day=$weekday!==null?['','Mondays','Tuesdays','Wednesdays','Thursdays','Fridays','Saturdays','Sundays'][$weekday]:'recent prep';
        $answer=$h['sampleCount']?('Based on '.$h['sampleCount'].' completed sample(s), '.$query.' averages '.$h['average'].' '.$h['unit'].' and the median is '.$h['median'].' '.$h['unit'].' for '.$day.'.'):('I do not have enough completed prep history for '.$query.' yet.');
        app_json_response(['ok'=>true,'skill'=>'prep.normal_history','answer'=>$answer,'data'=>$h,'sources'=>[]]);
    }

    if(preg_match('/\b(?:inventory forecast|forecast.*(?:inventory|shortage)|shortage forecast|what (?:are|is) (?:we|i) short|what do (?:we|i) need to order)\b/iu',$normalized)){
        if(!$canForecast)app_json_response(['ok'=>false,'message'=>'Inventory forecast permission is required for shortage and restock intelligence.'],403);
        $plan=pia_plan($pdo,$organizationId,$text,false,$userId);
        if(!$plan)app_json_response(['ok'=>true,'skill'=>'prep.inventory_forecast','answer'=>'There is no prep plan for that period yet.','data'=>[],'sources'=>[]]);
        $detail=prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']);$flagged=array_values(array_filter($detail['forecasts'],static fn(array $f):bool=>(float)$f['shortage_quantity']>0||(float)$f['restock_quantity']>0));
        $top=array_slice(array_map(static fn(array $f):string=>$f['inventory_name'].' (short '.pia_compact_quantity($f['shortage_quantity']).', restock '.pia_compact_quantity($f['restock_quantity']).' '.$f['unit'].')',$flagged),0,8);
        $answer=$flagged?('Forecast flags '.count($flagged).' item(s): '.implode('; ',$top).'.'):'No stored forecast shortages or restock-to-par gaps are currently flagged. Ask me to generate prep recommendations if you need the forecast recalculated.';
        app_json_response(['ok'=>true,'skill'=>'prep.inventory_forecast','answer'=>$answer,'data'=>$flagged,'sources'=>array_column($flagged,'public_id')]);
    }

    $plan=pia_plan($pdo,$organizationId,$text,false,$userId);
    if(!$plan)app_json_response(['ok'=>true,'skill'=>'prep.status','answer'=>'No prep plan exists for that period yet. Ask me to generate the prep recommendations when you are ready; I will require confirmation before creating it.','data'=>null,'sources'=>[]]);
    $detail=pia_visible_detail(prep_plan_detail($pdo,$organizationId,(string)$plan['public_id']),$canForecast);
    $open=count(array_filter($detail['tasks'],static fn(array $t):bool=>!in_array($t['status'],['completed','verified','cancelled'],true)));
    $done=count(array_filter($detail['tasks'],static fn(array $t):bool=>in_array($t['status'],['completed','verified'],true)));
    $answer='Prep plan '.$plan['plan_date'].' '.$plan['service_period'].': '.$open.' open task(s), '.$done.' completed, '.count($detail['recommendations']).' recommendation(s)';
    if($canForecast)$answer.=', and '.count(array_filter($detail['forecasts'],static fn(array $f):bool=>(float)$f['shortage_quantity']>0)).' forecast shortage(s)';
    $answer.='.';
    app_json_response(['ok'=>true,'skill'=>'prep.status','answer'=>$answer,'data'=>$detail,'sources'=>array_column($detail['tasks'],'public_id')]);
}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
