from pathlib import Path
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if old not in text:
        raise SystemExit(f"patch target not found: {label}")
    return text.replace(old, new, 1)


# Canonical Menu Manager projection and validation.
p = Path("includes/menu-manager-core.php")
s = p.read_text()
s = replace_once(s, "require_once __DIR__.'/pos-core.php';", "require_once __DIR__.'/pos-core.php';\nrequire_once __DIR__.'/menu-operations-core.php';", "menu operations require")
s = replace_once(s, "SELECT i.id,i.name,i.slug,i.description,i.is_active,i.version,i.section_id,s.name category_name", "SELECT i.id,i.name,i.slug,i.description,i.is_active,i.version,i.sort_order,i.section_id,s.name category_name", "list sort select")
s = replace_once(s, "GROUP BY i.id,s.id,mp.id ORDER BY s.sort_order,s.name,i.name,i.id", "GROUP BY i.id,s.id,mp.id ORDER BY s.sort_order,s.name,i.sort_order,i.name,i.id", "list item order")
s = replace_once(s, "'active'=>(bool)$r['is_active'],'version'=>(int)$r['version'],'priceCount'", "'active'=>(bool)$r['is_active'],'version'=>(int)$r['version'],'sortOrder'=>(int)($r['sort_order']??0),'priceCount'", "list sort payload")
old = """        if($existing){
            $pdo->prepare('UPDATE menu_items SET section_id=?,name=?,slug=?,description=?,preparation_notes=?,behavior_tags_json=?,is_active=?,version=version+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$categoryId,$name,$slug,$description,$prep,$metadataJson,$active,$org,$itemId]);
        }else{
            $pdo->prepare('INSERT INTO menu_items (organization_id,section_id,name,slug,description,preparation_notes,behavior_tags_json,is_active,version) VALUES (?,?,?,?,?,?,?,0,1)')->execute([$org,$categoryId,$name,$slug,$description,$prep,$metadataJson]);$itemId=(int)$pdo->lastInsertId();
        }
"""
new = """        $sortOrder=$existing?(int)($existing['sort_order']??0):0;
        if($existing && (int)$existing['section_id']!==$categoryId){$q=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM menu_items WHERE organization_id=? AND section_id=?');$q->execute([$org,$categoryId]);$sortOrder=(int)$q->fetchColumn();}
        if($existing){
            $pdo->prepare('UPDATE menu_items SET section_id=?,name=?,slug=?,description=?,preparation_notes=?,behavior_tags_json=?,is_active=?,sort_order=?,version=version+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$categoryId,$name,$slug,$description,$prep,$metadataJson,$active,$sortOrder,$org,$itemId]);
        }else{
            $q=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM menu_items WHERE organization_id=? AND section_id=?');$q->execute([$org,$categoryId]);$sortOrder=(int)$q->fetchColumn();
            $pdo->prepare('INSERT INTO menu_items (organization_id,section_id,name,slug,description,preparation_notes,behavior_tags_json,is_active,version,sort_order) VALUES (?,?,?,?,?,?,?,0,1,?)')->execute([$org,$categoryId,$name,$slug,$description,$prep,$metadataJson,$sortOrder]);$itemId=(int)$pdo->lastInsertId();
        }
"""
s = replace_once(s, old, new, "sensible item sort on category move")
s = replace_once(s, "    return true;\n}\n\nfunction menu_manager_price_channel_enabled", "    if(function_exists('menu_operations_item_sellable')&&!menu_operations_item_sellable($pdo,$org,$itemId,$channel,$locationId))return false;\n    return true;\n}\n\nfunction menu_manager_price_channel_enabled", "item operational check")
old_price = """function menu_manager_price_channel_enabled(PDO $pdo,int $org,int $priceId,string $channel,?int $locationId=null): bool
{
    $q=$pdo->prepare(\"SELECT p.menu_item_id FROM menu_item_prices p JOIN menu_items i ON i.id=p.menu_item_id AND i.organization_id=? WHERE p.id=? AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6)) LIMIT 1\");$q->execute([$org,$priceId]);$itemId=(int)($q->fetchColumn()?:0);return $itemId>0&&menu_manager_item_channel_enabled($pdo,$org,$itemId,$channel,$locationId);
}
"""
new_price = """function menu_manager_price_channel_enabled(PDO $pdo,int $org,int $priceId,string $channel,?int $locationId=null): bool
{
    $q=$pdo->prepare(\"SELECT p.menu_item_id FROM menu_item_prices p JOIN menu_items i ON i.id=p.menu_item_id AND i.organization_id=? WHERE p.id=? AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6)) LIMIT 1\");$q->execute([$org,$priceId]);$itemId=(int)($q->fetchColumn()?:0);
    if($itemId<1||!menu_manager_item_channel_enabled($pdo,$org,$itemId,$channel,$locationId))return false;
    return !function_exists('menu_operations_price_sellable')||menu_operations_price_sellable($pdo,$org,$priceId,$channel,$locationId);
}
"""
s = replace_once(s, old_price, new_price, "price operational validation")
new_channel = r'''function menu_manager_channel_menu(PDO $pdo,int $org,string $channel,?int $locationId=null): array
{
    $column=menu_manager_channels()[$channel]??null;if($column===null)return [];$args=[$org];$locationJoin='';$locationClause='';
    if($locationId){$locationJoin=' LEFT JOIN (SELECT menu_item_id,COUNT(*) total,MAX(CASE WHEN location_id=? THEN is_available ELSE 0 END) available FROM menu_item_location_availability WHERE organization_id=? GROUP BY menu_item_id) la ON la.menu_item_id=i.id';$args=[$locationId,$org,$org];$locationClause=' AND (la.total IS NULL OR la.total=0 OR la.available=1)';}
    $sql="SELECT s.id section_id,s.name section_name,s.sort_order section_sort,i.id item_id,i.name item_name,i.description,i.sort_order item_sort,p.id price_id,p.option_name,p.size_code,p.amount,p.currency,p.sort_order price_sort
          FROM menu_sections s JOIN menu_items i ON i.section_id=s.id AND i.organization_id=s.organization_id AND i.is_active=1
          LEFT JOIN menu_item_profiles mp ON mp.organization_id=i.organization_id AND mp.menu_item_id=i.id
          $locationJoin
          JOIN menu_item_prices p ON p.menu_item_id=i.id AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6))
          WHERE s.organization_id=? AND s.status='active' AND (mp.id IS NULL OR (mp.lifecycle_status='published' AND mp.$column=1)) $locationClause
          ORDER BY s.sort_order,s.name,i.sort_order,i.name,p.sort_order,p.amount,p.id";
    $q=$pdo->prepare($sql);$q->execute($args);$sections=[];
    foreach($q->fetchAll() as $r){
        $iid=(int)$r['item_id'];$pid=(int)$r['price_id'];
        if(function_exists('menu_operations_item_sellable')&&!menu_operations_item_sellable($pdo,$org,$iid,$channel,$locationId))continue;
        if(function_exists('menu_operations_price_sellable')&&!menu_operations_price_sellable($pdo,$org,$pid,$channel,$locationId))continue;
        $sid=(int)$r['section_id'];if(!isset($sections[$sid]))$sections[$sid]=['id'=>$sid,'name'=>(string)$r['section_name'],'items'=>[]];if(!isset($sections[$sid]['items'][$iid]))$sections[$sid]['items'][$iid]=['id'=>$iid,'name'=>(string)$r['item_name'],'description'=>$r['description'],'prices'=>[]];$sections[$sid]['items'][$iid]['prices'][]=['id'=>$pid,'optionName'=>(string)$r['option_name'],'sizeCode'=>$r['size_code'],'amount'=>(float)$r['amount'],'currency'=>(string)$r['currency']];
    }
    foreach($sections as $sid=>&$section){$section['items']=array_values(array_filter($section['items'],static fn(array $item):bool=>!empty($item['prices'])));if(!$section['items'])unset($sections[$sid]);}unset($section);return array_values($sections);
}
'''
pattern = r"function menu_manager_channel_menu\(PDO \$pdo,int \$org,string \$channel,\?int \$locationId=null\): array\n\{.*?\n\}\n\n(?=function menu_manager_public_sections)"
s, count = re.subn(pattern, new_channel + "\n", s, count=1, flags=re.S)
if count != 1:
    raise SystemExit("patch target not found: channel menu replacement")
