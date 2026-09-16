<?php
declare(strict_types=1);

require_once __DIR__.'/equipment-brain.php';
require_once __DIR__.'/agent-confirmation-core.php';

final class EquipmentAgentPermissionException extends RuntimeException {}

function equipment_agent_require_read(array $user): void
{
    if(!app_has_permission('equipment.view',$user)||!app_has_permission('agent.equipment_skills',$user)){
        throw new EquipmentAgentPermissionException('Equipment Agent access is not permitted.');
    }
}

function equipment_agent_require_edit(array $user): void
{
    equipment_agent_require_read($user);
    if(!app_has_permission('equipment.edit',$user))throw new EquipmentAgentPermissionException('Equipment changes require equipment.edit permission.');
}

function equipment_agent_require_service(array $user): void
{
    equipment_agent_require_read($user);
    if(!app_has_permission('equipment.service',$user))throw new EquipmentAgentPermissionException('Service history changes require equipment.service permission.');
}

function equipment_agent_ready(PDO $pdo): bool
{
    foreach(['equipment_assets','equipment_service_events','equipment_service_contacts','equipment_asset_service_contacts'] as $table){
        if(!equipment_brain_table_ready($pdo,$table))return false;
    }
    return true;
}

function equipment_agent_asset_by_public_id(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): ?array
{
    $publicId=trim($publicId);
    if($publicId==='')return null;
    $sql="SELECT * FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();return $row?:null;
}

function equipment_agent_context_asset(PDO $pdo,int $org,array $context,bool $forUpdate=false): ?array
{
    $publicId=trim((string)($context['selectedAssetPublicId']??$context['assetPublicId']??''));
    return $publicId!==''?equipment_agent_asset_by_public_id($pdo,$org,$publicId,$forUpdate):null;
}

function equipment_agent_find_asset(PDO $pdo,int $org,string $message,array $context=[]): ?array
{
    $selected=equipment_agent_context_asset($pdo,$org,$context);
    if($selected)return $selected;
    $text=mb_strtolower(trim($message),'UTF-8');
    $q=$pdo->prepare("SELECT * FROM equipment_assets WHERE organization_id=? AND archived_at IS NULL ORDER BY FIELD(criticality,'critical','high','medium','low'),name");
    $q->execute([$org]);$rows=$q->fetchAll();
    $best=null;$bestScore=0;
    foreach($rows as $row){
        $candidates=array_filter([(string)$row['name'],(string)($row['asset_tag']??''),(string)($row['model']??''),(string)($row['brand']??''),(string)$row['public_id']]);
        $score=0;
        foreach($candidates as $candidate){
            $candidate=mb_strtolower(trim($candidate),'UTF-8');
            if($candidate!==''&&str_contains($text,$candidate))$score=max($score,mb_strlen($candidate,'UTF-8'));
        }
        if($score>$bestScore){$best=$row;$bestScore=$score;}
    }
    return $best;
}

function equipment_agent_attention(PDO $pdo,int $org,int $limit=12): array
{
    $limit=max(1,min(30,$limit));
    $q=$pdo->prepare("SELECT public_id,name,asset_type,brand,model,location_name,operational_status,condition_status,criticality,maintenance_required,last_service_on,next_service_on,warranty_expires_on,replacement_cost,updated_at
        FROM equipment_assets
        WHERE organization_id=? AND archived_at IS NULL
          AND (operational_status IN ('maintenance','out_of_service') OR condition_status IN ('poor','replace') OR (maintenance_required=1 AND next_service_on IS NOT NULL AND next_service_on<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)))
        ORDER BY (operational_status='out_of_service') DESC,(condition_status='replace') DESC,FIELD(criticality,'critical','high','medium','low'),COALESCE(next_service_on,'9999-12-31'),name LIMIT {$limit}");
    $q->execute([$org]);return $q->fetchAll();
}

