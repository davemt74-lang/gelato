<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-camera-photo-library.php';
require_once __DIR__.'/kds-core.php';

const GLASSES_ORDER_PHOTO_STAGES=['pre_oven'=>2,'post_oven'=>3];

function glasses_order_photo_ready(PDO $pdo):bool {
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_order_photo_stages'");
    return (int)$q->fetchColumn()===1;
}
function glasses_order_photo_auth(PDO $pdo,array $user,int $locationId,bool $write):void {
    glasses_camera_location_authorize($pdo,$user,$locationId,$write);
    if(!app_has_permission('kds.view',$user)||!operational_location_allowed($pdo,$user,'kds.view',$locationId))
        throw new DomainException('KDS access is required at this location.');
    if(!glasses_order_photo_ready($pdo))throw new RuntimeException('Section 259 database upgrade is pending.');
}
function glasses_order_photo_candidates(PDO $pdo,array $user,int $locationId):array {
    $org=(int)$user['organization_id'];
    $q=$pdo->prepare("SELECT k.id,k.public_id,k.status,k.menu_item_id,k.station_id,
        c.public_id check_public_id,c.check_number,i.item_name_snapshot,i.special_instructions,i.quantity,
        mi.name menu_item_name
        FROM kds_order_items k
        JOIN pos_checks c ON c.id=k.check_id AND c.organization_id=k.organization_id
        JOIN pos_check_items i ON i.id=k.pos_check_item_id AND i.organization_id=k.organization_id
        LEFT JOIN menu_items mi ON mi.id=i.menu_item_id AND mi.organization_id=k.organization_id
        WHERE k.organization_id=? AND k.location_id=? AND k.status IN ('queued','in_progress','ready')
        ORDER BY FIELD(k.status,'ready','in_progress','queued'),k.id DESC LIMIT 100");
    $q->execute([$org,$locationId]);
    return array_map(static function(array $row):array{
        return ['kdsItemPublicId'=>(string)$row['public_id'],
            'checkPublicId'=>(string)$row['check_public_id'],
            'checkNumber'=>(string)$row['check_number'],
            'name'=>(string)$row['item_name_snapshot'],
            'menuItemId'=>$row['menu_item_id']!==null?(int)$row['menu_item_id']:null,
            'status'=>(string)$row['status'],'quantity'=>(float)$row['quantity'],
            'specialInstructions'=>(string)($row['special_instructions']??'')];
    },$q->fetchAll(PDO::FETCH_ASSOC));
}
function glasses_order_photo_snapshot(PDO $pdo,array $user,int $locationId):array {
    glasses_order_photo_auth($pdo,$user,$locationId,false);
    $org=(int)$user['organization_id'];
    $q=$pdo->prepare("SELECT s.kds_item_id,k.public_id kds_item_public_id,c.check_number,s.stage,
      s.status,s.camera_slot,s.photo_library_id,s.bypass_reason,s.revision,s.reviewed_at,
      p.media_id,m.public_id media_public_id,u.display_name reviewed_by
      FROM glasses_order_photo_stages s
      JOIN kds_order_items k ON k.id=s.kds_item_id AND k.organization_id=s.organization_id
      JOIN pos_checks c ON c.id=k.check_id AND c.organization_id=k.organization_id
      LEFT JOIN glasses_camera_photo_library p ON p.id=s.photo_library_id AND p.organization_id=s.organization_id
      LEFT JOIN glasses_vision_training_media m ON m.id=p.media_id AND m.organization_id=s.organization_id AND m.status='active'
      JOIN users u ON u.id=s.reviewer_id
      WHERE s.organization_id=? AND s.location_id=? ORDER BY s.reviewed_at DESC LIMIT 150");
    $q->execute([$org,$locationId]);$records=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$records[]=[
      'kdsItemPublicId'=>(string)$r['kds_item_public_id'],'checkNumber'=>(string)$r['check_number'],
      'stage'=>(string)$r['stage'],'status'=>(string)$r['status'],
      'cameraSlot'=>(int)$r['camera_slot'],
      'photoLibraryId'=>$r['photo_library_id']!==null?(int)$r['photo_library_id']:null,
      'mediaPublicId'=>$r['media_public_id']!==null?(string)$r['media_public_id']:null,
      'bypassReason'=>$r['bypass_reason']!==null?(string)$r['bypass_reason']:null,
      'revision'=>(int)$r['revision'],'reviewedAt'=>(string)$r['reviewed_at'],
      'reviewedBy'=>(string)$r['reviewed_by']
    ];
    return ['records'=>$records,'candidates'=>glasses_order_photo_candidates($pdo,$user,$locationId),
      'canManage'=>app_has_permission('glasses.manage',$user)];
}
function glasses_order_photo_validate(string $stage,string $action,array $in):array {
    if(!array_key_exists($stage,GLASSES_ORDER_PHOTO_STAGES))throw new InvalidArgumentException('Invalid pre/post oven stage.');
    if(!in_array($action,['confirm','bypass','reopen'],true))throw new InvalidArgumentException('Unsupported production photo action.');
    $public=trim((string)($in['kdsItemPublicId']??''));
    if($public===''||strlen($public)>100)throw new InvalidArgumentException('Select an exact kitchen item.');
    $revision=$in['expectedRevision']??null;
    if(!is_int($revision)||$revision<0)throw new InvalidArgumentException('Current stage revision is required.');
    $reason=trim((string)($in['bypassReason']??''));
    if($action==='bypass'&&(mb_strlen($reason)<5||mb_strlen($reason)>250))
        throw new InvalidArgumentException('Provide a bypass reason of 5–250 characters.');
    $photo=$in['photoLibraryId']??null;
    if($action==='confirm'&&(!is_int($photo)||$photo<1))
        throw new InvalidArgumentException('Save an explicitly reviewed photo before confirming the order.');
    return ['stage'=>$stage,'action'=>$action,'public'=>$public,'expectedRevision'=>$revision,
       'photoLibraryId'=>$photo,'reason'=>$reason];
}
function glasses_order_photo_action(PDO $pdo,array $user,int $locationId,array $in):array {
    glasses_order_photo_auth($pdo,$user,$locationId,true);
    $v=glasses_order_photo_validate((string)($in['stage']??''),(string)($in['action']??''),$in);
    $org=(int)$user['organization_id'];$actor=(int)$user['id'];
    $slot=GLASSES_ORDER_PHOTO_STAGES[$v['stage']];
    return operational_db_wrap($pdo,static function()use($pdo,$v,$org,$actor,$slot,$locationId):array {
        $item=kds_item($pdo,$org,$v['public'],true);
        if((int)$item['location_id']!==$locationId)throw new DomainException('This kitchen item belongs to another location.');
        if($v['action']==='confirm'){
            $expectedStatuses=$v['stage']==='pre_oven'?['in_progress']:['ready','completed'];
            if(!in_array((string)$item['status'],$expectedStatuses,true))
                throw new InvalidArgumentException('Kitchen item is not at the expected production stage; refresh the live ticket.');
        }elseif((string)$item['status']==='cancelled')
            throw new InvalidArgumentException('A cancelled kitchen item cannot be bypassed or reopened.');
        $camera=$pdo->prepare('SELECT id,enabled,role FROM glasses_camera_locations WHERE organization_id=? AND location_id=? AND slot=? LIMIT 1');
        $camera->execute([$org,$locationId,$slot]);$configured=$camera->fetch(PDO::FETCH_ASSOC);
        if($v['action']==='confirm'&&(!$configured||(int)$configured['enabled']!==1||
            (string)$configured['role']!==$v['stage']))
            throw new DomainException('The correct stage camera must be on and assigned to this production stage.');
        if($v['action']==='confirm'){
            $photo=$pdo->prepare("SELECT p.id,p.camera_slot,p.status,p.menu_item_id FROM glasses_camera_photo_library p
                 JOIN glasses_vision_training_media m ON m.id=p.media_id AND m.organization_id=p.organization_id AND m.status='active'
                 WHERE p.organization_id=? AND p.location_id=? AND p.id=? LIMIT 1 FOR UPDATE");
            $photo->execute([$org,$locationId,$v['photoLibraryId']]);$row=$photo->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['status']!=='active'||(int)$row['camera_slot']!==$slot)
                throw new DomainException('Select a saved photo from the correct camera and restaurant location.');
            if($row['menu_item_id']!==null&&(int)$row['menu_item_id']!==(int)$item['menu_item_id'])
                throw new DomainException('Photo belongs to a different menu item; review its training assignment.');
            $assigned=$pdo->prepare("SELECT s.id FROM glasses_order_photo_stages s
                WHERE s.organization_id=? AND s.photo_library_id=? AND (s.kds_item_id<>? OR s.stage<>?) LIMIT 1");
            $assigned->execute([$org,$v['photoLibraryId'],(int)$item['id'],$v['stage']]);
            if($assigned->fetchColumn())throw new DomainException('Photo is already confirmed for another item or stage.');
        }
        $current=$pdo->prepare('SELECT id,status,revision,photo_library_id FROM glasses_order_photo_stages
           WHERE organization_id=? AND kds_item_id=? AND stage=? FOR UPDATE');
        $current->execute([$org,(int)$item['id'],$v['stage']]);$old=$current->fetch(PDO::FETCH_ASSOC);
        if((int)($old['revision']??0)!==$v['expectedRevision'])
            throw new InvalidArgumentException('This stage was already updated. Refresh before retrying.');
        if($v['action']==='reopen'){
            if(!$old)throw new InvalidArgumentException('There is no completed stage to reopen.');
            $pdo->prepare('DELETE FROM glasses_order_photo_stages WHERE organization_id=? AND id=? AND revision=?')
                ->execute([$org,(int)$old['id'],$v['expectedRevision']]);
            app_audit($pdo,$org,$actor,'glasses.order_photo_reopened','kds_order_item',$v['public'],null,
                ['stage'=>$v['stage'],'locationId'=>$locationId,'previousStatus'=>$old['status']]);
            return ['stage'=>$v['stage'],'status'=>'pending','revision'=>0];
        }
        $status=$v['action']==='bypass'?'bypassed':'confirmed';
        $photoId=$status==='confirmed'?$v['photoLibraryId']:null;
        $reason=$status==='bypassed'?$v['reason']:null;
        if($old){
            $q=$pdo->prepare("UPDATE glasses_order_photo_stages SET camera_slot=?,photo_library_id=?,status=?,bypass_reason=?,
             reviewer_id=?,reviewed_at=NOW(6),revision=revision+1 WHERE organization_id=? AND id=? AND revision=?");
            $q->execute([$slot,$photoId,$status,$reason,$actor,$org,(int)$old['id'],$v['expectedRevision']]);
        }else{
            $q=$pdo->prepare("INSERT INTO glasses_order_photo_stages
              (organization_id,location_id,kds_item_id,stage,camera_slot,photo_library_id,status,bypass_reason,reviewer_id)
              VALUES (?,?,?,?,?,?,?,?,?)");
            $q->execute([$org,$locationId,(int)$item['id'],$v['stage'],$slot,$photoId,$status,$reason,$actor]);
        }
        app_audit($pdo,$org,$actor,'glasses.order_photo_'.$status,'kds_order_item',$v['public'],null,
            ['stage'=>$v['stage'],'locationId'=>$locationId,'photoLibraryId'=>$photoId,
              'bypassReason'=>$reason,'ticketPublicId'=>$item['check_public_id']]);
        return ['stage'=>$v['stage'],'status'=>$status,'revision'=>$v['expectedRevision']+1,
          'photoLibraryId'=>$photoId];
    });
}
