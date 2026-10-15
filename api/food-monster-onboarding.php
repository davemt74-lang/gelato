<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-onboarding.php';
$actor=app_require_auth();$pdo=app_pdo();
if(!fmgoal_ready($pdo))app_json_response(['ok'=>false,'message'=>'Participant onboarding is not installed. Run upgrade.php.'],503);
header('Cache-Control: no-store, private');
try{
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $action=(string)($_GET['action']??'list');
  if($action==='list')app_json_response(['ok'=>true,'goals'=>fmgoal_list($pdo,$actor),'roles'=>fmgoal_roles($pdo,$actor),'accounts'=>fmgoal_existing_accounts($pdo,$actor)]);
  $goal=fmgoal_get($pdo,$actor,(string)($_GET['goal']??''));
  if($action==='get')app_json_response(['ok'=>true,'goal'=>fmgoal_view($pdo,$goal)]);
  if($action==='photo'){
   $photoId=(int)($_GET['photo']??0);
   $q=$pdo->prepare('SELECT image_mime,image_bytes,image_nonce,image_encryption FROM fm_participant_photos WHERE id=? AND goal_id=? AND organization_id=? LIMIT 1');
   $q->execute([$photoId,(int)$goal['id'],(int)$actor['organization_id']]);$photo=$q->fetch(PDO::FETCH_ASSOC);
   if(!$photo)app_json_response(['ok'=>false,'message'=>'Photo not found.'],404);
   $plaintext=app_decrypt_secret((string)$photo['image_bytes'],(string)$photo['image_nonce'],(string)$photo['image_encryption']);
   header('Content-Type: '.$photo['image_mime']);header('Content-Length: '.strlen($plaintext));
   header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox');
   echo $plaintext;exit;
  }
  throw new InvalidArgumentException('Unsupported onboarding read action.');
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
 $input=app_json_input();app_verify_request_csrf($input);
 $action=(string)($input['action']??'');
 if($action==='start')app_json_response(['ok'=>true,'goal'=>fmgoal_view($pdo,fmgoal_create($pdo,$actor,(string)($input['trackLabel']??'')))],201);
 $goal=fmgoal_get($pdo,$actor,(string)($input['goal']??''));
 if($action==='name')$view=fmgoal_update($pdo,$actor,$goal,['preferred_name'=>fmgoal_name((string)($input['name']??'')),'phase'=>'type'],'name_recorded');
 elseif($action==='type'){
  $type=(string)($input['type']??'');
  if(!in_array($type,['employee','guest'],true))throw new InvalidArgumentException('Choose employee or guest.');
  $view=fmgoal_update($pdo,$actor,$goal,['participant_type'=>$type,'phase'=>'photos'],'type_recorded');
 }elseif($action==='photo_consent'){
  if(($input['consent']??null)!==true)throw new DomainException('Only an affirmative photo consent can enable storage.');
  $view=fmgoal_update($pdo,$actor,$goal,['photo_consent_at'=>date('Y-m-d H:i:s.u'),'phase'=>'photos'],'photo_consent');
 }elseif($action==='link_existing')$view=fmgoal_link_existing($pdo,$actor,$goal,(int)($input['userId']??0));
 elseif($action==='defer_photos'){
  if(($input['lowLight']??null)!==true)throw new DomainException('Explicit low-light deferral required.');
  $view=fmgoal_update($pdo,$actor,$goal,['photos_deferred'=>1,'phase'=>'voice_enrollment'],'photos_deferred');
 }elseif($action==='photo')$view=fmgoal_photo($pdo,$actor,$goal,(string)($input['image']??''));
 elseif($action==='voice_consent'){
  if(($input['consent']??null)!==true)throw new DomainException('Voice enrollment requires affirmative consent.');
  $view=fmgoal_update($pdo,$actor,$goal,['voice_consent_at'=>date('Y-m-d H:i:s.u'),'phase'=>'voice_enrollment'],'voice_consent');
 }elseif($action==='propose_employee')$view=fmgoal_account_propose($pdo,$actor,$goal,(string)($input['email']??''),(int)($input['roleId']??0));
 elseif($action==='confirm_employee'){app_json_response(['ok'=>true]+fmgoal_account_confirm($pdo,$actor,$goal));}
 elseif($action==='convert_guest')$view=fmgoal_guest_convert($pdo,$actor,$goal,(string)($input['email']??''),($input['consent']??false)===true);
 elseif($action==='close'){
  $status=(string)($input['status']??'')==='completed'?'completed':'cancelled';
  if($status==='completed')$view=fmgoal_finish($pdo,$actor,$goal);
  else{
   $pdo->prepare('DELETE FROM fm_participant_photos WHERE organization_id=? AND goal_id=?')->execute([(int)$actor['organization_id'],(int)$goal['id']]);
   $pdo->prepare("UPDATE fm_participant_goals SET status='cancelled',phase='cancelled',completed_at=NOW(6),updated_at=NOW(6) WHERE id=? AND organization_id=? AND status='in_progress'")
    ->execute([(int)$goal['id'],(int)$actor['organization_id']]);
   app_audit($pdo,(int)$actor['organization_id'],(int)$actor['id'],'agent.participant_goal_cancelled','participant_goal',(string)$goal['public_id']);
   fmph_write($pdo,$actor,'goal_cancelled',fmgoal_get($pdo,$actor,(string)$goal['public_id']));
   $view=fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$goal['public_id']));
  }
 }elseif($action==='remove_photo'){
  $photoId=(int)($input['photo']??0);
  $pdo->prepare('DELETE FROM fm_participant_photos WHERE id=? AND goal_id=? AND organization_id=?')
   ->execute([$photoId,(int)$goal['id'],(int)$actor['organization_id']]);
  if($photoId>0)fmph_write($pdo,$actor,'photo_removed',$goal);
  $view=fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$goal['public_id']));
 }else throw new InvalidArgumentException('Unsupported onboarding goal action.');
 app_json_response(['ok'=>true,'goal'=>$view]);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
 catch(DomainException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],403);}
 catch(Throwable $e){error_log('FM-G8C onboarding failure '.get_class($e).': '.$e->getMessage());app_json_response(['ok'=>false,'message'=>'Participant setup could not be completed.'],500);}
