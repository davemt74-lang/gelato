<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/glasses-simulator.php');
$layout=(string)file_get_contents($root.'/assets/css/glasses-simulator-device-first.css');
$tabs=(string)file_get_contents($root.'/assets/js/glasses-simulator-tabs.js');
$device=(string)file_get_contents($root.'/assets/js/glasses-voice-device.js');
$voice=(string)file_get_contents($root.'/assets/js/glasses-simulator-voice.js');
$assert=static function(bool $ok,string $why):void{if(!$ok){fwrite(STDERR,"FAIL glasses-device-tabs: ".$why."\n");exit(1);}};
$assert(str_contains($page,'id="glassesStage"'),'primary glasses display preserved');
$assert(str_contains($page,'assets/css/glasses-simulator-device-first.css'),'device-first CSS imported');
$assert(str_contains($page,'assets/js/glasses-voice-device.js'),'voice certificate loaded');
$assert(str_contains($page,'assets/js/glasses-simulator-tabs.js'),'tab UI loaded');
$assert(str_contains($page,'admin_control_allowed($user)'),'ADMIN must be permission scoped');
$assert(str_contains($page,'href="admin.php"') && str_contains($page,'>ADMIN</a>'),'ADMIN header shortcut');
$assert(!str_contains($page,'href="kds.php"')&&!str_contains($page,'href="pos.php"'),'POS and KDS header shortcuts removed');
foreach(['overview','voice','camera','runtime','build'] as $name)
    $assert(str_contains($tabs,"$name:'".ucfirst($name)."'"),"tab missing ".$name);
$assert(str_contains($tabs,'aria-controls')&&str_contains($tabs,'aria-selected')&&str_contains($tabs,"e.key==='ArrowRight'"),'accessible tab navigation');
$assert(str_contains($tabs,'move(card,section(group))'),'existing controls moved not cloned');
$assert(str_contains($layout,'flex-wrap:nowrap!important'),'single-row header');
$assert(str_contains($layout,'#deviceSelect{width:128px'),'compact device selector');
$assert(str_contains($layout,'#glassesStage{height:100%!important'),'main device view fills stage');
$assert(str_contains($device,'getUserMedia')&&str_contains($device,'getTracks?.().forEach(t=>t.stop())'),'certification capture must stop all tracks');
$assert(str_contains($device,'probeMicrophone=false'),'no mic on page load');
$assert(str_contains($voice,'recoverVoice')&&str_contains($voice,'scheduleRecovery'),'manual and automatic recovery');
$assert(str_contains($voice,'clearRecovery();releaseRecognition();diag(\'off\')'),'microphone off must cancel recovery and capture');
$assert(!str_contains($device,'localStorage')&&!str_contains($device,'sessionStorage')&&!str_contains($device,'fetch('),'diagnostics must not persist or post voice evidence');
echo "glasses-voice-device-and-tabbed-ui-contract=ok\n";
