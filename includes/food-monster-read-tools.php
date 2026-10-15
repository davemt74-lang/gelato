<?php
declare(strict_types=1);
/** FM-G7B2: deterministic, permission-aware, read-only Gelato evidence. */
function fm_read_intent(string $message): ?string
{
    $text=mb_strtolower(trim($message),'UTF-8');
    if($text===''||mb_strlen($text,'UTF-8')>2000)return null;
    // Mutations stay on the existing permission/approval routes.
    if(preg_match('/\b(refund|delete|remove|change|edit|update|modify|submit|approve|publish|assign|place order|clock me in|clock me out|clock in|clock out|void|cancel|create|charge|capture payment)\b/u',$text))return null;
    if(preg_match('/\b(who(?:\s+is|\x27s)?\s+(?:currently\s+)?(?:signed|logged)\s+in|who am i|am i (?:signed|logged) in|current (?:signed.in )?user|my (?:login|account) status|(?:who|which user) is using (?:this|the) account)\b/u',$text))return 'session_identity';
    if(preg_match('/\b(who(?:\s+is|\x27s)?\s+(?:currently\s+)?clocked in|who(?:\s+is|\x27s)?\s+(?:working|on shift|on the clock)|(?:staff|employees?|team|crew)\s+(?:clocked in|on the clock)|on the clock (?:now|today)|clocked in now|current clock.?ins?)\b/u',$text))return 'active_clock_ins';
    if(preg_match('/\b(sales|revenue|net sales|gross sales|sales totals?|sales report|sales figures?|how much (?:did|have) we (?:sell|make)|what (?:did|have) we (?:sell|made)|sales yesterday|sales today)\b/u',$text))return 'recorded_sales';
    return null;
}
function fm_read_allowed(array $user,string $intent): bool
{
    return match($intent){
        'session_identity'=>!empty($user['id'])&&!empty($user['organization_id']),
        'active_clock_ins'=>app_has_permission('timeclock.agent',$user)&&
            (app_has_permission('timeclock.view',$user)||app_has_permission('attendance.view',$user)),
        'recorded_sales'=>app_has_permission('sales.view',$user)&&app_has_permission('sales.agent',$user),
        default=>false,
    };
}
function fm_read_date(string $message,?DateTimeImmutable $today=null): string
{
    $today=$today??new DateTimeImmutable('today');
    $text=mb_strtolower(trim($message),'UTF-8');
    if(preg_match('/\byesterday\b/u',$text))return $today->modify('-1 day')->format('Y-m-d');
    if(preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u',$text,$m)){
        $candidate=DateTimeImmutable::createFromFormat('!Y-m-d',$m[1]);
        if(!$candidate||$candidate->format('Y-m-d')!==$m[1])throw new InvalidArgumentException('Use a valid sales date.');
        return $m[1];
    }
    $months=['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    if(preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec)\.?\s+(\d{1,2})(?:,?\s+(\d{4}))?\b/u',$text,$m)){
        $month=$months[substr($m[1],0,3)];$day=(int)$m[2];$year=isset($m[3])?(int)$m[3]:(int)$today->format('Y');
        if(!checkdate($month,$day,$year))throw new InvalidArgumentException('Use a valid sales date.');
        return sprintf('%04d-%02d-%02d',$year,$month,$day);
    }
    return $today->format('Y-m-d');
}
function fm_read_sales_answer(string $date,array $report): array
{
    $daily=(array)($report['daily']??[]);
    // Absence of imported daily rows is not evidence that sales are zero.
    if(!$daily)return ['answer'=>'No recorded sales period was available for '.$date.'. That does not mean sales were $0.00; verify the sales import or POS data source.',
        'data'=>['date'=>$date,'hasRecordedPeriod'=>false,'recordedAt'=>date(DATE_ATOM)],'sources'=>['Gelato Sales Intelligence']];
    $totals=(array)($report['totals']??[]);
    $sales=(float)($totals['netSales']??0);$tickets=(int)($totals['tickets']??0);
    $answer='For '.$date.', Gelato Sales Intelligence recorded $'.number_format($sales,2).' net sales across '.$tickets.' ticket'.($tickets===1?'':'s').'.';
    return ['answer'=>$answer,'data'=>['date'=>$date,'hasRecordedPeriod'=>true,'netSales'=>$sales,'tickets'=>$tickets,'recordedAt'=>date(DATE_ATOM)],'sources'=>['Gelato Sales Intelligence']];
}
function fm_read_clock_answer(array $rows): array
{
    $names=[];foreach(array_slice($rows,0,30) as $r){
        if(!is_array($r))continue;
        $name=trim(strip_tags((string)($r['display_name']??'')));
        if($name==='')continue;
        $names[]=$name.' (clocked in '.(string)($r['clocked_in_at']??'time unavailable').')';
    }
    $count=count($rows);
    $answer=$count?($count.' staff member'.($count===1?' is':'s are').' currently clocked in: '.implode('; ',$names).($count>30?'; and others.':'.')):
        'No active clock-in entries were returned for this organization. This is attendance status, not website login status.';
    return ['answer'=>$answer,'data'=>['activeCount'=>$count,'recordedAt'=>date(DATE_ATOM)],'sources'=>['Gelato Time Clock']];
}
function fm_read_execute(PDO $pdo,array $user,string $intent,string $message): array
{
    if(!fm_read_allowed($user,$intent))throw new DomainException('You do not have permission to view that Gelato information.');
    $org=(int)$user['organization_id'];
    if($intent==='session_identity'){
        $name=trim((string)($user['display_name']??$user['first_name']??''));
        $name=$name!==''?$name:'Account #'.(int)$user['id'];
        return ['skill'=>'session.current_user','answer'=>'You are signed into Gelato as '.$name.'. I can verify this session, but not which other users have an active website login. Clock-in status is separate.',
            'data'=>['sessionUserId'=>(int)$user['id'],'role'=>(string)($user['role_slug']??''),'recordedAt'=>date(DATE_ATOM)],'sources'=>['Gelato authenticated session']];
    }
    if($intent==='active_clock_ins'){
        require_once __DIR__.'/timeclock-agent-core.php';
        if(!timeclock_agent_ready($pdo))throw new RuntimeException('Time Clock is not installed. Run the Gelato upgrade.');
        $result=fm_read_clock_answer(timeclock_agent_active_staff($pdo,$org,null));
        return ['skill'=>'timeclock.active_staff']+$result;
    }
    if($intent==='recorded_sales'){
        require_once __DIR__.'/sales-intelligence-core.php';
        if(!sales_intelligence_ready($pdo))throw new RuntimeException('Sales Intelligence is not installed. Run the Gelato upgrade.');
        $date=fm_read_date($message);$result=fm_read_sales_answer($date,sales_dashboard($pdo,$org,$date,$date,null));
        return ['skill'=>'sales.verified_summary']+$result;
    }
    throw new InvalidArgumentException('Unknown read-only skill.');
}
