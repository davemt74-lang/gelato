<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/user-accounts.php';
$user = app_require_auth();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !app_has_permission('jobs.view', $user)) { app_json_response(['ok'=>false,'message'=>'You do not have permission to view jobs.'],403); }
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

function job_payload(array $row): array {
    return [
        'id' => $row['public_id'], 'slug' => $row['slug'], 'title' => $row['title'], 'department' => $row['department'] ?? '',
        'location' => $row['location_name'] ?? '', 'employmentType' => $row['employment_type'] ?? '', 'schedule' => $row['schedule_text'] ?? '',
        'payRange' => $row['pay_range'] ?? '', 'summary' => $row['summary'] ?? '', 'description' => $row['description'] ?? '',
        'responsibilities' => json_decode((string)($row['responsibilities_json'] ?? '[]'), true) ?: [],
        'requirements' => json_decode((string)($row['requirements_json'] ?? '[]'), true) ?: [],
        'benefits' => json_decode((string)($row['benefits_json'] ?? '[]'), true) ?: [],
        'status' => $row['status'], 'publishedAt' => $row['published_at'], 'closesAt' => $row['closes_at'],
    ];
}
function list_jobs(PDO $pdo, int $organizationId): array {
    $statement = $pdo->prepare('SELECT * FROM jobs WHERE organization_id = ? AND archived_at IS NULL ORDER BY sort_order, created_at DESC');
    $statement->execute([$organizationId]);
    return array_map('job_payload', $statement->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    app_json_response(['ok' => true, 'jobs' => list_jobs($pdo, $organizationId)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: GET, POST'); app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405); }
$input = app_json_input(); app_verify_request_csrf($input);
$job = $input['job'] ?? [];
if (!is_array($job)) app_json_response(['ok'=>false,'message'=>'The job payload is invalid.'],422);
foreach (['id'=>120,'title'=>180,'slug'=>160,'department'=>120,'location'=>180,'employmentType'=>80,'schedule'=>500,'payRange'=>180,'summary'=>12000,'description'=>50000,'status'=>32,'closesAt'=>10] as $field=>$limit) {
    if (isset($job[$field]) && (!is_string($job[$field]) || mb_strlen($job[$field])>$limit)) app_json_response(['ok'=>false,'message'=>'A job field is invalid or too long.'],422);
}
foreach (['responsibilities','requirements','benefits'] as $field) {
    if (isset($job[$field]) && (!is_array($job[$field]) || count($job[$field])>100)) app_json_response(['ok'=>false,'message'=>'Job lists must contain at most 100 text entries.'],422);
    foreach (($job[$field]??[]) as $value) if (!is_string($value) || mb_strlen($value)>2000) app_json_response(['ok'=>false,'message'=>'Job lists must contain text entries.'],422);
}
$closing=trim((string)($job['closesAt']??''));
$closingDate=$closing!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$closing):null;
if ($closing!=='' && (!$closingDate || $closingDate->format('Y-m-d')!==$closing)) app_json_response(['ok'=>false,'message'=>'Choose a valid closing date.'],422);
$existingPublicId = trim((string)($job['id'] ?? ''));
if ($existingPublicId !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $existingPublicId)) app_json_response(['ok'=>false,'message'=>'The job identifier is invalid.'],422);
$requiredPermission = $existingPublicId !== '' ? 'jobs.edit' : 'jobs.create';
if (!app_has_permission($requiredPermission, $user)) app_json_response(['ok'=>false,'message'=>'You do not have permission to save this job.'],403);
$title = trim((string)($job['title'] ?? ''));
if ($title === '' || mb_strlen($title)>180) app_json_response(['ok'=>false,'message'=>'A job title of at most 180 characters is required.'],422);
$status = (string)($job['status'] ?? 'draft');
if (!in_array($status,['draft','published','paused','archived'],true)) app_json_response(['ok'=>false,'message'=>'Choose a supported job status.'],422);
if ($status === 'published' && !app_has_permission('jobs.publish',$user)) app_json_response(['ok'=>false,'message'=>'You do not have permission to publish jobs.'],403);
$publicId = $existingPublicId ?: 'job-' . bin2hex(random_bytes(8));
$slug = strtolower(trim((string)($job['slug'] ?? $title)));
$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $slug), '-') ?: $publicId;
if (strlen($slug)>160) app_json_response(['ok'=>false,'message'=>'The job URL is too long.'],422);
$publishedAt = $status === 'published' ? (string)$pdo->query('SELECT NOW(6)')->fetchColumn() : null;
$values = [
    'slug'=>$slug,'title'=>$title,'department'=>trim((string)($job['department']??''))?:null,
    'location_name'=>trim((string)($job['location']??''))?:null,'employment_type'=>trim((string)($job['employmentType']??''))?:null,
    'schedule_text'=>trim((string)($job['schedule']??''))?:null,'pay_range'=>trim((string)($job['payRange']??''))?:null,
    'summary'=>trim((string)($job['summary']??''))?:null,'description'=>trim((string)($job['description']??''))?:null,
    'responsibilities_json'=>json_encode(array_values((array)($job['responsibilities']??[])),JSON_THROW_ON_ERROR),
    'requirements_json'=>json_encode(array_values((array)($job['requirements']??[])),JSON_THROW_ON_ERROR),
    'benefits_json'=>json_encode(array_values((array)($job['benefits']??[])),JSON_THROW_ON_ERROR),
    'status'=>$status,'published_at'=>$publishedAt,'closes_at'=>trim((string)($job['closesAt']??''))?:null,
    'updated_by'=>(int)$user['id'],
];
try {
    $pdo->beginTransaction();
    $lock=$pdo->prepare("SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE");
    $lock->execute([$organizationId]);
    if (!$lock->fetchColumn()) { $pdo->rollBack(); app_json_response(['ok'=>false,'message'=>'The restaurant was not found.'],404); }
    // Recheck authority after the lock shared with account/role revocations.
    accounts_lock_users($pdo,[(int)$user['id']]);
    $user=accounts_actor($pdo,$organizationId,(int)$user['id']);
    if (!app_has_permission($requiredPermission,$user)) throw new DomainException('You do not have permission to save this job.');
    if ($status==='published' && !app_has_permission('jobs.publish',$user)) throw new DomainException('You do not have permission to publish jobs.');
    if ($existingPublicId !== '') {
        $existing=$pdo->prepare('SELECT id,status,published_at FROM jobs WHERE organization_id=? AND public_id=? AND archived_at IS NULL FOR UPDATE');
        $existing->execute([$organizationId,$existingPublicId]);
        $before=$existing->fetch();
        if (!$before) { $pdo->rollBack(); app_json_response(['ok'=>false,'message'=>'The job was not found.'],404); }
        if ($before['status'] === 'published' && $status !== 'published' && !app_has_permission('jobs.publish',$user)) {
            $pdo->rollBack(); app_json_response(['ok'=>false,'message'=>'You do not have permission to unpublish jobs.'],403);
        }
        if ($status === 'published' && !empty($before['published_at'])) $values['published_at']=$before['published_at'];
        $assignments=implode(',',array_map(static fn(string $key):string=>$key.'=:'.$key,array_keys($values)));
        $statement=$pdo->prepare('UPDATE jobs SET '.$assignments.' WHERE organization_id=:organization_id AND public_id=:public_id');
        $statement->execute($values+['organization_id'=>$organizationId,'public_id'=>$publicId]);
    } else {
        $insert=$values+['organization_id'=>$organizationId,'public_id'=>$publicId,'created_by'=>(int)$user['id']];
        $statement=$pdo->prepare('INSERT INTO jobs ('.implode(',',array_keys($insert)).') VALUES ('.implode(',',array_map(static fn(string $key):string=>':'.$key,array_keys($insert))).')');
        $statement->execute($insert);
    }
    app_audit($pdo,$organizationId,(int)$user['id'],$existingPublicId!==''?'job.updated':'job.created','job',$publicId,null,['title'=>$title,'status'=>$status]);
    $pdo->commit();
    app_json_response(['ok'=>true,'message'=>'Job saved.','jobs'=>app_has_permission('jobs.view',$user)?list_jobs($pdo,$organizationId):[]]);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],403);
} catch (PDOException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($error->getCode()==='23000') app_json_response(['ok'=>false,'message'=>'A job already uses this URL. Choose a different job URL.'],409);
    app_json_response(['ok'=>false,'message'=>'The job could not be saved.'],503);
} catch (Throwable) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    app_json_response(['ok'=>false,'message'=>'The job could not be saved.'],503);
}
