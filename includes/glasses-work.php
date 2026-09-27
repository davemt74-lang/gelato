<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-core.php';
require_once __DIR__.'/kds-production.php';
require_once __DIR__.'/menu-training-knowledge.php';

function glasses_work_table_exists(PDO $pdo,string $table): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    return (int)$q->fetchColumn()===1;
}

function glasses_work_modifiers(PDO $pdo,int $org,int $posCheckItemId): array
{
    if(!glasses_work_table_exists($pdo,'pos_check_item_modifiers'))return [];
    $q=$pdo->prepare("SELECT modifier_group_name_snapshot,modifier_name_snapshot,quantity,unit_amount,total_amount
        FROM pos_check_item_modifiers
        WHERE organization_id=? AND pos_check_item_id=?
        ORDER BY id");
    $q->execute([$org,$posCheckItemId]);
    return array_map(static fn(array $r):array=>[
        'group'=>(string)$r['modifier_group_name_snapshot'],
        'name'=>(string)$r['modifier_name_snapshot'],
        'quantity'=>(float)$r['quantity'],
        'unitAmount'=>(float)$r['unit_amount'],
        'totalAmount'=>(float)$r['total_amount'],
    ],$q->fetchAll());
}

function glasses_work_recipe_source(PDO $pdo,int $org,string $menuItemName): array
{
    if(!glasses_work_table_exists($pdo,'recipes'))return ['status'=>'unavailable','recipe'=>null];
    $name=trim($menuItemName);
    if($name==='')return ['status'=>'unlinked','recipe'=>null];
    $q=$pdo->prepare("SELECT public_id,name,category,description,yield_quantity,yield_unit,ingredients_json,instructions_json,notes,mapping_status,updated_at
        FROM recipes
        WHERE organization_id=? AND archived_at IS NULL AND status='active' AND name=?
        ORDER BY updated_at DESC,id DESC LIMIT 3");
    $q->execute([$org,$name]);
    $rows=$q->fetchAll();
    if(count($rows)!==1)return ['status'=>count($rows)>1?'ambiguous':'unlinked','recipe'=>null,'matchCount'=>count($rows)];
    $r=$rows[0];
    return ['status'=>'exact_name','recipe'=>[
        'publicId'=>(string)$r['public_id'],
        'name'=>(string)$r['name'],
        'category'=>(string)($r['category']??''),
        'description'=>(string)($r['description']??''),
        'yieldQuantity'=>$r['yield_quantity']!==null?(float)$r['yield_quantity']:null,
        'yieldUnit'=>(string)($r['yield_unit']??''),
        'ingredients'=>json_decode((string)($r['ingredients_json']??'[]'),true)?:[],
        'instructions'=>json_decode((string)($r['instructions_json']??'[]'),true)?:[],
        'notes'=>(string)($r['notes']??''),
        'mappingStatus'=>(string)($r['mapping_status']??''),
        'updatedAt'=>$r['updated_at'],
    ]];
}

function glasses_work_menu_source(PDO $pdo,int $org,int $menuItemId): ?array
{
    $item=menu_training_item($pdo,$org,$menuItemId);
    if(!$item)return null;
    return [
        'id'=>$item['id'],
        'name'=>$item['name'],
        'slug'=>$item['slug'],
        'sectionId'=>$item['sectionId'],
        'sectionName'=>$item['sectionName'],
        'description'=>$item['description'],
        'preparationNotes'=>$item['preparationNotes'],
        'ingredients'=>$item['ingredientDetails'],
        'allergens'=>$item['allergens'],
    ];
}

function glasses_work_item(PDO $pdo,int $org,array $row): array
{
    $menuItemId=(int)$row['menu_item_id'];
    $name=(string)$row['item_name_snapshot'];
    return [
        'kdsItemPublicId'=>(string)$row['public_id'],
        'status'=>(string)$row['status'],
        'station'=>[
            'publicId'=>$row['station_public_id'],
            'name'=>$row['station_name'],
        ],
        'timing'=>[
            'ageSeconds'=>(int)($row['ageSeconds']??0),
            'targetSeconds'=>(int)($row['target_seconds']??0),
            'slaRatio'=>(float)($row['slaRatio']??0),
            'warning'=>!empty($row['warning']),
            'late'=>!empty($row['late']),
        ],
        'ticket'=>[
            'checkPublicId'=>(string)$row['check_public_id'],
            'checkNumber'=>(string)$row['check_number'],
            'serviceMode'=>(string)$row['service_mode'],
            'tableName'=>$row['table_name'],
            'guestCount'=>(int)$row['guest_count'],
            'serverName'=>$row['server_name']??$row['opened_by_name']??null,
            'seatNumber'=>$row['seat_number']!==null?(int)$row['seat_number']:null,
            'courseKey'=>$row['course_key']??null,
            'courseSequence'=>$row['course_sequence']!==null?(int)$row['course_sequence']:null,
        ],
        'posLine'=>[
            'id'=>(int)$row['pos_check_item_id'],
            'menuItemId'=>$menuItemId,
            'name'=>$name,
            'optionName'=>(string)($row['option_name_snapshot']??''),
            'quantity'=>(float)$row['quantity'],
            'specialInstructions'=>(string)($row['special_instructions']??''),
            'modifiers'=>glasses_work_modifiers($pdo,$org,(int)$row['pos_check_item_id']),
        ],
        'menu'=>glasses_work_menu_source($pdo,$org,$menuItemId),
        'recipeSource'=>glasses_work_recipe_source($pdo,$org,$name),
    ];
}

function glasses_work_focus_index(array $items): ?int
{
    if(!$items)return null;
    foreach(['in_progress','queued','ready','held'] as $status){
        foreach($items as $i=>$item)if((string)($item['status']??'')===$status)return $i;
    }
    return 0;
}

function glasses_current_work(PDO $pdo,array $device): array
{
    if((string)($device['status']??'')!=='active'||!empty($device['revoked_at']))
        throw new InvalidArgumentException('Glasses device is not active.');
    $org=(int)$device['organization_id'];
    $locationId=(int)$device['location_id'];
    $stationPublicId=trim((string)($device['station_public_id']??''));
    if($stationPublicId===''){
        return [
            'device'=>glasses_device_public($device),
            'assignmentRequired'=>true,
            'location'=>glasses_location($pdo,$org,$locationId),
            'station'=>null,
            'focusItem'=>null,
            'items'=>[],
            'metrics'=>['queued'=>0,'inProgress'=>0,'ready'=>0,'held'=>0,'warning'=>0,'late'=>0],
            'revision'=>hash('sha256','unassigned:'.(string)$device['public_id'].':'.$locationId),
            'pollAfterMs'=>2000,
        ];
    }

    $board=kds_production_board($pdo,$org,$locationId,$stationPublicId,false);
    $items=[];
    foreach((array)$board['items'] as $row)$items[]=glasses_work_item($pdo,$org,$row);
    $focusIndex=glasses_work_focus_index($items);
    $focus=$focusIndex!==null?$items[$focusIndex]:null;
    $revisionMaterial=[];
    foreach($items as $item)$revisionMaterial[]=[
        $item['kdsItemPublicId'],$item['status'],$item['timing']['ageSeconds'],
        $item['posLine']['id'],$item['posLine']['specialInstructions'],$item['posLine']['modifiers']
    ];
    $pdo->prepare("UPDATE glasses_devices SET last_seen_at=NOW(6),updated_at=NOW(6) WHERE organization_id=? AND id=? AND status='active'")
        ->execute([$org,(int)$device['id']]);

    return [
        'device'=>glasses_device_public(glasses_device_row($pdo,$org,(string)$device['public_id'],false)),
        'assignmentRequired'=>false,
        'location'=>$board['location'],
        'station'=>[
            'publicId'=>$stationPublicId,
            'name'=>(string)($device['station_name']??''),
        ],
        'focusItem'=>$focus,
        'items'=>$items,
        'metrics'=>[
            'queued'=>(int)($board['metrics']['queued']??0),
            'inProgress'=>(int)($board['metrics']['inProgress']??0),
            'ready'=>(int)($board['metrics']['ready']??0),
            'held'=>(int)($board['metrics']['held']??0),
            'warning'=>(int)($board['metrics']['warning']??0),
            'late'=>(int)($board['metrics']['late']??0),
        ],
        'revision'=>hash('sha256',json_encode($revisionMaterial,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
        'pollAfterMs'=>1200,
    ];
}
