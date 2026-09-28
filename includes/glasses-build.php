<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/glasses-work.php';
require_once __DIR__.'/glasses-definition.php';

function glasses_build_ready(PDO $pdo): bool
{
    foreach(['glasses_build_sessions','glasses_build_components','glasses_build_observations','glasses_build_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_build_component_key(string $name,?int $ingredientId=null): string
{
    if($ingredientId!==null&&$ingredientId>0)return 'ingredient:'.$ingredientId;
    $slug=mb_strtolower(trim($name),'UTF-8');
    $slug=preg_replace('/[^\pL\pN]+/u','-',$slug)??'';
    $slug=trim($slug,'-');
    return $slug!==''?'name:'.$slug:'name:unknown';
}

function glasses_build_expected_components(PDO $pdo,int $org,int $menuItemId): array
{
    $definition=glasses_definition_for_menu_item($pdo,$org,$menuItemId);
    if($definition!==null&&!empty($definition['definition']['ready'])&&empty($definition['stale'])){
        $out=[];
        foreach((array)($definition['definition']['components']??[]) as $component){
            $out[]=[
                'componentKey'=>(string)$component['componentKey'],
                'ingredientId'=>isset($component['ingredientId'])?(int)$component['ingredientId']:null,
                'displayName'=>(string)$component['displayName'],
                'expectedQuantity'=>(float)($component['expectedQuantity']??1),
                'unit'=>(string)($component['unit']??'portion'),
                'optional'=>!empty($component['optional']),
                'sortOrder'=>(int)($component['sortOrder']??0),
                'metadata'=>[
                    'source'=>'glasses_build_definition',
                    'definitionPublicId'=>$definition['publicId'],
                    'definitionVersion'=>$definition['version'],
                    'recipePublicId'=>$definition['recipePublicId'],
                    'recipeMatched'=>!empty($component['recipeMatched']),
                    'notes'=>(string)($component['notes']??''),
                ],
            ];
        }
        return $out;
    }

    $q=$pdo->prepare("SELECT ing.id ingredient_id,ing.canonical_name,mii.display_name,mii.is_optional,mii.sort_order
        FROM menu_item_ingredients mii
        JOIN ingredients ing ON ing.id=mii.ingredient_id AND ing.organization_id=?
        WHERE mii.menu_item_id=?
        ORDER BY mii.sort_order,ing.canonical_name");
    $q->execute([$org,$menuItemId]);
    $out=[];
    foreach($q->fetchAll() as $r){
        $ingredientId=(int)$r['ingredient_id'];
        $name=(string)($r['display_name']?:$r['canonical_name']);
        $out[]=[
            'componentKey'=>glasses_build_component_key($name,$ingredientId),
            'ingredientId'=>$ingredientId,
            'displayName'=>$name,
            'expectedQuantity'=>1.0,
            'unit'=>'portion',
            'optional'=>(bool)$r['is_optional'],
            'sortOrder'=>(int)$r['sort_order'],
            'metadata'=>['source'=>'menu_item_ingredients','canonicalName'=>(string)$r['canonical_name']],
        ];
    }
    return $out;
}

function glasses_build_event(PDO $pdo,int $org,int $sessionId,string $type,?string $componentKey,mixed $payload,?int $deviceId): void
{
    $pdo->prepare("INSERT INTO glasses_build_events (organization_id,build_session_id,event_type,component_key,payload_json,actor_device_id) VALUES (?,?,?,?,?,?)")
        ->execute([$org,$sessionId,mb_substr(trim($type),0,80,'UTF-8'),$componentKey,glasses_json_object($payload),$deviceId]);
}

function glasses_build_session_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT s.*,d.public_id device_public_id,k.public_id kds_public_id,k.location_id,k.station_id,k.status kds_status,ks.public_id station_public_id,ks.name station_name,
        bd.public_id build_definition_public_id,bd.version build_definition_version,bd.status build_definition_status
        FROM glasses_build_sessions s
        JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
        LEFT JOIN glasses_build_definitions bd ON bd.id=s.build_definition_id AND bd.organization_id=s.organization_id
        JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
        LEFT JOIN kds_stations ks ON ks.id=k.station_id AND ks.organization_id=k.organization_id
        WHERE s.organization_id=? AND s.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Build session was not found.');
    return $row;
}

function glasses_build_assert_device_session(array $device,array $session): void
{
    if((int)$session['organization_id']!==(int)$device['organization_id'])throw new InvalidArgumentException('Build session is not available to this device.');
    if((int)$session['device_id']!==(int)$device['id'])throw new InvalidArgumentException('Build session is active on another glasses device.');
    if((string)$session['status']!=='active')throw new InvalidArgumentException('Build session is not active.');
}

function glasses_build_components(PDO $pdo,int $org,int $sessionId,bool $forUpdate=false): array
{
    $q=$pdo->prepare("SELECT * FROM glasses_build_components WHERE organization_id=? AND build_session_id=? ORDER BY sort_order,id".($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$sessionId]);return $q->fetchAll();
}

function glasses_build_summary_from_rows(array $rows): array
{
    $required=0;$confirmed=0;$verify=0;$unexpected=0;$current=null;
    foreach($rows as $r){
        $status=(string)$r['status'];
        $optional=!empty($r['is_optional']);
        if(!$optional&&$status!=='unexpected'&&$status!=='ignored')$required++;
        if(!$optional&&$status==='confirmed')$confirmed++;
        if($status==='verify')$verify++;
        if($status==='unexpected')$unexpected++;
        if($current===null&&!$optional&&!in_array($status,['confirmed','ignored'],true))$current=(string)$r['component_key'];
    }
    return [
        'required'=>$required,
        'confirmed'=>$confirmed,
        'verify'=>$verify,
        'unexpected'=>$unexpected,
        'accounted'=>$required===$confirmed&&$verify===0&&$unexpected===0,
        'currentComponentKey'=>$current,
    ];
}

function glasses_build_component_public(array $r): array
{
    return [
        'componentKey'=>(string)$r['component_key'],
        'ingredientId'=>$r['ingredient_id']!==null?(int)$r['ingredient_id']:null,
        'displayName'=>(string)$r['display_name'],
        'expectedQuantity'=>(float)$r['expected_quantity'],
        'detectedQuantity'=>(float)$r['detected_quantity'],
        'unit'=>(string)($r['unit']??''),
        'optional'=>(bool)$r['is_optional'],
        'status'=>(string)$r['status'],
        'confidence'=>$r['confidence']!==null?(float)$r['confidence']:null,
        'sortOrder'=>(int)$r['sort_order'],
        'firstDetectedAt'=>$r['first_detected_at'],
        'confirmedAt'=>$r['confirmed_at'],
        'metadata'=>json_decode((string)($r['metadata_json']??'null'),true),
    ];
}

function glasses_build_payload(PDO $pdo,int $org,string $publicId): array
{
    $session=glasses_build_session_row($pdo,$org,$publicId,false);
    $rows=glasses_build_components($pdo,$org,(int)$session['id'],false);
    $components=array_map('glasses_build_component_public',$rows);
    return [
        'publicId'=>(string)$session['public_id'],
        'status'=>(string)$session['status'],
        'devicePublicId'=>(string)$session['device_public_id'],
        'kdsItemPublicId'=>(string)$session['kds_public_id'],
        'kdsStatus'=>(string)$session['kds_status'],
        'locationId'=>(int)$session['location_id'],
        'stationPublicId'=>$session['station_public_id'],
        'stationName'=>$session['station_name'],
        'posCheckItemId'=>(int)$session['pos_check_item_id'],
        'menuItemId'=>(int)$session['menu_item_id'],
        'sourceRevision'=>$session['source_revision'],
        'buildDefinition'=>$session['build_definition_id']!==null?[
            'id'=>(int)$session['build_definition_id'],
            'publicId'=>$session['build_definition_public_id'],
            'version'=>$session['build_definition_version']!==null?(int)$session['build_definition_version']:null,
            'status'=>$session['build_definition_status'],
        ]:null,
        'context'=>json_decode((string)($session['context_json']??'null'),true),
        'summary'=>glasses_build_summary_from_rows($rows),
        'components'=>$components,
        'startedAt'=>$session['started_at'],
        'completedAt'=>$session['completed_at'],
        'cancelledAt'=>$session['cancelled_at'],
        'updatedAt'=>$session['updated_at'],
    ];
}

function glasses_build_item_context(PDO $pdo,int $org,array $kds): array
{
    $q=$pdo->prepare("SELECT i.id,i.menu_item_id,i.item_name_snapshot,i.option_name_snapshot,i.quantity,i.special_instructions,c.public_id check_public_id,c.check_number
        FROM pos_check_items i JOIN pos_checks c ON c.id=i.check_id AND c.organization_id=i.organization_id
        WHERE i.organization_id=? AND i.id=? LIMIT 1");
    $q->execute([$org,(int)$kds['pos_check_item_id']]);$line=$q->fetch();
    if(!$line)throw new InvalidArgumentException('POS line for this kitchen item was not found.');
    return [
        'menuItemId'=>(int)$line['menu_item_id'],
        'checkPublicId'=>(string)$line['check_public_id'],
        'checkNumber'=>(string)$line['check_number'],
        'itemName'=>(string)$line['item_name_snapshot'],
        'optionName'=>(string)($line['option_name_snapshot']??''),
        'quantity'=>(float)$line['quantity'],
        'specialInstructions'=>(string)($line['special_instructions']??''),
        'modifiers'=>glasses_work_modifiers($pdo,$org,(int)$line['id']),
    ];
}

function glasses_build_start(PDO $pdo,array $device,string $kdsPublicId,?string $sourceRevision=null): array
{
    if(!glasses_build_ready($pdo))throw new RuntimeException('Glasses build-session migration is not installed.');
    $org=(int)$device['organization_id'];
    return glasses_transaction($pdo,function()use($pdo,$device,$org,$kdsPublicId,$sourceRevision):array{
        $kds=kds_item($pdo,$org,$kdsPublicId,true);
        if((int)$kds['location_id']!==(int)$device['location_id'])throw new InvalidArgumentException('Kitchen item belongs to a different location.');
        if($kds['station_id']===null||(int)$kds['station_id']!==(int)($device['station_id']??0))throw new InvalidArgumentException('Kitchen item is not assigned to this glasses station.');
        if(!in_array((string)$kds['status'],['queued','in_progress'],true))throw new InvalidArgumentException('Only queued or in-progress kitchen work can start a build session.');

        $q=$pdo->prepare("SELECT public_id,device_id FROM glasses_build_sessions WHERE organization_id=? AND kds_order_item_id=? AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$kds['id']]);$existing=$q->fetch();
        if($existing){
            if((int)$existing['device_id']!==(int)$device['id'])throw new InvalidArgumentException('This kitchen item already has an active build session on another glasses device.');
            return glasses_build_payload($pdo,$org,(string)$existing['public_id']);
        }

        $context=glasses_build_item_context($pdo,$org,$kds);
        $menuItemId=(int)$context['menuItemId'];
        $definition=glasses_definition_for_menu_item($pdo,$org,$menuItemId);
        $buildDefinitionId=null;
        if($definition!==null&&!empty($definition['definition']['ready'])&&empty($definition['stale'])){
            $buildDefinitionId=(int)$definition['id'];
            $sourceRevision=$sourceRevision??(string)$definition['sourceHash'];
            $context['buildDefinition']=[
                'publicId'=>$definition['publicId'],
                'version'=>$definition['version'],
                'recipePublicId'=>$definition['recipePublicId'],
                'steps'=>$definition['definition']['steps']??[],
            ];
        }
        $expected=glasses_build_expected_components($pdo,$org,$menuItemId);
        if(!$expected)throw new InvalidArgumentException('This menu item has no canonical ingredients configured for AR build tracking.');
        $public=glasses_public_id('build');
        $pdo->prepare("INSERT INTO glasses_build_sessions (organization_id,public_id,device_id,kds_order_item_id,pos_check_item_id,menu_item_id,build_definition_id,status,source_revision,context_json) VALUES (?,?,?,?,?,?,?,'active',?,?)")
            ->execute([$org,$public,(int)$device['id'],(int)$kds['id'],(int)$kds['pos_check_item_id'],$menuItemId,$buildDefinitionId,$sourceRevision,glasses_json_object($context)]);
        $sessionId=(int)$pdo->lastInsertId();
        $insert=$pdo->prepare("INSERT INTO glasses_build_components (organization_id,build_session_id,component_key,ingredient_id,display_name,expected_quantity,unit,is_optional,status,sort_order,metadata_json) VALUES (?,?,?,?,?,?,?,?,'waiting',?,?)");
        foreach($expected as $component)$insert->execute([
            $org,$sessionId,$component['componentKey'],$component['ingredientId'],$component['displayName'],$component['expectedQuantity'],$component['unit'],$component['optional']?1:0,$component['sortOrder'],glasses_json_object($component['metadata'])
        ]);
        glasses_build_event($pdo,$org,$sessionId,'session_started',null,['kdsItemPublicId'=>$kdsPublicId,'expectedCount'=>count($expected)],(int)$device['id']);
        return glasses_build_payload($pdo,$org,$public);
    });
}

function glasses_build_component_state(float $detected,float $expected,float $confidence,bool $unexpected=false): string
{
    if($unexpected)return 'unexpected';
    if($detected<=0)return 'waiting';
    if($confidence<0.75)return 'verify';
    if($detected+0.0001 >= $expected&&$confidence>=0.85)return 'confirmed';
    return 'detected';
}

function glasses_build_observe(PDO $pdo,array $device,string $sessionPublicId,array $input): array
{
    $org=(int)$device['organization_id'];
    $observationKey=mb_substr(trim((string)($input['observationKey']??'')),0,190,'UTF-8');
    if($observationKey==='')throw new InvalidArgumentException('Observation key is required for idempotency.');
    $componentKey=mb_substr(trim((string)($input['componentKey']??'')),0,160,'UTF-8');
    if($componentKey==='')throw new InvalidArgumentException('Component key is required.');
    $action=(string)($input['observationAction']??'added');
    if(!in_array($action,['added','removed','seen'],true))throw new InvalidArgumentException('Observation action is invalid.');
    $quantity=max(0.001,min(100.0,(float)($input['quantity']??1)));
    $confidence=max(0.0,min(1.0,(float)($input['confidence']??0)));
    $trackingId=mb_substr(trim((string)($input['trackingId']??'')),0,190,'UTF-8')?:null;
    $bbox=isset($input['bbox'])?glasses_json_object(is_array($input['bbox'])?$input['bbox']:null,2000):null;
    $metadata=isset($input['metadata'])?glasses_json_object(is_array($input['metadata'])?$input['metadata']:null,8000):null;

    return glasses_transaction($pdo,function()use($pdo,$device,$org,$sessionPublicId,$observationKey,$componentKey,$action,$quantity,$confidence,$trackingId,$bbox,$metadata,$input):array{
        $session=glasses_build_session_row($pdo,$org,$sessionPublicId,true);
        glasses_build_assert_device_session($device,$session);
        $q=$pdo->prepare('SELECT id FROM glasses_build_observations WHERE build_session_id=? AND observation_key=? LIMIT 1');
        $q->execute([(int)$session['id'],$observationKey]);
        if($q->fetchColumn())return glasses_build_payload($pdo,$org,$sessionPublicId);

        $q=$pdo->prepare('SELECT * FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=? LIMIT 1 FOR UPDATE');
        $q->execute([$org,(int)$session['id'],$componentKey]);$component=$q->fetch();
        $unexpected=false;
        if(!$component){
            $unexpected=true;
            $name=mb_substr(trim((string)($input['displayName']??$componentKey)),0,180,'UTF-8')?:'Unexpected component';
            $sort=10000;
            $pdo->prepare("INSERT INTO glasses_build_components (organization_id,build_session_id,component_key,display_name,expected_quantity,detected_quantity,unit,is_optional,status,confidence,sort_order,first_detected_at,metadata_json) VALUES (?,?,?,?,0,?,'',0,'unexpected',?,?,NOW(6),?)")
                ->execute([$org,(int)$session['id'],$componentKey,$name,$quantity,$confidence,$sort,glasses_json_object(['unexpected'=>true])]);
            $q=$pdo->prepare('SELECT * FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=? LIMIT 1 FOR UPDATE');
            $q->execute([$org,(int)$session['id'],$componentKey]);$component=$q->fetch();
        }else{
            $detected=(float)$component['detected_quantity'];
            if($action==='added')$detected+=$quantity;
            elseif($action==='removed')$detected=max(0,$detected-$quantity);
            elseif($action==='seen')$detected=max($detected,$quantity);
            $best=max((float)($component['confidence']??0),$confidence);
            $status=glasses_build_component_state($detected,(float)$component['expected_quantity'],$best,false);
            $confirmedAt=$status==='confirmed'?'NOW(6)':'NULL';
            $sql="UPDATE glasses_build_components SET detected_quantity=?,confidence=?,status=?,first_detected_at=COALESCE(first_detected_at,NOW(6)),confirmed_at={$confirmedAt},updated_at=NOW(6) WHERE organization_id=? AND id=?";
            $pdo->prepare($sql)->execute([$detected,$best,$status,$org,(int)$component['id']]);
        }

        $pdo->prepare("INSERT INTO glasses_build_observations (organization_id,build_session_id,observation_key,component_key,action,quantity,confidence,tracking_id,bbox_json,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$org,(int)$session['id'],$observationKey,$componentKey,$action,$quantity,$confidence,$trackingId,$bbox,$metadata]);
        glasses_build_event($pdo,$org,(int)$session['id'],$unexpected?'unexpected_observed':'component_observed',$componentKey,[
            'observationKey'=>$observationKey,'action'=>$action,'quantity'=>$quantity,'confidence'=>$confidence,'trackingId'=>$trackingId
        ],(int)$device['id']);
        $pdo->prepare('UPDATE glasses_build_sessions SET updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$org,(int)$session['id']]);
        return glasses_build_payload($pdo,$org,$sessionPublicId);
    });
}

function glasses_build_confirm(PDO $pdo,array $device,string $sessionPublicId,string $componentKey): array
{
    $org=(int)$device['organization_id'];
    return glasses_transaction($pdo,function()use($pdo,$device,$org,$sessionPublicId,$componentKey):array{
        $session=glasses_build_session_row($pdo,$org,$sessionPublicId,true);
        glasses_build_assert_device_session($device,$session);
        $q=$pdo->prepare("SELECT * FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$session['id'],$componentKey]);$component=$q->fetch();
        if(!$component)throw new InvalidArgumentException('Build component was not found.');
        if((string)$component['status']==='unexpected')throw new InvalidArgumentException('Unexpected components must be resolved, not confirmed as required.');
        $detected=max((float)$component['detected_quantity'],(float)$component['expected_quantity']);
        $pdo->prepare("UPDATE glasses_build_components SET detected_quantity=?,confidence=1,status='confirmed',first_detected_at=COALESCE(first_detected_at,NOW(6)),confirmed_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$detected,$org,(int)$component['id']]);
        glasses_build_event($pdo,$org,(int)$session['id'],'component_confirmed',$componentKey,['manual'=>true],(int)$device['id']);
        return glasses_build_payload($pdo,$org,$sessionPublicId);
    });
}

function glasses_build_resolve_unexpected(PDO $pdo,array $device,string $sessionPublicId,string $componentKey): array
{
    $org=(int)$device['organization_id'];
    return glasses_transaction($pdo,function()use($pdo,$device,$org,$sessionPublicId,$componentKey):array{
        $session=glasses_build_session_row($pdo,$org,$sessionPublicId,true);
        glasses_build_assert_device_session($device,$session);
        $q=$pdo->prepare("SELECT id,status FROM glasses_build_components WHERE organization_id=? AND build_session_id=? AND component_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$org,(int)$session['id'],$componentKey]);$component=$q->fetch();
        if(!$component||(string)$component['status']!=='unexpected')throw new InvalidArgumentException('Unexpected build component was not found.');
        $pdo->prepare("UPDATE glasses_build_components SET status='ignored',updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$org,(int)$component['id']]);
        glasses_build_event($pdo,$org,(int)$session['id'],'unexpected_resolved',$componentKey,['resolution'=>'ignored'],(int)$device['id']);
        return glasses_build_payload($pdo,$org,$sessionPublicId);
    });
}


function glasses_build_correction_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_observation_corrections'");
    $q->execute();
    return (int)$q->fetchColumn()===1;
}

function glasses_build_manual_event_keys(PDO $pdo,int $org,int $sessionId,string $eventType): array
{
    $q=$pdo->prepare("SELECT component_key FROM glasses_build_events
        WHERE organization_id=? AND build_session_id=? AND event_type=? AND component_key IS NOT NULL
        ORDER BY id");
    $q->execute([$org,$sessionId,$eventType]);
    $keys=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $key){
        $key=(string)$key;
        if($key!=='')$keys[$key]=true;
    }
    return $keys;
}

function glasses_build_latest_corrections(PDO $pdo,int $org,int $sessionId): array
{
    if(!glasses_build_correction_ready($pdo))return [];
    $q=$pdo->prepare("SELECT * FROM glasses_observation_corrections
        WHERE organization_id=? AND build_session_id=?
        ORDER BY id");
    $q->execute([$org,$sessionId]);
    $latest=[];
    foreach($q->fetchAll() as $row)$latest[(int)$row['observation_id']]=$row;
    return $latest;
}

function glasses_build_recompute_from_evidence(PDO $pdo,int $org,int $sessionId): void
{
    $components=glasses_build_components($pdo,$org,$sessionId,true);
    $componentByKey=[];
    $aggregate=[];
    foreach($components as $component){
        $key=(string)$component['component_key'];
        $componentByKey[$key]=$component;
        $aggregate[$key]=['detected'=>0.0,'confidence'=>0.0,'hasConfidence'=>false];
    }

    $corrections=glasses_build_latest_corrections($pdo,$org,$sessionId);
    $q=$pdo->prepare("SELECT * FROM glasses_build_observations
        WHERE organization_id=? AND build_session_id=?
        ORDER BY id");
    $q->execute([$org,$sessionId]);

    foreach($q->fetchAll() as $observation){
        $correction=$corrections[(int)$observation['id']]??null;
        if($correction&&(string)$correction['resolution']==='reject')continue;

        $componentKey=(string)$observation['component_key'];
        $quantity=(float)$observation['quantity'];
        if($correction&&(string)$correction['resolution']==='replace'){
            $replacement=trim((string)($correction['target_component_key']??''));
            if($replacement!=='')$componentKey=$replacement;
            if($correction['corrected_quantity']!==null)$quantity=(float)$correction['corrected_quantity'];
        }
        if(!isset($aggregate[$componentKey]))continue;

        $action=(string)$observation['action'];
        if($action==='added')$aggregate[$componentKey]['detected']+=$quantity;
        elseif($action==='removed')$aggregate[$componentKey]['detected']=max(0.0,$aggregate[$componentKey]['detected']-$quantity);
        elseif($action==='seen')$aggregate[$componentKey]['detected']=max($aggregate[$componentKey]['detected'],$quantity);

        $confidence=(float)$observation['confidence'];
        $aggregate[$componentKey]['confidence']=max($aggregate[$componentKey]['confidence'],$confidence);
        $aggregate[$componentKey]['hasConfidence']=true;
    }

    $manualConfirmed=glasses_build_manual_event_keys($pdo,$org,$sessionId,'component_confirmed');
    $manualResolved=glasses_build_manual_event_keys($pdo,$org,$sessionId,'unexpected_resolved');

    $update=$pdo->prepare("UPDATE glasses_build_components
        SET detected_quantity=?,confidence=?,status=?,confirmed_at=?,updated_at=NOW(6)
        WHERE organization_id=? AND id=?");

    foreach($components as $component){
        $key=(string)$component['component_key'];
        $detected=max(0.0,(float)$aggregate[$key]['detected']);
        $confidence=$aggregate[$key]['hasConfidence']?(float)$aggregate[$key]['confidence']:null;
        $expected=(float)$component['expected_quantity'];
        $status='waiting';
        $confirmedAt=null;

        if($expected<=0.0){
            if(isset($manualResolved[$key]))$status='ignored';
            elseif($detected>0.0)$status='unexpected';
            else $status='ignored';
        }elseif(isset($manualConfirmed[$key])){
            $detected=max($detected,$expected);
            $confidence=1.0;
            $status='confirmed';
            $confirmedAt=$component['confirmed_at']??(new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        }else{
            $status=glasses_build_component_state($detected,$expected,$confidence??0.0,false);
            if($status==='confirmed')$confirmedAt=$component['confirmed_at']??(new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        }

        $update->execute([$detected,$confidence,$status,$confirmedAt,$org,(int)$component['id']]);
    }
}

function glasses_build_correction_public(array $row): array
{
    return [
        'correctionKey'=>(string)$row['correction_key'],
        'observationKey'=>(string)($row['observation_key']??''),
        'resolution'=>(string)$row['resolution'],
        'targetComponentKey'=>$row['target_component_key'],
        'correctedQuantity'=>$row['corrected_quantity']!==null?(float)$row['corrected_quantity']:null,
        'hasCorrectedQuantity'=>$row['corrected_quantity']!==null,
        'reason'=>(string)($row['reason']??''),
        'createdAt'=>$row['created_at'],
    ];
}

function glasses_build_correct_observation(PDO $pdo,array $device,string $sessionPublicId,array $input): array
{
    if(!glasses_build_correction_ready($pdo))throw new RuntimeException('Glasses observation-correction migration is not installed.');
    $org=(int)$device['organization_id'];
    $observationKey=mb_substr(trim((string)($input['observationKey']??'')),0,190,'UTF-8');
    $correctionKey=mb_substr(trim((string)($input['correctionKey']??'')),0,190,'UTF-8');
    $resolution=trim((string)($input['resolution']??''));
    $reason=mb_substr(trim((string)($input['reason']??'')),0,500,'UTF-8')?:null;

    if($observationKey==='')throw new InvalidArgumentException('Observation key is required.');
    if($correctionKey==='')throw new InvalidArgumentException('Correction key is required for idempotency.');
    if(!in_array($resolution,['reject','replace'],true))throw new InvalidArgumentException('Correction resolution is invalid.');

    return glasses_transaction($pdo,function()use(
        $pdo,$device,$org,$sessionPublicId,$input,$observationKey,$correctionKey,$resolution,$reason
    ):array{
        $session=glasses_build_session_row($pdo,$org,$sessionPublicId,true);
        glasses_build_assert_device_session($device,$session);
        $sessionId=(int)$session['id'];

        $q=$pdo->prepare("SELECT c.*,o.observation_key
            FROM glasses_observation_corrections c
            JOIN glasses_build_observations o ON o.id=c.observation_id AND o.organization_id=c.organization_id
            WHERE c.organization_id=? AND c.build_session_id=? AND c.correction_key=?
            LIMIT 1");
        $q->execute([$org,$sessionId,$correctionKey]);
        if($existing=$q->fetch()){
            return [
                'buildSession'=>glasses_build_payload($pdo,$org,$sessionPublicId),
                'correction'=>glasses_build_correction_public($existing),
            ];
        }

        $q=$pdo->prepare("SELECT * FROM glasses_build_observations
            WHERE organization_id=? AND build_session_id=? AND observation_key=?
            LIMIT 1 FOR UPDATE");
        $q->execute([$org,$sessionId,$observationKey]);
        $observation=$q->fetch();
        if(!$observation)throw new InvalidArgumentException('Build observation was not found.');

        $targetComponentKey=null;
        $correctedQuantity=null;
        if($resolution==='replace'){
            $targetComponentKey=mb_substr(trim((string)($input['targetComponentKey']??$observation['component_key'])),0,160,'UTF-8');
            if($targetComponentKey==='')throw new InvalidArgumentException('Replacement component is required.');
            $q=$pdo->prepare("SELECT component_key,expected_quantity,status FROM glasses_build_components
                WHERE organization_id=? AND build_session_id=? AND component_key=?
                LIMIT 1 FOR UPDATE");
            $q->execute([$org,$sessionId,$targetComponentKey]);
            $target=$q->fetch();
            if(!$target||(float)$target['expected_quantity']<=0.0)
                throw new InvalidArgumentException('Replacement must target a configured recipe component.');

            $correctedQuantity=!empty($input['hasCorrectedQuantity'])
                ?(float)($input['correctedQuantity']??0)
                :(isset($input['correctedQuantity'])&&!array_key_exists('hasCorrectedQuantity',$input)
                    ?(float)$input['correctedQuantity']
                    :(float)$observation['quantity']);
            if(!is_finite($correctedQuantity)||$correctedQuantity<0.001||$correctedQuantity>100.0)
                throw new InvalidArgumentException('Corrected quantity must be between 0.001 and 100.');
        }

        $metadata=isset($input['metadata'])
            ?glasses_json_object(is_array($input['metadata'])?$input['metadata']:null,8000)
            :null;

        $pdo->prepare("INSERT INTO glasses_observation_corrections
            (organization_id,build_session_id,observation_id,correction_key,resolution,target_component_key,corrected_quantity,reason,actor_device_id,metadata_json)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $org,$sessionId,(int)$observation['id'],$correctionKey,$resolution,$targetComponentKey,$correctedQuantity,
                $reason,(int)$device['id'],$metadata
            ]);

        $correctionId=(int)$pdo->lastInsertId();
        glasses_build_recompute_from_evidence($pdo,$org,$sessionId);

        glasses_build_event($pdo,$org,$sessionId,'observation_corrected',(string)$observation['component_key'],[
            'observationKey'=>$observationKey,
            'correctionKey'=>$correctionKey,
            'resolution'=>$resolution,
            'targetComponentKey'=>$targetComponentKey,
            'correctedQuantity'=>$correctedQuantity,
            'reason'=>$reason,
        ],(int)$device['id']);

        $pdo->prepare("UPDATE glasses_build_sessions SET updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$org,$sessionId]);

        $q=$pdo->prepare("SELECT c.*,o.observation_key
            FROM glasses_observation_corrections c
            JOIN glasses_build_observations o ON o.id=c.observation_id AND o.organization_id=c.organization_id
            WHERE c.organization_id=? AND c.id=? LIMIT 1");
        $q->execute([$org,$correctionId]);
        $correction=$q->fetch();
        if(!$correction)throw new RuntimeException('Observation correction could not be loaded.');

        return [
            'buildSession'=>glasses_build_payload($pdo,$org,$sessionPublicId),
            'correction'=>glasses_build_correction_public($correction),
        ];
    });
}

function glasses_build_evidence(PDO $pdo,array $device,string $sessionPublicId,int $limit=50): array
{
    $org=(int)$device['organization_id'];
    $session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);
    glasses_build_assert_device_session($device,$session);
    $sessionId=(int)$session['id'];
    $limit=max(1,min(100,$limit));
    $latest=glasses_build_latest_corrections($pdo,$org,$sessionId);

    $q=$pdo->prepare("SELECT * FROM glasses_build_observations
        WHERE organization_id=? AND build_session_id=?
        ORDER BY id DESC LIMIT {$limit}");
    $q->execute([$org,$sessionId]);
    $items=[];
    foreach($q->fetchAll() as $observation){
        $correction=$latest[(int)$observation['id']]??null;
        $items[]=[
            'observationKey'=>(string)$observation['observation_key'],
            'componentKey'=>(string)$observation['component_key'],
            'action'=>(string)$observation['action'],
            'quantity'=>(float)$observation['quantity'],
            'confidence'=>$observation['confidence']!==null?(float)$observation['confidence']:null,
            'trackingId'=>$observation['tracking_id'],
            'bbox'=>json_decode((string)($observation['bbox_json']??'null'),true),
            'metadata'=>json_decode((string)($observation['metadata_json']??'null'),true),
            'createdAt'=>$observation['created_at'],
            'latestCorrection'=>$correction?glasses_build_correction_public($correction):null,
        ];
    }
    return $items;
}
