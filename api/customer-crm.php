<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/customer-crm-core.php';

$user=app_require_auth();$pdo=app_pdo();$org=(int)$user['organization_id'];$uid=(int)$user['id'];
$canView=app_has_permission('crm.view',$user);$canManage=app_has_permission('crm.manage',$user);$canConsent=app_has_permission('crm.consent.manage',$user);$canPosLink=app_has_permission('crm.pos_link',$user);
if(!($canView||$canManage||$canConsent||$canPosLink))app_json_response(['ok'=>false,'message'=>'Customer CRM permission required.'],403);
if(!crm_ready($pdo))app_json_response(['ok'=>false,'message'=>'Customer CRM migration is not installed. Run upgrade.php.'],503);

try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $action=(string)($_GET['action']??'search');
        if($action==='search'){
            if(!($canView||$canPosLink))app_json_response(['ok'=>false,'message'=>'Customer lookup permission required.'],403);
            $query=mb_substr(trim((string)($_GET['q']??'')),0,240,'UTF-8');$minimal=!$canView||($_GET['scope']??'')==='pos';
            app_json_response(['ok'=>true,'customers'=>crm_search($pdo,$org,$query,(int)($_GET['limit']??50),$minimal),'permissions'=>['view'=>$canView,'manage'=>$canManage,'consent'=>$canConsent,'posLink'=>$canPosLink]]);
        }
        if($action==='customer'){
            if(!$canView)app_json_response(['ok'=>false,'message'=>'Customer CRM view permission required.'],403);
            $public=trim((string)($_GET['customer']??''));if($public==='')throw new InvalidArgumentException('Choose a customer.');
            app_json_response(['ok'=>true,'customer'=>crm_profile($pdo,$org,$public),'permissions'=>['view'=>$canView,'manage'=>$canManage,'consent'=>$canConsent,'posLink'=>$canPosLink]]);
        }
        app_json_response(['ok'=>false,'message'=>'Unsupported Customer CRM action.'],422);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
    $input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');$public=trim((string)($input['customerPublicId']??$input['publicId']??''));
    if($action==='customer.save'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Customer CRM management permission required.'],403);
        $saved=crm_customer_save($pdo,$org,$input,$uid,(string)($input['source']??'manual'));$profile=crm_profile($pdo,$org,(string)$saved['public_id']);
        app_audit($pdo,$org,$uid,$public!==''?'crm.customer_updated':'crm.customer_created','crm_customer',$profile['publicId'],null,['displayName'=>$profile['displayName'],'source'=>$profile['source']]);
        app_json_response(['ok'=>true,'customer'=>$profile],$public!==''?200:201);
    }
    if($public==='')throw new InvalidArgumentException('Choose a customer.');
    if($action==='customer.archive'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Customer CRM management permission required.'],403);$row=crm_customer_archive($pdo,$org,$public,$uid);app_audit($pdo,$org,$uid,'crm.customer_archived','crm_customer',$public);app_json_response(['ok'=>true,'customer'=>['publicId'=>$public,'status'=>$row['status']]]);
    }
    if($action==='note.add'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Customer CRM management permission required.'],403);$notes=crm_note_add($pdo,$org,$public,(string)($input['note']??''),$uid);app_audit($pdo,$org,$uid,'crm.note_added','crm_customer',$public);app_json_response(['ok'=>true,'notes'=>$notes],201);
    }
    if($action==='tag.add'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Customer CRM management permission required.'],403);$tags=crm_tag_add($pdo,$org,$public,(string)($input['name']??''),$uid);app_audit($pdo,$org,$uid,'crm.tag_added','crm_customer',$public,null,['name'=>mb_substr(trim((string)($input['name']??'')),0,120,'UTF-8')]);app_json_response(['ok'=>true,'tags'=>$tags],201);
    }
    if($action==='tag.remove'){
        if(!$canManage)app_json_response(['ok'=>false,'message'=>'Customer CRM management permission required.'],403);$tags=crm_tag_remove($pdo,$org,$public,(int)($input['tagId']??0));app_audit($pdo,$org,$uid,'crm.tag_removed','crm_customer',$public,null,['tagId'=>(int)($input['tagId']??0)]);app_json_response(['ok'=>true,'tags'=>$tags]);
    }
    if($action==='consent.set'){
        if(!$canConsent)app_json_response(['ok'=>false,'message'=>'Customer consent management permission required.'],403);$channel=(string)($input['channel']??'');$status=(string)($input['status']??'');$source=(string)($input['source']??'manual');$current=crm_consent_set($pdo,$org,$public,$channel,$status,$source,(string)($input['evidenceNote']??''),$uid);app_audit($pdo,$org,$uid,'crm.consent_recorded','crm_customer',$public,null,['channel'=>$channel,'status'=>$status,'source'=>$source]);app_json_response(['ok'=>true,'consents'=>$current],201);
    }
    if($action==='pos.attach'||$action==='pos.detach'){
        if(!$canPosLink)app_json_response(['ok'=>false,'message'=>'POS customer-link permission required.'],403);$check=trim((string)($input['checkPublicId']??''));if($check==='')throw new InvalidArgumentException('Choose an open POS check.');$attached=crm_attach_check($pdo,$org,$check,$action==='pos.detach'?null:$public);app_audit($pdo,$org,$uid,$action==='pos.detach'?'crm.pos_detached':'crm.pos_attached','pos_check',$check,null,['customerPublicId'=>$action==='pos.detach'?null:$public]);app_json_response(['ok'=>true,'customer'=>$attached]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported Customer CRM action.'],422);
}catch(InvalidArgumentException $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){app_json_response(['ok'=>false,'message'=>$e->getMessage()],500);}
