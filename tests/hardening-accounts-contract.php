<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

// Every invitation created by this suite uses a captured sendmail transport.
if(!in_array($argv[1]??'',['run','accept-worker'],true)) {
    $directory=sys_get_temp_dir().'/gelato-accounts-contract-'.bin2hex(random_bytes(8));
    if(!mkdir($directory,0700))throw new RuntimeException('Could not create account test mail directory.');
    $environment=getenv();$environment['GELATO_ACCOUNTS_MAIL_CAPTURE']=$directory.'/mail.txt';
    $transport=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/hardening-accounts-fixture.php').' mail';
    try {
        $process=proc_open([PHP_BINARY,'-d','sendmail_path='.$transport,__FILE__,'run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
        if(!is_resource($process))throw new RuntimeException('Could not start captured-mail account suite.');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        fwrite(STDOUT,$output);if($errors!=='')fwrite(STDERR,$errors);
    } finally { foreach(glob($directory.'/*')?:[] as $file)@unlink($file);@rmdir($directory); }
    exit($code??1);
}
require __DIR__.'/hardening-accounts-fixture.php';
if(($argv[1]??'')==='accept-worker') {
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if(!accounts_invitation_preview(app_pdo(),$input['token']))throw new RuntimeException('Worker invitation must start valid.');
    fwrite(STDOUT,"ready\n");fflush(STDOUT);
    try { accounts_accept_invitation(app_pdo(),$input['token'],$input['password']);echo json_encode(['won'=>true]); }
    catch(InvalidArgumentException){echo json_encode(['won'=>false]);}
    exit;
}

$checks=0;
function ac_assert(bool $condition,string $message):void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function ac_one(PDO $pdo,string $sql,array $arguments=[]):mixed {$q=$pdo->prepare($sql);$q->execute($arguments);return $q->fetchColumn();}
function ac_row(PDO $pdo,string $sql,array $arguments=[]):array {$q=$pdo->prepare($sql);$q->execute($arguments);return $q->fetch()?:[];}
function ac_reject(callable $action,string $message):void {try{$action();}catch(InvalidArgumentException|DomainException){ac_assert(true,$message);return;}throw new RuntimeException($message);}
function ac_profile(PDO $pdo,int $org,int $uid,array $changes=[]):array {$row=accounts_target($pdo,$org,$uid);return array_replace(['id'=>'db-user-'.$uid,'firstName'=>$row['first_name'],'lastName'=>$row['last_name'],'displayName'=>$row['display_name'],'phone'=>$row['phone']??'','position'=>$row['job_title']?:'Employee'],$changes);}
function ac_mail_token():string {$mail=(string)file_get_contents((string)getenv('GELATO_ACCOUNTS_MAIL_CAPTURE'));if(!preg_match('/account-invitation\.php\?token=([a-f0-9]{64})/',$mail,$match))throw new RuntimeException('Captured invitation must contain one recipient link.');return $match[1];}
function ac_invite_input(string $email,int $role,array $changes=[]):array{return array_replace(['email'=>$email,'firstName'=>'Invited','lastName'=>'Recipient','displayName'=>'Submitted local profile','phone'=>'6025550188','position'=>'Kitchen','roleId'=>'db-role-'.$role],$changes);}

$pdo=app_pdo();app_boot_session();$fixture=accounts_fixture_seed($pdo);$org=(int)$fixture['organizations']['home'];$other=(int)$fixture['organizations']['other'];
$actor=static fn(string $kind,int $organization=0):array=>['id'=>(int)$fixture['users'][$kind]['id'],'organization_id'=>$organization?:$org];
$owner=$actor('owner');$manager=$actor('manager');$employeeId=(int)$fixture['users']['employee']['id'];$sharedId=(int)$fixture['users']['shared']['id'];
$employeeRole=(int)$fixture['roles']['employee'];$managerRole=(int)$fixture['roles']['manager'];$foreignId=(int)$fixture['users']['foreign']['id'];

// Complete permission rows must still be used when GROUP_CONCAT would truncate them.
$oldGroupConcat=(int)$pdo->query('SELECT @@SESSION.group_concat_max_len')->fetchColumn();$pdo->exec('SET SESSION group_concat_max_len=4');
$freshManager=accounts_actor($pdo,$org,(int)$manager['id']);
ac_assert(in_array('users.assign_roles',$freshManager['permissions'],true)&&in_array('resumes.convert_to_employee',$freshManager['permissions'],true),'Full manager permissions must survive a low GROUP_CONCAT limit.');
$assignable=accounts_assignable_roles($pdo,$org,$freshManager);$assignableIds=array_column($assignable,'id');
ac_assert(in_array('db-role-'.$employeeRole,$assignableIds,true),'A bounded employee role must be assignable.');
ac_assert(!in_array('db-role-'.$fixture['roles']['owner'],$assignableIds,true)&&!in_array('db-role-'.$fixture['roles']['broader'],$assignableIds,true),'Managers must not assign owner or broader account types.');
$pdo->exec('SET SESSION group_concat_max_len='.$oldGroupConcat);
foreach([$fixture['roles']['broader'],$fixture['roles']['otherEmployee'],$fixture['roles']['owner']] as $role)ac_reject(fn()=>accounts_update($pdo,$manager,ac_profile($pdo,$org,$employeeId,['action'=>'update','roleIds'=>['db-role-'.$role]])),'Unauthorized/foreign role assignment must be denied.');
ac_reject(fn()=>accounts_update($pdo,$manager,['action'=>'status','id'=>$fixture['users']['owner']['id'],'status'=>'suspended']),'Manager must not suspend protected owner access.');
ac_reject(fn()=>accounts_update($pdo,$owner,['action'=>'status','id'=>$owner['id'],'status'=>'suspended']),'An owner must not suspend their own access.');
ac_reject(fn()=>accounts_update($pdo,$manager,['action'=>'status','id'=>$manager['id'],'status'=>'suspended']),'A manager must not suspend their own access.');
ac_reject(fn()=>accounts_update($pdo,$owner,ac_profile($pdo,$org,(int)$owner['id'],['action'=>'update','roleIds'=>['db-role-'.$employeeRole]])),'Protected self-role removal must be denied.');
ac_reject(fn()=>accounts_update($pdo,$actor('employee')+['permissions'=>['*']],['action'=>'status','id'=>$sharedId,'status'=>'suspended']),'Caller-supplied wildcard authority must not override current server permissions.');
ac_reject(fn()=>accounts_update($pdo,$owner,['action'=>'status','id'=>$foreignId,'status'=>'suspended']),'Account updates must not cross tenant boundaries.');

// A restaurant suspension changes that membership, invalidates its session, and stays revoked after reactivation.
app_session_start_user($pdo,$org,$sharedId);$homeSession=$_SESSION;
app_session_start_user($pdo,$other,$sharedId);$otherSession=$_SESSION;
accounts_update($pdo,$manager,['action'=>'status','id'=>$sharedId,'status'=>'suspended']);
ac_assert(ac_one($pdo,'SELECT status FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org,$sharedId])==='suspended','Suspension must persist in the database.');
ac_assert(ac_one($pdo,'SELECT status FROM users WHERE id=?',[$sharedId])==='active','Restaurant suspension must not globally disable a shared identity.');
$_SESSION=$homeSession;ac_assert(app_current_user(true)===null,'Suspended restaurant session must be rejected.');
$_SESSION=$otherSession;ac_assert(app_current_user(true)!==null,'Other restaurant session must remain valid.');
accounts_update($pdo,$manager,['action'=>'status','id'=>$sharedId,'status'=>'active']);
$_SESSION=$homeSession;ac_assert(app_current_user(true)===null,'Reactivation must not resurrect an old session.');
$_SESSION=$otherSession;ac_assert(app_current_user(true)!==null,'Other restaurant session must survive reactivation elsewhere.');
app_session_start_user($pdo,$org,$sharedId);ac_assert(app_current_user(true)!==null,'A fresh sign-in must work after reactivation.');

// Profiles persist; managers may change a local position but not another shared identity's personal details.
$edited=accounts_update($pdo,$manager,ac_profile($pdo,$org,$employeeId,['action'=>'update','firstName'=>'Persisted','displayName'=>'Persisted Employee','phone'=>'6025550222','position'=>'Lead cook']));
ac_assert($edited['user']['firstName']==='Persisted'&&accounts_payload($pdo,$org,$employeeId,(int)$manager['id'])['position']==='Lead cook','Profile/position edits must survive a fresh database read.');
ac_reject(fn()=>accounts_update($pdo,$manager,ac_profile($pdo,$org,$employeeId,['action'=>'update','password'=>'Unwanted-Password!42'])),'Managers must not set plaintext passwords.');
ac_reject(fn()=>accounts_update($pdo,$manager,ac_profile($pdo,$org,$employeeId,['action'=>'update','email'=>'replacement-'.$fixture['suffix'].'@example.test'])),'The account editor must not replace verified email identity.');
ac_reject(fn()=>accounts_update($pdo,$manager,ac_profile($pdo,$org,$sharedId,['action'=>'update','firstName'=>'Unauthorized personal change'])),'Managers must not alter another shared identity profile.');
$oldOtherPosition=ac_one($pdo,'SELECT job_title FROM organization_memberships WHERE organization_id=? AND user_id=?',[$other,$sharedId]);
accounts_update($pdo,$manager,ac_profile($pdo,$org,$sharedId,['action'=>'update','position'=>'Home shift leader']));
ac_assert(ac_one($pdo,'SELECT job_title FROM organization_memberships WHERE organization_id=? AND user_id=?',[$other,$sharedId])===$oldOtherPosition,'Local job-title updates must not change another restaurant membership.');
accounts_update($pdo,$actor('shared'),ac_profile($pdo,$org,$sharedId,['action'=>'profile','firstName'=>'SelfEdited','displayName'=>'SelfEdited Shared','phone'=>'6025550333','position'=>'Must not replace local job title']));
ac_assert(ac_one($pdo,'SELECT first_name FROM users WHERE id=?',[$sharedId])==='SelfEdited','The account holder can update their own shared profile.');
ac_assert(ac_one($pdo,'SELECT job_title FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org,$sharedId])==='Home shift leader','Self-profile updates must not overwrite restaurant job-title administration.');

// Inviting an existing identity must expose only the submitted invitation profile until mailbox/password acceptance.
$foreignEmail=$fixture['users']['foreign']['email'];$originalForeign=ac_row($pdo,'SELECT first_name,last_name,display_name,phone,password_hash FROM users WHERE id=?',[$foreignId]);
$invited=accounts_invite($pdo,$manager,ac_invite_input($foreignEmail,$employeeRole));$foreignToken=ac_mail_token();
ac_assert($invited['invitation']['delivery']==='sent','Fixture invitation must be captured successfully.');
ac_assert((int)ac_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org,$foreignId])===0,'Invitation must not grant membership before acceptance.');
$pending=accounts_payload($pdo,$org,$foreignId,(int)$manager['id']);
ac_assert($pending['pendingMembership']&&$pending['displayName']==='Submitted local profile'&&$pending['phone']==='6025550188','Pending directory row must use the inviter-submitted local details.');
$publicPayload=json_encode($invited,JSON_THROW_ON_ERROR);
ac_assert(!str_contains($publicPayload,$fixture['foreignPrivate']['displayName'])&&!str_contains($publicPayload,$fixture['foreignPrivate']['phone'])&&!str_contains($publicPayload,$foreignToken),'Invitation response must not disclose foreign profile or recipient token.');
for($i=0;$i<5;$i++)ac_reject(fn()=>accounts_accept_invitation($pdo,$foreignToken,'Incorrect-Existing!42'),'Wrong existing passwords must be rejected and counted.');
ac_assert((int)ac_one($pdo,'SELECT locked_until>NOW(6) FROM users WHERE id=?',[$foreignId])===1,'Invitation password guesses must use the shared account lockout.');
ac_reject(fn()=>accounts_accept_invitation($pdo,$foreignToken,$fixture['password']),'A locked account must not accept an invitation with the correct password.');
$pdo->prepare('UPDATE users SET locked_until=NULL,failed_login_count=0 WHERE id=?')->execute([$foreignId]);
$accepted=accounts_accept_invitation($pdo,$foreignToken,$fixture['password']);
ac_assert((int)$accepted['userId']===$foreignId&&(int)$accepted['organizationId']===$org,'Correct existing credential must activate the requested restaurant membership.');
$afterForeign=ac_row($pdo,'SELECT first_name,last_name,display_name,phone,password_hash FROM users WHERE id=?',[$foreignId]);
ac_assert($afterForeign===$originalForeign,'Existing identity acceptance must preserve its own profile and password.');
ac_assert((int)ac_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE user_id=? AND status=\'active\'',[$foreignId])===2,'Existing identity must retain its other active membership.');
ac_reject(fn()=>accounts_accept_invitation($pdo,$foreignToken,$fixture['password']),'Accepted invitations must not replay.');

// New-account invitations remain unusable on expiry, email mismatch, weak passwords, or revoked inviter authority.
$new=accounts_invite($pdo,$manager,ac_invite_input($fixture['inviteEmail'],$employeeRole));$token=ac_mail_token();$uid=accounts_id($new['user']['id']);
ac_assert((int)ac_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE user_id=?',[$uid])===0,'New invited accounts also have no membership until acceptance.');
ac_assert(ac_one($pdo,'SELECT status FROM users WHERE id=?',[$uid])==='invited','New identities must remain invited.');
ac_reject(fn()=>accounts_accept_invitation($pdo,$token,'short'),'Weak new passwords must not activate invitations.');
$pdo->prepare('UPDATE account_invitations SET expires_at=DATE_SUB(NOW(6),INTERVAL 1 MINUTE) WHERE token_hash=?')->execute([hash('sha256',$token)]);
ac_assert(accounts_invitation_preview($pdo,$token)===null,'Expired invitation must not be previewable.');
ac_reject(fn()=>accounts_accept_invitation($pdo,$token,'Recipient-Chosen!42'),'Expired invitation must not activate.');
$pdo->prepare('UPDATE account_invitations SET expires_at=DATE_ADD(NOW(6),INTERVAL 1 DAY),email=? WHERE token_hash=?')->execute(['mismatch-'.$fixture['suffix'].'@example.test',hash('sha256',$token)]);
ac_reject(fn()=>accounts_accept_invitation($pdo,$token,'Recipient-Chosen!42'),'Invitation identity mismatch must not activate.');
$pdo->prepare('UPDATE account_invitations SET email=? WHERE token_hash=?')->execute([$fixture['inviteEmail'],hash('sha256',$token)]);
accounts_update($pdo,$owner,['action'=>'status','id'=>$manager['id'],'status'=>'suspended']);
ac_reject(fn()=>accounts_accept_invitation($pdo,$token,'Recipient-Chosen!42'),'An inactive inviter must not authorize new membership.');
ac_assert(ac_one($pdo,'SELECT status FROM users WHERE id=?',[$uid])==='invited'&&(int)ac_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE user_id=?',[$uid])===0,'Failed inviter validation must roll back credential activation.');
accounts_update($pdo,$owner,['action'=>'status','id'=>$manager['id'],'status'=>'active']);
accounts_accept_invitation($pdo,$token,'Recipient-Chosen!42');
ac_assert(password_verify('Recipient-Chosen!42',(string)ac_one($pdo,'SELECT password_hash FROM users WHERE id=?',[$uid])),'Only the recipient-chosen credential must be stored.');
ac_assert((int)ac_one($pdo,'SELECT email_verified_at IS NOT NULL FROM users WHERE id=?',[$uid])===1,'Mailbox acceptance must mark the email verified.');
ac_reject(fn()=>accounts_accept_invitation($pdo,$token,'Another-Password!42'),'A used new-account invitation must not replace its credential.');

// Delivery failure is durable and retryable, without silently granting membership.
$capture=(string)getenv('GELATO_ACCOUNTS_MAIL_CAPTURE');file_put_contents($capture.'.fail','fail');
try{$failed=accounts_invite($pdo,$manager,ac_invite_input('delivery-'.$fixture['suffix'].'@example.test',$employeeRole));}finally{@unlink($capture.'.fail');}
$failedId=accounts_id($failed['user']['id']);
ac_assert($failed['invitation']['delivery']==='failed'&&(int)ac_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE user_id=?',[$failedId])===0,'Failed mail must be reported without activating access.');
$resent=accounts_update($pdo,$manager,['action'=>'resend','id'=>$failedId]);$resentToken=ac_mail_token();
ac_assert($resent['invitation']['delivery']==='sent'&&accounts_invitation_preview($pdo,$resentToken)!==null,'A failed-delivery invitation must support a fresh captured retry.');
accounts_update($pdo,$manager,['action'=>'status','id'=>$failedId,'status'=>'suspended']);
ac_reject(fn()=>accounts_accept_invitation($pdo,$resentToken,'Cancelled-Invite!42'),'Cancelling a pending invitation must revoke its recipient link.');

// An audit failure rolls back profile, role and membership changes together.
$beforeUser=ac_row($pdo,'SELECT first_name,display_name,phone FROM users WHERE id=?',[$employeeId]);
$beforeMembership=accounts_target($pdo,$org,$employeeId);$beforeRoles=array_column(accounts_roles($pdo,$org,(int)$beforeMembership['membership_id']),'id');
$pdo->exec('DROP TRIGGER IF EXISTS hardening_accounts_fail');
try{
    $pdo->exec("CREATE TRIGGER hardening_accounts_fail BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.organization_id=".$org." AND NEW.action='user.updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Account rollback fixture'; END IF; END");
    $thrown=false;try{accounts_update($pdo,$owner,ac_profile($pdo,$org,$employeeId,['action'=>'update','firstName'=>'RollbackThis','displayName'=>'RollbackThis Person','position'=>'Rollback position','roleIds'=>['db-role-'.$managerRole]]));}catch(PDOException){$thrown=true;}
    ac_assert($thrown,'The real audit write must fail in the rollback fixture.');
}finally{$pdo->exec('DROP TRIGGER IF EXISTS hardening_accounts_fail');}
ac_assert(ac_row($pdo,'SELECT first_name,display_name,phone FROM users WHERE id=?',[$employeeId])===$beforeUser,'Audit failure must roll back global profile writes.');
$afterMembership=accounts_target($pdo,$org,$employeeId);
ac_assert($afterMembership['job_title']===$beforeMembership['job_title']&&$afterMembership['membership_updated_at']===$beforeMembership['membership_updated_at'],'Audit failure must roll back local position/session-version writes.');
ac_assert(array_column(accounts_roles($pdo,$org,(int)$afterMembership['membership_id']),'id')===$beforeRoles,'Audit failure must roll back role replacement.');

// Applicant conversion is a single durable invitation even when the manager repeats the action.
require_once __DIR__.'/../includes/hiring-core.php';
$job='accounts-convert-'.$fixture['suffix'];$pdo->prepare("INSERT INTO jobs(organization_id,public_id,slug,title,status) VALUES (?,?,?,?,'published')")->execute([$org,$job,$job,'Applicant role']);
$application=hiring_submit($pdo,$org,['jobId'=>$job,'firstName'=>'Candidate','lastName'=>'Converted','email'=>'converted-'.$fixture['suffix'].'@example.test','phone'=>'6025550444','availability'=>'Evenings','experience'=>'Kitchen experience','consent'=>true,'idempotencyKey'=>bin2hex(random_bytes(16))],[],'conversion-'.$fixture['suffix']);
$converted=accounts_invite($pdo,$manager,['applicationId'=>$application['applicationId']]);$convertedId=accounts_id($converted['user']['id']);
$convertedAgain=accounts_invite($pdo,$manager,['applicationId'=>$application['applicationId']]);
ac_assert($convertedAgain['user']['id']===$converted['user']['id'],'Repeated conversion must return the existing invited account.');
ac_assert((int)ac_one($pdo,'SELECT COUNT(*) FROM account_invitations WHERE organization_id=? AND user_id=?',[$org,$convertedId])===1,'Repeated conversion must not send another invitation.');
ac_assert((int)ac_one($pdo,'SELECT converted_user_id FROM resume_submissions WHERE public_id=? AND organization_id=?',[$application['applicationId'],$org])===$convertedId,'Conversion must link the durable hiring record.');
ac_assert((int)ac_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org,$convertedId])===0,'Conversion itself must not grant login access.');

// Two real processes race one still-valid invitation behind the same organization lock.
$race=accounts_invite($pdo,$owner,ac_invite_input('race-'.$fixture['suffix'].'@example.test',$employeeRole));$raceToken=ac_mail_token();$raceId=accounts_id($race['user']['id']);
$passwords=['Invitation-Race-One!42','Invitation-Race-Two!43'];$workers=[];$wins=0;$winner=null;
$pdo->beginTransaction();accounts_lock_org($pdo,$org);
try{
    foreach($passwords as $password){
        $process=proc_open([PHP_BINARY,__FILE__,'accept-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start invitation race worker.');
        fwrite($pipes[0],json_encode(['token'=>$raceToken,'password'=>$password],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],15);
        if(trim((string)fgets($pipes[1]))!=='ready')throw new RuntimeException('Invitation worker did not reach the barrier.');
        $workers[]=[$process,$pipes,$password];
    }
    usleep(20000);$pdo->commit();
    foreach($workers as [$process,$pipes,$password]){
        $result=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('Invitation worker failed: '.$errors);
        $outcome=json_decode($result,true,512,JSON_THROW_ON_ERROR);if($outcome['won']){$wins++;$winner=$password;}
    }
}finally{if($pdo->inTransaction())$pdo->rollBack();}
ac_assert($wins===1,'Exactly one concurrent invitation acceptance may succeed.');
ac_assert(password_verify((string)$winner,(string)ac_one($pdo,'SELECT password_hash FROM users WHERE id=?',[$raceId])),'The losing acceptance must not overwrite the winner credential.');
ac_assert((int)ac_one($pdo,'SELECT COUNT(*) FROM organization_memberships WHERE organization_id=? AND user_id=?',[$org,$raceId])===1,'Concurrent acceptance must create only one restaurant membership.');
ac_assert((int)ac_one($pdo,"SELECT COUNT(*) FROM audit_log WHERE organization_id=? AND entity_type='user' AND entity_id=? AND action='user.invitation_accepted'",[$org,(string)$raceId])===1,'Concurrent acceptance must audit exactly one success.');
echo "Account lifecycle DB behavior passed ($checks checks): durable membership/profile changes, tenant-only revocation, non-resurrection, complete permissions, invitation privacy/acceptance/lockout, delivery retry, audit rollback, conversion idempotency and two-process acceptance race.\n";
