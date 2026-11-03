<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/hiring-core.php';

function hiring_fixture_seed(PDO $pdo): array
{
    $suffix=bin2hex(random_bytes(6));
    $organizationId=hiring_public_organization_id($pdo);
    $pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Hiring outsider '.$suffix]);
    $otherOrg=(int)$pdo->lastInsertId();
    $password='Hiring-Contract!42'; $users=[];
    foreach (['manager','employee','outsider','reader'] as $kind) {
        $org=$kind==='outsider'?$otherOrg:$organizationId;
        $email='hiring-'.$kind.'-'.$suffix.'@example.test';
        $pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status,email_verified_at,password_changed_at)
            VALUES (?,?,?,'Hiring',?,'active',NOW(6),NOW(6))")->execute([$email,app_password_hash($password),$kind,$kind.' Hiring']);
        $uid=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
        $membership=(int)$pdo->lastInsertId();
        $owner=in_array($kind,['manager','outsider'],true)?1:0;
        $pdo->prepare('INSERT INTO roles(organization_id,name,slug,is_system_role,is_owner_role) VALUES (?,?,?,?,?)')
            ->execute([$org,'Hiring '.$kind.' '.$suffix,'hiring-'.$kind.'-'.$suffix,$owner,$owner]);
        $role=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_roles(membership_id,role_id,assigned_by) VALUES (?,?,?)')->execute([$membership,$role,$uid]);
        if ($kind==='reader') $pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE permission_key='resumes.view'")->execute([$role]);
        $users[$kind]=['id'=>$uid,'email'=>$email,'organization_id'=>$org,'permissions'=>$owner?['*']:($kind==='reader'?['resumes.view']:[])];
    }
    $jobs=[];
    foreach (['open','closed','foreign'] as $kind) {
        $org=$kind==='foreign'?$otherOrg:$organizationId;
        $id='hiring-'.$kind.'-'.$suffix;
        $pdo->prepare('INSERT INTO jobs(organization_id,public_id,slug,title,status) VALUES (?,?,?,?,?)')
            ->execute([$org,$id,$id,'Hiring '.$kind.' '.$suffix,$kind==='closed'?'draft':'published']);
        $jobs[$kind]=$id;
    }
    return ['organizationId'=>$organizationId,'otherOrganizationId'=>$otherOrg,'users'=>$users,'jobs'=>$jobs,'password'=>$password,'suffix'=>$suffix];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    $pdo=app_pdo(); $mode=$argv[1]??'seed';
    if ($mode==='seed') { echo json_encode(hiring_fixture_seed($pdo),JSON_THROW_ON_ERROR); exit; }
    $arg=json_decode(base64_decode($argv[2]??''),true)?:[];
    if ($mode==='inspect') {
        $statement=$pdo->prepare('SELECT * FROM resume_submissions WHERE organization_id=? AND email=? ORDER BY id');
        $statement->execute([(int)$arg['organizationId'],$arg['email']]);$rows=$statement->fetchAll();
        $attachments=[];foreach($rows as $row){$s=$pdo->prepare('SELECT f.* FROM resume_files rf JOIN files f ON f.id=rf.file_id WHERE resume_submission_id=?');$s->execute([(int)$row['id']]);$attachments=array_merge($attachments,$s->fetchAll());}
        echo json_encode(['rows'=>$rows,'attachments'=>$attachments],JSON_THROW_ON_ERROR);exit;
    }
    if ($mode==='fail-writes') {
        $pdo->exec('DROP TRIGGER IF EXISTS hardening_hiring_fail');
        $pdo->exec("CREATE TRIGGER hardening_hiring_fail BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.entity_type='resume' AND NEW.action='resume.created' AND NEW.organization_id=".(int)$arg['organizationId']." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Hiring test rollback'; END IF; END");
        echo '{}';exit;
    }
    if ($mode==='restore-writes') { $pdo->exec('DROP TRIGGER IF EXISTS hardening_hiring_fail');echo '{}';exit; }
    if ($mode==='private-count') {
        $directory=app_private_storage_root().'/hiring-documents/'.(int)$arg['organizationId'];$count=0;
        if(is_dir($directory))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$count++;
        echo json_encode(['count'=>$count]);exit;
    }
    throw new InvalidArgumentException('Unknown hiring fixture mode.');
}
