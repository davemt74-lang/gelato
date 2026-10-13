<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function hudAssert(bool $ok,string $message):void {
    if(!$ok){fwrite(STDERR,"FAIL glasses HUD voice: ".$message."\n");exit(1);}
}
$page=(string)file_get_contents($root.'/glasses-simulator.php');
$js=(string)file_get_contents($root.'/assets/js/glasses-web-simulator.js');
$voice=(string)file_get_contents($root.'/assets/js/glasses-simulator-voice.js');
$css=(string)file_get_contents($root.'/assets/css/glasses-simulator-hud-ux.css');
hudAssert(str_contains($page,'assets/css/glasses-simulator-hud-ux.css?v=20261013-voice1'),'HUD stylesheet must be loaded after base simulator CSS');
hudAssert(str_contains($page,'assets/js/glasses-simulator-voice.js?v=20261013-device1'),'voice module must load after simulator');
foreach(['simViewHudOnly','simViewGlasses','simNavNext','simNavPrevious','simNavNextItem','simNavPreviousItem','simNavItemAdded','simNavRepeat','simVoiceToggle','simVoiceReplies','simVoiceHelp','simVoiceFeedback','simNavFeedback','simNavContext'] as $id)
    hudAssert(str_contains($page,'id="'.$id.'"'),'missing accessible control '.$id);
hudAssert(str_contains($css,'.sim-stage-panel{position:sticky')&&str_contains($css,'.glasses-stage{flex:none;min-height:460px'),'stage must not stretch to match long diagnostics');
hudAssert(str_contains($css,'.glasses-stage.frame-none .lens-projection{inset:0!important'),'frameless mode must fill HUD stage');
hudAssert(str_contains($css,'@media(max-width:640px)'),'HUD must support narrow phones');
hudAssert(str_contains($voice,'SpeechRecognition||window.webkitSpeechRecognition'),'feature detection required');
hudAssert(str_contains($voice,"active.lang='en-US'"),'voice locale required');
hudAssert(str_contains($voice,'stopVoice()'),'voice must have explicit stop');
hudAssert(str_contains($voice,'normalizePhrase'),'voice command parser required');
hudAssert(str_contains($voice,'organizeControls()'),'diagnostics should not displace live work');
hudAssert(str_contains($voice,'simAdvancedSettings'),'advanced settings preserved and collapsible');
hudAssert(str_contains($js,'window.GelatoGlassesNavigation={dispatch:simulatorNavigation'),'voice cannot bypass shared navigation dispatch');
hudAssert(str_contains($js,'function simulatorNavigation(action)'),'navigation must be implemented');
hudAssert(str_contains($js,"action==='item_added'"),'ingredient acknowledgement command missing');
hudAssert(str_contains($js,"KDS was not changed."),'item added must disclose read-only behavior');
hudAssert(str_contains($js,"if(state.build)return {ok:false,message:'Ingredient confirmation requires evidence."),'avoid false live build confirmations');
hudAssert(str_contains($js,"state.device?.virtualPreview?'WEB · LIVE'"),'virtual display must not claim Bluetooth hardware');
hudAssert(!str_contains($js,"' · 74°F'"),'fabricated temperature removed');
hudAssert(!str_contains($js,"'87%'"),'fabricated battery removed');
hudAssert(str_contains($js,"window.GelatoGlassesVoiceUI?.refresh?.();"),'voice HUD must update after KDS refresh');
hudAssert(str_contains($js,"localStorage.getItem(FRAME_MODE_KEY):'svg'"),'new installs default to visible glasses frame');
hudAssert(str_contains($js,"'X-CSRF-Token':cfg.csrf"),'simulator API must send CSRF header');
hudAssert(str_contains($js,'csrf_token:cfg.csrf'),'simulator API must send the server-expected csrf_token body field');
hudAssert(!str_contains($js,'{csrf:cfg.csrf,action'),'deprecated CSRF field must not be sent');
hudAssert(str_contains((string)file_get_contents($root.'/api/glasses-simulator.php'),'app_verify_request_csrf($in);'),'server CSRF enforcement retained');
hudAssert(!str_contains($voice,'fetch('),'voice navigation should not POST kitchen changes');
echo "glasses-hud-voice-contract=ok\n";
