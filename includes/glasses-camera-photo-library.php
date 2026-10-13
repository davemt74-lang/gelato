<?php
declare(strict_types=1);
require_once __DIR__.'/glasses-camera-locations.php';

function glasses_camera_photo_ready(PDO $pdo):bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_camera_photo_library'");
    return (int)$q->fetchColumn()===1;
}
function glasses_camera_photo_authorize(PDO $pdo,array $user,int $locationId):void
{
    glasses_camera_location_authorize($pdo,$user,$locationId,true);
    if(!glasses_camera_photo_ready($pdo))throw new RuntimeException('Section 258 photo library database migration is pending.');
}
function glasses_camera_photo_item(PDO $pdo,int $org,int $menuId):array
{
    $q=$pdo->prepare('SELECT id,name FROM menu_items WHERE organization_id=? AND id=? AND is_active=1 LIMIT 1');
    $q->execute([$org,$menuId]);
    $result=$q->fetch(PDO::FETCH_ASSOC);
    if(!$result)throw new InvalidArgumentException('Select an active menu item from this restaurant.');
    return $result;
}
function glasses_camera_photo_library(PDO $pdo,array $user,int $locationId):array
{
    glasses_camera_photo_authorize($pdo,$user,$locationId);
    $org=(int)$user['organization_id'];
    $menu=$pdo->prepare("SELECT id,name,slug FROM menu_items WHERE organization_id=? AND is_active=1 ORDER BY name,id LIMIT 500");
    $menu->execute([$org]);
    $q=$pdo->prepare("SELECT p.id,p.camera_slot,p.menu_item_id,p.photo_role,p.is_reference,p.employee_rating,p.review_note,
        p.added_at,m.public_id media_public_id,m.perceptual_hash,m.quality_state,m.quality_flags_json,
        m.brightness_mean,m.contrast_mean,m.blur_score,m.width,m.height,m.created_at captured_at,
        i.name menu_item_name
        FROM glasses_camera_photo_library p
        JOIN glasses_vision_training_media m ON m.id=p.media_id AND m.organization_id=p.organization_id AND m.status='active'
        LEFT JOIN menu_items i ON i.id=p.menu_item_id AND i.organization_id=p.organization_id
        WHERE p.organization_id=? AND p.location_id=? AND p.status='active'
        ORDER BY p.id DESC LIMIT 300");
    $q->execute([$org,$locationId]);
    $photos=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $photos[]=[
            'id'=>(int)$r['id'],'slot'=>(int)$r['camera_slot'],
            'mediaPublicId'=>(string)$r['media_public_id'],
            'menuItemId'=>$r['menu_item_id']!==null?(int)$r['menu_item_id']:null,
            'menuItemName'=>$r['menu_item_name']!==null?(string)$r['menu_item_name']:null,
            'role'=>(string)$r['photo_role'],'isReference'=>(bool)$r['is_reference'],
            'rating'=>$r['employee_rating']!==null?(int)$r['employee_rating']:null,
            'note'=>(string)($r['review_note']??''),
            'capturedAt'=>(string)$r['captured_at'],'addedAt'=>(string)$r['added_at'],
            'hash'=>$r['perceptual_hash']!==null?(string)$r['perceptual_hash']:null,
            'quality'=>['state'=>(string)$r['quality_state'],
                'flags'=>json_decode((string)($r['quality_flags_json']??'[]'),true)?:[],
                'brightness'=>$r['brightness_mean']!==null?(float)$r['brightness_mean']:null,
                'contrast'=>$r['contrast_mean']!==null?(float)$r['contrast_mean']:null,
                'blur'=>$r['blur_score']!==null?(float)$r['blur_score']:null],
            'width'=>(int)$r['width'],'height'=>(int)$r['height']
        ];
    }
    return ['photos'=>$photos,'menuItems'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'slug'=>(string)$r['slug']],$menu->fetchAll(PDO::FETCH_ASSOC))];
}
function glasses_camera_photo_command(PDO $pdo,array $user,int $locationId,array $in):array
{
    glasses_camera_photo_authorize($pdo,$user,$locationId);
    $org=(int)$user['organization_id'];$actor=(int)$user['id'];
    $action=(string)($in['action']??'');
    if($action==='register'){
        $slot=$in['slot']??null;
        if(!is_int($slot)||$slot<1||$slot>5)throw new InvalidArgumentException('Camera slot must be between 1 and 5.');
        $public=trim((string)($in['mediaPublicId']??''));
        if($public===''||strlen($public)>80)throw new InvalidArgumentException('A reviewed Vision Lab photo is required.');
        $conf=$pdo->prepare("SELECT enabled FROM glasses_camera_locations WHERE organization_id=? AND location_id=? AND slot=? LIMIT 1");
        $conf->execute([$org,$locationId,$slot]);
        if(!(bool)$conf->fetchColumn())throw new DomainException('Camera is off. Enable the camera before saving photos.');
        $media=$pdo->prepare("SELECT id,status,consent_basis,perceptual_hash,metadata_json FROM glasses_vision_training_media
          WHERE organization_id=? AND public_id=? LIMIT 1");
        $media->execute([$org,$public]);$row=$media->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='active'||$row['consent_basis']!=='training_media_opt_in')
            throw new DomainException('Only active, explicitly opted-in Vision Lab media can be saved.');
        $provenance=json_decode((string)($row['metadata_json']??'{}'),true)?:[];
        if(($provenance['source']??null)!=='camera_stage_gallery'||
            (int)($provenance['cameraSlot']??0)!==$slot||
            (int)($provenance['locationId']??0)!==$locationId)
            throw new DomainException('The photo was not captured from this authorized camera location.');
        $existing=$pdo->prepare("SELECT id,location_id,camera_slot FROM glasses_camera_photo_library WHERE organization_id=? AND media_id=?");
        $existing->execute([$org,(int)$row['id']]);$prior=$existing->fetch(PDO::FETCH_ASSOC);
        if($prior){
            if((int)$prior['location_id']!==$locationId||(int)$prior['camera_slot']!==$slot)
                throw new DomainException('This photo already belongs to a different camera location.');
            return ['id'=>(int)$prior['id'],'existing'=>true];
        }
        $menuId=$in['menuItemId']??null;
        if($menuId!==null&&(!is_int($menuId)||$menuId<1))throw new InvalidArgumentException('Invalid menu item.');
        $isReference=$in['isReference']??false;
        if(!is_bool($isReference))throw new InvalidArgumentException('Reference status must be explicit.');
        if($isReference&&$menuId===null)throw new InvalidArgumentException('Assign a menu item before saving a training reference.');
        if($menuId!==null)glasses_camera_photo_item($pdo,$org,$menuId);
        $rating=$in['rating']??null;
        if($rating!==null&&(!is_int($rating)||$rating<1||$rating>5))throw new InvalidArgumentException('Photo rating must be 1–5.');
        $note=trim((string)($in['note']??''));
        if(mb_strlen($note,'UTF-8')>300)throw new InvalidArgumentException('Photo note must be 300 characters or fewer.');
        $sql="INSERT INTO glasses_camera_photo_library
          (organization_id,location_id,camera_slot,media_id,menu_item_id,photo_role,is_reference,employee_rating,review_note,added_by,updated_by)
          VALUES (?,?,?,?,?,'gallery',?,?,?,?,?)";
        $pdo->prepare($sql)->execute([$org,$locationId,$slot,(int)$row['id'],$menuId,$isReference?1:0,$rating,$note,$actor,$actor]);
        $id=(int)$pdo->lastInsertId();
        app_audit($pdo,$org,$actor,'glasses.camera_photo_added','camera_photo',(string)$id,null,
            ['locationId'=>$locationId,'slot'=>$slot,'reference'=>$isReference,'menuItemId'=>$menuId]);
        return ['id'=>$id,'existing'=>false];
    }
    if(!in_array($action,['review','hide'],true))throw new InvalidArgumentException('Unsupported camera photo action.');
    $id=$in['photoId']??null;
    if(!is_int($id)||$id<1)throw new InvalidArgumentException('A valid camera photo is required.');
    $q=$pdo->prepare("SELECT id FROM glasses_camera_photo_library WHERE organization_id=? AND location_id=? AND id=? AND status='active'");
    $q->execute([$org,$locationId,$id]);if(!$q->fetchColumn())throw new DomainException('Camera photo is not accessible.');
    if($action==='hide'){
        $pdo->prepare("UPDATE glasses_camera_photo_library SET status='hidden',updated_by=? WHERE organization_id=? AND location_id=? AND id=?")
            ->execute([$actor,$org,$locationId,$id]);
        app_audit($pdo,$org,$actor,'glasses.camera_photo_hidden','camera_photo',(string)$id,null,['locationId'=>$locationId]);
        return ['id'=>$id,'hidden'=>true];
    }
    $menuId=$in['menuItemId']??null;
    if($menuId!==null&&(!is_int($menuId)||$menuId<1))throw new InvalidArgumentException('Invalid menu item.');
    if($menuId!==null)glasses_camera_photo_item($pdo,$org,$menuId);
    $reference=$in['isReference']??false;
    if(!is_bool($reference)||($reference&&$menuId===null))
        throw new InvalidArgumentException('A reference must have a confirmed menu item assignment.');
    $rating=$in['rating']??null;
    if($rating!==null&&(!is_int($rating)||$rating<1||$rating>5))throw new InvalidArgumentException('Rating must be 1–5.');
    $note=trim((string)($in['note']??''));
    if(mb_strlen($note,'UTF-8')>300)throw new InvalidArgumentException('Photo note is too long.');
    $pdo->prepare("UPDATE glasses_camera_photo_library SET menu_item_id=?,is_reference=?,employee_rating=?,review_note=?,updated_by=?
      WHERE organization_id=? AND location_id=? AND id=? AND status='active'")
      ->execute([$menuId,$reference?1:0,$rating,$note,$actor,$org,$locationId,$id]);
    app_audit($pdo,$org,$actor,'glasses.camera_photo_reviewed','camera_photo',(string)$id,null,
        ['reference'=>$reference,'menuItemId'=>$menuId,'rating'=>$rating,'locationId'=>$locationId]);
    return ['id'=>$id,'reviewed'=>true];
}
