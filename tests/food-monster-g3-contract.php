<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function gate(bool $check,string $message): void {
 if(!$check){fwrite(STDERR,"FM-G3 FAIL: ".$message."\n");exit(1);}
}
$camera=(string)file_get_contents($root.'/js/food-monster-camera.js');
$actor=(string)file_get_contents($root.'/js/food-monster.js');
$style=(string)file_get_contents($root.'/css/food-monster.css');
gate(strlen($camera)>5000,'Camera module not installed');
gate(str_contains($actor,'data-fm-camera-loader'),'Only the mounted global mascot can initialize camera');
gate(str_contains($actor,'setFaceTarget'),'Camera should drive gaze and body movement');
gate(str_contains($actor,'cameraManualUntil'),'Manual dragging must suspend camera follow');
gate(str_contains($camera,"window.addEventListener('gelato-agent-ready'"),'Wait for authorized account bootstrap');
gate(str_contains($camera,'getUserMedia'),'Webcam must require explicit browser consent');
gate(str_contains($camera,'audio:false'),'Webcam cannot open a parallel microphone');
gate(str_contains($camera,'window.isSecureContext'),'HTTPS/localhost camera requirement');
gate(str_contains($camera,'setInterval(scan,360)'),'Bound face-model inference frequency');
gate(str_contains($camera,'faces.length!==1'),'Fail closed recognition when multiple people appear');
gate(str_contains($camera,"withFaceDescriptor()"),'Face descriptor matching is real, not box-based pseudo-recognition');
gate(str_contains($camera,'samples.length')===false,'Enrollment must not use arbitrary sample count');
gate(str_contains($camera,'for(let i=0;i<3;i++)'),'Three biometric enrollment samples required');
gate(str_contains($camera,"S.matchCount>=2"),'Stable matching required before greeting');
gate(str_contains($camera,"sessionUserMatch===true"),'Voice recognition cannot adopt another account');
gate(str_contains($camera,'Number(value.userId)===Number(ACCOUNT)'),'Verified speaker must equal session user');
gate(str_contains($camera,'crypto.subtle.encrypt'),'Encrypted descriptor storage only');
gate(str_contains($camera,"iterations:240000"),'PBKDF2 key stretching');
gate(str_contains($camera,"ACCOUNT"),'Account-scoped persisted biometric template');
gate(str_contains($camera,'localStorage.removeItem(TEMPLATE)'),'Biometric data can be deleted');
gate(str_contains($camera,"document.addEventListener('visibilitychange'"),'Pause camera when tab hidden');
gate(str_contains($camera,"window.addEventListener('pagehide',stop)"),'Stop physical camera on page exit');
gate(!str_contains($camera,"fetch('api/"),'Camera module cannot bypass Gelato Agent authorization');
gate(!str_contains($camera,"api_key"),'No API key in camera module');
gate(!str_contains($camera,"autoStart"),'No unattended camera start');
gate(str_contains($style,'#fm-camera-dialog'),'Camera controls must be on demand');
echo "FM-G3 camera/privacy architecture checks passed (26 gates)\n";
