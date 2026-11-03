<?php
declare(strict_types=1);
require_once __DIR__ . '/private-storage.php';

/** The public restaurant is selected by server configuration/data, never an input tenant id. */
function hiring_public_organization_id(PDO $pdo): int
{
    $id = (int)$pdo->query("SELECT o.id FROM organizations o
        LEFT JOIN brand_settings b ON b.organization_id=o.id
        WHERE o.status='active' ORDER BY b.id IS NULL,b.id,o.id LIMIT 1")->fetchColumn();
    if ($id < 1) throw new RuntimeException('No public restaurant is available.');
    return $id;
}

function hiring_public_jobs(PDO $pdo, int $organizationId): array
{
    $statement = $pdo->prepare("SELECT public_id,title,department,location_name FROM jobs
        WHERE organization_id=? AND status='published' AND archived_at IS NULL
        AND (closes_at IS NULL OR closes_at>=CURDATE()) ORDER BY sort_order,published_at DESC,title");
    $statement->execute([$organizationId]);
    return array_map(static fn(array $row): array => ['id'=>$row['public_id'],'title'=>$row['title'],
        'department'=>$row['department']??'','location'=>$row['location_name']??'','status'=>'published'], $statement->fetchAll());
}

function hiring_text(array $input, string $key, int $maximum, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('The application contains an invalid field.');
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $maximum || str_contains($value, "\0")) {
        throw new InvalidArgumentException('Please complete the required fields within the displayed limits.');
    }
    return $value;
}

function hiring_validate_application(array $input): array
{
    $data = [];
    foreach (['jobId'=>120,'firstName'=>100,'lastName'=>100,'email'=>254,'phone'=>40,'availability'=>4000,'experience'=>12000] as $key=>$maximum) {
        $data[$key] = hiring_text($input,$key,$maximum);
    }
    $data['email'] = mb_strtolower($data['email']);
    if (!filter_var($data['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (!in_array($input['consent']??null, [true,'1','yes'], true)) throw new InvalidArgumentException('Confirm that your application information is accurate.');
    $key = hiring_text($input,'idempotencyKey',64);
    if (!preg_match('/^[a-f0-9]{32,64}$/D',$key)) throw new InvalidArgumentException('The submission reference is invalid. Reload the form.');
    $data['requestHash'] = hash('sha256',$key);
    $data['consent'] = true;
    return $data;
}

/** Validate bytes, not the browser-provided MIME type. Files are served as private attachments. */
function hiring_validate_attachment(string $name, string $bytes, string $kind): array
{
    if (!in_array($kind,['resume','video','photo'],true)) throw new InvalidArgumentException('Unsupported attachment field.');
    $name = basename(str_replace('\\','/',$name));
    $name = preg_replace('/[\x00-\x1f\x7f]/u','',$name) ?? '';
    if ($name === '' || mb_strlen($name)>200) throw new InvalidArgumentException('An attachment filename is invalid.');
    $size = strlen($bytes);
    $limit = $kind === 'photo' ? 2*1024*1024 : 10*1024*1024;
    if ($size < 1 || $size > $limit) throw new InvalidArgumentException('Resume/video files must be at most 10 MB; each photo must be at most 2 MB.');
    $extension = strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
    $valid = false;
    if ($kind === 'photo') {
        $formats = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        $image = @getimagesizefromstring($bytes);
        $valid = isset($formats[$extension]) && $mime === $formats[$extension] && is_array($image)
            && ($image['mime']??'') === $mime && $image[0]>0 && $image[1]>0 && $image[0]*$image[1]<=40000000;
    } elseif ($kind === 'resume') {
        if ($extension === 'pdf') {
            $valid = $mime === 'application/pdf' && str_starts_with($bytes,'%PDF-') && str_contains(substr($bytes,-2048),'%%EOF');
        } elseif ($extension === 'doc') {
            $valid = str_starts_with($bytes,"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")
                && in_array($mime,['application/msword','application/CDFV2','application/x-ole-storage'],true);
            if ($valid) $mime = 'application/msword';
        } elseif ($extension === 'docx') {
            if (!class_exists('ZipArchive')) throw new RuntimeException('DOCX uploads are unavailable. Please upload a PDF.');
            $temporary = tempnam(sys_get_temp_dir(),'gelato-resume-');
            if ($temporary === false) throw new RuntimeException('The attachment could not be checked.');
            $archive = new ZipArchive();
            try {
                file_put_contents($temporary,$bytes);
                if ($archive->open($temporary) === true) {
                    $valid = $archive->numFiles<=2000 && $archive->locateName('[Content_Types].xml')!==false
                        && $archive->locateName('word/document.xml')!==false && $archive->locateName('word/vbaProject.bin')===false;
                    $uncompressed = 0;
                    for ($i=0;$valid && $i<$archive->numFiles;$i++) {
                        $entry=$archive->statIndex($i); $uncompressed+=(int)($entry['size']??0);
                        if ($uncompressed>50*1024*1024) $valid=false;
                    }
                    $archive->close();
                }
            } finally { @unlink($temporary); }
            if ($valid) $mime='application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
    } else {
        $formats = ['mp4'=>['video/mp4','application/mp4'],'m4v'=>['video/mp4','video/x-m4v'],'webm'=>['video/webm'],'mov'=>['video/quicktime']];
        $valid = isset($formats[$extension]) && in_array($mime,$formats[$extension],true);
        if ($valid && in_array($extension,['mp4','m4v','mov'],true)) $valid = substr($bytes,4,4)==='ftyp';
        if ($valid && $extension==='webm') $valid = str_starts_with($bytes,"\x1a\x45\xdf\xa3");
    }
    if (!$valid) throw new InvalidArgumentException('An attachment does not match its supported file type. Use PDF/DOC/DOCX, JPEG/PNG/WebP, or MP4/WebM/MOV.');
    return ['name'=>$name,'bytes'=>$bytes,'size'=>$size,'mime'=>$mime,'kind'=>$kind,'extension'=>$extension,'checksum'=>hash('sha256',$bytes)];
}

function hiring_http_attachments(array $uploads): array
{
    $result=[];
    foreach ($uploads as $field=>$upload) {
        if (!in_array($field,['resume','video','photos'],true)) throw new InvalidArgumentException('Unsupported attachment field.');
        $many = is_array($upload['name']??null);
        if ($many !== ($field==='photos')) throw new InvalidArgumentException('Invalid attachment field.');
        $count=$many ? count($upload['name']) : 1;
        if ($count>5) throw new InvalidArgumentException('Choose at most five photos.');
        for ($i=0;$i<$count;$i++) {
            $error=$many ? ($upload['error'][$i]??UPLOAD_ERR_NO_FILE) : ($upload['error']??UPLOAD_ERR_NO_FILE);
            if ($error===UPLOAD_ERR_NO_FILE) continue;
            if ($error!==UPLOAD_ERR_OK) throw new InvalidArgumentException('An attachment exceeded the hosting limit or did not finish uploading. Choose a smaller file and try again.');
            $temporary=$many ? ($upload['tmp_name'][$i]??'') : ($upload['tmp_name']??'');
            if (!is_string($temporary) || !is_uploaded_file($temporary)) throw new InvalidArgumentException('The uploaded attachment is invalid.');
            $size=filesize($temporary);
            if ($size===false || $size>10*1024*1024) throw new InvalidArgumentException('The uploaded attachment is too large.');
            $bytes=file_get_contents($temporary);
            if (!is_string($bytes)) throw new RuntimeException('The attachment could not be read.');
            $name=$many ? ($upload['name'][$i]??'') : ($upload['name']??'');
            $result[]=hiring_validate_attachment((string)$name,$bytes,$field==='photos'?'photo':$field);
        }
    }
    if (array_sum(array_column($result,'size'))>25*1024*1024) throw new InvalidArgumentException('The application attachments must total no more than 25 MB.');
    return $result;
}

function hiring_application_receipt(array $row, bool $replayed = false): array
{
    return ['applicationId'=>(string)$row['public_id'],'receipt'=>(string)$row['public_id'],
        'submittedAt'=>(string)$row['submitted_at'],'replayed'=>$replayed];
}

/** Receipt, audit, metadata and complete private bytes are committed together. */
function hiring_submit(PDO $pdo, int $organizationId, array $input, array $attachments, string $clientAddress): array
{
    $data=hiring_validate_application($input);
    $fingerprint=$data;
    unset($fingerprint['requestHash']);
    $fingerprint['files']=array_map(static fn(array $file):array=>array_intersect_key($file,array_flip(['kind','name','size','mime','checksum'])),$attachments);
    $payloadHash=hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR));
    $written=[]; $committing=false;
    if ($pdo->inTransaction()) throw new LogicException('Application intake requires its own transaction.');
    $pdo->beginTransaction();
    try {
        // A stable tenant lock makes idempotency and the durable abuse counter atomic.
        $statement=$pdo->prepare("SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE");
        $statement->execute([$organizationId]);
        if (!$statement->fetchColumn()) throw new InvalidArgumentException('This restaurant is not accepting applications.');
        $statement=$pdo->prepare('SELECT public_id,payload_hash,submitted_at FROM resume_submissions WHERE organization_id=? AND request_hash=? FOR UPDATE');
        $statement->execute([$organizationId,$data['requestHash']]);
        if ($existing=$statement->fetch()) {
            if (!hash_equals((string)$existing['payload_hash'],$payloadHash)) throw new DomainException('This submission reference has already been used for different information. Reload before starting another application.');
            $pdo->commit();
            return hiring_application_receipt($existing,true);
        }
        $statement=$pdo->prepare("SELECT id,title FROM jobs WHERE organization_id=? AND public_id=? AND status='published'
            AND archived_at IS NULL AND (closes_at IS NULL OR closes_at>=CURDATE()) FOR UPDATE");
        $statement->execute([$organizationId,$data['jobId']]);
        $job=$statement->fetch();
        if (!$job) throw new InvalidArgumentException('This job is no longer accepting applications. Refresh the available positions.');
        $bucket=hash('sha256','hiring-ip:'.$clientAddress);
        $statement=$pdo->prepare("INSERT INTO hiring_intake_limits(organization_id,bucket_hash) VALUES (?,?)
            ON DUPLICATE KEY UPDATE bucket_hash=VALUES(bucket_hash)");
        $statement->execute([$organizationId,$bucket]);
        $statement=$pdo->prepare("UPDATE hiring_intake_limits SET request_count=IF(window_started_at<=DATE_SUB(NOW(6),INTERVAL 1 HOUR),0,request_count),
            window_started_at=IF(window_started_at<=DATE_SUB(NOW(6),INTERVAL 1 HOUR),NOW(6),window_started_at)
            WHERE organization_id=? AND bucket_hash=?");
        $statement->execute([$organizationId,$bucket]);
        $statement=$pdo->prepare('SELECT request_count FROM hiring_intake_limits WHERE organization_id=? AND bucket_hash=? FOR UPDATE');
        $statement->execute([$organizationId,$bucket]);
        if ((int)$statement->fetchColumn()>=10) throw new OverflowException('Too many applications have been submitted from this connection. Please try again later.');
        $statement=$pdo->prepare('UPDATE hiring_intake_limits SET request_count=request_count+1 WHERE organization_id=? AND bucket_hash=?');
        $statement->execute([$organizationId,$bucket]);
        $pdo->prepare("INSERT INTO forms(organization_id,form_type,name,slug,status,version,published_at)
            VALUES (?,'resume','Job application','gelato-hiring-intake','published',1,NOW(6))
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute([$organizationId]);
        $formId=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO form_submissions(form_id,organization_id,submission_type,consent_snapshot_json)
            VALUES (?,?,'resume',?)")->execute([$formId,$organizationId,json_encode(['accurate'=>true,'version'=>'hiring-b1'],JSON_THROW_ON_ERROR)]);
        $submissionId=(int)$pdo->lastInsertId();
        $publicId='application-'.bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO resume_submissions(organization_id,form_submission_id,job_id,first_name,last_name,email,phone,
            position_interest,availability_summary,experience_summary,status,source,public_id,request_hash,payload_hash,answers_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,'new','Public resume form',?,?,?,'{}')")
            ->execute([$organizationId,$submissionId,(int)$job['id'],$data['firstName'],$data['lastName'],$data['email'],$data['phone'],
                $job['title'],$data['availability'],$data['experience'],$publicId,$data['requestHash'],$payloadHash]);
        $resumeId=(int)$pdo->lastInsertId();
        foreach ($attachments as $attachment) {
            $stored=bin2hex(random_bytes(16)).'.'.$attachment['extension'];
            $relative='hiring-documents/'.$organizationId.'/'.$publicId.'/'.$stored;
            $written[]=app_private_storage_write($relative,$attachment['bytes']);
            $pdo->prepare("INSERT INTO files(organization_id,storage_driver,storage_path,original_name,stored_name,mime_type,file_size,checksum_sha256,visibility)
                VALUES (?,'private',?,?,?,?,?,?,'hiring_private')")
                ->execute([$organizationId,$relative,$attachment['name'],$stored,$attachment['mime'],$attachment['size'],$attachment['checksum']]);
            $fileId=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO resume_files(resume_submission_id,file_id,file_type) VALUES (?,?,?)')->execute([$resumeId,$fileId,$attachment['kind']]);
        }
        app_audit($pdo,$organizationId,null,'resume.created','resume',$publicId,null,['jobId'=>$data['jobId'],'status'=>'new','attachments'=>count($attachments)]);
        $statement=$pdo->prepare('SELECT public_id,submitted_at FROM resume_submissions WHERE id=?');
        $statement->execute([$resumeId]);
        $receipt=hiring_application_receipt($statement->fetch());
        $committing=true;
        $pdo->commit();
        return $receipt;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Preserve files after an ambiguous DB commit; a retry resolves the receipt.
        if (!$committing) foreach ($written as $path) @unlink($path);
        throw $error;
    }
}

function hiring_resume_payload(array $row): array
{
    return ['id'=>$row['public_id'] ?: 'legacy-'.(int)$row['id'],'jobId'=>$row['job_public_id']??'',
        'firstName'=>$row['first_name'],'lastName'=>$row['last_name'],'email'=>$row['email'],'phone'=>$row['phone']??'',
        'position'=>$row['position_interest']??'','availability'=>$row['availability_summary']??'','experience'=>$row['experience_summary']??'',
        'status'=>$row['status'],'submittedAt'=>$row['submitted_at'],'updatedAt'=>$row['updated_at'],'source'=>$row['source']??'Public resume form',
        'convertedUserId'=>empty($row['converted_user_id'])?null:'db-user-'.(int)$row['converted_user_id'],
        'answers'=>json_decode((string)($row['answers_json']??'{}'),true)?:[]];
}

function hiring_find_resume(PDO $pdo, int $organizationId, string $publicId, bool $lock = false): ?array
{
    $statement=$pdo->prepare('SELECT r.*,j.public_id AS job_public_id FROM resume_submissions r
        LEFT JOIN jobs j ON j.id=r.job_id AND j.organization_id=r.organization_id
        WHERE r.organization_id=? AND r.public_id=?'.($lock?' FOR UPDATE':''));
    $statement->execute([$organizationId,$publicId]);
    return $statement->fetch() ?: null;
}

function hiring_resume_detail(PDO $pdo, int $organizationId, string $publicId): ?array
{
    $row=hiring_find_resume($pdo,$organizationId,$publicId);
    if (!$row) return null;
    $payload=hiring_resume_payload($row);
    $statement=$pdo->prepare('SELECT n.id,n.note_text,n.created_at,u.display_name FROM resume_notes n
        JOIN users u ON u.id=n.author_user_id WHERE n.resume_submission_id=? ORDER BY n.id DESC');
    $statement->execute([(int)$row['id']]);
    $payload['notes']=array_map(static fn(array $note):array=>['id'=>(string)$note['id'],'text'=>$note['note_text'],
        'author'=>$note['display_name'],'createdAt'=>$note['created_at']],$statement->fetchAll());
    $statement=$pdo->prepare("SELECT f.id,f.original_name,f.mime_type,f.file_size,rf.file_type FROM resume_files rf
        JOIN files f ON f.id=rf.file_id AND f.organization_id=? AND f.visibility='hiring_private' AND f.deleted_at IS NULL
        WHERE rf.resume_submission_id=? ORDER BY rf.id");
    $statement->execute([$organizationId,(int)$row['id']]);
    $payload['attachments']=array_map(static fn(array $file):array=>['id'=>(string)$file['id'],'name'=>$file['original_name'],
        'type'=>$file['mime_type'],'size'=>(int)$file['file_size'],'kind'=>$file['file_type'],
        'url'=>'api/resume-file.php?id='.(int)$file['id'].'&application='.rawurlencode($publicId)],$statement->fetchAll());
    $resumeFile=array_values(array_filter($payload['attachments'],static fn(array $file):bool=>$file['kind']==='resume'))[0]??null;
    $payload['fileName']=$resumeFile['name']??'';
    $payload['fileSize']=$resumeFile['size']??0;
    return $payload;
}

function hiring_review(PDO $pdo, array $actor, array $input): array
{
    require_once __DIR__ . '/user-accounts.php';
    $action=(string)($input['action']??'');
    $permission=['status'=>'resumes.review','note'=>'resumes.add_notes'][$action]??null;
    if (!$permission || !app_has_permission('resumes.view',$actor) || !app_has_permission($permission,$actor)) throw new DomainException('You do not have permission for this hiring action.');
    $publicId=hiring_text($input,'id',48);
    $organizationId=(int)$actor['organization_id'];
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare("SELECT id FROM organizations WHERE id=? AND status='active' FOR UPDATE");
        $lock->execute([$organizationId]);
        if (!$lock->fetchColumn()) throw new OutOfBoundsException('The restaurant was not found.');
        // Account and role writers use this same org -> user lock order. A request
        // that waited for revocation must not keep its earlier permission snapshot.
        accounts_lock_users($pdo,[(int)$actor['id']]);
        $actor=accounts_actor($pdo,$organizationId,(int)$actor['id']);
        if (!app_has_permission('resumes.view',$actor) || !app_has_permission($permission,$actor)) throw new DomainException('You do not have permission for this hiring action.');
        $row=hiring_find_resume($pdo,$organizationId,$publicId,true);
        if (!$row) throw new OutOfBoundsException('The application was not found.');
        if ($action==='status') {
            $status=hiring_text($input,'status',40);
            $allowed=['new','under_review','contacted','interview_scheduled','interviewed','on_hold','offer_pending','hired','rejected','withdrawn','archived'];
            if (!in_array($status,$allowed,true)) throw new InvalidArgumentException('Choose a supported application status.');
            if (($input['expectedStatus']??null)!==$row['status']) throw new UnexpectedValueException('The application changed. Refresh it before updating its status.');
            if ($status!==$row['status']) {
                $pdo->prepare('UPDATE resume_submissions SET status=?,archived_at=IF(?=\'archived\',NOW(6),NULL) WHERE id=? AND organization_id=?')
                    ->execute([$status,$status,(int)$row['id'],$organizationId]);
                $pdo->prepare('INSERT INTO resume_status_history(resume_submission_id,previous_status,new_status,changed_by) VALUES (?,?,?,?)')
                    ->execute([(int)$row['id'],$row['status'],$status,(int)$actor['id']]);
                app_audit($pdo,$organizationId,(int)$actor['id'],'resume.status_changed','resume',$publicId,['status'=>$row['status']],['status'=>$status]);
            }
        } else {
            $note=hiring_text($input,'text',4000);
            $pdo->prepare('INSERT INTO resume_notes(resume_submission_id,author_user_id,note_text) VALUES (?,?,?)')->execute([(int)$row['id'],(int)$actor['id'],$note]);
            app_audit($pdo,$organizationId,(int)$actor['id'],'resume.note_added','resume',$publicId);
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    return hiring_resume_detail($pdo,$organizationId,$publicId) ?? throw new RuntimeException('The saved application could not be loaded.');
}
