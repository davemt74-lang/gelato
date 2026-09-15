<?php
declare(strict_types=1);

require_once __DIR__.'/menu-sync.php';
require_once __DIR__.'/pos-core.php';

function menu_manager_tables(): array
{
    return ['menu_item_profiles','menu_item_location_availability','menu_modifier_groups','menu_modifier_options','menu_modifier_option_prices','pos_check_item_modifiers'];
}

function menu_manager_ready(PDO $pdo): bool
{
    try{
        foreach(menu_manager_tables() as $table){
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $q->execute([$table]);
            if((int)$q->fetchColumn()!==1)return false;
        }
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_check_items' AND column_name='modifier_amount'");
        return (int)$q->fetchColumn()===1;
    }catch(Throwable){return false;}
}

function menu_manager_lifecycle_statuses(): array { return ['draft','published','paused','archived']; }
function menu_manager_channels(): array
{
    return [
        'public_menu'=>'public_menu_enabled',
        'online_order'=>'online_order_enabled',
        'pos'=>'pos_enabled',
        'packages'=>'packages_enabled',
        'catering'=>'catering_enabled',
    ];
}

function menu_manager_slug_unique(PDO $pdo,int $org,string $name,?int $ignoreId=null): string
{
    $base=menu_slugify($name);
    for($i=0;$i<500;$i++){
        $slug=$i===0?$base:$base.'-'.($i+1);
        $sql='SELECT COUNT(*) FROM menu_items WHERE organization_id=? AND slug=?'.($ignoreId?' AND id<>?':'');
        $q=$pdo->prepare($sql);$args=[$org,$slug];if($ignoreId)$args[]=$ignoreId;$q->execute($args);
        if((int)$q->fetchColumn()===0)return $slug;
    }
    return $base.'-'.bin2hex(random_bytes(4));
}

function menu_manager_category_slug_unique(PDO $pdo,int $org,string $name,?int $ignoreId=null): string
{
    $base=menu_slugify($name);
    for($i=0;$i<500;$i++){
        $slug=$i===0?$base:$base.'-'.($i+1);
        $sql='SELECT COUNT(*) FROM menu_sections WHERE organization_id=? AND slug=?'.($ignoreId?' AND id<>?':'');
        $q=$pdo->prepare($sql);$args=[$org,$slug];if($ignoreId)$args[]=$ignoreId;$q->execute($args);
        if((int)$q->fetchColumn()===0)return $slug;
    }
    return $base.'-'.bin2hex(random_bytes(4));
}

function menu_manager_categories(PDO $pdo,int $org,bool $includeInactive=true): array
{
    $sql='SELECT id,name,slug,description,sort_order,status FROM menu_sections WHERE organization_id=?'.($includeInactive?'':" AND status='active'").' ORDER BY sort_order,name,id';
    $q=$pdo->prepare($sql);$q->execute([$org]);
    return array_map(static fn(array $r):array=>[
        'id'=>(int)$r['id'],'name'=>(string)$r['name'],'slug'=>(string)$r['slug'],'description'=>(string)($r['description']??''),
        'sortOrder'=>(int)$r['sort_order'],'status'=>(string)$r['status'],
    ],$q->fetchAll());
}

