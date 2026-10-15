<?php
declare(strict_types=1);
$root = dirname(__DIR__);
function fm_check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FM-G1 FAIL: ".$message."\n"); exit(1); }
}
$read = static fn(string $file): string => (string)file_get_contents($root.'/'.$file);
foreach(['food-monster.php','css/food-monster.css','js/food-monster.js','assets/food-monster/chef-mascot.svg','js/global-add-canvas.js'] as $path) {
    fm_check(is_file($root.'/'.$path), 'Missing required file '.$path);
}
$page=$read('food-monster.php');
$js=$read('js/food-monster.js');
$css=$read('css/food-monster.css');
$loader=$read('js/global-add-canvas.js');
$svg=$read('assets/food-monster/chef-mascot.svg');
fm_check(str_contains($page,'app_require_auth()'),'Dedicated page must require existing Gelato sign-in');
fm_check(str_contains($page,'js/global-agent.js'),'Dedicated page must use Gelato global agent');
fm_check(str_contains($page,'js/food-monster.js'),'Dedicated page must load mascot companion');
fm_check(!str_contains($page,'<aside'),'Dedicated page must not have a sidebar');
fm_check(!str_contains($page,'server/config.php'),'Dedicated page must not import standalone auth');
fm_check(str_contains($js,'if(window.GelatoFoodMonster)return'),'Single instance guard missing');
fm_check(str_contains($js,'gelato-agent-response'),'Mascot must react to current Agent response events');
fm_check(str_contains($js,'gelato-agent-listening-change'),'Mascot must react to current listening events');
fm_check(str_contains($js,'gelato-agent-status'),'Mascot must react to current Agent state');
fm_check(str_contains($js,'data-fm-pupil'),'Pupil tracking layer must exist');
fm_check(str_contains($js,'pointermove'),'Pointer follow and drag must work');
fm_check(str_contains($js,'localStorage'),'Mascot layout must persist across pages');
fm_check(str_contains($js,'fm-minimized'),'Mascot must be minimizable');
fm_check(str_contains($loader,'data-food-monster-loader'),'Shared authenticated shell must load the mascot');
fm_check(str_contains($loader,"if (page !== 'food-monster.php'"),'Do not duplicate stage page mascot');
fm_check(str_contains($css,'#gelato-agent-bar'),'Stage must use the Gelato sticky chat footer');
fm_check(str_contains($css,'prefers-reduced-motion'),'Honor reduced motion');
fm_check(str_contains($svg,'data-fm-pupil'),'SVG pupil should be independently positioned');
fm_check(!str_contains($js,'getUserMedia('),'G1 must not open competing camera sessions');
fm_check(!str_contains($js,'SpeechRecognition('),'G1 must not open competing listening sessions');
fm_check(!str_contains($js,'/v1/messages'),'G1 must not create a second LLM backend');
fm_check(!str_contains($js,'api_key'),'G1 must not handle provider keys');
fm_check(!str_contains($js,'api/agent-workspace.php'),'G1 must never bypass existing agent session endpoints');
echo "FM-G1 stage/shared-agent integration contract: PASS (23 checks)\n";
