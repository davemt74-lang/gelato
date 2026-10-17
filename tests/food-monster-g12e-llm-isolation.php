<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/food-monster-llm.php';
$n=0;
function check12(bool $assertion,string $message):void {
    global $n;
    if(!$assertion)throw new RuntimeException('FAIL: '.$message);
    $n++;
}
$room=[
 ['role'=>'user','content'=>'Employee A: confidential dough question'],
 ['role'=>'assistant','content'=>'Earlier employee response'],
 ['role'=>'user','content'=>'Employee B: how do I stretch dough?'],
];
$person=[
 ['role'=>'user','content'=>'Employee B: show me how to stretch dough'],
 ['role'=>'assistant','content'=>'Stretch dough gently'],
 ['role'=>'user','content'=>'Employee B: how do I stretch dough?'],
];
$voice=['role'=>'user','content'=>'Employee B: how do I stretch dough?','channel'=>'voice'];
$isolated=fm12e_isolate_voice_history($room,$person,$voice,true);
check12($isolated===$person,'verified employee receives only own canonical history');
check12(!str_contains(json_encode($isolated),'confidential dough question'),'room coworker history excluded');
$unknown=fm12e_isolate_voice_history($room,[],$voice,false);
check12($unknown===[['role'=>'user','content'=>$voice['content']]],'unknown voice has only current turn');
check12(!str_contains(json_encode($unknown),'Earlier employee response'),'unknown voice cannot inherit previous agent answer');
$mismatched=$person;$mismatched[2]['content']='A different user question';
check12(fm12e_isolate_voice_history($room,$mismatched,$voice,true)===$unknown,
 'mismatched person mirror fails closed');
$typed=$voice;$typed['channel']='text';
check12(fm12e_isolate_voice_history($room,$person,$typed,true)===$room,
 'operator typed chat retains authorized chronological history');
check12(fm12e_isolate_voice_history($room,[],$voice,true)===$unknown,
 'missing person mirror fails closed');
echo 'FM-G12E LLM multi-speaker isolation PASS ('.$n.' assertions)'."\n";
