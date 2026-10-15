<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$next=(string)file_get_contents($root.'/js/agent-next-moves.js');
$companion=(string)file_get_contents($root.'/js/food-monster-suggestions.js');
$mount=(string)file_get_contents($root.'/js/food-monster.js');
$css=(string)file_get_contents($root.'/css/food-monster.css');
$asserts=0;
function fm5(bool $condition,string $label):void{
 global $asserts;if(!$condition){fwrite(STDERR,"FM-G5 FAIL: ".$label."\n");exit(1);}$asserts++;
}
fm5(str_contains($next,'gelato-agent-next-moves-change'),'reuse existing Next Moves state');
fm5(str_contains($next,'dismiss:dismissMove'),'shared dismissal on existing controller');
fm5(str_contains($mount,'data-fm-suggestions-loader'),'load from existing mascot, not a second Agent');
fm5(str_contains($companion,"window.GelatoAgentNextMoves?.moves"),'read existing authorized moves');
fm5(str_contains($companion,"gelato-agent-next-moves-change"),'subscribe to authorized signal events');
fm5(str_contains($companion,"gelato-agent-response"),'consume existing tool results');
fm5(str_contains($companion,"String(agent.user.id)") && str_contains($companion,"agent?.user?.id"),'scope dismissal history to signed-in user');
fm5(str_contains($companion,"sessionStorage"),'session-scoped dedup, not persistent surveillance');
fm5(str_contains($companion,"['critical','high']"),'only high importance auto prompts');
fm5(str_contains($companion,"const question="),'review sends explanation request');
fm5(str_contains($companion,"agent.send(question,false)"),'same Agent permission gated chat');
fm5(str_contains($companion,"Do not execute consequential changes unless I explicitly confirm."),'protect tool approval');
fm5(!str_contains($companion,"api/brain-events.php"),'must not independently synthesize proactive events');
fm5(!str_contains($companion,"action:'write'"),'must not bypass Agent tool routing');
fm5(!str_contains($companion,"getUserMedia"),'must not open camera or microphone');
fm5(!str_contains($companion,"speechSynthesis"),'no unrequested spoken interruption');
fm5(str_contains($companion,"textContent=current.summary"),'untrusted summary must be inert text');
fm5(str_contains($companion,"response.status===401||response.status===403"),'manager brain denial fails closed');
fm5(str_contains($companion,"if(!stage||blocked||document.hidden)return"),'no duplicate polling on global pages');
fm5(str_contains($css,".fm-suggestion-panel[hidden]"),'must honor hidden panel');
fm5(str_contains($css,"prefers-reduced-motion"),'must honor reduced motion');
echo "FM-G5 governed suggestion contract: PASS ({$asserts} checks)\n";
