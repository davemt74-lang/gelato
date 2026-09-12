<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/operations-core.php';
$user=app_require_auth();$pdo=app_pdo();$organizationId=(int)$user['organization_id'];$userId=(int)$user['id'];
if(!operations_core_ready($pdo)){http_response_code(503);exit('Operations Core migration is not installed.');}
$proofId=(int)($_GET['id']??0);if($proofId<=0){http_response_code(404);exit('Proof not found.');}
$stmt=$pdo->prepare("SELECT p.id,p.task_id,p.uploaded_by,f.storage_driver,f.storage_path,f.original_name,f.mime_type,f.file_size,t.public_id task_public_id FROM restaurant_task_proofs p INNER JOIN restaurant_tasks t ON t.id=p.task_id AND t.organization_id=p.organization_id INNER JOIN files f ON f.id=p.file_id AND f.organization_id=p.organization_id WHERE p.organization_id=? AND p.id=? AND t.archived_at IS NULL LIMIT 1");$stmt->execute([$organizationId,$proofId]);$proof=$stmt->fetch();if(!$proof){http_response_code(404);exit('Proof not found.');}
$canManage=app_has_permission('tasks.manage',$user);$assigned=$pdo->prepare('SELECT COUNT(*) FROM restaurant_task_assignments WHERE organization_id=? AND task_id=? AND user_id=?');$assigned->execute([$organizationId,(int)$proof['task_id'],$userId]);if(!$canManage&&(int)$assigned->fetchColumn()===0){http_response_code(403);exit('Task proof access denied.');}
if((string)$proof['storage_driver']!=='local_private'||!str_starts_with((string)$proof['mime_type'],'image/')){http_response_code(404);exit('Proof image unavailable.');}
$privateRoot=realpath(dirname(RESTAURANT_APP_ROOT).'/gelato-private-storage');$relative=ltrim((string)$proof['storage_path'],'/');$absolute=$privateRoot!==false?realpath($privateRoot.'/'.$relative):false;if($privateRoot===false||$absolute===false||!str_starts_with($absolute,$privateRoot.DIRECTORY_SEPARATOR)||!is_file($absolute)){http_response_code(404);exit('Proof image unavailable.');}
header('Content-Type: '.(string)$proof['mime_type']);header('Content-Length: '.(string)filesize($absolute));header('Content-Disposition: inline; filename="'.addcslashes(basename((string)$proof['original_name']),"\"\\").'"');header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; sandbox');readfile($absolute);exit;
