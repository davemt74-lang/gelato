<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-build.php';

function glasses_validation_ready(PDO $pdo): bool
{
    foreach(['glasses_product_validations','glasses_validation_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==1)return false;
    }
    return true;
}

function glasses_validation_row(PDO $pdo,int $org,string $publicId,bool $forUpdate=false): array
{
    $sql="SELECT v.*,s.public_id build_session_public_id,s.device_id,s.status build_session_status,
        d.public_id device_public_id,k.public_id kds_item_public_id,k.status kds_status
        FROM glasses_product_validations v
        JOIN glasses_build_sessions s ON s.id=v.build_session_id AND s.organization_id=v.organization_id
        JOIN glasses_devices d ON d.id=s.device_id AND d.organization_id=s.organization_id
        JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
        WHERE v.organization_id=? AND v.public_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Product validation was not found.');
    return $row;
}

function glasses_validation_for_session_row(PDO $pdo,int $org,int $sessionId,bool $forUpdate=false): ?array
{
    $q=$pdo->prepare("SELECT * FROM glasses_product_validations WHERE organization_id=? AND build_session_id=? LIMIT 1".($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$sessionId]);$row=$q->fetch();return $row?:null;
}

function glasses_validation_component_check(array $component): array
{
    $status=(string)$component['status'];
    $expected=(float)$component['expected_quantity'];
    $detected=(float)$component['detected_quantity'];
    $optional=!empty($component['is_optional']);
    $passed=$optional||$status==='confirmed'||$status==='ignored';
    return [
        'componentKey'=>(string)$component['component_key'],
        'displayName'=>(string)$component['display_name'],
        'expectedQuantity'=>$expected,
        'detectedQuantity'=>$detected,
        'unit'=>(string)($component['unit']??''),
        'optional'=>$optional,
        'componentStatus'=>$status,
        'confidence'=>$component['confidence']!==null?(float)$component['confidence']:null,
        'passed'=>$passed,
    ];
}

function glasses_validation_snapshot(PDO $pdo,int $org,array $session): array
{
    $components=glasses_build_components($pdo,$org,(int)$session['id'],false);
    $checks=[];$blockers=[];$required=0;$passed=0;
    foreach($components as $component){
        $check=glasses_validation_component_check($component);
        $checks[]=$check;
        if($check['optional'])continue;
        if($check['componentStatus']==='unexpected'){
            $blockers[]=['type'=>'unexpected','componentKey'=>$check['componentKey'],'message'=>'Unexpected component detected: '.$check['displayName']];
            continue;
        }
        $required++;
        if($check['passed']){$passed++;continue;}
        if($check['componentStatus']==='verify'){
            $blockers[]=['type'=>'verify','componentKey'=>$check['componentKey'],'message'=>$check['displayName'].' needs verification.'];
        }else{
            $missing=max(0.0,$check['expectedQuantity']-$check['detectedQuantity']);
            $blockers[]=[
                'type'=>'missing',
                'componentKey'=>$check['componentKey'],
                'message'=>$check['displayName'].' is not fully accounted for.',
                'missingQuantity'=>$missing,
                'unit'=>$check['unit'],
            ];
        }
    }

    // Unexpected components are optional=false in storage but must not inflate the required recipe count.
    $unexpected=array_values(array_filter($components,static fn(array $c):bool=>(string)$c['status']==='unexpected'));
    foreach($unexpected as $component){
        $exists=false;
        foreach($blockers as $b)if(($b['type']??'')==='unexpected'&&($b['componentKey']??'')===(string)$component['component_key']){$exists=true;break;}
        if(!$exists)$blockers[]=['type'=>'unexpected','componentKey'=>(string)$component['component_key'],'message'=>'Unexpected component detected: '.(string)$component['display_name']];
    }

    $verifyCount=count(array_filter($components,static fn(array $c):bool=>(string)$c['status']==='verify'));
    $unexpectedCount=count($unexpected);
    $missingCount=count(array_filter($blockers,static fn(array $b):bool=>($b['type']??'')==='missing'));
    $allAccounted=$required>0&&$passed===$required&&$verifyCount===0&&$unexpectedCount===0&&$missingCount===0;
    $status=$allAccounted?'ready_for_finishing':(($verifyCount>0||$unexpectedCount>0)?'blocked':'pending');
    $nextStage=$allAccounted?'expo_finishing':null;
    $buildDefinition=$session['build_definition_id']!==null?[
        'id'=>(int)$session['build_definition_id'],
        'publicId'=>$session['build_definition_public_id']??null,
        'version'=>$session['build_definition_version']!==null?(int)$session['build_definition_version']:null,
    ]:null;

    $summary=[
        'requiredComponents'=>$required,
        'passedComponents'=>$passed,
        'missingComponents'=>$missingCount,
        'verifyComponents'=>$verifyCount,
        'unexpectedComponents'=>$unexpectedCount,
        'allIngredientsAccountedFor'=>$allAccounted,
        'checks'=>$checks,
        'buildDefinition'=>$buildDefinition,
        'next'=>$allAccounted?[
            'stage'=>'expo_finishing',
            'label'=>'Expo / Finishing',
            'available'=>true,
            'message'=>'All ingredients accounted for.',
        ]:[
            'stage'=>null,
            'label'=>'Expo / Finishing',
            'available'=>false,
            'message'=>'Resolve build validation before finishing.',
        ],
    ];
    return ['status'=>$status,'summary'=>$summary,'blockers'=>$blockers,'nextStage'=>$nextStage];
}

function glasses_validation_public(array $row): array
{
    return [
        'publicId'=>(string)$row['public_id'],
        'buildSessionPublicId'=>(string)($row['build_session_public_id']??''),
        'status'=>(string)$row['status'],
        'revision'=>(int)$row['revision'],
        'snapshotHash'=>(string)$row['snapshot_hash'],
        'summary'=>json_decode((string)$row['summary_json'],true)?:[],
        'blockers'=>json_decode((string)($row['blockers_json']??'[]'),true)?:[],
        'nextStage'=>$row['next_stage'],
        'kdsItemPublicId'=>$row['kds_item_public_id']??null,
        'kdsStatus'=>$row['kds_status']??null,
        'evaluatedAt'=>$row['evaluated_at'],
        'updatedAt'=>$row['updated_at'],
    ];
}

function glasses_validation_event(PDO $pdo,int $org,int $validationId,int $sessionId,string $type,?string $from,?string $to,array $payload,?int $deviceId): void
{
    $pdo->prepare("INSERT INTO glasses_validation_events (organization_id,validation_id,build_session_id,event_type,from_status,to_status,payload_json,actor_device_id) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$org,$validationId,$sessionId,$type,$from,$to,glasses_json_object($payload),$deviceId]);
}

function glasses_validation_evaluate(PDO $pdo,array $device,string $sessionPublicId): array
{
    if(!glasses_validation_ready($pdo))throw new RuntimeException('Glasses product-validation migration is not installed.');
    $org=(int)$device['organization_id'];
    return glasses_transaction($pdo,function()use($pdo,$device,$org,$sessionPublicId):array{
        $session=glasses_build_session_row($pdo,$org,$sessionPublicId,true);
        glasses_build_assert_device_session($device,$session);
        $snapshot=glasses_validation_snapshot($pdo,$org,$session);
        $hash=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $existing=glasses_validation_for_session_row($pdo,$org,(int)$session['id'],true);
        $summaryJson=json_encode($snapshot['summary'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $blockersJson=$snapshot['blockers']?json_encode($snapshot['blockers'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;

        if(!$existing){
            $public=glasses_public_id('validation');
            $pdo->prepare("INSERT INTO glasses_product_validations (organization_id,public_id,build_session_id,status,revision,snapshot_hash,summary_json,blockers_json,next_stage,evaluated_at) VALUES (?,?,?, ?,1,?,?,?,?,NOW(6))")
                ->execute([$org,$public,(int)$session['id'],$snapshot['status'],$hash,$summaryJson,$blockersJson,$snapshot['nextStage']]);
            $id=(int)$pdo->lastInsertId();
            glasses_validation_event($pdo,$org,$id,(int)$session['id'],'validation_created',null,$snapshot['status'],[
                'snapshotHash'=>$hash,'nextStage'=>$snapshot['nextStage'],'blockerCount'=>count($snapshot['blockers'])
            ],(int)$device['id']);
        }else{
            $public=(string)$existing['public_id'];$id=(int)$existing['id'];
            if(hash_equals((string)$existing['snapshot_hash'],$hash)){
                $q=$pdo->prepare("SELECT v.*,s.public_id build_session_public_id,k.public_id kds_item_public_id,k.status kds_status
                    FROM glasses_product_validations v
                    JOIN glasses_build_sessions s ON s.id=v.build_session_id AND s.organization_id=v.organization_id
                    JOIN kds_order_items k ON k.id=s.kds_order_item_id AND k.organization_id=s.organization_id
                    WHERE v.organization_id=? AND v.id=? LIMIT 1");
                $q->execute([$org,$id]);return glasses_validation_public($q->fetch());
            }
            $from=(string)$existing['status'];
            $pdo->prepare("UPDATE glasses_product_validations SET status=?,revision=revision+1,snapshot_hash=?,summary_json=?,blockers_json=?,next_stage=?,evaluated_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=?")
                ->execute([$snapshot['status'],$hash,$summaryJson,$blockersJson,$snapshot['nextStage'],$org,$id]);
            glasses_validation_event($pdo,$org,$id,(int)$session['id'],'validation_changed',$from,$snapshot['status'],[
                'snapshotHash'=>$hash,'nextStage'=>$snapshot['nextStage'],'blockerCount'=>count($snapshot['blockers'])
            ],(int)$device['id']);
        }

        $row=glasses_validation_row($pdo,$org,$public,false);
        return glasses_validation_public($row);
    });
}

function glasses_validation_get(PDO $pdo,array $device,string $sessionPublicId): array
{
    $org=(int)$device['organization_id'];
    $session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);
    glasses_build_assert_device_session($device,$session);
    $row=glasses_validation_for_session_row($pdo,$org,(int)$session['id'],false);
    if(!$row)return glasses_validation_evaluate($pdo,$device,$sessionPublicId);
    $full=glasses_validation_row($pdo,$org,(string)$row['public_id'],false);
    return glasses_validation_public($full);
}
