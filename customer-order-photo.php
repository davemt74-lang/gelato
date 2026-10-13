<?php
declare(strict_types=1);
require_once __DIR__.'/includes/public-site.php';
require_once __DIR__.'/includes/customer-account-core.php';
require_once __DIR__.'/includes/glasses-customer-photo-notifications.php';
app_boot_session();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; sandbox");
$pdo=app_pdo();
$ctx=public_site_context($pdo);$org=(int)$ctx['organizationId'];
$account=customer_account_require($pdo,$org);
$profile=customer_account_profile($pdo,$org,(int)$account['id']);
$order=trim((string)($_GET['order']??''));
$shareId=filter_var($_GET['share']??null,FILTER_VALIDATE_INT);
if($order===''||strlen($order)>120||!$shareId||$shareId<1){http_response_code(404);exit;}
$matches=glasses_customer_photo_visible($pdo,$org,(int)$profile['id'],$order);
$match=null;
foreach($matches as $photo)if((int)$photo['shareId']===(int)$shareId){$match=$photo;break;}
if(!$match){http_response_code(404);exit;}
try{
    $record=glasses_vision_training_media_row($pdo,$org,(string)$match['mediaPublicId'],false);
    $mime=(string)$record['mime_type'];
    if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)
        ||(string)$record['status']!=='active'
        ||($record['retention_until']!==null&&strtotime((string)$record['retention_until'])<=time())){
        http_response_code(404);exit;
    }
    $root=realpath(glasses_vision_training_media_storage_root());
    $relative=(string)$record['storage_relative_path'];
    $candidate=$root!==false?realpath($root.'/'.$relative):false;
    if(!$root||!$candidate||!str_starts_with($candidate,$root.DIRECTORY_SEPARATOR)||!is_file($candidate)){
        http_response_code(404);exit;
    }
    header('Content-Type: '.$mime);
    header('Content-Length: '.(string)filesize($candidate));
    header('Content-Disposition: inline; filename="approved-pizza-photo"');
    readfile($candidate);
}catch(Throwable $e){error_log('[customer-photo-view] '.$e->getMessage());http_response_code(404);}
