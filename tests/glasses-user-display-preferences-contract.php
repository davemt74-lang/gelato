<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$api=file_get_contents($root.'/api/glasses-user-preferences.php');
$sql=file_get_contents($root.'/database/20261213_glasses_user_display_preferences.sql');
$page=file_get_contents($root.'/glasses-simulator.php');
$js=file_get_contents($root.'/assets/js/glasses-user-display-preferences.js');
$main=file_get_contents($root.'/assets/js/glasses-web-simulator.js');
$assert=static function(bool $ok,string $message):void{
 if(!$ok){fwrite(STDERR,"FAIL HUD user preferences: $message\n");exit(1);}
};
$assert(str_contains($sql,'PRIMARY KEY (organization_id,user_id)'),'per-user and per-organization storage');
$assert(str_contains($sql,'FOREIGN KEY (user_id) REFERENCES users(id)'),'user ownership');
$assert(str_contains($api,'app_require_auth()'),'authenticated endpoint');
$assert(str_contains($api,"app_has_permission('glasses.view',\$user)"),'glasses view permission');
$assert(str_contains($api,'app_verify_request_csrf($input)'),'CSRF required for writes');
$assert(str_contains($api,"WHERE organization_id=? AND user_id=?"),'GET tenant/user scoped');
$assert(str_contains($api,"(organization_id,user_id,settings_json)"),'write tenant/user scoped');
$assert(str_contains($api,"'Cache-Control: private, no-store, max-age=0'"),'private preferences uncached');
$assert(str_contains($api,'$keys=['),'allowlisted UI only');
$assert(str_contains($api,"'calibration'"),'lens tuning persisted');
$assert(str_contains($api,"'activeCameraTab'"),'camera view persisted');
$assert(!str_contains($api,'audioBlob')&&!str_contains($api,'speechTranscript'),'no speech storage');
$assert(str_contains($page,'glasses-user-display-preferences.js'),'user preference loader attached');
$assert(str_contains($js,'preferences-ready'),'wait for simulator initialization');
$assert(str_contains($js,'credentials:'),"same-origin authenticated requests");
$assert(str_contains($js,'save()'),'save action');
$assert(str_contains($main,"':u'+String(cfg.userId||0)"),'browser fallback scoped per user');
$assert(!str_contains($js,'startVoice()')&&!str_contains($js,'startCamera()'),'restore never activates capture automatically');
echo "glasses-user-display-preferences-contract=ok\n";
