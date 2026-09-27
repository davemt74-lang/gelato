<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-validation.php';
require_once __DIR__.'/kds-core.php';

function glasses_handoff_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_kds_handoffs'");
    $q->execute();
    return (int)$q->fetchColumn()===1;
}

function glasses_handoff_row_by_session(PDO $pdo,int $org,int $sessionId,bool $forUpdate=false): ?array
{
    $sql="SELECT h.*,s.public_id build_session_public_id,v.public_id validation_public_id,
        d.public_id device_public_id,k.public_id kds_item_public_id,k.status kds_status,
        ks.public_id station_public_id,ks.name station_name
        FROM glasses_kds_handoffs h
        JOIN glasses_build_sessions s ON s.id=h.build_session_id AND s.organization_id=h.organization_id
        JOIN glasses_product_validations v ON v.id=h.validation_id AND v.organization_id=h.organization_id
        JOIN glasses_devices d ON d.id=h.device_id AND d.organization_id=h.organization_id
        JOIN kds_order_items k ON k.id=h.kds_order_item_id AND k.organization_id=h.organization_id
        LEFT JOIN kds_stations ks ON ks.id=k.station_id AND ks.organization_id=k.organization_id
        WHERE h.organization_id=? AND h.build_session_id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);
    $q->execute([$org,$sessionId]);
    $row=$q->fetch();
    return $row?:null;
}

function glasses_handoff_public(array $row): array
{
    return [
        'publicId'=>(string)$row['public_id'],
        'buildSessionPublicId'=>(string)$row['build_session_public_id'],
        'validationPublicId'=>(string)$row['validation_public_id'],
        'devicePublicId'=>(string)$row['device_public_id'],
        'kdsItemPublicId'=>(string)$row['kds_item_public_id'],
        'stationPublicId'=>$row['station_public_id'],
        'stationName'=>$row['station_name'],
        'fromStatus'=>(string)$row['from_status'],
        'toStatus'=>(string)$row['to_status'],
        'kdsStatus'=>(string)$row['kds_status'],
        'validationSnapshotHash'=>(string)$row['validation_snapshot_hash'],
        'status'=>(string)$row['status'],
        'completedAt'=>$row['completed_at'],
        'next'=>[
            'stage'=>'expo',
            'label'=>'Sent to Expo / Finishing',
            'available'=>false,
        ],
    ];
}

function glasses_handoff_actor_user(array $device): int
{
    $userId=(int)($device['paired_by']??0);
    if($userId<1)throw new RuntimeException('Paired glasses has no accountable Gelato user for KDS handoff.');
    return $userId;
}

