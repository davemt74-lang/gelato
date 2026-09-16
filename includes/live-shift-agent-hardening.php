<?php
declare(strict_types=1);

require_once __DIR__.'/live-shift-agent-core.php';
require_once __DIR__.'/agent-brain-orchestrator.php';
require_once __DIR__.'/live-shift-brain.php';
require_once __DIR__.'/admin-control-core.php';

function live_shift_is_broad_manager_request(string $message): bool
{
    $text=live_shift_norm($message);
    return preg_match('/\b(what needs attention right now|what should i do right now|what should we do right now|what is happening right now|what\x27s happening right now|restaurant overview|operating snapshot)\b/u',$text)===1;
}

function live_shift_check_unpaid(PDO $pdo,int $org,string $checkPublicId): void
{
    $base=pos_check_base($pdo,$org,$checkPublicId,false);
    pos_item_action_assert_unpaid($pdo,$org,(int)$base['id']);
}

function live_shift_hardened_handle(PDO $pdo,array $user,array $pageContext,string $message): array
{
    $module=(string)($pageContext['module']??'');
    if(!in_array($module,['pos','table_service','kds'],true)&&admin_dashboard_allowed($user)&&live_shift_is_broad_manager_request($message)){
        $snapshot=live_shift_merge_brain_snapshot($pdo,$user,agent_brain_orchestration_snapshot($pdo,$user,null));
        $result=agent_brain_orchestration_answer($snapshot,$message);
        return ['answer'=>$result['answer'],'data'=>$result['data'],'sources'=>$result['sources'],'snapshot'=>$snapshot,'locationId'=>$snapshot['scope']['locationId']??null,'node'=>'brain'];
    }

    $locationId=live_shift_location($pdo,$user,$pageContext);
    $snapshot=live_shift_snapshot($pdo,$user,$locationId);
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$text=live_shift_clean($message,1600);$lower=mb_strtolower($text,'UTF-8');

    if(preg_match('/\b(?:assign|transfer|change)\s+(?:the\s+)?server(?:\s+(?:for|on)\s+(?:this\s+)?(?:check|table|order))?\s+(?:to\s+)?(.+)$/iu',$text,$m)){
        if(!app_has_permission('table_service.use',$user)||!operational_location_allowed($pdo,$user,'table_service.use',$locationId))throw new DomainException('Table Service operating permission is required for server assignment.');
        $check=live_shift_check($snapshot,$pageContext,$text);$staff=live_shift_staff($snapshot,$m[1]);
        $updated=table_service_assign_server($pdo,$org,(string)$check['publicId'],(int)$staff['id'],$uid);
        app_audit($pdo,$org,$uid,'agent.live_shift.server_assigned','pos_check',(string)$check['publicId'],null,['sourceNode'=>'live_shift','locationId'=>$locationId,'serverUserId'=>$staff['id']]);
        return ['answer'=>'Assigned '.$staff['name'].' to '.$check['checkNumber'].'.','sources'=>['Table Service staff assignment'],'check'=>$updated,'locationId'=>$locationId,'node'=>'live_shift'];
    }

    $requiresUnpaid=preg_match('/\b(?:send|fire|hold|add|set|change)\b/u',$lower)===1
        &&preg_match('/\b(?:kitchen|check|ticket|order|items?|note|drinks|starters|mains|dessert|other)\b/u',$lower)===1;
    if($requiresUnpaid){
        try{$check=live_shift_check($snapshot,$pageContext,$text);live_shift_check_unpaid($pdo,$org,(string)$check['publicId']);}
        catch(InvalidArgumentException $e){
            if(str_contains($e->getMessage(),'captured tender'))throw $e;
        }
    }

    return live_shift_handle($pdo,$user,$pageContext,$message);
}
