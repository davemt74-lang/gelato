<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring-core.php';
$user=app_current_user();
if (!$user) app_json_response(['ok'=>false,'message'=>'Sign in to review applications.'],401);
if (!app_has_permission('resumes.view',$user)) app_json_response(['ok'=>false,'message'=>'You do not have permission to view applications.'],403);
$pdo=app_pdo(); $organizationId=(int)$user['organization_id'];
try {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $id=trim((string)($_GET['id']??''));
        if ($id!=='') {
            $resume=hiring_resume_detail($pdo,$organizationId,$id);
            if (!$resume) app_json_response(['ok'=>false,'message'=>'The application was not found.'],404);
            app_json_response(['ok'=>true,'resume'=>$resume,'csrfToken'=>app_csrf_token()]);
        }
        $before=max(0,(int)($_GET['before']??0));
        $sql='SELECT r.*,j.public_id AS job_public_id FROM resume_submissions r
            LEFT JOIN jobs j ON j.id=r.job_id AND j.organization_id=r.organization_id
            WHERE r.organization_id=?'.($before>0?' AND r.id<?':'').' ORDER BY r.id DESC LIMIT 101';
        $statement=$pdo->prepare($sql);
        $statement->execute($before>0?[$organizationId,$before]:[$organizationId]);
        $rows=$statement->fetchAll(); $more=count($rows)>100;
        if ($more) array_pop($rows);
        $statement=$pdo->prepare('SELECT status,COUNT(*) AS total FROM resume_submissions WHERE organization_id=? GROUP BY status');
        $statement->execute([$organizationId]);
        $counts=[]; foreach ($statement->fetchAll() as $row) $counts[$row['status']]=(int)$row['total'];
        app_json_response(['ok'=>true,'resumes'=>array_map('hiring_resume_payload',$rows),
            'nextCursor'=>$more?(int)$rows[count($rows)-1]['id']:null,'totalCount'=>array_sum($counts),'counts'=>$counts,'csrfToken'=>app_csrf_token()]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: GET, POST'); app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405); }
    $input=app_json_input(); app_verify_request_csrf($input);
    $resume=hiring_review($pdo,$user,$input);
    app_json_response(['ok'=>true,'message'=>'Application saved.','resume'=>$resume]);
} catch (DomainException $error) { app_json_response(['ok'=>false,'message'=>$error->getMessage()],403);
} catch (OutOfBoundsException $error) { app_json_response(['ok'=>false,'message'=>$error->getMessage()],404);
} catch (UnexpectedValueException $error) { app_json_response(['ok'=>false,'message'=>$error->getMessage()],409);
} catch (InvalidArgumentException $error) { app_json_response(['ok'=>false,'message'=>$error->getMessage()],422);
} catch (Throwable $error) {
    error_log('Hiring review failed: '.get_class($error));
    app_json_response(['ok'=>false,'message'=>'The application could not be saved. Please try again.'],503);
}