function glasses_handoff_to_expo(PDO $pdo,array $device,string $sessionPublicId): array
{
    if(!glasses_handoff_ready($pdo))throw new RuntimeException('Glasses Expo handoff migration is not installed.');
    $org=(int)$device['organization_id'];

    return glasses_transaction($pdo,function()use($pdo,$device,$org,$sessionPublicId):array{
        $session=glasses_build_session_row($pdo,$org,$sessionPublicId,true);

        if((int)$session['device_id']!==(int)$device['id'])
            throw new InvalidArgumentException('Build session is active on another glasses device.');

        $existing=glasses_handoff_row_by_session($pdo,$org,(int)$session['id'],true);
        if($existing)return glasses_handoff_public($existing);

        if((string)$session['status']!=='active')
            throw new InvalidArgumentException('Build session is not active.');

        if((string)($device['status']??'')!=='active'||!empty($device['revoked_at']))
            throw new InvalidArgumentException('Glasses device is not active.');

        if((int)$session['location_id']!==(int)$device['location_id'])
            throw new InvalidArgumentException('Build session is assigned to a different location.');
        if((int)($session['station_id']??0)!==(int)($device['station_id']??0))
            throw new InvalidArgumentException('Build session is assigned to a different kitchen station.');

        // Re-evaluate inside the same transaction so evidence cannot change between validation and KDS handoff.
        $validation=glasses_validation_evaluate($pdo,$device,$sessionPublicId);
        if((string)$validation['status']!=='ready_for_finishing'||(string)($validation['nextStage']??'')!=='expo_finishing')
            throw new InvalidArgumentException('Product validation is not ready for Expo / Finishing.');

        $validationRow=glasses_validation_row($pdo,$org,(string)$validation['publicId'],true);
        if(!hash_equals((string)$validationRow['snapshot_hash'],(string)$validation['snapshotHash']))
            throw new RuntimeException('Product validation changed during Expo handoff.');

        $kds=kds_item($pdo,$org,(string)$session['kds_public_id'],true);
        if((int)$kds['location_id']!==(int)$device['location_id']||(int)($kds['station_id']??0)!==(int)($device['station_id']??0))
            throw new InvalidArgumentException('Kitchen item assignment changed before Expo handoff.');

        $from=(string)$kds['status'];
        if(in_array($from,['held','completed','cancelled'],true))
            throw new InvalidArgumentException('Kitchen item cannot be handed to Expo from '.$from.'.');

        $actorUserId=glasses_handoff_actor_user($device);
        $note='AR glasses validated handoff from '.(string)$device['public_id'].' using '.(string)$validation['publicId'].'.';

        if($from==='queued'){
            kds_transition($pdo,$org,(string)$kds['public_id'],'in_progress',$actorUserId,$note);
            $kds=kds_transition($pdo,$org,(string)$kds['public_id'],'ready',$actorUserId,$note);
        }elseif($from==='in_progress'){
            $kds=kds_transition($pdo,$org,(string)$kds['public_id'],'ready',$actorUserId,$note);
        }elseif($from==='ready'){
            // Already visible to Expo through another legitimate KDS action. Close the AR work idempotently.
            $kds=kds_item($pdo,$org,(string)$kds['public_id'],false);
        }else{
            throw new InvalidArgumentException('Kitchen item cannot be handed to Expo from '.$from.'.');
        }

        $public=glasses_public_id('handoff');
        $pdo->prepare("INSERT INTO glasses_kds_handoffs
            (organization_id,public_id,build_session_id,validation_id,device_id,kds_order_item_id,from_status,to_status,validation_snapshot_hash,status)
            VALUES (?,?,?,?,?,?,?,?,?,'completed')")
            ->execute([
                $org,$public,(int)$session['id'],(int)$validationRow['id'],(int)$device['id'],(int)$session['kds_order_item_id'],
                $from,'ready',(string)$validationRow['snapshot_hash']
            ]);

        $pdo->prepare("UPDATE glasses_build_sessions
            SET status='completed',completed_at=NOW(6),updated_at=NOW(6)
            WHERE organization_id=? AND id=? AND status='active'")
            ->execute([$org,(int)$session['id']]);

        $fromValidation=(string)$validationRow['status'];
        $pdo->prepare("UPDATE glasses_product_validations
            SET status='handed_off',next_stage='expo',updated_at=NOW(6)
            WHERE organization_id=? AND id=?")
            ->execute([$org,(int)$validationRow['id']]);

        glasses_validation_event(
            $pdo,$org,(int)$validationRow['id'],(int)$session['id'],
            'handoff_to_expo',$fromValidation,'handed_off',
            [
                'handoffPublicId'=>$public,
                'kdsItemPublicId'=>(string)$kds['public_id'],
                'fromStatus'=>$from,
                'toStatus'=>'ready',
                'snapshotHash'=>(string)$validationRow['snapshot_hash'],
            ],
            (int)$device['id']
        );

        glasses_build_event(
            $pdo,$org,(int)$session['id'],'session_completed',null,
            [
                'reason'=>'expo_handoff',
                'handoffPublicId'=>$public,
                'validationPublicId'=>(string)$validation['publicId'],
                'kdsStatus'=>'ready',
            ],
            (int)$device['id']
        );

        $row=glasses_handoff_row_by_session($pdo,$org,(int)$session['id'],false);
        if(!$row)throw new RuntimeException('Expo handoff could not be loaded after completion.');
        return glasses_handoff_public($row);
    });
}
