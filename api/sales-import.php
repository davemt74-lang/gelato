<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/sales-intelligence-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
if(!app_has_permission('sales.import',$user))app_json_response(['ok'=>false,'message'=>'Sales import permission required.'],403);
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
app_verify_request_csrf();
if(!sales_intelligence_ready($pdo))app_json_response(['ok'=>false,'message'=>'Sales Intelligence migration is not installed. Run upgrade.php.'],503);
if(!isset($_FILES['salesFile'])||!is_array($_FILES['salesFile']))app_json_response(['ok'=>false,'message'=>'Choose a CSV sales file.'],422);
$file=$_FILES['salesFile'];$error=(int)($file['error']??UPLOAD_ERR_NO_FILE);if($error!==UPLOAD_ERR_OK)app_json_response(['ok'=>false,'message'=>'The sales CSV upload failed.'],422);
$size=(int)($file['size']??0);if($size<1||$size>15*1024*1024)app_json_response(['ok'=>false,'message'=>'Sales CSV files must be 15 MB or smaller.'],422);
$tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))app_json_response(['ok'=>false,'message'=>'The uploaded sales file is invalid.'],422);
$name=(string)($file['name']??'sales.csv');$extension=mb_strtolower((string)pathinfo($name,PATHINFO_EXTENSION),'UTF-8');if(!in_array($extension,['csv','txt'],true))app_json_response(['ok'=>false,'message'=>'Upload a .csv or delimited .txt sales file.'],422);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);$allowed=['text/plain','text/csv','application/csv','application/vnd.ms-excel','application/octet-stream'];if($mime!==''&&!in_array($mime,$allowed,true))app_json_response(['ok'=>false,'message'=>'The uploaded file does not look like CSV data.'],422);
$locationId=isset($_POST['locationId'])&&$_POST['locationId']!==''?(int)$_POST['locationId']:null;$allowDuplicate=($_POST['allowDuplicate']??'')==='1';
try{$result=sales_import_csv($pdo,$org,$uid,$tmp,$name,$locationId,$allowDuplicate);app_audit($pdo,$org,$uid,'sales.csv_imported','sales_import_batch',(string)$result['publicId'],null,['filename'=>$name,'rows'=>$result['rowCount'],'accepted'=>$result['accepted'],'rejected'=>$result['rejected'],'periodStart'=>$result['periodStart'],'periodEnd'=>$result['periodEnd'],'locationId'=>$locationId]);app_json_response(['ok'=>true,'import'=>$result,'message'=>'Sales CSV imported and normalized.'],201);}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>'The sales CSV could not be imported.'],500);}