p.write_text(s)

# Main Agent Workspace route.
p = Path("includes/agent-workspace-core.php")
s = p.read_text()
marker = "    $text=mb_strtolower(preg_replace('/^hey\\s+gelato[,\\s]*/iu','',trim($message))??trim($message),'UTF-8');\n"
s = replace_once(s, marker, marker + "    $menu='/\\b(menu|menu item|sold.?out|86(?:d)?|eighty.?six|food item|drink item|menu price|pizza size|modifier|topping|add.?on)\\b/u';\n", "agent menu intent pattern")
marker = "    if(preg_match($time,$text)&&(app_has_permission('timeclock.agent',$user)||app_has_permission('timeclock.self',$user)||app_has_permission('attendance.view',$user)))return ['route'=>'api/timeclock-agent.php','domain'=>'timeclock'];"
s = replace_once(s, marker, "    if(preg_match($menu,$text)&&app_has_permission('menu.view',$user))return ['route'=>'api/agent-brain.php','domain'=>'restaurant_brain'];\n" + marker, "agent menu route")
s = replace_once(s, "if(app_has_permission('agent.equipment_skills',$user)||app_has_permission('wholesale.agent',$user)||app_has_permission('recipes.agent',$user))return ['route'=>'api/agent-brain.php','domain'=>'restaurant_brain'];", "if(app_has_permission('menu.view',$user)||app_has_permission('agent.equipment_skills',$user)||app_has_permission('wholesale.agent',$user)||app_has_permission('recipes.agent',$user))return ['route'=>'api/agent-brain.php','domain'=>'restaurant_brain'];", "agent menu fallback")
p.write_text(s)

