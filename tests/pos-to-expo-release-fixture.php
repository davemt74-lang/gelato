<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||getenv('GELATO_POS_EXPO_FIXTURE')!=='1'){
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../includes/menu-manager-core.php';

function expo_fixture_insert(PDO $pdo,string $sql,array $values): int
{
    $pdo->prepare($sql)->execute($values);
    return (int)$pdo->lastInsertId();
}

$action=$argv[1]??'';
if($action==='seed'){
    ob_start();
    require __DIR__.'/hardening-native-pdo-fixture.php';
    $accounts=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
    $org=$accounts['organizations']['home'];
    $owner=$accounts['users']['owner'];
    $location=expo_fixture_insert($pdo,"INSERT INTO locations (organization_id,name,status)
        VALUES (?,'Release Kitchen','active')",[$org]);
    $section=expo_fixture_insert($pdo,"INSERT INTO menu_sections (organization_id,name,slug,status)
        VALUES (?,'Pizza','pizza','active')",[$org]);
    $pizza=expo_fixture_insert($pdo,"INSERT INTO menu_items (organization_id,section_id,name,slug,is_active)
        VALUES (?,?,'Release Pepperoni Pizza','pepperoni-pizza',1)",[$org,$section]);
    $price=expo_fixture_insert($pdo,"INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency)
        VALUES (?,'Large','LRG',16.00,'USD')",[$pizza]);
    $pepperoni=expo_fixture_insert($pdo,"INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status)
        VALUES (?,'Pepperoni','pepperoni','food','verified')",[$org]);
    $pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order)
        VALUES (?,?,'Pepperoni',0,1,1)")->execute([$pizza,$pepperoni]);

    $recipe='release-pizza-'.bin2hex(random_bytes(6));
    $ingredients=[['quantity'=>'3','unit'=>'slices','ingredient'=>'Pepperoni','notes'=>'']];
    $pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,ingredients_json,instructions_json,status,mapping_status,created_by,updated_by)
        VALUES (?,?,'Release Pepperoni Pizza','Pizza',?,?,'active','mapped',?,?)")
        ->execute([$org,$recipe,json_encode($ingredients,JSON_THROW_ON_ERROR),'["Add pepperoni"]',$owner['id'],$owner['id']]);
    require_once __DIR__.'/../includes/glasses-definition.php';
    if(glasses_definition_compile($pdo,$org,$pizza,$recipe,$owner['id'])['status']!=='ready')
        throw new RuntimeException('Fixture pizza definition did not compile.');

    $group=expo_fixture_insert($pdo,"INSERT INTO menu_modifier_groups (organization_id,menu_item_id,public_id,name,max_select)
        VALUES (?,?,'release-pizza-toppings','Toppings',1)",[$org,$pizza]);
    $option=expo_fixture_insert($pdo,"INSERT INTO menu_modifier_options (organization_id,modifier_group_id,public_id,name,default_price_delta)
        VALUES (?,?,'release-pizza-extra-cheese','Extra cheese',1.50)",[$org,$group]);
    echo json_encode(compact('org','owner','location','pizza','price','pepperoni','option')+[
        'password'=>$accounts['password'],
        'nativePrepares'=>$accounts['nativePrepares'],
    ],JSON_THROW_ON_ERROR);
    exit;
}

require __DIR__.'/../includes/bootstrap.php';
$pdo=app_pdo();
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$org=(int)$input['org'];
$check=(string)$input['check'];

if($action==='attach-modifier'){
    // The POS endpoint accepts text instructions. Online checkout uses this
    // canonical writer to attach a structured add-on to the same POS line.
    $price=(int)$input['price'];
    $addons=menu_manager_validate_addons($pdo,$org,$price,[['optionId'=>(int)$input['option'],'quantity'=>1]],
        'online_order',(int)$input['location']);
    $result=pos_transaction($pdo,fn():array=>menu_manager_apply_pos_addons($pdo,$org,$check,$price,2,$addons));
    echo json_encode($result,JSON_THROW_ON_ERROR);
    exit;
}

if($action!=='state')throw new InvalidArgumentException('Choose seed, attach-modifier, or state.');

$queries=[
    'lines'=>"SELECT i.id,i.status pos_status,i.modifier_amount,k.status kitchen_status,k.revision,k.last_action_by
        FROM pos_checks c JOIN pos_check_items i ON i.organization_id=c.organization_id AND i.check_id=c.id
        LEFT JOIN kds_order_items k ON k.organization_id=i.organization_id AND k.pos_check_item_id=i.id
        WHERE c.organization_id=? AND c.public_id=? ORDER BY i.id",
    'events'=>"SELECT k.pos_check_item_id,e.event_type,e.from_status,e.to_status,e.actor_user_id
        FROM pos_checks c JOIN kds_order_items k ON k.organization_id=c.organization_id AND k.check_id=c.id
        JOIN kds_order_events e ON e.organization_id=k.organization_id AND e.kds_order_item_id=k.id
        WHERE c.organization_id=? AND c.public_id=? ORDER BY e.id",
    'builds'=>"SELECT k.pos_check_item_id,b.public_id,b.status
        FROM pos_checks c JOIN kds_order_items k ON k.organization_id=c.organization_id AND k.check_id=c.id
        JOIN glasses_build_sessions b ON b.organization_id=k.organization_id AND b.kds_order_item_id=k.id
        WHERE c.organization_id=? AND c.public_id=? ORDER BY b.id",
    'observations'=>"SELECT b.public_id session_public_id,o.observation_key
        FROM pos_checks c JOIN kds_order_items k ON k.organization_id=c.organization_id AND k.check_id=c.id
        JOIN glasses_build_sessions b ON b.organization_id=k.organization_id AND b.kds_order_item_id=k.id
        JOIN glasses_build_observations o ON o.organization_id=b.organization_id AND o.build_session_id=b.id
        WHERE c.organization_id=? AND c.public_id=? ORDER BY o.id",
    'corrections'=>"SELECT b.public_id session_public_id,r.correction_key,r.resolution
        FROM pos_checks c JOIN kds_order_items k ON k.organization_id=c.organization_id AND k.check_id=c.id
        JOIN glasses_build_sessions b ON b.organization_id=k.organization_id AND b.kds_order_item_id=k.id
        JOIN glasses_observation_corrections r ON r.organization_id=b.organization_id AND r.build_session_id=b.id
        WHERE c.organization_id=? AND c.public_id=? ORDER BY r.id",
    'handoffs'=>"SELECT k.pos_check_item_id,h.public_id,h.validation_snapshot_hash,v.status validation_status
        FROM pos_checks c JOIN kds_order_items k ON k.organization_id=c.organization_id AND k.check_id=c.id
        JOIN glasses_kds_handoffs h ON h.organization_id=k.organization_id AND h.kds_order_item_id=k.id
        JOIN glasses_product_validations v ON v.organization_id=h.organization_id AND v.id=h.validation_id
        WHERE c.organization_id=? AND c.public_id=? ORDER BY h.id",
];
$result=[];
foreach($queries as $name=>$sql){
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$org,$check]);
    $result[$name]=$stmt->fetchAll();
}
echo json_encode($result,JSON_THROW_ON_ERROR);
