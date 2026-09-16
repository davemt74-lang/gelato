<?php
declare(strict_types=1);

require_once __DIR__.'/package-deals-core.php';

function marketing_brain_signal_rows(PDO $pdo,array $user): array
{
    if(!app_has_permission('packages.view',$user)||!package_deals_ready($pdo))return [];
    $org=(int)$user['organization_id'];$packages=package_deal_list($pdo,$org,true);if(!$packages)return [];
    $active=array_values(array_filter($packages,static fn(array $p):bool=>(string)($p['status']??'')==='active'));
    $drafts=array_values(array_filter($packages,static fn(array $p):bool=>(string)($p['status']??'')==='draft'));
    $activeFeatured=array_values(array_filter($active,static fn(array $p):bool=>!empty($p['featured'])));
    $rows=[];
    if(!$active&&$drafts){
        $candidate=$drafts[0];
        $rows[]=[
            'key'=>'marketing:no-active-package','node'=>'marketing','nodeLabel'=>'Marketing + Public Site','severity'=>'normal','score'=>64,
            'title'=>'No Package Deal is currently active','detail'=>'There are '.count($drafts).' draft package'.(count($drafts)===1?'':'s').' available for review, including '.(string)$candidate['name'].'.',
            'href'=>'packages-admin.php','evidence'=>['draftCount'=>count($drafts),'packagePublicId'=>$candidate['id']??null],
            'nextMove'=>['node'=>'marketing','nodeLabel'=>'Marketing + Public Site','mode'=>'review','label'=>'Review','prompt'=>'Review the draft Package Deals and tell me which promotion is safest to activate next.'],
        ];
    }elseif($active&&!$activeFeatured){
        usort($active,static fn(array $a,array $b):int=>((float)($b['stats']['revenue']??0)<=>(float)($a['stats']['revenue']??0))?:((int)($b['stats']['redemptions']??0)<=>(int)($a['stats']['redemptions']??0)));
        $candidate=$active[0];
        $rows[]=[
            'key'=>'marketing:no-featured-package','node'=>'marketing','nodeLabel'=>'Marketing + Public Site','severity'=>'low','score'=>48,
            'title'=>'Active promotion is not Featured on Public Specials','detail'=>(string)$candidate['name'].' is active but no active Package Deal is currently marked Featured.',
            'href'=>'packages-admin.php','evidence'=>['activeCount'=>count($active),'packagePublicId'=>$candidate['id']??null,'packageRevenue'=>(float)($candidate['stats']['revenue']??0)],
            'nextMove'=>['node'=>'marketing','nodeLabel'=>'Marketing + Public Site','mode'=>'review','label'=>'Review','prompt'=>'Review active Package Deals and tell me whether the strongest current promotion should be Featured on the public Specials page.'],
        ];
    }
    return $rows;
}

function marketing_merge_brain_snapshot(PDO $pdo,array $user,array $snapshot): array
{
    $extra=marketing_brain_signal_rows($pdo,$user);if(!$extra)return $snapshot;
    $byKey=[];foreach(array_merge((array)($snapshot['signals']??[]),$extra) as $signal)$byKey[(string)$signal['key']]=$signal;
    $signals=array_values($byKey);$rank=static fn(string $severity):int=>match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
    usort($signals,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:($rank((string)$b['severity'])<=>$rank((string)$a['severity']))?:strcmp((string)$a['title'],(string)$b['title']));
    $snapshot['signals']=$signals;
    $snapshot['nextMoves']=array_values(array_map(static fn(array $signal):array=>['key'=>$signal['key'],'node'=>$signal['node'],'nodeLabel'=>$signal['nodeLabel'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'detail'=>$signal['detail'],'href'=>$signal['href'],'mode'=>$signal['nextMove']['mode'],'label'=>$signal['nextMove']['label'],'prompt'=>$signal['nextMove']['prompt']],array_slice($signals,0,12)));
    return $snapshot;
}
