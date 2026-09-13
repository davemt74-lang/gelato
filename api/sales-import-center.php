<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/sales-import-center-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$canImport=app_has_permission('sales.import',$user);$canProfiles=app_has_permission('sales.import_profiles.manage',$user)||$canImport;
if(!$canImport)app_json_response(['ok'=>false,'message'=>'Sales import permission required.'],403);
if(!sales_import_center_ready($pdo))app_json_response(['ok'=>false,'message'=>'Sales Import Center migration is not installed. Run upgrade.php.'],503);

function sic_locations(PDO $pdo,int $org): array {$q=$pdo->prepare("SELECT id,name FROM locations WHERE organization_id=? AND status='active' ORDER BY name");$q->execute([$org]);return $q->fetchAll();}
function sic_upload(): array {
    if(!isset($_FILES['salesFile'])||!is_array($_FILES['salesFile']))throw new InvalidArgumentException('Choose a CSV sales file.');$f=$_FILES['salesFile'];$err=(int)($f['error']??UPLOAD_ERR_NO_FILE);if($err!==UPLOAD_ERR_OK)throw new InvalidArgumentException('The sales CSV upload failed.');$size=(int)($f['size']??0);if($size<1||$size>15*1024*1024)throw new InvalidArgumentException('Sales CSV files must be 15 MB or smaller.');$tmp=(string)($f['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new InvalidArgumentException('The uploaded sales file is invalid.');$name=(string)($f['name']??'sales.csv');$ext=mb_strtolower((string)pathinfo($name,PATHINFO_EXTENSION),'UTF-8');if(!in_array($ext,['csv','txt'],true))throw new InvalidArgumentException('Upload a .csv or delimited .txt sales file.');return ['tmp'=>$tmp,'name'=>$name,'size'=>$size];
}

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $dashboard=sales_import_center_dashboard($pdo,$org);app_json_response(['ok'=>true,'dashboard'=>$dashboard,'locations'=>sic_locations($pdo,$org),'permissions'=>['profiles'=>$canProfiles],'canonicalFields'=>sales_alias_map()]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}app_verify_request_csrf();$action=(string)($_POST['action']??'preview');$file=sic_upload();
    if($action==='preview'){$preview=sales_import_center_preview($pdo,$org,$file['tmp']);app_audit($pdo,$org,$uid,'sales.import_previewed','sales_import_preview',$preview['headerSignature'],null,['filename'=>$file['name'],'headers'=>count($preview['headers'])]);app_json_response(['ok'=>true,'preview'=>$preview]);}
    if($action==='import'){
        $mapping=json_decode((string)($_POST['mapping']??'{}'),true);if(!is_array($mapping))throw new InvalidArgumentException('Column mapping is invalid.');$options=['profileId'=>$_POST['profileId']??'','profileName'=>$_POST['profileName']??'','locationId'=>$_POST['locationId']??'','cadence'=>$_POST['cadence']??'manual','defaultGranularity'=>$_POST['defaultGranularity']??'daily','defaultServicePeriod'=>$_POST['defaultServicePeriod']??'all','saveProfile'=>$canProfiles&&($_POST['saveProfile']??'')==='1','allowDuplicate'=>($_POST['allowDuplicate']??'')==='1','mapping'=>$mapping];$result=sales_import_center_import($pdo,$org,$uid,$file['tmp'],$file['name'],$options);$import=$result['import'];app_audit($pdo,$org,$uid,'sales.import_center_completed','sales_import_batch',(string)$import['publicId'],null,['filename'=>$file['name'],'accepted'=>$import['accepted'],'rejected'=>$import['rejected'],'periodStart'=>$import['periodStart'],'periodEnd'=>$import['periodEnd'],'profileId'=>$result['profile']['public_id']??null]);app_json_response(['ok'=>true,'result'=>$result,'dashboard'=>sales_import_center_dashboard($pdo,$org),'message'=>'Sales file normalized and imported.'],201);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported Sales Import Center action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