# Media changes refresh menu brain snapshot.
p = Path("api/media.php")
s = p.read_text()
s = replace_once(s, "require_once __DIR__.'/../includes/media-core.php';", "require_once __DIR__.'/../includes/media-core.php';\nrequire_once __DIR__.'/../includes/menu-operations-core.php';", "media operations require")
s = replace_once(s, "        app_audit($pdo,$org,$userId,'media.removed',$target,(string)$id,null,['target'=>$target]);\n        app_json_response(['ok'=>true,'media'=>$media]);", "        app_audit($pdo,$org,$userId,'media.removed',$target,(string)$id,null,['target'=>$target]);\n        if(menu_operations_ready($pdo)&&in_array($target,['menu_item','ingredient','location'],true)){menu_operations_event($pdo,$org,'media.removed',ucfirst(str_replace('_',' ',$target)).' image removed',$target==='menu_item'?$id:null,null,$target==='location'?$id:null,$userId,['target'=>$target,'id'=>$id]);menu_operations_sync_brain($pdo,$org,$userId);}\n        app_json_response(['ok'=>true,'media'=>$media]);", "media remove brain sync")
s = replace_once(s, "    app_audit($pdo,$org,$userId,'media.uploaded',$target,(string)$id,null,['target'=>$target,'fileId'=>$stored['id'],'mimeType'=>$stored['mimeType'],'fileSize'=>$stored['fileSize']]);\n    app_json_response(['ok'=>true,'media'=>$media]);", "    app_audit($pdo,$org,$userId,'media.uploaded',$target,(string)$id,null,['target'=>$target,'fileId'=>$stored['id'],'mimeType'=>$stored['mimeType'],'fileSize'=>$stored['fileSize']]);\n    if(menu_operations_ready($pdo)&&in_array($target,['menu_item','ingredient','location'],true)){menu_operations_event($pdo,$org,'media.uploaded',ucfirst(str_replace('_',' ',$target)).' image updated',$target==='menu_item'?$id:null,null,$target==='location'?$id:null,$userId,['target'=>$target,'id'=>$id]);menu_operations_sync_brain($pdo,$org,$userId);}\n    app_json_response(['ok'=>true,'media'=>$media]);", "media upload brain sync")
p.write_text(s)

# Bulk move endpoint in the expanded Menu Manager API.
p = Path("api/menu-manager.php")
s = p.read_text()
needle = "        if($action==='operations.bulk_distribution'){\n"
insert = """        if($action==='operations.bulk_move'){
            $ids=array_values(array_unique(array_filter(array_map('intval',is_array($payload['itemIds']??null)?$payload['itemIds']:[]),static fn(int $v):bool=>$v>0)));if(!$ids)throw new InvalidArgumentException('Select at least one menu item.');$categoryId=(int)($payload['categoryId']??0);$q=$pdo->prepare(\"SELECT id,name FROM menu_sections WHERE organization_id=? AND id=? AND status='active' LIMIT 1\");$q->execute([$org,$categoryId]);$category=$q->fetch();if(!$category)throw new InvalidArgumentException('Choose an active destination category.');$q=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM menu_items WHERE organization_id=? AND section_id=?');$q->execute([$org,$categoryId]);$sort=(int)$q->fetchColumn();$pdo->beginTransaction();try{foreach($ids as $id){menu_operations_item_row($pdo,$org,$id);$sort+=10;$pdo->prepare('UPDATE menu_items SET section_id=?,sort_order=?,version=version+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$categoryId,$sort,$org,$id]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}menu_operations_event($pdo,$org,'items.bulk_moved','Selected menu items moved to '.$category['name'],null,null,null,$userId,['itemIds'=>$ids,'categoryId'=>$categoryId]);menu_operations_sync_brain($pdo,$org,$userId);app_audit($pdo,$org,$userId,'menu.operations.bulk_move','menu_section',(string)$categoryId,null,['count'=>count($ids)]);app_json_response(['ok'=>true,'updatedItems'=>count($ids)]);
        }
""" + needle
s = replace_once(s, needle, insert, "bulk move API")
p.write_text(s)
