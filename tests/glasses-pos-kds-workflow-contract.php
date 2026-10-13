<?php
declare(strict_types=1);
function testGlassesWorkflow(bool $ok,string $message): void {
    if (!$ok) { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
}
$root=dirname(__DIR__);
$lab=(string)file_get_contents($root.'/glasses-vision-lab.php');
$testPage=(string)file_get_contents($root.'/glasses-pos-kds-test.php');
$css=(string)file_get_contents($root.'/assets/css/glasses-pos-kds-test.css');
$js=(string)file_get_contents($root.'/assets/js/glasses-pos-kds-test.js');
$visionCss=(string)file_get_contents($root.'/assets/css/glasses-vision-lab.css');
$sim=(string)file_get_contents($root.'/glasses-simulator.php');
$simApi=(string)file_get_contents($root.'/api/glasses-simulator.php');
$simServer=(string)file_get_contents($root.'/includes/glasses-simulator.php');
foreach (['glasses-simulator.php','glasses-pos-kds-test.php','pos.php','kds.php'] as $href) {
    testGlassesWorkflow(is_file($root.'/'.$href),'missing route '.$href);
}
testGlassesWorkflow(str_contains($lab,'id="vlOpenGlassesSimulator"') && str_contains($lab,'href="glasses-simulator.php"'),'Vision Lab simulator header link missing');
testGlassesWorkflow(str_contains($lab,'id="vlOpenPosKdsTest"') && str_contains($lab,'href="glasses-pos-kds-test.php"'),'Vision Lab side-by-side launch missing');
testGlassesWorkflow(str_contains($lab,'id="vlOrderGlassesTest"'),'Vision Lab Mock versus Live test cards missing');
testGlassesWorkflow(str_contains($lab,'This demo does not write POS, KDS, or build data'),'Mock isolation not explained');
testGlassesWorkflow(str_contains($lab,'does not create tickets or advance build/KDS state automatically'),'Live safety boundary not explained');
testGlassesWorkflow(str_contains($testPage,'app_require_auth()') && str_contains($testPage,"app_has_permission('glasses.view'"),'test workspace requires AR glasses permission');
testGlassesWorkflow(str_contains($testPage,"app_has_permission('pos.use'") && str_contains($testPage,"app_has_permission('kds.view'"),'embedded POS and KDS permissions required');
testGlassesWorkflow(substr_count($testPage,'<iframe ' )===3,'POS KDS and Glasses must each have one framed runtime');
foreach (['pos.php','kds.php','glasses-simulator.php'] as $route) {
    testGlassesWorkflow(str_contains($testPage,'src="'.$route.'"'),'missing live iframe source '.$route);
    testGlassesWorkflow(str_contains($testPage,'href="'.$route.'"'),'missing standalone fallback '.$route);
}
testGlassesWorkflow(str_contains($testPage,'Live Gelato') && str_contains($testPage,'Mock'),'mode guidance missing');
testGlassesWorkflow(str_contains($testPage,'allow="camera"'),'glasses frame must allow camera preview');
testGlassesWorkflow(str_contains($testPage,'rel="noopener"'),'full page links require noopener');
testGlassesWorkflow(str_contains($testPage,'assets/js/glasses-pos-kds-test.js?v=20261013-test1'),'test workspace script version missing');
testGlassesWorkflow(str_contains($testPage,'assets/css/glasses-pos-kds-test.css?v=20261013-test1'),'test workspace style version missing');
testGlassesWorkflow(str_contains($js,"new Set(['all', 'pos', 'kds', 'glasses'])"),'three panel focus plus all missing');
testGlassesWorkflow(str_contains($js,"setAttribute('aria-pressed'"),'tab accessibility missing');
testGlassesWorkflow(str_contains($js,'compact.addEventListener'),'responsive panel updates missing');
testGlassesWorkflow(!str_contains($js,'fetch('),'test board must not silently modify or request kitchen data');
testGlassesWorkflow(str_contains($css,'grid-template-columns:repeat(3,minmax(0,1fr))'),'side-by-side layout missing');
testGlassesWorkflow(str_contains($css,'@media(max-width:1120px)'),'responsive one-panel mode missing');
testGlassesWorkflow(str_contains($visionCss,'.vl-workflow-modes'),'Vision Lab cards lack responsive CSS');
testGlassesWorkflow(str_contains($sim,'<option value="mock">Mock</option>') && str_contains($sim,'<option value="live">Live Gelato</option>'),'simulator mode switch missing');
testGlassesWorkflow(str_contains($simApi,"'action'") || str_contains($simApi,"'work'"),'existing simulator API missing');
testGlassesWorkflow(str_contains($simServer,'glasses_current_work('),'live simulator no longer reads real KDS work');
echo "glasses-pos-kds-workflow=ok\n";
