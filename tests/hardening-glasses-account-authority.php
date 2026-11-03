<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/glasses-handoff.php';

$pdo=app_pdo();$checks=0;
function hga_check(bool $ok,string $message):void{
    global $checks;if(!$ok)throw new RuntimeException($message);$checks++;
}
function hga_reject(callable $operation,string $label):void{
    try{$operation();}catch(InvalidArgumentException $error){
        hga_check(str_contains($error->getMessage(),'re-pair'),$label.' explains how to restore accountable pairing.');return;
    }
    throw new RuntimeException($label.' must be rejected.');
}
function hga_account_blocked(PDO $pdo,string $token,array $cachedDevice,string $label):void{
    hga_reject(fn()=>glasses_authenticate_token($pdo,$token),$label.' token authentication');
    hga_reject(fn()=>glasses_handoff_actor_user($pdo,$cachedDevice),$label.' cached-device actor fallback');
    // Account checks precede validation, kitchen state transitions, and handoff writes.
    hga_reject(fn()=>glasses_handoff_to_expo($pdo,$cachedDevice,'absent-build'),$label.' canonical handoff');
}
hga_check($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql','Accountability regression requires MySQL/MariaDB.');
$suffix=bin2hex(random_bytes(6));$pdo->beginTransaction();
try{
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
        ->execute(['Glasses accountability '.$suffix]);$org=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
        ->execute(['Other glasses restaurant '.$suffix]);$otherOrg=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO locations(organization_id,name,status) VALUES (?,'Accountable kitchen','active')")
        ->execute([$org]);$location=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status) VALUES (?,'fixture-hash','Device','Manager','Device Manager','active')")
        ->execute(['glasses-account-'.$suffix.'@example.test']);$uid=(int)$pdo->lastInsertId();
    $membership=$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')");
    $membership->execute([$org,$uid]);$membership->execute([$otherOrg,$uid]);
    $grant=glasses_create_pairing_grant($pdo,$org,$location,null,$uid);
    $paired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'accountability-'.$suffix,'displayName'=>'Accountability fixture']);
    $token=(string)$paired['deviceToken'];$device=glasses_authenticate_token($pdo,$token);
    hga_check((int)$device['organization_id']===$org&&(int)$device['paired_by']===$uid,'Active pairing authenticates its actual restaurant and accountable identity.');
    hga_check(glasses_handoff_actor_user($pdo,$device)===$uid,'Active pairing can supply the canonical handoff actor.');

    $pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE organization_id=? AND user_id=?")->execute([$org,$uid]);
    hga_account_blocked($pdo,$token,$device,'Suspended restaurant membership');
    $q=$pdo->prepare('SELECT status FROM organization_memberships WHERE organization_id=? AND user_id=?');$q->execute([$otherOrg,$uid]);
    hga_check($q->fetchColumn()==='active','Suspending device accountability does not change the other restaurant membership.');
    $q=$pdo->prepare('SELECT status FROM users WHERE id=?');$q->execute([$uid]);
    hga_check($q->fetchColumn()==='active','Tenant suspension is rejected while the global identity remains active.');
    $pdo->prepare("UPDATE organization_memberships SET status='active' WHERE organization_id=? AND user_id=?")->execute([$org,$uid]);
    hga_check((int)glasses_authenticate_token($pdo,$token)['paired_by']===$uid,'Restoring active local accountability preserves the existing device token boundary.');

    $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$uid]);
    hga_account_blocked($pdo,$token,$device,'Globally suspended identity');
    $pdo->prepare("UPDATE users SET status='active',archived_at=NOW(6) WHERE id=?")->execute([$uid]);
    hga_account_blocked($pdo,$token,$device,'Archived identity');
    $pdo->prepare('UPDATE users SET archived_at=NULL WHERE id=?')->execute([$uid]);

    $pdo->prepare('DELETE FROM organization_memberships WHERE organization_id=? AND user_id=?')->execute([$org,$uid]);
    hga_account_blocked($pdo,$token,$device,'Membership only in another restaurant');
    $membership->execute([$org,$uid]);
    $pdo->prepare('UPDATE glasses_devices SET paired_by=NULL WHERE organization_id=? AND id=?')->execute([$org,$device['id']]);
    $unowned=glasses_device_row($pdo,$org,(string)$device['public_id']);
    hga_account_blocked($pdo,$token,$unowned,'Missing accountable pairing identity');

    // Existing administrative revoke/re-pair flow restores accountability and rotates the token.
    glasses_device_revoke($pdo,$org,(string)$device['public_id'],$uid);
    $grant=glasses_create_pairing_grant($pdo,$org,$location,null,$uid);
    $repaired=glasses_pair_device($pdo,(string)$grant['pairingCode'],['hardwareIdentifier'=>'accountability-'.$suffix,'displayName'=>'Repaired fixture']);
    $fresh=glasses_authenticate_token($pdo,(string)$repaired['deviceToken']);
    hga_check((int)$fresh['id']===(int)$device['id']&&(int)$fresh['paired_by']===$uid,'Re-pair preserves physical device identity and restores an active accountable member.');
    $oldBlocked=false;try{glasses_authenticate_token($pdo,$token);}catch(InvalidArgumentException){$oldBlocked=true;}
    hga_check($oldBlocked,'Re-pair does not revive the previous bearer token.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}
echo "Glasses accountable identity: PASS ($checks checks)\n";
