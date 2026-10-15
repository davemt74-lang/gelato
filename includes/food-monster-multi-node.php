<?php
declare(strict_types=1);
/** FM-G7 is a deterministic read-only planner, never a write-capable LLM tool loop. */
function fm7_requested_domains(string $message):array {
  $text=mb_strtolower(trim($message),'UTF-8');
  if($text===''||mb_strlen($text,'UTF-8')>4500)return [];
  if(preg_match('/\b(create|change|edit|update|delete|remove|refund|approve|confirm|place|submit|purchase|send|post|publish|clock in|clock out|assign|fire|mark|complete|cancel|execute|apply)\b/u',$text))return [];
  if(!preg_match('/\b(check|review|inspect|summari[sz]e|compare|analy[sz]e|assess|look at|report|show|tell me|what(?:\x27s| is)|how are|overview|what should)\b/u',$text))return [];
  $patterns=[
    'kitchen'=>'/\b(kds|tickets?|kitchen|active orders?|orders?|pickup|expo)\b/u',
    'inventory'=>'/\b(inventory|stock|shortages?|reorder|supplies|tasks?)\b/u',
    'scheduling'=>'/\b(schedul(?:e|ing)|employees?|staff(?:ing)?|shifts?|coverage|labor)\b/u',
    'purchasing'=>'/\b(purchas(?:e|ing)|vendors?|supplier|purchase orders?|deliveries?|receiving)\b/u',
    'prep'=>'/\b(prep|forecast|production readiness)\b/u',
    'catering'=>'/\b(catering|banquet|events? readiness)\b/u',
    'crm'=>'/\b(crm|customers?|guests?|retention|lapsed)\b/u'
  ];
  $found=[];foreach($patterns as $name=>$pattern)if(preg_match($pattern,$text))$found[]=$name;
  if(count($found)>=2)return array_slice($found,0,6);
  if(preg_match('/\b(entire (?:restaurant|business)|across (?:the )?(?:restaurant|business)|all departments|full operations overview)\b/u',$text))
    return ['kitchen','inventory','scheduling','purchasing','prep','catering'];
  return [];
}
function fm7_signal_domain(array $signal):?string {
  $node=(string)($signal['node']??'');
  $prefix=strtolower(explode(':',(string)($signal['key']??''),2)[0]);
  return match(true){
    in_array($prefix,['kds','pos','online'],true),in_array($node,['kds','pos','online_orders'],true)=>'kitchen',
    $prefix==='scheduling'||$node==='scheduling'=>'scheduling',
    $prefix==='purchasing'||$node==='purchasing'=>'purchasing',
    $prefix==='prep'||$node==='prep'=>'prep',
    $prefix==='crm'||$node==='crm'=>'crm',
    $prefix==='catering'=>'catering',
    str_contains((string)($signal['key']??''),'inventory')||$prefix==='operations'||$node==='operations'=>'inventory',
    default=>null
  };
}
function fm7_briefing(array $snapshot,array $domains):array {
  $labels=['kitchen'=>'KDS / Orders','inventory'=>'Inventory / Tasks','scheduling'=>'Staffing / Schedule',
    'purchasing'=>'Purchasing','prep'=>'Prep Forecast','catering'=>'Catering','crm'=>'Customer CRM'];
  $lines=[];$sources=['Gelato Agent Brain'];$steps=[];$findings=[];
  foreach($domains as $domain){
    if(!isset($labels[$domain]))continue;
    $filtered=[];
    foreach((array)($snapshot['signals']??[]) as $signal)
      if(is_array($signal)&&fm7_signal_domain($signal)===$domain)$filtered[]=$signal;
    usort($filtered,static fn($a,$b)=>(int)($b['score']??0)<=>(int)($a['score']??0));
    $steps[]=['domain'=>$domain,'label'=>$labels[$domain],'status'=>'read_only_reviewed','signalCount'=>count($filtered)];
    if(!$filtered){$lines[]=$labels[$domain].': No exception signal returned; this does NOT certify full health.';continue;}
    $sources[]=$labels[$domain];
    foreach(array_slice($filtered,0,3) as $signal){
      $title=mb_substr(trim(strip_tags((string)($signal['title']??''))),0,180,'UTF-8');
      $detail=mb_substr(trim(strip_tags((string)($signal['detail']??''))),0,350,'UTF-8');
      $lines[]=$labels[$domain].': '.$title.($detail!==''?' — '.$detail:'');
      $severity=(string)($signal['severity']??'normal');
      $findings[]=[
        'domain'=>$domain,'severity'=>in_array($severity,['critical','high','normal','low'],true)?$severity:'normal',
        'title'=>$title,'summary'=>$detail,'node'=>(string)($signal['node']??$domain),
        'suggestedReview'=>mb_substr((string)($signal['nextMove']['prompt']??''),0,450,'UTF-8'),
        'key'=>(string)($signal['key']??'')
      ];
    }
  }
  $rank=['critical'=>0,'high'=>1,'normal'=>2,'low'=>3];
  usort($findings,static fn($a,$b)=>$rank[$a['severity']]<=>$rank[$b['severity']]);
  $answer="I reviewed available permission-scoped Agent Brain signals in ".count($steps)." areas.\n\n".implode("\n",$lines)."\n\n";
  if($findings){
    $answer.="Recommended reviews (no changes made):\n";
    foreach(array_slice($findings,0,4) as $i=>$f)$answer.=($i+1).'. '.$f['title'].' — '.
      ($f['suggestedReview']?:'Review the underlying Gelato module before acting.')."\n";
  }else $answer.="No exception signals were returned. Consult the original modules for complete live records.\n";
  $answer.="\nNo orders, inventory, schedules, or other operational records were modified. Any write remains permission-gated.";
  return ['skill'=>'agent.multi_node.read_only','answer'=>$answer,'sources'=>array_values(array_unique($sources)),
    'data'=>['mode'=>'read_only','steps'=>$steps,'findings'=>array_slice($findings,0,10),'generatedAt'=>$snapshot['generatedAt']??null]];
}
