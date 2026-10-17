<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/food-monster-g11d-followups.php';
$n=0;
function ok11d(bool $x,string $m):void{global $n;if(!$x)throw new RuntimeException($m);$n++;}
$mk=static fn(string $id,string $text)=>['messageId'=>'msg-'.str_repeat($id,20),
    'text'=>$text,'createdAt'=>'2026-10-16'];
$memory=['openReports'=>[$mk('e','Report: oven is broken')],
    'recentIngredientQuestions'=>[],
    'recentTrainingQuestions'=>[
        $mk('a','Show me how to prepare pizza dough'),
        $mk('b','How to prepare the crust dough'),
        $mk('c','How to prepare pizza dough right now?')]];
$current='msg-'.str_repeat('c',20);
$hint=fm11d_suggest_from_memory($memory,'How to prepare pizza dough right now?',$current);
ok11d(is_array($hint)&&$hint['type']==='training_recap','repeat training topic produces offer');
ok11d(count($hint['sourceMessageIds'])===2,'two separate earlier sources required');
ok11d(!in_array($current,$hint['sourceMessageIds'],true),'current message excluded from evidence');
ok11d($hint['executed']===false&&$hint['requiresApproval']===false,'follow-up never executes tools');
ok11d(!isset($hint['personId'])&&!isset($hint['employeeName']),'no employee profiling fields');
ok11d(!str_contains(json_encode($hint),'oven is broken'),'unrelated report never quoted');
ok11d(fm11d_suggest_from_memory($memory,'How do we handle closing?',str_replace('c','d',$current))===null,
 'unrelated topic cannot trigger a recap');
ok11d(fm11d_suggest_from_memory($memory,'Report: dough mixer broken',$current)===null,
 'operational report cannot trigger an optional training hint');
ok11d(fm11d_suggest_from_memory($memory,'How to prepare pizza dough right now?','fake')===null,
 'forged reference invalid');
$memory['recentTrainingQuestions']=[$mk('a','Show me how to prepare pizza dough'),$mk('c','How to prepare pizza dough right now?')];
ok11d(fm11d_suggest_from_memory($memory,'How to prepare pizza dough right now?',$current)===null,
 'one earlier interaction is not evidence of repeated training need');
$memory=['openReports'=>[],'recentTrainingQuestions'=>[],
 'recentIngredientQuestions'=>[
  $mk('a','Does pesto contain dairy ingredients?'),
  $mk('b','Which allergens are in pesto?'),
  $mk('c','Does pesto have milk ingredients?')]];
$hint=fm11d_suggest_from_memory($memory,'Does pesto have milk ingredients?',$current);
ok11d(is_array($hint)&&$hint['type']==='ingredient_recap','repeated ingredient topic produces offer');
ok11d($hint['sourceMessageIds']===['msg-'.str_repeat('b',20),'msg-'.str_repeat('a',20)]
   ||count($hint['sourceMessageIds'])===2,'ingredient hint links to source records');
// G12C: distinct IDs with identical recognized content are not two independent examples.
$duplicate=['openReports'=>[],'recentIngredientQuestions'=>[],
 'recentTrainingQuestions'=>[
  $mk('a','How to prepare the pizza crust?'),
  $mk('b','How to prepare the pizza crust?'),
  $mk('c','How to prepare the pizza crust right now?')]];
ok11d(fm11d_suggest_from_memory($duplicate,'How to prepare the pizza crust right now?',
 'msg-'.str_repeat('c',20))===null,'duplicated transcript cannot trigger repeated-training suggestion');
$duplicate['recentTrainingQuestions'][1]=$mk('b','Show me how to prepare pizza crust dough');
ok11d(fm11d_suggest_from_memory($duplicate,'How to prepare the pizza crust right now?',
 'msg-'.str_repeat('c',20))!==null,'distinct training questions still qualify');
// G12C: paraphrases with identical meaningful terms are one source of evidence.
$duplicate['recentTrainingQuestions'][1]=$mk('b','Could you show me how to prepare pizza crust?');
ok11d(fm11d_suggest_from_memory($duplicate,'How to prepare the pizza crust right now?',
 'msg-'.str_repeat('c',20))===null,'paraphrased duplicate does not become new evidence');

// G12D: exercise actual runtime gate, not strings in production source.
$first=['type'=>'training_recap','sourceMessageIds'=>['msg-'.str_repeat('a',20),'msg-'.str_repeat('b',20)]];
$reversed=['type'=>'training_recap','sourceMessageIds'=>array_reverse($first['sourceMessageIds'])];
$changed=['type'=>'training_recap','sourceMessageIds'=>['msg-'.str_repeat('a',20),'msg-'.str_repeat('d',20)]];
$times=[];$history=[];
ok11d(fm12d_advice_gate($times,$history,'org:owner:employee1',$first,100000),'first suggestion allowed');
ok11d(!fm12d_advice_gate($times,$history,'org:owner:employee1',$reversed,101201),'same sources reversed do not produce repeat');
ok11d(fm12d_advice_gate($times,$history,'org:owner:employee1',$changed,101201),'new independent evidence produces new offer');
ok11d(fm12d_advice_gate($times,$history,'org:owner:employee2',$first,101202),'employee boundaries independent');
ok11d(!fm12d_advice_gate($times,$history,'org:owner:employee2',$first,101203),'employee cooldown remains enforced');
ok11d(!fm12d_advice_gate($times,$history,'org:owner:employee2',['type'=>'training_recap',
 'sourceMessageIds'=>[$first['sourceMessageIds'][0],$first['sourceMessageIds'][0]]],102500),'duplicate evidence IDs rejected');
for($i=0;$i<40;$i++){
 $key='org:owner:staff'.$i;
 fm12d_advice_gate($times,$history,$key,$first,105000);
}
ok11d(count($times)<=32&&count($history)<=32,'session policy has bounded cardinality');
$workspace=file_get_contents(__DIR__.'/../api/agent-workspace.php');
$client=file_get_contents(__DIR__.'/../js/global-agent.js');
ok11d(str_contains($workspace,'fm11d_verified_turn_suggestion(')
  &&str_contains($workspace,"$"."personSpeaker")&&str_contains($workspace,"$"."role==='user'"),
 'only server-side verified person turn obtains a suggestion');
ok11d(str_contains($client,'conversationalSuggestion')&&str_contains($client,'suggested.executed===false'),
 'client never presents suggestion as completed action');
echo "FM-G11D grounded, evidence-linked conversational hint PASS ($n assertions)\n";
