<?php
declare(strict_types=1);
require __DIR__.'/../includes/food-monster-g13a-engagement.php';
$n=0;
function g13a_ok(bool $assert,string $message):void{
 global $n;
 if(!$assert)throw new RuntimeException('FM-G13A FAIL: '.$message);
 $n++;
}
$room='agent-'.str_repeat('c',20);
$now=1740000000;
$mk=static function(string $text,int $age,string $thread,string $role='user',string $category='training_question')use($now):array{
 return ['role'=>$role,'channel'=>'voice','content'=>$text,'created_at'=>gmdate('Y-m-d H:i:s',$now-$age),
  'structured'=>['collection'=>'fm9d_person_turn','operatorThreadId'=>$thread,'memoryCategory'=>$category]];
};
$current=$mk('And what about that?',2,$room);
$prior=$mk('How do I stretch the pizza dough?',50,$room);
$other=$mk('What is the secret employee schedule?',100,$room);
$base=[$other,$prior,$current];
$plan=fm13a_engagement_plan($base,$current,$room,true,$now);
g13a_ok($plan['mode']==='continue','verified same person short follow-up uses contextual memory');
g13a_ok(str_contains($plan['context'],'stretch the pizza dough'),'context grounded in immediate previous person question');
g13a_ok(!str_contains($plan['context'],'secret employee schedule'),'older/unrelated questions not injected');
g13a_ok($plan['reply']===null,'verified continuation never preempts the LLM answer');
$unknown=fm13a_engagement_plan($base,$current,$room,false,$now);
g13a_ok($unknown['mode']==='clarify'&&!str_contains(json_encode($unknown),'stretch the pizza dough'),'unverified voice gets no person context');
g13a_ok(fm13a_engagement_plan([$prior,$current],$current,'agent-'.str_repeat('d',20),true,$now)['mode']==='clarify','cross-room continuity denied');
g13a_ok(fm13a_engagement_plan([$mk('How do I stretch dough?',700,$room),$current],$current,$room,true,$now)['mode']==='clarify','expired context not revived');
g13a_ok(fm13a_engagement_plan([$mk('How do I stretch dough?',50,'agent-'.str_repeat('d',20)),$current],$current,$room,true,$now)['mode']==='clarify','previous other room never admitted');
g13a_ok(fm13a_engagement_plan([$mk('Where is the register?',50,$room,'user','general'),$current],$current,$room,true,$now)['mode']==='clarify','irrelevant old memory category not borrowed');
g13a_ok(fm13a_engagement_plan([$mk('How do I stretch dough?',50,$room),$current+['x'=>1]],$current+['x'=>1],$room,true,$now)['mode']==='continue','ignored unknown harmless metadata');
$tampered=$current;$tampered['structured']['collection']='web_cam_guess';
g13a_ok(fm13a_engagement_plan([$prior,$tampered],$current,$room,true,$now)['mode']==='clarify','visual inference cannot supply canonical turn');
$wrong=$current;$wrong['content']='A different message';
g13a_ok(fm13a_engagement_plan([$prior,$wrong],$current,$room,true,$now)['mode']==='clarify','mismatched current voice message denied');
g13a_ok(fm13a_engagement_plan([$prior,$current],$current,$room,true,$now-300)['mode']==='clarify','future current turn denied');
$explicit=$mk('And please tell me how to prepare gluten-free dough.',2,$room);
g13a_ok(fm13a_engagement_plan([$prior,$explicit],$explicit,$room,false,$now)['mode']==='ordinary','explicit request not treated as ambiguous');
foreach(['And what about that?','How about it?','What is the next step?','Does this contain dairy?','How long does it take?'] as $q)
 g13a_ok(fm13a_needs_context($q),'detects contextual prompt: '.$q);
foreach(['Please explain the pizza recipe','Show me toppings for our menu','What does mozzarella contain?'] as $q)
 g13a_ok(!fm13a_needs_context($q),'explicit utterance not overly restricted: '.$q);
$canon=['role'=>'user','channel'=>'voice','content'=>'Buy another oven'];
g13a_ok(fm13a_voice_route_mode($canon,'Buy another oven',false)==='conversation_only','employee spoken tool request restricted');
g13a_ok(fm13a_voice_route_mode($canon,'Buy another oven',true)==='owner','server-verified operator voice retains owner route');
g13a_ok(fm13a_voice_route_mode($canon,'A different request',false)==='ordinary','router requires exact canonical message');
g13a_ok(fm13a_voice_route_mode(['role'=>'user','channel'=>'text','content'=>'Buy another oven'],'Buy another oven',false)==='ordinary','typed owner request unchanged');
g13a_ok(fm13a_voice_route_mode(['role'=>'agent','channel'=>'voice','content'=>'Buy another oven'],'Buy another oven',false)==='ordinary','agent response cannot be a participant command');
g13a_ok(fm13a_voice_route_mode(['role'=>'user','channel'=>'voice','content'=>'Buy another oven'],'Buy another oven',false)!=='owner','unknown speech cannot acquire permissions');
echo 'FM-G13A grounded engagement and route isolation PASS ('.$n.' assertions)'."\n";
