<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/food-monster-vp3-link.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Origin: none');
if(!fm_kb2_https($_SERVER)){
    app_json_response(['ok'=>false,'message'=>'HTTPS is required for VP3 pairing and access.'],403);
}
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if($method==='GET'){
    // Public discovery discloses ONLY the local installation identity and
    // protocol. It does not grant knowledge access or expose service URLs.
    try{
        app_json_response(['ok'=>true,'schema'=>'food-monster-vp3-v1',
            'instanceId'=>fm_kb2_instance(app_pdo()),
            'capabilities'=>['knowledge.search'],
            'pairing'=>'admin-issued-one-time-code',
            'authentication'=>'bearer']);
    }catch(Throwable $e){
        error_log('VP3 discovery failed: '.get_class($e));
        app_json_response(['ok'=>false,'message'=>'Connector is not installed. Run upgrade.php.'],503);
    }
}
if($method!=='POST'){
    header('Allow: GET, POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length>8192)app_json_response(['ok'=>false,'message'=>'Request is too large.'],413);
$input=app_json_input();
$action=(string)($input['action']??'');
try{
    if($action==='exchange'){
        // No anonymous endpoint can mint a code. Only a logged-in Gelato
        // owner can issue one via vp3-connections.php with a CSRF check.
        $code=(string)($input['code']??'');
        app_json_response(fm_kb2_redeem(app_pdo(),$code));
    }
    if($action==='revoke'){
        // Holder may destroy only its own bearer credential. This does not
        // require a still-active owner role, unlike reading private data.
        // No caller-supplied owner ID, connection ID, or scope is trusted.
        app_json_response(fm_kb4b_revoke_self(app_pdo(),fm_kb2_bearer($_SERVER)));
    }
    if($action==='search'){
        $token=fm_kb2_bearer($_SERVER);
        $grant=fm_kb2_grant(app_pdo(),$token);
        $q=(string)($input['q']??'');
        $type=(string)($input['type']??'all');
        $limit=$input['limit']??10;
        if(!is_int($limit))throw new InvalidArgumentException('Limit must be an integer.');
        $result=fm_kb1_search(app_pdo(),$grant['user'],$q,$type,$limit);
        app_audit(app_pdo(),(int)$grant['user']['organization_id'],
            (int)$grant['user']['id'],'vp3.knowledge_read','vp3_connection',
            $grant['connectionId'],null,
            ['types'=>$result['types'],'count'=>$result['count']]);
        app_json_response($result);
    }
    throw new InvalidArgumentException('Unknown connector action.');
}catch(DomainException $e){
    app_json_response(['ok'=>false,'message'=>'Connection is not authorized or is expired.'],403);
}catch(InvalidArgumentException $e){
    app_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('FM-KB2 connector failure: '.get_class($e));
    app_json_response(['ok'=>false,'message'=>'Connector temporarily unavailable.'],503);
}
