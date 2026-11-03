<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/hardening-hiring-fixture.php';
function hc_assert(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
function hc_reject(callable $operation,string $message):void {try{$operation();}catch(InvalidArgumentException|DomainException|OverflowException|UnexpectedValueException|OutOfBoundsException){return;}throw new RuntimeException($message);}
function hc_forbidden(callable $operation,string $message):void {try{$operation();}catch(DomainException){return;}throw new RuntimeException($message);}
$pdo=app_pdo();$fixture=hiring_fixture_seed($pdo);$org=(int)$fixture['organizationId'];
$input=['jobId'=>$fixture['jobs']['open'],'firstName'=>'Durable','lastName'=>'Applicant','email'=>'contract-'.$fixture['suffix'].'@example.test',
    'phone'=>'6025550100','availability'=>'Evenings','experience'=>'Kitchen experience','consent'=>true,'idempotencyKey'=>bin2hex(random_bytes(16))];
$pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
$file=hiring_validate_attachment('resume.pdf',$pdf,'resume');
$receipt=hiring_submit($pdo,$org,$input,[$file],'192.0.2.'.$fixture['users']['manager']['id']);
$repeat=hiring_submit($pdo,$org,$input,[$file],'192.0.2.'.$fixture['users']['manager']['id']);
hc_assert($repeat['replayed'] && $repeat['applicationId']===$receipt['applicationId'],'Identical retries must return the sole persisted receipt.');
$detail=hiring_resume_detail($pdo,$org,$receipt['applicationId']);
hc_assert($detail!==null && count($detail['attachments'])===1 && $detail['email']===$input['email'],'Applicant and attachment metadata must be durable.');
hc_assert(hiring_resume_detail($pdo,(int)$fixture['otherOrganizationId'],$receipt['applicationId'])===null,'Other tenants cannot see the record.');
$statement=$pdo->prepare('SELECT f.storage_path FROM files f JOIN resume_files rf ON rf.file_id=f.id JOIN resume_submissions r ON r.id=rf.resume_submission_id WHERE r.public_id=? AND r.organization_id=?');
$statement->execute([$receipt['applicationId'],$org]);
$path=app_private_storage_read((string)$statement->fetchColumn(),'hiring-documents/'.$org);
hc_assert($path!==null && file_get_contents($path)===$pdf,'The private resume bytes must match the received document.');
hc_reject(fn()=>hiring_submit($pdo,$org,array_replace($input,['experience'=>'Changed']),[$file],'192.0.2.1'),'Changed content must not reuse a receipt.');
foreach(['closed','foreign'] as $kind)hc_reject(fn()=>hiring_submit($pdo,$org,array_replace($input,['jobId'=>$fixture['jobs'][$kind],'idempotencyKey'=>bin2hex(random_bytes(16))]),[],'192.0.2.1'),'Closed/foreign jobs must reject intake.');
hc_reject(fn()=>hiring_validate_attachment('resume.pdf','<script>alert(1)</script>','resume'),'Mislabeled executable content must be rejected.');
hc_reject(fn()=>hiring_validate_attachment('photo.png',str_repeat('x',2*1024*1024+1),'photo'),'Oversized photos must be rejected.');
hc_reject(fn()=>hiring_review($pdo,$fixture['users']['reader'],['action'=>'status','id'=>$receipt['applicationId'],'status'=>'hired','expectedStatus'=>'new']),'View permission must not grant review authority.');
$review=hiring_review($pdo,$fixture['users']['manager'],['action'=>'status','id'=>$receipt['applicationId'],'status'=>'under_review','expectedStatus'=>'new']);
hc_assert($review['status']==='under_review','Review changes must persist.');
hc_reject(fn()=>hiring_review($pdo,$fixture['users']['manager'],['action'=>'status','id'=>$receipt['applicationId'],'status'=>'rejected','expectedStatus'=>'new']),'A stale status write must be rejected.');
$review=hiring_review($pdo,$fixture['users']['manager'],['action'=>'note','id'=>$receipt['applicationId'],'text'=>'Interview next week.']);
hc_assert(count($review['notes'])===1 && $review['notes'][0]['text']==='Interview next week.','Private reviewer notes must persist.');
hc_reject(fn()=>hiring_review($pdo,$fixture['users']['outsider'],['action'=>'note','id'=>$receipt['applicationId'],'text'=>'Cross tenant']),'Foreign reviewers must not alter the application.');
// Model a request whose actor was authorized before a revocation committed while
// it waited for the organization lock. Both actions must reload that authority.
$staleActor=$fixture['users']['manager'];$managerId=(int)$staleActor['id'];
$snapshot=function()use($pdo,$org,$receipt):array{
    $q=$pdo->prepare("SELECT r.status,r.updated_at,r.archived_at,
        (SELECT COUNT(*) FROM resume_notes n WHERE n.resume_submission_id=r.id) AS note_count,
        (SELECT COUNT(*) FROM resume_status_history h WHERE h.resume_submission_id=r.id) AS history_count,
        (SELECT COUNT(*) FROM audit_log a WHERE a.organization_id=r.organization_id AND a.entity_type='resume' AND a.entity_id=r.public_id) AS audit_count
        FROM resume_submissions r WHERE r.organization_id=? AND r.public_id=?");
    $q->execute([$org,$receipt['applicationId']]);return $q->fetch();
};
$beforeDenied=$snapshot();
try{
    $pdo->prepare('UPDATE user_roles ur JOIN organization_memberships om ON om.id=ur.membership_id SET ur.revoked_at=NOW(6) WHERE om.organization_id=? AND om.user_id=?')->execute([$org,$managerId]);
    hc_forbidden(fn()=>hiring_review($pdo,$staleActor,['action'=>'status','id'=>$receipt['applicationId'],'status'=>'hired','expectedStatus'=>'under_review']),'Revoked review authority must not survive in the request actor.');
    hc_forbidden(fn()=>hiring_review($pdo,$staleActor,['action'=>'note','id'=>$receipt['applicationId'],'text'=>'A stale authorization must not save this note.']),'Revoked note authority must not survive in the request actor.');
    hc_assert($snapshot()===$beforeDenied,'Denied stale-authority writes must preserve status, notes, history and audit.');
}finally{
    $pdo->prepare('UPDATE user_roles ur JOIN organization_memberships om ON om.id=ur.membership_id SET ur.revoked_at=NULL WHERE om.organization_id=? AND om.user_id=?')->execute([$org,$managerId]);
}
try{
    $pdo->prepare("UPDATE organization_memberships SET status='suspended' WHERE organization_id=? AND user_id=?")->execute([$org,$managerId]);
    hc_forbidden(fn()=>hiring_review($pdo,$staleActor,['action'=>'note','id'=>$receipt['applicationId'],'text'=>'Suspended membership note']),'A suspended membership must lose review authority before writes.');
}finally{
    $pdo->prepare("UPDATE organization_memberships SET status='active' WHERE organization_id=? AND user_id=?")->execute([$org,$managerId]);
}
try{
    $pdo->prepare("UPDATE users SET status='suspended' WHERE id=?")->execute([$managerId]);
    hc_forbidden(fn()=>hiring_review($pdo,$staleActor,['action'=>'note','id'=>$receipt['applicationId'],'text'=>'Suspended identity note']),'A suspended identity must lose review authority before writes.');
}finally{
    $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$managerId]);
}
hc_assert($snapshot()===$beforeDenied && !$pdo->inTransaction(),'All denied review writes must leave no changes or open transaction.');
$bucket='quota-'.$fixture['suffix'];
for($i=0;$i<10;$i++)hiring_submit($pdo,$org,array_replace($input,['idempotencyKey'=>bin2hex(random_bytes(16))]),[],$bucket);
hc_reject(fn()=>hiring_submit($pdo,$org,array_replace($input,['idempotencyKey'=>bin2hex(random_bytes(16))]),[],$bucket),'Public application quota must survive browser/session changes.');
echo "Hiring DB behavior passed: durable private bytes, idempotency, tenant/job validation, fresh permission and active-account checks, stale-review protection, private notes and durable intake quota.\n";
