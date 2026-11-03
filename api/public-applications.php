<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/hiring-core.php';
header('Referrer-Policy: no-referrer');
try {
    $pdo=app_pdo();
    $organizationId=hiring_public_organization_id($pdo);
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        app_json_response(['ok'=>true,'csrfToken'=>app_csrf_token(),'jobs'=>hiring_public_jobs($pdo,$organizationId),
            'idempotencyKey'=>bin2hex(random_bytes(16))]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') {
        header('Allow: GET, POST'); app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    }
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>26*1024*1024) app_json_response(['ok'=>false,'message'=>'The application attachments must total no more than 25 MB.'],413);
    app_verify_request_csrf($_POST);
    $receipt=hiring_submit($pdo,$organizationId,$_POST,hiring_http_attachments($_FILES),(string)($_SERVER['REMOTE_ADDR']??''));
    app_json_response(['ok'=>true,'message'=>'Your application was received by the restaurant.','receipt'=>$receipt],$receipt['replayed']?200:201);
} catch (InvalidArgumentException $error) {
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],422);
} catch (DomainException $error) {
    app_json_response(['ok'=>false,'message'=>$error->getMessage()],409);
} catch (OverflowException $error) {
    header('Retry-After: 3600'); app_json_response(['ok'=>false,'message'=>$error->getMessage()],429);
} catch (Throwable $error) {
    error_log('Hiring intake failed: '.get_class($error));
    app_json_response(['ok'=>false,'message'=>'Your application could not be confirmed. Keep this page open and try again; your submission reference will prevent a duplicate.'],503);
}
