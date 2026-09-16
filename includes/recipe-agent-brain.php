<?php
declare(strict_types=1);

require_once __DIR__.'/recipe-agent-core.php';

function recipe_agent_brain_signal_rows(PDO $pdo,array $user): array
{
    if(!app_has_permission('recipes.view',$user)||!app_has_permission('recipes.agent',$user)||!recipe_agent_ready($pdo))return [];
    $org=(int)$user['organization_id'];$gaps=recipe_agent_standard_gaps($pdo,$org,20);if(!$gaps)return [];
    $summary=recipe_agent_library_summary($pdo,$org);$first=$gaps[0];$count=count($gaps);$severity=$count>=5?'high':'normal';$score=$count>=5?68:48;
    return [[
        'key'=>'recipes:production-standards','node'=>'recipes','nodeLabel'=>'Recipe + Production Standards','severity'=>$severity,'score'=>$score,
        'title'=>'Recipe production standards need attention',
        'detail'=>$count.' active recipe'.($count===1?' is':'s are').' missing one or more production-standard fields. '.$first['name'].' is missing '.implode(', ',$first['gaps']).'.',
        'href'=>'recipes.php','evidence'=>['activeRecipes'=>$summary['active'],'recipesWithGaps'=>$count,'firstRecipePublicId'=>$first['publicId'],'firstRecipeGaps'=>$first['gaps']],
        'nextMove'=>['node'=>'recipes','nodeLabel'=>'Recipe + Production Standards','mode'=>'review','label'=>'Review','prompt'=>'Review recipe production standards that are incomplete and show me what needs to be filled in first.'],
    ]];
}

function recipe_agent_merge_brain_snapshot(PDO $pdo,array $user,array $snapshot): array
{
    $extra=recipe_agent_brain_signal_rows($pdo,$user);if(!$extra)return $snapshot;
    $byKey=[];foreach(array_merge((array)($snapshot['signals']??[]),$extra) as $signal)$byKey[(string)$signal['key']]=$signal;
    $signals=array_values($byKey);$rank=static fn(string $severity):int=>match($severity){'critical'=>4,'high'=>3,'normal'=>2,'low'=>1,default=>0};
    usort($signals,static fn(array $a,array $b):int=>((int)$b['score']<=>(int)$a['score'])?:($rank((string)$b['severity'])<=>$rank((string)$a['severity']))?:strcmp((string)$a['title'],(string)$b['title']));
    $snapshot['signals']=$signals;
    $snapshot['nextMoves']=array_values(array_map(static fn(array $signal):array=>['key'=>$signal['key'],'node'=>$signal['node'],'nodeLabel'=>$signal['nodeLabel'],'severity'=>$signal['severity'],'score'=>$signal['score'],'title'=>$signal['title'],'detail'=>$signal['detail'],'href'=>$signal['href'],'mode'=>$signal['nextMove']['mode'],'label'=>$signal['nextMove']['label'],'prompt'=>$signal['nextMove']['prompt']],array_slice($signals,0,12)));
    return $snapshot;
}
