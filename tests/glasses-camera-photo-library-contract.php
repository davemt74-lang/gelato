<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$api=file_get_contents($root.'/api/glasses-camera-photo-library.php');
$server=file_get_contents($root.'/includes/glasses-camera-photo-library.php');
$sql=file_get_contents($root.'/database/20261209_section258_camera_photo_library.sql');
$assert=static function(bool $ok,string $message):void{
    if(!$ok){fwrite(STDERR,"FAIL camera photo library: $message\n");exit(1);}
};
$assert(str_contains($sql,'CREATE TABLE glasses_camera_photo_library'),'schema is versioned');
$assert(str_contains($sql,'UNIQUE KEY uq_glasses_camera_media'),'media cannot be linked twice');
$assert(str_contains($sql,'media_id BIGINT UNSIGNED NOT NULL'),'existing Vision Lab is the photo store');
$assert(str_contains($api,'app_require_auth()'),'API authenticates');
$assert(str_contains($api,'app_verify_request_csrf($in)'),'POST requires CSRF');
$assert(str_contains($api,"'Cache-Control: private, no-store, max-age=0'"),'private gallery not cached');
$assert(str_contains($server,'glasses_camera_location_authorize($pdo,$user,$locationId,true)'),'location and glasses.manage enforced');
$assert(str_contains($server,"WHERE organization_id=? AND id=? AND is_active=1"),'reference must use active menu item');
$assert(str_contains($server,"WHERE organization_id=? AND public_id=? LIMIT 1"),'media must be in same organization');
$assert(str_contains($server,"'training_media_opt_in'"),'media consent required');
$assert(str_contains($server,'$isReference&&$menuId===null'),'reference requires menu item');
$assert(str_contains($server,"'isReference'"),'reference status is explicit');
$assert(str_contains($server,"action==='hide'"),'hide does not destroy private vision training source');
$assert(str_contains($server,'UPDATE glasses_camera_photo_library SET'),'review is update scoped to gallery');
$assert(!str_contains($server,"INSERT INTO glasses_vision_training_media"),'do not duplicate image storage');
$assert(!str_contains($server,'sms_')&&!str_contains($server,'send_notification('),'no unapproved customer delivery');
echo "section258-camera-photo-privacy-and-scope=ok\n";
