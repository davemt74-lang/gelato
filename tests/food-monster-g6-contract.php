<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
function check_g6(bool $ok,string $label):void{
 global $passed;if(!$ok){fwrite(STDERR,"FM-G6 FAIL: ".$label."\n");exit(1);}$passed++;
}
$passed=0;
$global=$read('js/global-add-canvas.js');
$universal=$read('js/universal-admin-page-shell.js');
$workspace=$read('js/workspace-shell-sync.js');
$page=$read('food-monster.php');
$actor=$read('js/food-monster.js');
$agent=$read('js/global-agent.js');
$llm=$read('api/food-monster-llm.php');
$camera=$read('js/food-monster-camera.js');
$suggestions=$read('js/food-monster-suggestions.js');
$version='20261014-fmg6';
$agentVersion='20261015-fmg7c';
$mascotVersion='20261015-fmg7b1';
foreach([
 'js/global-add-canvas.js'=>$global,'js/universal-admin-page-shell.js'=>$universal,
 'js/workspace-shell-sync.js'=>$workspace,'food-monster.php'=>$page,'js/food-monster.js'=>$actor
] as $path=>$body)check_g6(str_contains($body,'v='.$mascotVersion),'FM-G7A release stamp is missing in '.$path);
check_g6(str_contains($global,'js/global-agent.js?v='.$agentVersion),'Global Agent fallback cache must update');
check_g6(str_contains($global,'js/agent-next-moves.js?v='.$version),'Shared Next Moves dismissal cache must update');
check_g6(str_contains($global,'js/food-monster.js?v='.$mascotVersion),'Mascot cache must update on global pages');
check_g6(str_contains($universal,'js/global-add-canvas.js?v='.$mascotVersion),'Universal admin shell must request new loader');
check_g6(str_contains($workspace,'js/global-add-canvas.js?v='.$mascotVersion),'Workspace shell must request new loader');
check_g6(str_contains($page,'css/food-monster.css?v='.$mascotVersion),'Stage CSS must be fresh');
check_g6(str_contains($page,'js/global-agent.js?v='.$agentVersion),'Stage global Agent must be fresh');
check_g6(str_contains($page,'js/food-monster.js?v='.$mascotVersion),'Stage mascot must be fresh');
check_g6(str_contains($actor,'document.querySelector(\'link[rel="stylesheet"][href^="css/food-monster.css"]\')'),'Do not insert second stage stylesheet');
check_g6(str_contains($page,'app_require_auth()'),'Stage must require logged-in Gelato user');
check_g6(!str_contains($page,'<aside'),'Stage cannot load a sidebar');
check_g6(str_contains($agent,'if (route.route)')&&str_contains($agent,'else if (window.GelatoFoodMonsterLLM?.ask)'),'Existing tool route precedes model');
check_g6(str_contains($llm,'app_verify_request_csrf'),'LLM endpoint requires CSRF');
check_g6(str_contains($llm,'gaw_thread($pdo,$org,$uid'),'LLM history is user/organization isolated');
check_g6(str_contains($camera,'window.isSecureContext'),'Camera requires secure context');
check_g6(str_contains($camera,'audio:false'),'Camera never opens competing microphone');
check_g6(str_contains($camera,'sessionUserMatch===true'),'Voice-greeting identity is not account auth');
check_g6(str_contains($camera,'crypto.subtle.encrypt'),'Biometric template encrypted');
check_g6(str_contains($suggestions,'agent.send(question,false)'),'Recommendations require human Review interaction');
check_g6(!str_contains($suggestions,'speechSynthesis'),'No automatic spoken alerts');
check_g6(!str_contains($suggestions,'getUserMedia'),'Proactive system never opens camera');
echo "FM-G6 release-integration & privacy contract: PASS ({$passed} checks)\n";
