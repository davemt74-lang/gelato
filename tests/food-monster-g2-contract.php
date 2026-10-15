<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$js=(string)file_get_contents($root.'/js/food-monster.js');
$css=(string)file_get_contents($root.'/css/food-monster.css');
function fmg2check(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FM-G2 FAIL: ".$message."\n");exit(1);}}
foreach(['gelato-voice-turn','gelato-agent-listening-change','gelato-agent-status','gelato-agent-response','--fm-mouth-open','performanceState.voiceSpeaking','performanceState.thinking','performanceState.listening','kind===\'ended\'','kind===\'interrupted\'','owner===\'global-agent\''] as $symbol)fmg2check(str_contains($js,$symbol),"Missing expression input: ".$symbol);
foreach(['.fm-listening','.fm-happy','.fm-alert','.fm-sleepy','prefers-reduced-motion'] as $symbol)fmg2check(str_contains($css,$symbol),"Missing expressive style: ".$symbol);
fmg2check(!str_contains($js,'getUserMedia('),'Do not create another camera session');
fmg2check(!str_contains($js,'new SpeechSynthesisUtterance'),'Do not create another TTS engine');
fmg2check(!str_contains($js,'api_key'),'No browser API secrets');
echo "FM-G2 expression lifecycle contract: PASS (19 checks)\n";
