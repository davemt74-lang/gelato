<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-knowledge-api.php';

// Session-only in v1. HomeServer must not scrape browser cookies or reuse an
// owner's session. A later scoped application-grant endpoint will authenticate
// machines and map them to an authorized Food Monster principal.
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
    header('Allow: GET');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$user=app_current_user();
if(!$user)app_json_response(['ok'=>false,'message'=>'Authentication required.'],401);
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
try{
    $query=(string)($_GET['q']??'');
    $type=(string)($_GET['type']??'all');
    $limitRaw=(string)($_GET['limit']??'10');
    if(!preg_match('/^(?:[1-9]|1[0-9]|20)$/D',$limitRaw))
        throw new InvalidArgumentException('Limit must be between 1 and 20.');
    $response=fm_kb1_search(app_pdo(),$user,$query,$type,(int)$limitRaw);
    // Only metadata is audited. No raw queries, ingredients or private recipes.
    app_audit(app_pdo(),(int)$user['organization_id'],(int)$user['id'],
        'food_monster.knowledge_api_read','knowledge_search',null,null,
        ['types'=>$response['types'],'count'=>$response['count']]);
    app_json_response($response);
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>'Not authorized for this knowledge.'],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-KB1 knowledge API error: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Knowledge is temporarily unavailable.'],503);
}