function equipment_agent_asset_data(PDO $pdo,int $org,array $asset): array
{
    return [
        'publicId'=>(string)$asset['public_id'],'name'=>(string)$asset['name'],'assetType'=>(string)($asset['asset_type']??''),
        'brand'=>(string)($asset['brand']??''),'model'=>(string)($asset['model']??''),'assetTag'=>(string)($asset['asset_tag']??''),
        'location'=>(string)($asset['location_name']??''),'status'=>(string)($asset['operational_status']??''),'condition'=>(string)($asset['condition_status']??''),
        'criticality'=>(string)($asset['criticality']??''),'lastServiceOn'=>$asset['last_service_on']?:null,'nextServiceOn'=>$asset['next_service_on']?:null,
        'warrantyExpiresOn'=>$asset['warranty_expires_on']?:null,'replacementCost'=>$asset['replacement_cost']!==null?(float)$asset['replacement_cost']:null,
        'manualUrl'=>$asset['manual_url']?:null,'maintenanceNotes'=>$asset['maintenance_notes']?:null,'safetyNotes'=>$asset['safety_notes']?:null,
        'partsConsumables'=>$asset['parts_consumables']?:null,'updatedAt'=>(string)$asset['updated_at'],
    ];
}

function equipment_agent_propose(PDO $pdo,array $user,array $asset,string $type,array $payload,string $answer,string $skill='equipment.action_proposal'): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];
    $proposal=gac_pending_store('equipment',$org,$uid,$type,$payload+[
        'assetPublicId'=>(string)$asset['public_id'],'assetName'=>(string)$asset['name'],'expectedUpdatedAt'=>(string)$asset['updated_at']
    ],$answer);
    app_audit($pdo,$org,$uid,'equipment.agent_action_proposed','equipment_agent_proposal',(string)$proposal['id'],null,['type'=>$type,'assetPublicId'=>$asset['public_id']]);
    return gac_proposal_result($proposal,$skill,'Equipment + Maintenance Agent');
}

function equipment_agent_assert_fresh(array $asset,array $payload): void
{
    if((string)$asset['updated_at']!==(string)($payload['expectedUpdatedAt']??'')){
        throw new InvalidArgumentException('This equipment record changed after the proposal was created. Refresh it and ask again before confirming.');
    }
}