function menu_manager_category_save(PDO $pdo,int $org,?int $categoryId,array $input): array
{
    $name=mb_substr(trim((string)($input['name']??'')),0,160,'UTF-8');
    if($name==='')throw new InvalidArgumentException('Category name is required.');
    $description=mb_substr(trim((string)($input['description']??'')),0,5000,'UTF-8')?:null;
    $sort=(int)($input['sortOrder']??0);$status=((string)($input['status']??'active'))==='inactive'?'inactive':'active';
    if($categoryId){
        $q=$pdo->prepare('SELECT id FROM menu_sections WHERE organization_id=? AND id=? LIMIT 1 FOR UPDATE');$q->execute([$org,$categoryId]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Menu category was not found.');
        $slug=menu_manager_category_slug_unique($pdo,$org,(string)($input['slug']??$name),$categoryId);
        $pdo->prepare('UPDATE menu_sections SET name=?,slug=?,description=?,sort_order=?,status=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
            ->execute([$name,$slug,$description,$sort,$status,$org,$categoryId]);
    }else{
        $slug=menu_manager_category_slug_unique($pdo,$org,(string)($input['slug']??$name));
        $pdo->prepare('INSERT INTO menu_sections (organization_id,name,slug,description,sort_order,status) VALUES (?,?,?,?,?,?)')
            ->execute([$org,$name,$slug,$description,$sort,$status]);
        $categoryId=(int)$pdo->lastInsertId();
    }
    $q=$pdo->prepare('SELECT id,name,slug,description,sort_order,status FROM menu_sections WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$categoryId]);
    $r=$q->fetch();
    return ['id'=>(int)$r['id'],'name'=>(string)$r['name'],'slug'=>(string)$r['slug'],'description'=>(string)($r['description']??''),'sortOrder'=>(int)$r['sort_order'],'status'=>(string)$r['status']];
}

function menu_manager_profile_defaults(array $item): array
{
    return [
        'itemType'=>'food','lifecycleStatus'=>!empty($item['is_active'])?'published':'paused','kitchenStation'=>'',
        'publicMenu'=>true,'onlineOrder'=>true,'pos'=>true,'packages'=>true,'catering'=>false,
    ];
}

function menu_manager_item_profile(PDO $pdo,int $org,int $itemId,array $item=[]): array
{
    if(!menu_manager_ready($pdo))return menu_manager_profile_defaults($item);
    $q=$pdo->prepare('SELECT * FROM menu_item_profiles WHERE organization_id=? AND menu_item_id=? LIMIT 1');$q->execute([$org,$itemId]);$r=$q->fetch();
    if(!$r)return menu_manager_profile_defaults($item);
    return [
        'itemType'=>(string)$r['item_type'],'lifecycleStatus'=>(string)$r['lifecycle_status'],'kitchenStation'=>(string)($r['kitchen_station']??''),
        'publicMenu'=>(bool)$r['public_menu_enabled'],'onlineOrder'=>(bool)$r['online_order_enabled'],'pos'=>(bool)$r['pos_enabled'],
        'packages'=>(bool)$r['packages_enabled'],'catering'=>(bool)$r['catering_enabled'],
    ];
}

function menu_manager_list_items(PDO $pdo,int $org,string $query='',?int $categoryId=null,bool $includeArchived=false): array
{
    $args=[$org];$where=['i.organization_id=?'];
    if($categoryId){$where[]='i.section_id=?';$args[]=$categoryId;}
    if(trim($query)!==''){$where[]='(i.name LIKE ? OR s.name LIKE ? OR i.description LIKE ?)';$like='%'.trim($query).'%';array_push($args,$like,$like,$like);}
    if(!$includeArchived)$where[]="COALESCE(mp.lifecycle_status,IF(i.is_active=1,'published','paused'))<>'archived'";
    $sql="SELECT i.id,i.name,i.slug,i.description,i.is_active,i.version,i.section_id,s.name category_name,s.status category_status,
                 mp.item_type,mp.lifecycle_status,mp.kitchen_station,mp.public_menu_enabled,mp.online_order_enabled,mp.pos_enabled,mp.packages_enabled,mp.catering_enabled,
                 MIN(CASE WHEN p.active_until IS NULL OR p.active_until>NOW(6) THEN p.amount END) min_price,
                 MAX(CASE WHEN p.active_until IS NULL OR p.active_until>NOW(6) THEN p.amount END) max_price,
                 COUNT(DISTINCT CASE WHEN p.active_until IS NULL OR p.active_until>NOW(6) THEN p.id END) price_count
          FROM menu_items i JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id
          LEFT JOIN menu_item_profiles mp ON mp.menu_item_id=i.id AND mp.organization_id=i.organization_id
          LEFT JOIN menu_item_prices p ON p.menu_item_id=i.id
          WHERE ".implode(' AND ',$where)." GROUP BY i.id,s.id,mp.id ORDER BY s.sort_order,s.name,i.name,i.id";
    $q=$pdo->prepare($sql);$q->execute($args);$out=[];
    foreach($q->fetchAll() as $r){
        $legacy=$r['lifecycle_status']===null;
        $out[]=[
            'id'=>(int)$r['id'],'name'=>(string)$r['name'],'slug'=>(string)$r['slug'],'description'=>(string)($r['description']??''),'categoryId'=>(int)$r['section_id'],'categoryName'=>(string)$r['category_name'],
            'itemType'=>$legacy?'legacy':(string)$r['item_type'],'lifecycleStatus'=>$legacy?((int)$r['is_active']===1?'published':'paused'):(string)$r['lifecycle_status'],
            'active'=>(bool)$r['is_active'],'version'=>(int)$r['version'],'priceCount'=>(int)$r['price_count'],'minPrice'=>$r['min_price']!==null?(float)$r['min_price']:null,'maxPrice'=>$r['max_price']!==null?(float)$r['max_price']:null,
            'distribution'=>[
                'publicMenu'=>$legacy?true:(bool)$r['public_menu_enabled'],'onlineOrder'=>$legacy?true:(bool)$r['online_order_enabled'],'pos'=>$legacy?true:(bool)$r['pos_enabled'],
                'packages'=>$legacy?true:(bool)$r['packages_enabled'],'catering'=>$legacy?false:(bool)$r['catering_enabled'],
            ],
        ];
    }
    return $out;
}

function menu_manager_item_row(PDO $pdo,int $org,int $itemId,bool $forUpdate=false): array
{
    $q=$pdo->prepare('SELECT i.*,s.name category_name,s.slug category_slug,s.status category_status FROM menu_items i JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id WHERE i.organization_id=? AND i.id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $q->execute([$org,$itemId]);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Menu item was not found.');return $r;
}

function menu_manager_item_payload(PDO $pdo,int $org,int $itemId): array
{
    $item=menu_manager_item_row($pdo,$org,$itemId,false);$profile=menu_manager_item_profile($pdo,$org,$itemId,$item);
    $q=$pdo->prepare('SELECT id,option_name,size_code,amount,currency,sort_order,active_from,active_until FROM menu_item_prices WHERE menu_item_id=? ORDER BY sort_order,id');$q->execute([$itemId]);
    $prices=[];foreach($q->fetchAll() as $r)$prices[]=['id'=>(int)$r['id'],'label'=>(string)$r['option_name'],'sizeCode'=>(string)($r['size_code']??''),'amount'=>(float)$r['amount'],'currency'=>(string)$r['currency'],'sortOrder'=>(int)$r['sort_order'],'activeFrom'=>$r['active_from'],'activeUntil'=>$r['active_until'],'active'=>$r['active_until']===null||strtotime((string)$r['active_until'])>time()];
    $q=$pdo->prepare('SELECT ing.id,ing.canonical_name name,ing.slug,ing.category,mii.display_name,mii.is_optional,mii.can_remove,mii.sort_order FROM menu_item_ingredients mii JOIN ingredients ing ON ing.id=mii.ingredient_id AND ing.organization_id=? WHERE mii.menu_item_id=? ORDER BY mii.sort_order,ing.canonical_name');$q->execute([$org,$itemId]);
    $ingredients=[];foreach($q->fetchAll() as $r)$ingredients[]=['id'=>(int)$r['id'],'name'=>(string)($r['display_name']?:$r['name']),'canonicalName'=>(string)$r['name'],'category'=>(string)($r['category']??''),'optional'=>(bool)$r['is_optional'],'canRemove'=>(bool)$r['can_remove'],'sortOrder'=>(int)$r['sort_order']];
    $groups=[];
    if(menu_manager_ready($pdo)){
        $q=$pdo->prepare("SELECT g.id group_id,g.public_id group_public,g.name group_name,g.modifier_type,g.min_select,g.max_select,g.sort_order group_sort,
                                o.id option_id,o.public_id option_public,o.ingredient_id,o.name option_name,o.default_price_delta,o.max_quantity,o.sort_order option_sort
                         FROM menu_modifier_groups g LEFT JOIN menu_modifier_options o ON o.modifier_group_id=g.id AND o.organization_id=g.organization_id AND o.status='active'
                         WHERE g.organization_id=? AND g.menu_item_id=? AND g.status='active' ORDER BY g.sort_order,g.id,o.sort_order,o.id");$q->execute([$org,$itemId]);
        foreach($q->fetchAll() as $r){$gid=(int)$r['group_id'];if(!isset($groups[$gid]))$groups[$gid]=['id'=>(string)$r['group_public'],'name'=>(string)$r['group_name'],'type'=>(string)$r['modifier_type'],'minSelect'=>(int)$r['min_select'],'maxSelect'=>(int)$r['max_select'],'sortOrder'=>(int)$r['group_sort'],'options'=>[]];if($r['option_id']!==null)$groups[$gid]['options'][(int)$r['option_id']]=['id'=>(string)$r['option_public'],'dbId'=>(int)$r['option_id'],'ingredientId'=>$r['ingredient_id']!==null?(int)$r['ingredient_id']:null,'name'=>(string)$r['option_name'],'defaultPriceDelta'=>(float)$r['default_price_delta'],'maxQuantity'=>(int)$r['max_quantity'],'sortOrder'=>(int)$r['option_sort'],'sizePrices'=>[]];}
        if($groups){
            $q=$pdo->prepare("SELECT op.modifier_option_id,op.menu_item_price_id,op.amount_delta FROM menu_modifier_option_prices op JOIN menu_modifier_options o ON o.id=op.modifier_option_id JOIN menu_modifier_groups g ON g.id=o.modifier_group_id WHERE op.organization_id=? AND g.menu_item_id=? AND g.status='active' AND o.status='active'");$q->execute([$org,$itemId]);
            foreach($q->fetchAll() as $r){foreach($groups as &$group){if(isset($group['options'][(int)$r['modifier_option_id']])){$group['options'][(int)$r['modifier_option_id']]['sizePrices'][]=['priceId'=>(int)$r['menu_item_price_id'],'amountDelta'=>(float)$r['amount_delta']];break;}}unset($group);}
        }
        foreach($groups as &$group)$group['options']=array_values($group['options']);unset($group);
    }
    $locations=[];$q=$pdo->prepare("SELECT l.id,l.name,l.status,COALESCE(a.is_available,1) is_available FROM locations l LEFT JOIN menu_item_location_availability a ON a.organization_id=l.organization_id AND a.menu_item_id=? AND a.location_id=l.id WHERE l.organization_id=? ORDER BY l.status='active' DESC,l.name,l.id");$q->execute([$itemId,$org]);foreach($q->fetchAll() as $r)$locations[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'status'=>(string)$r['status'],'available'=>(bool)$r['is_available']];
    $metadata=[];try{$metadata=json_decode((string)($item['behavior_tags_json']??'{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($metadata))$metadata=[];}catch(Throwable){$metadata=[];}
    return [
        'id'=>(int)$item['id'],'name'=>(string)$item['name'],'slug'=>(string)$item['slug'],'description'=>(string)($item['description']??''),'preparationNotes'=>(string)($item['preparation_notes']??''),
        'categoryId'=>(int)$item['section_id'],'categoryName'=>(string)$item['category_name'],'active'=>(bool)$item['is_active'],'version'=>(int)$item['version'],'metadata'=>$metadata,
        'profile'=>$profile,'sizes'=>$prices,'ingredients'=>$ingredients,'modifierGroups'=>array_values($groups),'locations'=>$locations,
    ];
}

function menu_manager_ingredient_upsert(PDO $pdo,int $org,string $name): int
{
    $name=mb_substr(trim($name),0,160,'UTF-8');if($name==='')throw new InvalidArgumentException('Ingredient name cannot be empty.');$slug=menu_slugify($name);
    $pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'menu-builder','verified') ON DUPLICATE KEY UPDATE canonical_name=VALUES(canonical_name)")->execute([$org,$name,$slug]);
    $q=$pdo->prepare('SELECT id FROM ingredients WHERE organization_id=? AND slug=? LIMIT 1');$q->execute([$org,$slug]);$id=(int)$q->fetchColumn();if($id<1)throw new RuntimeException('Ingredient could not be saved.');return $id;
}

function menu_manager_ingredient_search(PDO $pdo,int $org,string $query='',int $limit=60): array
{
    $limit=max(1,min(100,$limit));$like='%'.trim($query).'%';$q=$pdo->prepare("SELECT id,canonical_name,slug,category,verification_status FROM ingredients WHERE organization_id=? AND canonical_name LIKE ? ORDER BY canonical_name,id LIMIT $limit");$q->execute([$org,$like]);
    return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'name'=>(string)$r['canonical_name'],'slug'=>(string)$r['slug'],'category'=>(string)($r['category']??''),'verification'=>(string)($r['verification_status']??'')],$q->fetchAll());
}

function menu_manager_normalize_sizes(array $raw): array
{
    if(!$raw||count($raw)>30)throw new InvalidArgumentException('Add between 1 and 30 size / price options.');$out=[];
    foreach($raw as $index=>$row){if(!is_array($row))continue;$label=mb_substr(trim((string)($row['label']??'')),0,160,'UTF-8');$amount=round((float)($row['amount']??-1),2);if($label===''||$amount<0||$amount>100000)throw new InvalidArgumentException('Each size needs a label and valid price.');$key=mb_substr(trim((string)($row['clientKey']??('size-'.$index))),0,80,'UTF-8');if($key==='')$key='size-'.$index;$out[]=['id'=>max(0,(int)($row['id']??0)),'clientKey'=>$key,'label'=>$label,'sizeCode'=>mb_substr(trim((string)($row['sizeCode']??menu_slugify($label))),0,80,'UTF-8'),'amount'=>$amount,'sortOrder'=>(int)($row['sortOrder']??$index)];}
    if(!$out)throw new InvalidArgumentException('Add at least one size / price.');return $out;
}

function menu_manager_save_item(PDO $pdo,int $org,?int $itemId,array $input,int $userId): array
{
    if(!menu_manager_ready($pdo))throw new RuntimeException('Menu Manager is not installed. Run Upgrade first.');
    $name=mb_substr(trim((string)($input['name']??'')),0,200,'UTF-8');if($name==='')throw new InvalidArgumentException('Food item name is required.');
    $categoryId=max(0,(int)($input['categoryId']??0));$q=$pdo->prepare("SELECT id FROM menu_sections WHERE organization_id=? AND id=? AND status='active' LIMIT 1");$q->execute([$org,$categoryId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active food category.');
    $description=mb_substr(trim((string)($input['description']??'')),0,8000,'UTF-8')?:null;$prep=mb_substr(trim((string)($input['preparationNotes']??'')),0,8000,'UTF-8')?:null;$sizes=menu_manager_normalize_sizes(is_array($input['sizes']??null)?$input['sizes']:[]);
    $profileInput=is_array($input['distribution']??null)?$input['distribution']:[];$station=mb_substr(trim((string)($input['kitchenStation']??'')),0,120,'UTF-8')?:null;
    return pos_transaction($pdo,function()use($pdo,$org,$itemId,$input,$userId,$name,$categoryId,$description,$prep,$sizes,$profileInput,$station):array{
        $existing=$itemId?menu_manager_item_row($pdo,$org,$itemId,true):null;$existingProfile=$existing?menu_manager_item_profile($pdo,$org,$itemId,$existing):null;
        $lifecycle=$existingProfile['lifecycleStatus']??'draft';$active=$lifecycle==='published'?1:0;$slug=menu_manager_slug_unique($pdo,$org,(string)($input['slug']??$name),$itemId);
        $metadata=[];if($existing){try{$metadata=json_decode((string)($existing['behavior_tags_json']??'{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($metadata))$metadata=[];}catch(Throwable){$metadata=[];}}
        $metadata['source']='menu-manager';$metadata['itemType']='food';$metadata['managed']=true;$metadataJson=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if($existing){
            $pdo->prepare('UPDATE menu_items SET section_id=?,name=?,slug=?,description=?,preparation_notes=?,behavior_tags_json=?,is_active=?,version=version+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$categoryId,$name,$slug,$description,$prep,$metadataJson,$active,$org,$itemId]);
        }else{
            $pdo->prepare('INSERT INTO menu_items (organization_id,section_id,name,slug,description,preparation_notes,behavior_tags_json,is_active,version) VALUES (?,?,?,?,?,?,?,0,1)')->execute([$org,$categoryId,$name,$slug,$description,$prep,$metadataJson]);$itemId=(int)$pdo->lastInsertId();
        }
        $public=!empty($profileInput['publicMenu'])?1:0;$online=!empty($profileInput['onlineOrder'])?1:0;$pos=!empty($profileInput['pos'])?1:0;$packages=!empty($profileInput['packages'])?1:0;$catering=!empty($profileInput['catering'])?1:0;
        $pdo->prepare("INSERT INTO menu_item_profiles (organization_id,menu_item_id,item_type,lifecycle_status,kitchen_station,public_menu_enabled,online_order_enabled,pos_enabled,packages_enabled,catering_enabled,created_by,updated_by) VALUES (?,?,'food',?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE item_type='food',kitchen_station=VALUES(kitchen_station),public_menu_enabled=VALUES(public_menu_enabled),online_order_enabled=VALUES(online_order_enabled),pos_enabled=VALUES(pos_enabled),packages_enabled=VALUES(packages_enabled),catering_enabled=VALUES(catering_enabled),updated_by=VALUES(updated_by),updated_at=NOW(6)")
            ->execute([$org,$itemId,$lifecycle,$station,$public,$online,$pos,$packages,$catering,$userId,$userId]);

        $existingPriceIds=[];$q=$pdo->prepare('SELECT id FROM menu_item_prices WHERE menu_item_id=?');$q->execute([$itemId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$existingPriceIds[(int)$id]=true;
        $kept=[];$sizeMap=[];
        foreach($sizes as $size){
            $priceId=(int)$size['id'];
            if($priceId>0){if(!isset($existingPriceIds[$priceId]))throw new InvalidArgumentException('One of the edited size prices does not belong to this item.');$pdo->prepare('UPDATE menu_item_prices SET option_name=?,size_code=?,amount=?,sort_order=?,active_from=COALESCE(active_from,NOW(6)),active_until=NULL WHERE id=? AND menu_item_id=?')->execute([$size['label'],$size['sizeCode'],$size['amount'],$size['sortOrder'],$priceId,$itemId]);}
            else{$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order,active_from,active_until) VALUES (?,?,?,?,'USD',?,NOW(6),NULL)")->execute([$itemId,$size['label'],$size['sizeCode'],$size['amount'],$size['sortOrder']]);$priceId=(int)$pdo->lastInsertId();}
            $kept[$priceId]=true;$sizeMap[$size['clientKey']]=$priceId;$sizeMap[(string)$priceId]=$priceId;
        }
        foreach(array_keys($existingPriceIds) as $oldId)if(!isset($kept[$oldId]))$pdo->prepare('UPDATE menu_item_prices SET active_until=COALESCE(active_until,NOW(6)) WHERE id=? AND menu_item_id=?')->execute([$oldId,$itemId]);

        $pdo->prepare('DELETE FROM menu_item_ingredients WHERE menu_item_id=?')->execute([$itemId]);
        $ingredients=is_array($input['ingredients']??null)?$input['ingredients']:[];if(count($ingredients)>100)throw new InvalidArgumentException('Too many included ingredients.');
        foreach($ingredients as $index=>$ingredient){if(!is_array($ingredient))continue;$ingredientId=(int)($ingredient['id']??0);$display=mb_substr(trim((string)($ingredient['name']??'')),0,160,'UTF-8');if($ingredientId<1){$ingredientId=menu_manager_ingredient_upsert($pdo,$org,$display);}else{$q=$pdo->prepare('SELECT canonical_name FROM ingredients WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$ingredientId]);$canonical=$q->fetchColumn();if($canonical===false)throw new InvalidArgumentException('One of the selected ingredients is invalid.');if($display==='')$display=(string)$canonical;}$pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,?,?,?)')->execute([$itemId,$ingredientId,$display?:null,!empty($ingredient['optional'])?1:0,array_key_exists('canRemove',$ingredient)?(!empty($ingredient['canRemove'])?1:0):1,(int)($ingredient['sortOrder']??$index)]);}

        $pdo->prepare("UPDATE menu_modifier_options o JOIN menu_modifier_groups g ON g.id=o.modifier_group_id SET o.status='inactive',o.updated_at=NOW(6) WHERE g.organization_id=? AND g.menu_item_id=? AND g.status='active'")->execute([$org,$itemId]);
        $pdo->prepare("UPDATE menu_modifier_groups SET status='inactive',updated_at=NOW(6) WHERE organization_id=? AND menu_item_id=? AND status='active'")->execute([$org,$itemId]);
        $groups=is_array($input['modifierGroups']??null)?$input['modifierGroups']:[];if(count($groups)>20)throw new InvalidArgumentException('A food item cannot have more than 20 modifier groups.');
        foreach($groups as $groupIndex=>$group){if(!is_array($group))continue;$groupName=mb_substr(trim((string)($group['name']??'')),0,160,'UTF-8');if($groupName==='')throw new InvalidArgumentException('Each add-on group needs a name.');$type=in_array((string)($group['type']??'add_on'),['add_on','choice'],true)?(string)($group['type']??'add_on'):'add_on';$min=max(0,(int)($group['minSelect']??0));$max=max($min,min(50,(int)($group['maxSelect']??10)));$groupPublic=sales_public_id('modifier-group');$pdo->prepare('INSERT INTO menu_modifier_groups (organization_id,menu_item_id,public_id,name,modifier_type,min_select,max_select,sort_order,status,created_by) VALUES (?,?,?,?,?,?,?,?,\'active\',?)')->execute([$org,$itemId,$groupPublic,$groupName,$type,$min,$max,(int)($group['sortOrder']??$groupIndex),$userId]);$groupId=(int)$pdo->lastInsertId();$options=is_array($group['options']??null)?$group['options']:[];if(count($options)>100)throw new InvalidArgumentException($groupName.' has too many add-ons.');foreach($options as $optionIndex=>$option){if(!is_array($option))continue;$optionName=mb_substr(trim((string)($option['name']??'')),0,160,'UTF-8');if($optionName==='')throw new InvalidArgumentException('Each add-on needs a name.');$ingredientId=(int)($option['ingredientId']??0);if($ingredientId<1&&trim((string)($option['ingredientName']??''))!=='')$ingredientId=menu_manager_ingredient_upsert($pdo,$org,(string)$option['ingredientName']);if($ingredientId>0){$q=$pdo->prepare('SELECT COUNT(*) FROM ingredients WHERE organization_id=? AND id=?');$q->execute([$org,$ingredientId]);if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('An add-on ingredient is invalid.');}$delta=round(max(0,(float)($option['defaultPriceDelta']??0)),2);$maxQty=max(1,min(20,(int)($option['maxQuantity']??1)));$optionPublic=sales_public_id('modifier-option');$pdo->prepare('INSERT INTO menu_modifier_options (organization_id,modifier_group_id,ingredient_id,public_id,name,default_price_delta,max_quantity,sort_order,status) VALUES (?,?,?,?,?,?,?,?,\'active\')')->execute([$org,$groupId,$ingredientId?:null,$optionPublic,$optionName,$delta,$maxQty,(int)($option['sortOrder']??$optionIndex)]);$optionId=(int)$pdo->lastInsertId();$priceDeltas=is_array($option['priceDeltas']??null)?$option['priceDeltas']:[];foreach($priceDeltas as $key=>$value){$pid=$sizeMap[(string)$key]??0;if($pid<1)continue;$amount=round(max(0,(float)$value),2);$pdo->prepare('INSERT INTO menu_modifier_option_prices (organization_id,modifier_option_id,menu_item_price_id,amount_delta) VALUES (?,?,?,?)')->execute([$org,$optionId,$pid,$amount]);}}}

        $pdo->prepare('DELETE FROM menu_item_location_availability WHERE organization_id=? AND menu_item_id=?')->execute([$org,$itemId]);$availability=is_array($input['locationAvailability']??null)?$input['locationAvailability']:[];
        if($availability){$q=$pdo->prepare('SELECT id FROM locations WHERE organization_id=?');$q->execute([$org]);$valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));$validMap=array_fill_keys($valid,true);foreach($availability as $loc=>$isAvailable){$locId=(int)$loc;if($locId<1||!isset($validMap[$locId]))continue;$pdo->prepare('INSERT INTO menu_item_location_availability (organization_id,menu_item_id,location_id,is_available,updated_by) VALUES (?,?,?,?,?)')->execute([$org,$itemId,$locId,!empty($isAvailable)?1:0,$userId]);}}
        return menu_manager_item_payload($pdo,$org,$itemId);
    });
}

function menu_manager_set_status(PDO $pdo,int $org,int $itemId,string $action,int $userId): array
{
    if(!menu_manager_ready($pdo))throw new RuntimeException('Menu Manager is not installed. Run Upgrade first.');$action=strtolower(trim($action));
    return pos_transaction($pdo,function()use($pdo,$org,$itemId,$action,$userId):array{
        $item=menu_manager_item_row($pdo,$org,$itemId,true);$profile=menu_manager_item_profile($pdo,$org,$itemId,$item);$current=$profile['lifecycleStatus'];
        $target=match($action){'publish','resume'=>'published','pause'=>'paused','archive'=>'archived','draft'=>'draft',default=>throw new InvalidArgumentException('Unknown menu item lifecycle action.')};
        if($target==='published'){$q=$pdo->prepare("SELECT COUNT(*) FROM menu_item_prices WHERE menu_item_id=? AND (active_from IS NULL OR active_from<=NOW(6)) AND (active_until IS NULL OR active_until>NOW(6))");$q->execute([$itemId]);if((int)$q->fetchColumn()<1)throw new InvalidArgumentException('Add at least one active size / price before publishing.');$q=$pdo->prepare("SELECT COUNT(*) FROM menu_sections WHERE organization_id=? AND id=? AND status='active'");$q->execute([$org,(int)$item['section_id']]);if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Move this item into an active category before publishing.');}
        $pdo->prepare("INSERT INTO menu_item_profiles (organization_id,menu_item_id,item_type,lifecycle_status,public_menu_enabled,online_order_enabled,pos_enabled,packages_enabled,catering_enabled,created_by,updated_by) VALUES (?,?,'food',?,1,1,1,1,0,?,?) ON DUPLICATE KEY UPDATE lifecycle_status=VALUES(lifecycle_status),updated_by=VALUES(updated_by),updated_at=NOW(6)")->execute([$org,$itemId,$target,$userId,$userId]);
        $pdo->prepare('UPDATE menu_items SET is_active=?,version=version+1,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$target==='published'?1:0,$org,$itemId]);
        return menu_manager_item_payload($pdo,$org,$itemId);
    });
}

function menu_manager_duplicate(PDO $pdo,int $org,int $itemId,int $userId): array
{
    $source=menu_manager_item_payload($pdo,$org,$itemId);$priceKey=[];$sizes=[];foreach($source['sizes'] as $index=>$size){if(!$size['active'])continue;$key='size-'.$index;$priceKey[(int)$size['id']]=$key;$sizes[]=['clientKey'=>$key,'label'=>$size['label'],'sizeCode'=>$size['sizeCode'],'amount'=>$size['amount'],'sortOrder'=>$index];}
    $groups=[];foreach($source['modifierGroups'] as $group){$g=['name'=>$group['name'],'type'=>$group['type'],'minSelect'=>$group['minSelect'],'maxSelect'=>$group['maxSelect'],'sortOrder'=>$group['sortOrder'],'options'=>[]];foreach($group['options'] as $option){$deltas=[];foreach($option['sizePrices'] as $sp){$key=$priceKey[(int)$sp['priceId']]??null;if($key)$deltas[$key]=(float)$sp['amountDelta'];}$g['options'][]=['name'=>$option['name'],'ingredientId'=>$option['ingredientId'],'defaultPriceDelta'=>$option['defaultPriceDelta'],'maxQuantity'=>$option['maxQuantity'],'sortOrder'=>$option['sortOrder'],'priceDeltas'=>$deltas];}$groups[]=$g;}
    $availability=[];foreach($source['locations'] as $location)$availability[(string)$location['id']]=!empty($location['available']);
    return menu_manager_save_item($pdo,$org,null,[
        'name'=>$source['name'].' Copy','categoryId'=>$source['categoryId'],'description'=>$source['description'],'preparationNotes'=>$source['preparationNotes'],'sizes'=>$sizes,
        'ingredients'=>array_map(static fn(array $i):array=>['id'=>$i['id'],'name'=>$i['name'],'optional'=>$i['optional'],'canRemove'=>$i['canRemove'],'sortOrder'=>$i['sortOrder']],$source['ingredients']),
        'modifierGroups'=>$groups,'kitchenStation'=>$source['profile']['kitchenStation'],'distribution'=>[
            'publicMenu'=>$source['profile']['publicMenu'],'onlineOrder'=>$source['profile']['onlineOrder'],'pos'=>$source['profile']['pos'],'packages'=>$source['profile']['packages'],'catering'=>$source['profile']['catering'],
        ],'locationAvailability'=>$availability,
    ],$userId);
}

function menu_manager_item_channel_enabled(PDO $pdo,int $org,int $itemId,string $channel,?int $locationId=null): bool
{
    $column=menu_manager_channels()[$channel]??null;if($column===null)return false;
    $q=$pdo->prepare("SELECT i.is_active,s.status section_status,mp.lifecycle_status,mp.$column channel_enabled FROM menu_items i JOIN menu_sections s ON s.id=i.section_id AND s.organization_id=i.organization_id LEFT JOIN menu_item_profiles mp ON mp.organization_id=i.organization_id AND mp.menu_item_id=i.id WHERE i.organization_id=? AND i.id=? LIMIT 1");$q->execute([$org,$itemId]);$r=$q->fetch();if(!$r||(int)$r['is_active']!==1||(string)$r['section_status']!=='active')return false;
    if($r['lifecycle_status']!==null&&((string)$r['lifecycle_status']!=='published'||!(bool)$r['channel_enabled']))return false;
    if($locationId&&menu_manager_ready($pdo)){$q=$pdo->prepare('SELECT COUNT(*) total,COALESCE(MAX(CASE WHEN location_id=? THEN is_available END),0) available FROM menu_item_location_availability WHERE organization_id=? AND menu_item_id=?');$q->execute([$locationId,$org,$itemId]);$a=$q->fetch();if((int)($a['total']??0)>0&&!(bool)($a['available']??0))return false;}
    return true;
}

function menu_manager_price_channel_enabled(PDO $pdo,int $org,int $priceId,string $channel,?int $locationId=null): bool
{
    $q=$pdo->prepare("SELECT p.menu_item_id FROM menu_item_prices p JOIN menu_items i ON i.id=p.menu_item_id AND i.organization_id=? WHERE p.id=? AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6)) LIMIT 1");$q->execute([$org,$priceId]);$itemId=(int)($q->fetchColumn()?:0);return $itemId>0&&menu_manager_item_channel_enabled($pdo,$org,$itemId,$channel,$locationId);
}

function menu_manager_channel_menu(PDO $pdo,int $org,string $channel,?int $locationId=null): array
{
    $column=menu_manager_channels()[$channel]??null;if($column===null)return [];$args=[$org];$locationJoin='';$locationClause='';
    if($locationId){$locationJoin=' LEFT JOIN (SELECT menu_item_id,COUNT(*) total,MAX(CASE WHEN location_id=? THEN is_available ELSE 0 END) available FROM menu_item_location_availability WHERE organization_id=? GROUP BY menu_item_id) la ON la.menu_item_id=i.id';$args=[$locationId,$org,$org];$locationClause=' AND (la.total IS NULL OR la.total=0 OR la.available=1)';}
    $sql="SELECT s.id section_id,s.name section_name,s.sort_order section_sort,i.id item_id,i.name item_name,i.description,p.id price_id,p.option_name,p.size_code,p.amount,p.currency,p.sort_order price_sort
          FROM menu_sections s JOIN menu_items i ON i.section_id=s.id AND i.organization_id=s.organization_id AND i.is_active=1
          LEFT JOIN menu_item_profiles mp ON mp.organization_id=i.organization_id AND mp.menu_item_id=i.id
          $locationJoin
          JOIN menu_item_prices p ON p.menu_item_id=i.id AND (p.active_from IS NULL OR p.active_from<=NOW(6)) AND (p.active_until IS NULL OR p.active_until>NOW(6))
          WHERE s.organization_id=? AND s.status='active' AND (mp.id IS NULL OR (mp.lifecycle_status='published' AND mp.$column=1)) $locationClause
          ORDER BY s.sort_order,s.name,i.name,p.sort_order,p.amount,p.id";
    $q=$pdo->prepare($sql);$q->execute($args);$sections=[];foreach($q->fetchAll() as $r){$sid=(int)$r['section_id'];$iid=(int)$r['item_id'];if(!isset($sections[$sid]))$sections[$sid]=['id'=>$sid,'name'=>(string)$r['section_name'],'items'=>[]];if(!isset($sections[$sid]['items'][$iid]))$sections[$sid]['items'][$iid]=['id'=>$iid,'name'=>(string)$r['item_name'],'description'=>$r['description'],'prices'=>[]];$sections[$sid]['items'][$iid]['prices'][]=['id'=>(int)$r['price_id'],'optionName'=>(string)$r['option_name'],'sizeCode'=>$r['size_code'],'amount'=>(float)$r['amount'],'currency'=>(string)$r['currency']];}foreach($sections as &$section)$section['items']=array_values($section['items']);unset($section);return array_values($sections);
}

function menu_manager_public_sections(PDO $pdo,int $org): array
{
    $menu=menu_manager_channel_menu($pdo,$org,'public_menu');$out=[];
    foreach($menu as $section){$items=[];foreach($section['items'] as $item){$q=$pdo->prepare('SELECT COALESCE(mii.display_name,ing.canonical_name) name FROM menu_item_ingredients mii JOIN ingredients ing ON ing.id=mii.ingredient_id WHERE mii.menu_item_id=? ORDER BY mii.sort_order,ing.canonical_name');$q->execute([(int)$item['id']]);$ingredients=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));$prices=array_map(static fn(array $p):array=>['label'=>(string)$p['optionName'],'price'=>(float)$p['amount']],$item['prices']);$items[]=['name'=>(string)$item['name'],'description'=>(string)($item['description']??''),'specialNotes'=>'','ingredients'=>$ingredients,'prices'=>$prices,'facts'=>[],'tags'=>[],'sourceUrl'=>'','imageUrl'=>'','rawPrice'=>'','featured'=>false];}$out[]=['id'=>menu_slugify((string)$section['name']),'name'=>(string)$section['name'],'icon'=>menu_section_icon(menu_slugify((string)$section['name'])),'intro'=>'','facts'=>[],'items'=>$items];}
    return $out;
}

function menu_manager_customization_addons(PDO $pdo,int $org,int $priceId,string $channel='online_order',?int $locationId=null): array
{
    if(!menu_manager_ready($pdo)||!menu_manager_price_channel_enabled($pdo,$org,$priceId,$channel,$locationId))return [];
    $q=$pdo->prepare("SELECT g.id group_id,g.public_id group_public,g.name group_name,g.modifier_type,g.min_select,g.max_select,g.sort_order group_sort,
                            o.id option_id,o.public_id option_public,o.name option_name,o.max_quantity,o.default_price_delta,COALESCE(sp.amount_delta,o.default_price_delta) price_delta
                     FROM menu_item_prices p JOIN menu_modifier_groups g ON g.menu_item_id=p.menu_item_id AND g.organization_id=? AND g.status='active'
                     JOIN menu_modifier_options o ON o.modifier_group_id=g.id AND o.organization_id=g.organization_id AND o.status='active'
                     LEFT JOIN menu_modifier_option_prices sp ON sp.modifier_option_id=o.id AND sp.menu_item_price_id=p.id AND sp.organization_id=g.organization_id
                     WHERE p.id=? ORDER BY g.sort_order,g.id,o.sort_order,o.id");$q->execute([$org,$priceId]);$groups=[];foreach($q->fetchAll() as $r){$gid=(int)$r['group_id'];if(!isset($groups[$gid]))$groups[$gid]=['id'=>(string)$r['group_public'],'name'=>(string)$r['group_name'],'type'=>(string)$r['modifier_type'],'minSelect'=>(int)$r['min_select'],'maxSelect'=>(int)$r['max_select'],'options'=>[]];$groups[$gid]['options'][]=['id'=>(int)$r['option_id'],'publicId'=>(string)$r['option_public'],'name'=>(string)$r['option_name'],'maxQuantity'=>(int)$r['max_quantity'],'priceDelta'=>(float)$r['price_delta']];}return array_values($groups);
}

function menu_manager_validate_addons(PDO $pdo,int $org,int $priceId,array $raw,string $channel='online_order',?int $locationId=null): array
{
    $catalog=menu_manager_customization_addons($pdo,$org,$priceId,$channel,$locationId);$options=[];$groups=[];foreach($catalog as $group){$groups[(string)$group['id']]=['max'=>(int)$group['maxSelect'],'min'=>(int)$group['minSelect'],'total'=>0,'name'=>(string)$group['name']];foreach($group['options'] as $option)$options[(int)$option['id']]=['group'=>(string)$group['id'],'groupName'=>(string)$group['name']]+$option;}
    $out=[];foreach($raw as $row){if(!is_array($row))continue;$id=(int)($row['optionId']??0);$qty=(int)($row['quantity']??0);if($id<1||$qty<1)continue;if(!isset($options[$id]))throw new InvalidArgumentException('One of the selected add-ons is no longer available.');$option=$options[$id];if($qty>(int)$option['maxQuantity'])throw new InvalidArgumentException($option['name'].' exceeds the allowed add-on quantity.');$groups[$option['group']]['total']+=$qty;if($groups[$option['group']]['total']>$groups[$option['group']]['max'])throw new InvalidArgumentException($groups[$option['group']]['name'].' has too many selections.');$out[]=['optionId'=>$id,'groupId'=>$option['group'],'groupName'=>$option['groupName'],'name'=>$option['name'],'quantity'=>$qty,'unitAmount'=>(float)$option['priceDelta'],'lineAmount'=>pos_money((float)$option['priceDelta']*$qty)];}
    foreach($groups as $group)if($group['total']<$group['min'])throw new InvalidArgumentException($group['name'].' requires at least '.$group['min'].' selection(s).');return $out;
}

function menu_manager_apply_pos_addons(PDO $pdo,int $org,string $checkPublicId,int $priceId,float $itemQuantity,array $addons): array
{
    if(!$addons)return pos_check_details($pdo,$org,$checkPublicId);$check=pos_require_open_check($pdo,$org,$checkPublicId,true);$q=$pdo->prepare("SELECT id,unit_price,quantity FROM pos_check_items WHERE organization_id=? AND check_id=? AND menu_item_price_id=? AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE");$q->execute([$org,(int)$check['id'],$priceId]);$line=$q->fetch();if(!$line)throw new RuntimeException('The POS line for this customized item was not found.');$modifierTotal=0.0;foreach($addons as $addon){$total=pos_money((float)$addon['unitAmount']*(int)$addon['quantity']*$itemQuantity);$modifierTotal+=$total;$pdo->prepare('INSERT INTO pos_check_item_modifiers (organization_id,pos_check_item_id,modifier_option_id,modifier_group_name_snapshot,modifier_name_snapshot,quantity,unit_amount,total_amount) VALUES (?,?,?,?,?,?,?,?)')->execute([$org,(int)$line['id'],(int)$addon['optionId'],(string)$addon['groupName'],(string)$addon['name'],(float)$addon['quantity']*$itemQuantity,(float)$addon['unitAmount'],$total]);}$modifierTotal=pos_money($modifierTotal);$gross=pos_money((float)$line['unit_price']*(float)$line['quantity']+$modifierTotal);$pdo->prepare('UPDATE pos_check_items SET modifier_amount=?,gross_amount=?,net_amount=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$modifierTotal,$gross,$gross,$org,(int)$line['id']]);pos_recalculate_check($pdo,$org,(int)$check['id']);return pos_check_details($pdo,$org,$checkPublicId);
}
