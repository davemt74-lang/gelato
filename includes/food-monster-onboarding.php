<?php
declare(strict_types=1);
/**
 * FM-G8C — server-owned, permission-gated participant onboarding goal.
 * A camera track or self-reported name never authenticates a user.
 */
require_once __DIR__.'/customer-crm-core.php';
function fmgoal_owner(array $user):bool {return (int)($user['is_owner_role']??0)===1;}
function fmgoal_ready(PDO $pdo):bool {
 try{
  $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('fm_participant_goals','fm_participant_photos')");
  return (int)$q->fetchColumn()===2;
 }catch(Throwable){return false;}
}
function fmgoal_create(PDO $pdo,array $actor,string $label=''):array {
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 $id='fmg-'.bin2hex(random_bytes(12));
 $label=substr(preg_replace('/[^A-Za-z0-9_-]/','',trim($label))??'',0,48);
 $q=$pdo->prepare("INSERT INTO fm_participant_goals (organization_id,public_id,created_by,tracking_label) VALUES (?,?,?,?)");
 $q->execute([$org,$id,$uid,$label]);
 app_audit($pdo,$org,$uid,'agent.participant_goal_started','participant_goal',$id);
 return fmgoal_get($pdo,$actor,$id);
}
function fmgoal_get(PDO $pdo,array $actor,string $publicId,bool $lock=false):array {
 if(!preg_match('/^fmg-[0-9a-f]{24}$/D',$publicId))throw new InvalidArgumentException('Participant setup was not found.');
 $org=(int)$actor['organization_id'];
 $q=$pdo->prepare('SELECT * FROM fm_participant_goals WHERE organization_id=? AND public_id=? LIMIT 1'.($lock?' FOR UPDATE':''));
 $q->execute([$org,$publicId]);$goal=$q->fetch(PDO::FETCH_ASSOC);
 if(!$goal||((int)$goal['created_by']!==(int)$actor['id']&&(int)($goal['linked_user_id']??0)!==(int)$actor['id']&&!fmgoal_owner($actor)))throw new InvalidArgumentException('Participant setup was not found.');
 return $goal;
}
function fmgoal_view(PDO $pdo,array $goal):array {
 $q=$pdo->prepare('SELECT id FROM fm_participant_photos WHERE organization_id=? AND goal_id=? ORDER BY id DESC LIMIT 8');
 $q->execute([(int)$goal['organization_id'],(int)$goal['id']]);
 return [
  'id'=>(string)$goal['public_id'],'trackLabel'=>(string)$goal['tracking_label'],
  'name'=>(string)$goal['preferred_name'],'type'=>(string)$goal['participant_type'],
  'phase'=>(string)$goal['phase'],'status'=>(string)$goal['status'],
  'employeeUserId'=>$goal['linked_user_id']?(int)$goal['linked_user_id']:null,
  'crmCustomerId'=>$goal['crm_customer_public_id']?:null,
  'email'=>(string)($goal['email']??''),
  'proposedRoleId'=>$goal['proposed_role_id']?(int)$goal['proposed_role_id']:null,
  'photoConsent'=>$goal['photo_consent_at']!==null,
  'voiceConsent'=>$goal['voice_consent_at']!==null,
  'guestCrmConsent'=>$goal['guest_crm_consent_at']!==null,
  'photos'=>array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),
  'voiceVerified'=>fmgoal_voice_enrolled($pdo,(int)$goal['organization_id'],(int)($goal['linked_user_id']??0)),
  'updatedAt'=>(string)$goal['updated_at']
 ];
}
function fmgoal_voice_enrolled(PDO $pdo,int $org,int $uid):bool {
 if($uid<1)return false;
 try{
  $q=$pdo->prepare("SELECT COUNT(*) FROM voice_identity_profiles WHERE organization_id=? AND user_id=? AND status='active'");
  $q->execute([$org,$uid]);return (int)$q->fetchColumn()>0;
 }catch(Throwable){return false;}
}
function fmgoal_list(PDO $pdo,array $actor):array {
 $q=$pdo->prepare("SELECT public_id FROM fm_participant_goals WHERE organization_id=? AND (created_by=? OR linked_user_id=?) ORDER BY updated_at DESC LIMIT 25");
 $q->execute([(int)$actor['organization_id'],(int)$actor['id'],(int)$actor['id']]);
 $goals=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$goals[]=fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$id));
 return $goals;
}
function fmgoal_name(string $name):string {
 $name=trim(preg_replace('/[\x00-\x1f\x7f<>]+/u',' ',trim($name))??'');
 if(mb_strlen($name,'UTF-8')<2||mb_strlen($name,'UTF-8')>120)throw new InvalidArgumentException('Tell me the participant name (2–120 characters).');
 return $name;
}
function fmgoal_update(PDO $pdo,array $actor,array $goal,array $fields,string $event):array {
 if((string)$goal['status']!=='in_progress')throw new DomainException('This onboarding goal is no longer active.');
 $allowed=['preferred_name','participant_type','phase','photo_consent_at','voice_consent_at','guest_crm_consent_at','email','proposed_role_id','linked_user_id','crm_customer_public_id'];
 foreach(array_keys($fields) as $key)if(!in_array($key,$allowed,true))throw new InvalidArgumentException('Unsupported setup change.');
 if(!$fields)throw new InvalidArgumentException('No change requested.');
 $columns=[];$values=[];foreach($fields as $key=>$value){$columns[]=$key.'=?';$values[]=$value;}
 $values[]=(int)$actor['organization_id'];$values[]=(int)$goal['id'];
 $pdo->prepare('UPDATE fm_participant_goals SET '.implode(',',$columns).',updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute($values);
 app_audit($pdo,(int)$actor['organization_id'],(int)$actor['id'],'agent.participant_'.$event,'participant_goal',(string)$goal['public_id']);
 return fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$goal['public_id']));
}
function fmgoal_photo(PDO $pdo,array $actor,array $goal,string $dataUri):array {
 if($goal['photo_consent_at']===null)throw new DomainException('Confirm the participant consented to photo storage first.');
 if((string)$goal['status']!=='in_progress')throw new DomainException('Cannot save photos to a completed setup.');
 if(!preg_match('#^data:image/(webp|jpeg);base64,([A-Za-z0-9+/=]+)$#D',$dataUri,$match))throw new InvalidArgumentException('Capture a JPEG or WebP photo.');
 $bytes=base64_decode($match[2],true);
 if(!is_string($bytes)||strlen($bytes)<250||strlen($bytes)>140000)throw new InvalidArgumentException('Photo must be between 250 bytes and 140 KB.');
 $mime='image/'.$match[1];
 if(!function_exists('getimagesizefromstring')||!(($info=@getimagesizefromstring($bytes))&&($info['mime']??'')===$mime))throw new InvalidArgumentException('Photo encoding is invalid.');
 if($info[0]>1200||$info[1]>1200)throw new InvalidArgumentException('Photo dimensions must be 1200px or smaller.');
 $org=(int)$actor['organization_id'];
 $q=$pdo->prepare('SELECT COUNT(*) FROM fm_participant_photos WHERE organization_id=? AND goal_id=?');
 $q->execute([$org,(int)$goal['id']]);if((int)$q->fetchColumn()>=8)throw new DomainException('Photo gallery is full (eight images).');
 $encrypted=app_encrypt_secret($bytes);
 $q=$pdo->prepare('INSERT INTO fm_participant_photos (organization_id,goal_id,image_mime,image_bytes,image_nonce,image_encryption,created_by) VALUES (?,?,?,?,?,?,?)');
 $q->bindValue(1,$org,PDO::PARAM_INT);$q->bindValue(2,(int)$goal['id'],PDO::PARAM_INT);
 $q->bindValue(3,$mime);$q->bindValue(4,$encrypted['ciphertext'],PDO::PARAM_LOB);$q->bindValue(5,$encrypted['nonce']);$q->bindValue(6,$encrypted['method']);$q->bindValue(7,(int)$actor['id'],PDO::PARAM_INT);$q->execute();
 app_audit($pdo,$org,(int)$actor['id'],'agent.participant_photo_saved','participant_goal',(string)$goal['public_id']);
 return fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$goal['public_id']));
}
function fmgoal_roles(PDO $pdo,array $actor):array {
 if(!fmgoal_owner($actor))return [];
 $q=$pdo->prepare("SELECT id,name,slug FROM roles WHERE organization_id=? AND is_owner_role=0 AND is_assignable=1 AND slug NOT IN ('customer','wholesale_customer','super_admin') ORDER BY name");
 $q->execute([(int)$actor['organization_id']]);
 return array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['name']],$q->fetchAll(PDO::FETCH_ASSOC));
}
function fmgoal_account_propose(PDO $pdo,array $actor,array $goal,string $email,int $roleId):array {
 if(!fmgoal_owner($actor))throw new DomainException('Protected super-admin approval is required.');
 if((string)$goal['participant_type']!=='employee'||$goal['preferred_name']==='')throw new DomainException('Confirm the employee name and type first.');
 if((string)$goal['status']!=='in_progress'||$goal['linked_user_id'])throw new DomainException('The employee account is already linked or the goal is closed.');
 $email=mb_strtolower(trim($email),'UTF-8');
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>254)throw new InvalidArgumentException('A valid employee email is required.');
 if(!in_array($roleId,array_column(fmgoal_roles($pdo,$actor),'id'),true))throw new DomainException('Choose an assignable employee role; super-admin roles cannot be assigned.');
 $q=$pdo->prepare('SELECT id FROM users WHERE email=? AND archived_at IS NULL LIMIT 1');
 $q->execute([$email]);if($q->fetchColumn())throw new DomainException('An account with that email exists. Link an existing staff record instead.');
 return fmgoal_update($pdo,$actor,$goal,['email'=>$email,'proposed_role_id'=>$roleId,'phase'=>'account_review'],'account_proposed');
}
function fmgoal_account_confirm(PDO $pdo,array $actor,array $goal):array {
 if(!fmgoal_owner($actor))throw new DomainException('Protected super-admin approval is required.');
 $org=(int)$actor['organization_id'];$uid=(int)$actor['id'];
 $pdo->beginTransaction();
 try{
  $goal=fmgoal_get($pdo,$actor,(string)$goal['public_id'],true);
  if((string)$goal['status']!=='in_progress'||(string)$goal['phase']!=='account_review'||$goal['linked_user_id'])throw new DomainException('No pending account proposal.');
  $roleId=(int)$goal['proposed_role_id'];
  if(!in_array($roleId,array_column(fmgoal_roles($pdo,$actor),'id'),true))throw new DomainException('The selected role is no longer assignable.');
  $email=(string)$goal['email'];
  $q=$pdo->prepare('SELECT id FROM users WHERE email=? AND archived_at IS NULL LIMIT 1 FOR UPDATE');$q->execute([$email]);
  if($q->fetchColumn())throw new DomainException('Another account now uses this email.');
  $name=fmgoal_name((string)$goal['preferred_name']);
  $parts=preg_split('/\s+/u',$name,2);$first=$parts[0];$last=$parts[1]??'';
  if(mb_strlen($first,'UTF-8')>100||mb_strlen($last,'UTF-8')>100)throw new InvalidArgumentException('Employee first and last names must be 100 characters or fewer.');
  $hash=app_password_hash(bin2hex(random_bytes(40))); // Unknown, unusable initial password.
  $pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$email,$hash,$first,$last,$name]);
  $newUid=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,job_title,status) VALUES (?,?,'Employee','active')")->execute([$org,$newUid]);
  $membership=(int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO user_roles (membership_id,role_id,location_id,assigned_by) VALUES (?,?,NULL,?)')->execute([$membership,$roleId,$uid]);
  $pdo->prepare("UPDATE fm_participant_goals SET linked_user_id=?,approved_by=?,approved_at=NOW(6),phase='password_pin_setup',updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([$newUid,$uid,(int)$goal['id'],$org]);
  $token=bin2hex(random_bytes(32));
  $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(6),INTERVAL 60 MINUTE))')->execute([$newUid,hash('sha256',$token)]);
  app_audit($pdo,$org,$uid,'agent.participant_employee_created','user',(string)$newUid,null,['roleId'=>$roleId,'goalId'=>(string)$goal['public_id']]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 $sent=app_send_password_reset_email($email,$name,app_url('reset-password.php?token='.rawurlencode($token)));
 if(!$sent)error_log('Food Monster: password setup email delivery failed for user ID '.$newUid);
 $view=fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$goal['public_id']));
 return ['goal'=>$view,'emailSent'=>$sent,'message'=>$sent?'Employee created. The employee has been emailed a secure password/PIN setup link.':'Employee created, but setup email could not be sent. Ask them to use Forgot Password to set credentials.'];
}
function fmgoal_finish(PDO $pdo,array $actor,array $goal):array {
 if((string)$goal['status']!=='in_progress'||trim((string)$goal['preferred_name'])===''||!in_array($goal['participant_type'],['employee','guest'],true))
  throw new DomainException('Confirm participant name and type before completion.');
 if($goal['participant_type']==='employee'){
  if(!$goal['linked_user_id'])throw new DomainException('Employee account must be linked before completing onboarding.');
  $photos=fmgoal_view($pdo,$goal)['photos'];
  if(count($photos)<3)throw new DomainException('Employee photo gallery needs at least three consented photos.');
  if(!fmgoal_voice_enrolled($pdo,(int)$actor['organization_id'],(int)$goal['linked_user_id']))
   throw new DomainException('Employee voice profile is not yet enrolled. Have them enroll through their authenticated Time Clock account.');
 }
 $pdo->prepare("UPDATE fm_participant_goals SET status='completed',phase='completed',completed_at=NOW(6) WHERE organization_id=? AND id=? AND status='in_progress'")
  ->execute([(int)$actor['organization_id'],(int)$goal['id']]);
 app_audit($pdo,(int)$actor['organization_id'],(int)$actor['id'],'agent.participant_goal_completed','participant_goal',(string)$goal['public_id']);
 return fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$goal['public_id']));
}
function fmgoal_guest_convert(PDO $pdo,array $actor,array $goal,string $email,bool $explicitConsent):array {
 if(!app_has_permission('crm.manage',$actor))throw new DomainException('Customer CRM management permission required.');
 if(!$explicitConsent)throw new DomainException('The guest must explicitly agree to creation of a CRM contact.');
 if((string)$goal['participant_type']!=='guest'||$goal['preferred_name']==='')throw new DomainException('Confirm guest name and participant type.');
 if($goal['crm_customer_public_id'])throw new DomainException('Guest was already linked to CRM.');
 if(!crm_ready($pdo))throw new DomainException('CRM is unavailable. Run upgrade.php.');
 $contact=crm_customer_save($pdo,(int)$actor['organization_id'],['displayName'=>(string)$goal['preferred_name'],'email'=>$email],(int)$actor['id'],'manual');
 $pdo->prepare("UPDATE fm_participant_goals SET guest_crm_consent_at=NOW(6),crm_customer_public_id=?,phase='complete',status='completed',completed_at=NOW(6) WHERE id=? AND organization_id=?")->execute([(string)$contact['public_id'],(int)$goal['id'],(int)$actor['organization_id']]);
 app_audit($pdo,(int)$actor['organization_id'],(int)$actor['id'],'agent.guest_crm_conversion','crm_customer',(string)$contact['public_id'],null,['goal'=>(string)$goal['public_id'],'marketingConsent'=>'not_recorded']);
 return fmgoal_view($pdo,fmgoal_get($pdo,$actor,(string)$goal['public_id']));
}
