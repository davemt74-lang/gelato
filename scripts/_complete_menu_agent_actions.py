from pathlib import Path

p=Path('api/agent-brain.php')
s=p.read_text(encoding='utf-8')

old="require __DIR__ . '/../includes/menu-operations-core.php';"
new=old+"\nrequire_once __DIR__ . '/../includes/menu-manager-core.php';"
if new not in s:
    if old not in s: raise SystemExit('menu operations require not found')
    s=s.replace(old,new,1)

marker="function brain_answer(PDO $pdo,int $organizationId,string $message,array $user):array\n{"
helpers=r'''function brain_menu_ids(array $raw): array
{
    return array_values(array_unique(array_filter(array_map('intval',$raw),static fn(int $v):bool=>$v>0)));
}

function brain_menu_bulk_move(PDO $pdo,int $org,array $itemIds,int $categoryId,int $userId): int
{
    $ids=brain_menu_ids($itemIds);if(!$ids)throw new InvalidArgumentException('Select at least one menu item.');
    $q=$pdo->prepare("SELECT id,name FROM menu_sections WHERE organization_id=? AND id=? AND status='active' LIMIT 1");$q->execute([$org,$categoryId]);$category=$q->fetch();if(!$category)throw new InvalidArgumentException('Choose an active destination category.');
    $q=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM menu_items WHERE organization_id=? AND section_id=?');$q->execute([$org,$categoryId]);$sort=(int)$q->fetchColumn();
    $pdo->beginTransaction();try{foreach($ids as $id){menu_operations_item_row($pdo,$org,$id);$sort+=10;$pdo->prepare('UPDATE menu_items SET section_id=?,sort_order=?,version=version+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$categoryId,$sort,$org,$id]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    menu_operations_event($pdo,$org,'items.bulk_moved','Selected menu items moved to '.$category['name'],null,null,null,$userId,['itemIds'=>$ids,'categoryId'=>$categoryId]);menu_operations_sync_brain($pdo,$org,$userId);return count($ids);
}

function brain_menu_bulk_distribution(PDO $pdo,int $org,array $itemIds,string $channel,bool $enabled,int $userId): int
{
    $ids=brain_menu_ids($itemIds);if(!$ids)throw new InvalidArgumentException('Select at least one menu item.');$column=menu_manager_channels()[$channel]??null;if($column===null)throw new InvalidArgumentException('Unknown distribution channel.');
    $pdo->beginTransaction();try{foreach($ids as $id){menu_operations_item_row($pdo,$org,$id);$pdo->prepare("UPDATE menu_item_profiles SET {$column}=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND menu_item_id=?")->execute([$enabled?1:0,$userId,$org,$id]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    menu_operations_event($pdo,$org,'distribution.bulk_updated','Bulk menu distribution updated',null,null,null,$userId,['itemIds'=>$ids,'channel'=>$channel,'enabled'=>$enabled]);menu_operations_sync_brain($pdo,$org,$userId);return count($ids);
}

function brain_menu_bulk_status(PDO $pdo,int $org,array $itemIds,string $status,int $userId): int
{
    $ids=brain_menu_ids($itemIds);if(!$ids)throw new InvalidArgumentException('Select at least one menu item.');if(!in_array($status,['publish','pause','resume','archive','draft'],true))throw new InvalidArgumentException('Unknown lifecycle status.');
    foreach($ids as $id)menu_manager_set_status($pdo,$org,$id,$status,$userId);menu_operations_event($pdo,$org,'lifecycle.bulk_updated','Bulk menu lifecycle changed to '.$status,null,null,null,$userId,['itemIds'=>$ids]);menu_operations_sync_brain($pdo,$org,$userId);return count($ids);
}

'''
if 'function brain_menu_bulk_move(' not in s:
    if marker not in s: raise SystemExit('brain_answer marker not found')
    s=s.replace(marker,helpers+marker,1)

old_handler="    elseif($skill==='menu.update_price'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_update_price($pdo,$organizationId,(int)($args['priceId']??0),(float)($args['amount']??-1),(int)$user['id'])];}\n    elseif($skill==='equipment.search')"
new_handler="""    elseif($skill==='menu.update_price'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_update_price($pdo,$organizationId,(int)($args['priceId']??0),(float)($args['amount']??-1),(int)$user['id'])];}
    elseif($skill==='menu.bulk_price'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedPrices'=>menu_operations_bulk_price($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(string)($args['mode']??''),(float)($args['value']??0),(int)$user['id'])]];}
    elseif($skill==='menu.schedule_save'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_save_schedule($pdo,$organizationId,(int)($args['itemId']??0),((int)($args['locationId']??0))?:null,(string)($args['channel']??'online_order'),is_array($args['rows']??null)?$args['rows']:[],(int)$user['id'])];}
    elseif($skill==='menu.reorder_categories'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);menu_operations_reorder_categories($pdo,$organizationId,is_array($args['ids']??null)?$args['ids']:[],(int)$user['id']);$result=['skill'=>$skill,'data'=>['updated'=>true]];}
    elseif($skill==='menu.reorder_items'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);menu_operations_reorder_items($pdo,$organizationId,(int)($args['categoryId']??0),is_array($args['ids']??null)?$args['ids']:[],(int)$user['id']);$result=['skill'=>$skill,'data'=>['updated'=>true]];}
    elseif($skill==='menu.bulk_move'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedItems'=>brain_menu_bulk_move($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(int)($args['categoryId']??0),(int)$user['id'])]];}
    elseif($skill==='menu.bulk_distribution'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedItems'=>brain_menu_bulk_distribution($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(string)($args['channel']??''),!empty($args['enabled']),(int)$user['id'])]];}
    elseif($skill==='menu.bulk_status'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedItems'=>brain_menu_bulk_status($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(string)($args['status']??''),(int)$user['id'])]];}
    elseif($skill==='equipment.search')"""
if "menu.bulk_price" not in s:
    if old_handler not in s: raise SystemExit('menu update-price handler marker not found')
    s=s.replace(old_handler,new_handler,1)

p.write_text(s,encoding='utf-8')