function equipment_agent_execute_pending(PDO $pdo,array $user,array $proposal): array
{
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$type=(string)($proposal['type']??'');$payload=is_array($proposal['payload']??null)?$proposal['payload']:[];
    if((int)($proposal['organization_id']??0)!==$org||(int)($proposal['user_id']??0)!==$uid)throw new EquipmentAgentPermissionException('This Equipment proposal belongs to another session.');
    $pdo->beginTransaction();
    try{
        $asset=equipment_agent_asset_by_public_id($pdo,$org,(string)($payload['assetPublicId']??''),true);
        if(!$asset)throw new InvalidArgumentException('The equipment asset no longer exists.');
        equipment_agent_assert_fresh($asset,$payload);
        if($type==='set_status'){
            equipment_agent_require_edit($user);
            $status=(string)($payload['newStatus']??'');
            if(!in_array($status,['active','maintenance','out_of_service'],true))throw new InvalidArgumentException('Unsupported equipment status.');
            $pdo->prepare("UPDATE equipment_assets SET operational_status=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")
                ->execute([$status,$uid,(int)$asset['id'],$org]);
            equipment_brain_sync_asset($pdo,$org,(int)$asset['id'],$uid);
            app_audit($pdo,$org,$uid,'equipment.agent_status_changed','equipment_asset',(string)$asset['public_id'],(string)$asset['name'],['from'=>$asset['operational_status'],'to'=>$status]);
            $pdo->commit();
            return ['skill'=>'equipment.action_confirmed','answer'=>"Confirmed. {$asset['name']} is now marked ".str_replace('_',' ',$status).'.','data'=>['assetPublicId'=>$asset['public_id'],'status'=>$status],'sources'=>['Equipment Catalog','Equipment + Maintenance Agent']];
        }
        if($type==='schedule_service'){
            equipment_agent_require_edit($user);
            $date=(string)($payload['nextServiceOn']??'');
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('The proposed service date is invalid.');
            $pdo->prepare("UPDATE equipment_assets SET maintenance_required=1,next_service_on=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")
                ->execute([$date,$uid,(int)$asset['id'],$org]);
            equipment_brain_sync_asset($pdo,$org,(int)$asset['id'],$uid);
            app_audit($pdo,$org,$uid,'equipment.agent_service_scheduled','equipment_asset',(string)$asset['public_id'],(string)$asset['name'],['nextServiceOn'=>$date]);
            $pdo->commit();
            return ['skill'=>'equipment.action_confirmed','answer'=>"Confirmed. {$asset['name']} is scheduled for maintenance on {$date}.",'data'=>['assetPublicId'=>$asset['public_id'],'nextServiceOn'=>$date],'sources'=>['Equipment Catalog','Equipment + Maintenance Agent']];
        }
        if($type==='record_service'){
            equipment_agent_require_service($user);
            $eventType=(string)($payload['eventType']??'maintenance');
            if(!in_array($eventType,['maintenance','repair','inspection','cleaning'],true))throw new InvalidArgumentException('Unsupported service event type.');
            $description=trim((string)($payload['description']??''));if($description==='')throw new InvalidArgumentException('A service description is required.');
            $servicedOn=(string)($payload['servicedOn']??date('Y-m-d'));
            $nextDue=trim((string)($payload['nextDueOn']??''));$nextDue=$nextDue!==''?$nextDue:null;
            $public='svc-'.bin2hex(random_bytes(10));
            $pdo->prepare("INSERT INTO equipment_service_events (organization_id,equipment_asset_id,public_id,event_type,serviced_on,description,status,next_due_on,created_by,updated_by) VALUES (?,?,?,?,?,?,'completed',?,?,?)")
                ->execute([$org,(int)$asset['id'],$public,$eventType,$servicedOn,mb_substr($description,0,1000,'UTF-8'),$nextDue,$uid,$uid]);
            $sql="UPDATE equipment_assets SET last_service_on=?".($nextDue!==null?',next_service_on=?':'').",updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?";
            $params=[$servicedOn];if($nextDue!==null)$params[]=$nextDue;$params[]=$uid;$params[]=(int)$asset['id'];$params[]=$org;
            $pdo->prepare($sql)->execute($params);
            equipment_brain_sync_asset($pdo,$org,(int)$asset['id'],$uid);
            app_audit($pdo,$org,$uid,'equipment.agent_service_recorded','equipment_service_event',$public,(string)$asset['name'],['eventType'=>$eventType,'servicedOn'=>$servicedOn,'nextDueOn'=>$nextDue]);
            $pdo->commit();
            return ['skill'=>'equipment.action_confirmed','answer'=>"Confirmed. I recorded the {$eventType} service for {$asset['name']} on {$servicedOn}.",'data'=>['assetPublicId'=>$asset['public_id'],'serviceEventPublicId'=>$public,'servicedOn'=>$servicedOn,'nextDueOn'=>$nextDue],'sources'=>['Equipment Service History','Equipment + Maintenance Agent']];
        }
        throw new InvalidArgumentException('The pending Equipment action is no longer supported.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function equipment_agent_extract_date(string $text): ?string
{
    if(preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/',$text,$m)){
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$m[1]);if($d&&$d->format('Y-m-d')===$m[1])return $m[1];
    }
    $lower=mb_strtolower($text,'UTF-8');
    if(preg_match('/\btomorrow\b/u',$lower))return date('Y-m-d',strtotime('+1 day'));
    if(preg_match('/\btoday\b/u',$lower))return date('Y-m-d');
    return null;
}

function equipment_agent_handle(PDO $pdo,array $user,array $input): array
{
    equipment_agent_require_read($user);if(!equipment_agent_ready($pdo))throw new RuntimeException('Equipment data is not installed. Run upgrade.php.');
    $org=(int)$user['organization_id'];$uid=(int)$user['id'];$message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>2400)throw new InvalidArgumentException('Ask Gelato an equipment question no longer than 2,400 characters.');
    $context=is_array($input['pageContext']??null)?$input['pageContext']:[];$pending=gac_pending_get('equipment',$org,$uid);
    if(gac_is_confirm($message)){
        if(!$pending)throw new InvalidArgumentException('There is no pending Equipment action to confirm.');
        try{$result=equipment_agent_execute_pending($pdo,$user,$pending);}catch(Throwable $e){gac_pending_clear('equipment',$org,$uid);throw $e;}
        gac_pending_clear('equipment',$org,$uid);app_audit($pdo,$org,$uid,'equipment.agent_action_confirmed','equipment_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']??null]);return ['ok'=>true]+$result;
    }
    if(gac_is_cancel($message)&&$pending){
        gac_pending_clear('equipment',$org,$uid);app_audit($pdo,$org,$uid,'equipment.agent_action_discarded','equipment_agent_proposal',(string)$pending['id'],null,['type'=>$pending['type']??null]);
        return ['ok'=>true,'skill'=>'equipment.action_cancelled','answer'=>'Cancelled. I did not change the equipment record or service history.','data'=>['cancelledProposal'=>$pending['id']],'sources'=>['Equipment + Maintenance Agent']];
    }

    $lower=mb_strtolower($message,'UTF-8');$asset=equipment_agent_find_asset($pdo,$org,$message,$context);

    $status=null;
    if(preg_match('/\b(out of service|take .*offline|mark .*offline)\b/u',$lower))$status='out_of_service';
    elseif(preg_match('/\b(maintenance mode|mark .*maintenance|set .*maintenance)\b/u',$lower))$status='maintenance';
    elseif(preg_match('/\b(return .*service|back in service|back to service|mark .*active|set .*active)\b/u',$lower))$status='active';
    if($status!==null&&preg_match('/\b(mark|set|take|return|put|move)\b/u',$lower)){
        equipment_agent_require_edit($user);if(!$asset)throw new InvalidArgumentException('Select or name the equipment asset before changing its status.');
        return ['ok'=>true]+equipment_agent_propose($pdo,$user,$asset,'set_status',['newStatus'=>$status,'expectedStatus'=>$asset['operational_status']],"Mark {$asset['name']} as ".str_replace('_',' ',$status).'? Confirm to apply this equipment status change.');
    }

    if(preg_match('/\b(schedule|set|move)\b.*\b(service|maintenance|inspection)\b/u',$lower)||preg_match('/\bnext service\b/u',$lower)){
        $date=equipment_agent_extract_date($message);
        if($date!==null){
            equipment_agent_require_edit($user);if(!$asset)throw new InvalidArgumentException('Select or name the equipment asset before scheduling maintenance.');
            return ['ok'=>true]+equipment_agent_propose($pdo,$user,$asset,'schedule_service',['nextServiceOn'=>$date,'expectedNextServiceOn'=>$asset['next_service_on']],"Schedule maintenance for {$asset['name']} on {$date}? Confirm to update the maintenance schedule.");
        }
    }

    if(preg_match('/\b(record|log|add)\s+(?:a\s+)?(maintenance|repair|inspection|cleaning|service)\b/u',$lower,$m)){
        equipment_agent_require_service($user);if(!$asset)throw new InvalidArgumentException('Select or name the equipment asset before recording service.');
        $eventType=$m[1]==='service'?'maintenance':$m[1];
        $description='';
        if(preg_match('/:\s*(.+)$/us',$message,$dm))$description=trim($dm[1]);
        elseif(preg_match('/\b(?:maintenance|repair|inspection|cleaning|service)\b\s+(?:for|on)?\s*(.+)$/iu',$message,$dm))$description=trim($dm[1]);
        $description=preg_replace('/\s+next\s+due\s+20\d{2}-\d{2}-\d{2}.*$/iu','',$description)??$description;
        if($description===''||preg_match('/^(?:this|the|selected)\s+(?:equipment|asset)$/iu',$description))throw new InvalidArgumentException('Include a short service description, for example: “record repair: replaced the thermostat”.');
        $nextDue=null;if(preg_match('/\bnext\s+due\s+(20\d{2}-\d{2}-\d{2})\b/u',$lower,$nd))$nextDue=$nd[1];
        return ['ok'=>true]+equipment_agent_propose($pdo,$user,$asset,'record_service',['eventType'=>$eventType,'description'=>$description,'servicedOn'=>date('Y-m-d'),'nextDueOn'=>$nextDue],"Record {$eventType} service for {$asset['name']} today: {$description}".($nextDue?" Next due {$nextDue}.":'.').' Confirm to add this service-history entry.');
    }

    if(preg_match('/\b(replace|replacement|replacement cost|buy a new|new unit)\b/u',$lower)&&$asset){
        $cost=$asset['replacement_cost']!==null?'$'.number_format((float)$asset['replacement_cost'],2):'not recorded';
        return ['ok'=>true,'skill'=>'equipment.replacement_intelligence','answer'=>"{$asset['name']} is currently {$asset['operational_status']} with condition {$asset['condition_status']} and criticality {$asset['criticality']}. Recorded replacement cost: {$cost}. I can identify the operational case for replacement here; any vendor/PO action stays with the Purchasing Agent.",'data'=>['asset'=>equipment_agent_asset_data($pdo,$org,$asset),'handoff'=>['node'=>'purchasing','prompt'=>"Review replacement purchasing options for {$asset['name']}"]],'sources'=>['Equipment Catalog','Equipment Service History']];
    }

    if($asset){
        $events=equipment_brain_asset_events($pdo,$org,(int)$asset['id'],8);$contacts=equipment_brain_asset_contacts($pdo,$org,(int)$asset['id']);
        $data=equipment_agent_asset_data($pdo,$org,$asset);
        if(preg_match('/\b(history|service history|repair history|maintenance history|last service|recent service)\b/u',$lower)){
            $answer=$events?"Recent service for {$asset['name']}:\n".implode("\n",array_map(static fn($e)=>'- '.$e['serviced_on'].' '.$e['event_type'].': '.$e['description'],$events)):"No service history is recorded for {$asset['name']}.";
            return ['ok'=>true,'skill'=>'equipment.service_history','answer'=>$answer,'data'=>['asset'=>$data,'events'=>$events],'sources'=>['Equipment Service History']];
        }
        if(preg_match('/\b(contact|vendor|technician|repair company|service company|who services)\b/u',$lower)){
            $answer=$contacts?"Service contacts for {$asset['name']}:\n".implode("\n",array_map(static fn($c)=>'- '.$c['company_name'].($c['phone']?' · '.$c['phone']:''),$contacts)):"No active service contact is linked to {$asset['name']}.";
            return ['ok'=>true,'skill'=>'equipment.service_contacts','answer'=>$answer,'data'=>['asset'=>$data,'contacts'=>$contacts],'sources'=>['Equipment Service Contacts']];
        }
        $next=$asset['next_service_on']?:'not scheduled';$last=$asset['last_service_on']?:'not recorded';
        return ['ok'=>true,'skill'=>'equipment.asset_context','answer'=>"{$asset['name']} is {$asset['operational_status']} · condition {$asset['condition_status']} · {$asset['criticality']} criticality. Location: ".($asset['location_name']?:'not recorded').". Last service: {$last}; next service: {$next}.".($asset['maintenance_notes']?" Maintenance notes: {$asset['maintenance_notes']}":''),'data'=>['asset'=>$data,'events'=>$events,'contacts'=>$contacts],'sources'=>['Equipment Catalog','Equipment Service History']];
    }

    $summary=equipment_brain_summary($pdo,$org);$attention=equipment_agent_attention($pdo,$org,12);
    if(preg_match('/\b(overdue|due|maintenance|service|attention|risk|problem|down|offline|out of service|critical)\b/u',$lower)){
        $lines=[];foreach($attention as $row){$due=$row['next_service_on']?' · due '.$row['next_service_on']:'';$lines[]='- '.$row['name'].' · '.str_replace('_',' ',$row['operational_status']).' · '.$row['criticality'].$due;}
        $answer="Equipment attention: {$summary['outOfService']} out of service, {$summary['overdueMaintenance']} overdue for maintenance, {$summary['dueWithin30Days']} due within 30 days.".($lines?"\n".implode("\n",$lines):' No equipment currently matches the attention rules.');
        return ['ok'=>true,'skill'=>'equipment.maintenance_attention','answer'=>$answer,'data'=>['summary'=>$summary,'attention'=>$attention],'sources'=>['Equipment Catalog','Equipment Service History']];
    }
    return ['ok'=>true,'skill'=>'equipment.summary','answer'=>"Equipment: {$summary['assets']} active catalog assets, {$summary['criticalAssets']} critical, {$summary['outOfService']} out of service, {$summary['overdueMaintenance']} overdue for maintenance, and {$summary['dueWithin30Days']} due within 30 days.",'data'=>['summary'=>$summary,'attention'=>$attention],'sources'=>['Equipment Catalog']];
}
