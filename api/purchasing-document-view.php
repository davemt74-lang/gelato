<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/purchasing-core.php';
$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];
if(!purchasing_documents_ready($pdo)){http_response_code(503);exit('Purchasing document migration is not installed.');}
if(!app_has_permission('purchasing.view',$user)&&!app_has_permission('receiving.view',$user)){http_response_code(403);exit('Purchasing document access denied.');}
$id=trim((string)($_GET['id']??''));if($id===''){http_response_code(404);exit('Document not found.');}
$q=$pdo->prepare("SELECT d.public_id,d.document_type,d.purchase_order_id,d.goods_receipt_id,f.storage_driver,f.storage_path,f.original_name,f.mime_type,f.file_size FROM purchasing_documents d INNER JOIN files f ON f.id=d.file_id AND f.organization_id=d.organization_id INNER JOIN purchase_orders po ON po.id=d.purchase_order_id AND po.organization_id=d.organization_id WHERE d.organization_id=? AND d.public_id=? AND po.archived_at IS NULL LIMIT 1");$q->execute([$org,$id]);$doc=$q->fetch();if(!$doc){http_response_code(404);exit('Document not found.');}
if((string)$doc['storage_driver']!=='local_private'){http_response_code(404);exit('Document unavailable.');}$allowed=['image/jpeg','image/png','image/webp','application/pdf'];if(!in_array((string)$doc['mime_type'],$allowed,true)){http_response_code(404);exit('Document unavailable.');}
$privateRoot=realpath(dirname(RESTAURANT_APP_ROOT).'/gelato-private-storage');$relative=ltrim((string)$doc['storage_path'],'/');$absolute=$privateRoot!==false?realpath($privateRoot.'/'.$relative):false;if($privateRoot===false||$absolute===false||!str_starts_with($absolute,$privateRoot.DIRECTORY_SEPARATOR)||!is_file($absolute)){http_response_code(404);exit('Document unavailable.');}
$name=addcslashes(basename((string)$doc['original_name']),"\"\\");header('Content-Type: '.(string)$doc['mime_type']);header('Content-Length: '.(string)filesize($absolute));header('Content-Disposition: inline; filename="'.$name.'"');header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; object-src 'self'; frame-ancestors 'self'; sandbox");readfile($absolute);exit;
