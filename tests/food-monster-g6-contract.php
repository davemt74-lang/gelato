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
$suggestions=$read('js/food-monster-suggestions.js');
$version='20261014-fmg6';
$agentVersion='20261026-b8d16a-voice1';
$manualChatVersion='20261023-manual-chat1';
// Stage, workspace and global admin loaders must all fetch the current mascot/chat UI.
$stageAgentVersion=$agentVersion;
$stageMascotVersion='20261026-b8d16a-mascot1';
$stageCssVersion=$manualChatVersion;
check_g6(str_contains($global,'js/food-monster.js?v='.$stageMascotVersion),
    'Current shared mascot loader must request the updated asset');
check_g6(str_contains($actor,'v='.$manualChatVersion),
    'Mascot preserves its current stylesheet cache stamp');
foreach(['js/universal-admin-page-shell.js'=>$universal,'js/workspace-shell-sync.js'=>$workspace] as $path=>$body)
 check_g6(str_contains($body,'v='.$manualChatVersion),'Current shared loader release stamp is missing in '.$path);
check_g6(str_contains($global,'js/global-agent.js?v='.$agentVersion),'Global Agent fallback cache must update');
check_g6(str_contains($global,'js/agent-next-moves.js?v='.$version),'Shared Next Moves dismissal cache must update');
check_g6(str_contains($global,'js/food-monster.js?v='.$stageMascotVersion),'Mascot cache must update on global pages');
check_g6(str_contains($universal,'js/global-add-canvas.js?v='.$manualChatVersion),'Universal admin shell must request new loader');
check_g6(str_contains($workspace,'js/global-add-canvas.js?v='.$manualChatVersion),'Workspace shell must request new loader');
check_g6(str_contains($page,'css/food-monster.css?v='.$stageCssVersion),'Stage CSS must be fresh');
check_g6(str_contains($page,'js/global-agent.js?v='.$stageAgentVersion),'Stage global Agent must be fresh');
check_g6(str_contains($page,'js/food-monster.js?v='.$stageMascotVersion),'Stage mascot must be fresh');
check_g6(str_contains($actor,'document.querySelector(\'link[rel="stylesheet"][href^="css/food-monster.css"]\')'),'Do not insert second stage stylesheet');
check_g6(str_contains($page,'app_require_auth()'),'Stage must require logged-in Gelato user');
check_g6(!str_contains($page,'<aside'),'Stage cannot load a sidebar');
check_g6(str_contains($agent,'if(window.GelatoFoodMonsterLLM?.ask)')
    &&str_contains($agent,'const delegation=result?.data?.delegation;')
    &&!str_contains($agent,'result?.data?.offlineFallback===true'),
    'Model-first routing fails closed when no provider is configured');
check_g6(str_contains($llm,'app_verify_request_csrf'),'LLM endpoint requires CSRF');
check_g6(str_contains($llm,'gaw_thread($pdo,$org,$uid'),'LLM history is user/organization isolated');
check_g6(str_contains($suggestions,'agent.send(question,false)'),'Recommendations require human Review interaction');
check_g6(!str_contains($suggestions,'speechSynthesis'),'No automatic spoken alerts');
check_g6(!str_contains($suggestions,'getUserMedia'),'Proactive system never opens camera');
echo "FM-G6 release-integration & privacy contract: PASS ({$passed} checks)\n";
