<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/equipment-brain.php';
require __DIR__ . '/../includes/floor-equipment-brain.php';
require __DIR__ . '/../includes/restaurant-brain.php';
require __DIR__ . '/../includes/menu-operations-core.php';
require_once __DIR__ . '/../includes/menu-manager-core.php';

$user = app_require_auth();
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

function brain_can_equipment(array $user): bool
{
    return app_has_permission('agent.equipment_skills', $user) && app_has_permission('equipment.view', $user);
}
function brain_can_wholesale(array $user): bool
{
    return app_has_permission('wholesale.agent', $user) && app_has_permission('wholesale.view', $user);
}
function brain_can_recipes(array $user): bool
{
    return app_has_permission('recipes.agent', $user) && app_has_permission('recipes.view', $user);
}
function brain_can_menu(array $user): bool
{
    return app_has_permission('menu.view', $user);
}
function brain_require_any(array $user): void
{
    if (!brain_can_menu($user) && !brain_can_equipment($user) && !brain_can_wholesale($user) && !brain_can_recipes($user)) {
        app_json_response(['ok'=>false,'message'=>'You do not have permission to use restaurant Agent skills.'],403);
    }
}

function brain_asset_search(PDO $pdo, int $organizationId, string $query, int $limit = 12): array
{
    $query = trim($query);$limit=max(1,min(30,$limit));$like='%'.$query.'%';
    $statement=$pdo->prepare("SELECT public_id,name,asset_type,purpose,brand,manufacturer,model,serial_number,asset_tag,manufacture_year,operational_status,condition_status,criticality,location_name,next_service_on,last_service_on,maintenance_required,floor_plan_public_id,floor_plan_x_ft,floor_plan_y_ft,floor_plan_rotation_deg FROM equipment_assets WHERE organization_id=? AND archived_at IS NULL AND (?='' OR name LIKE ? OR asset_type LIKE ? OR purpose LIKE ? OR brand LIKE ? OR manufacturer LIKE ? OR model LIKE ? OR serial_number LIKE ? OR asset_tag LIKE ? OR location_name LIKE ?) ORDER BY FIELD(criticality,'critical','high','medium','low'),name LIMIT {$limit}");
    $statement->execute([$organizationId,$query,$like,$like,$like,$like,$like,$like,$like,$like,$like]);
    return array_map(static fn(array $row):array=>[
        'id'=>(string)$row['public_id'],'name'=>(string)$row['name'],'assetType'=>(string)$row['asset_type'],'purpose'=>(string)($row['purpose']??''),'brand'=>(string)($row['brand']??''),'manufacturer'=>(string)($row['manufacturer']??''),'model'=>(string)($row['model']??''),'serialNumber'=>(string)($row['serial_number']??''),'assetTag'=>(string)($row['asset_tag']??''),'manufactureYear'=>$row['manufacture_year']!==null?(int)$row['manufacture_year']:null,'operationalStatus'=>(string)$row['operational_status'],'conditionStatus'=>(string)$row['condition_status'],'criticality'=>(string)$row['criticality'],'locationName'=>(string)($row['location_name']??''),'nextServiceOn'=>$row['next_service_on'],'lastServiceOn'=>$row['last_service_on'],'maintenanceRequired'=>(bool)$row['maintenance_required'],'floorPlanId'=>(string)($row['floor_plan_public_id']??''),'xFt'=>$row['floor_plan_x_ft']!==null?(float)$row['floor_plan_x_ft']:null,'yFt'=>$row['floor_plan_y_ft']!==null?(float)$row['floor_plan_y_ft']:null,'rotationDeg'=>(float)($row['floor_plan_rotation_deg']??0),
    ],$statement->fetchAll());
}

function brain_service_contacts(PDO $pdo,int $organizationId,string $assetPublicId='',string $specialty=''):array
{
    $params=[$organizationId];$join='';$where="c.organization_id=? AND c.archived_at IS NULL AND c.status='active'";
    if($assetPublicId!==''){$join=' INNER JOIN equipment_asset_service_contacts link ON link.service_contact_id=c.id INNER JOIN equipment_assets a ON a.id=link.equipment_asset_id ';$where.=' AND a.organization_id=? AND a.public_id=? AND a.archived_at IS NULL';$params[]=$organizationId;$params[]=$assetPublicId;}
    if($specialty!==''){$where.=' AND c.specialty LIKE ?';$params[]='%'.$specialty.'%';}
    $statement=$pdo->prepare("SELECT DISTINCT c.public_id,c.company_name,c.contact_name,c.specialty,c.phone,c.email,c.website,c.emergency_phone,c.is_preferred,c.is_warranty_provider FROM equipment_service_contacts c {$join} WHERE {$where} ORDER BY c.is_preferred DESC,c.is_warranty_provider DESC,c.company_name LIMIT 30");$statement->execute($params);
    return array_map(static fn(array $row):array=>['id'=>(string)$row['public_id'],'companyName'=>(string)$row['company_name'],'contactName'=>(string)($row['contact_name']??''),'specialty'=>(string)($row['specialty']??''),'phone'=>(string)($row['phone']??''),'email'=>(string)($row['email']??''),'website'=>(string)($row['website']??''),'emergencyPhone'=>(string)($row['emergency_phone']??''),'preferred'=>(bool)$row['is_preferred'],'warrantyProvider'=>(bool)$row['is_warranty_provider']],$statement->fetchAll());
}

function brain_asset_context(PDO $pdo,int $organizationId,string $publicId):?array
{
    $statement=$pdo->prepare('SELECT * FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$statement->execute([$organizationId,$publicId]);$asset=$statement->fetch();if(!$asset)return null;
    $knowledge=equipment_brain_asset_text($pdo,$organizationId,$asset);if(!empty($asset['floor_plan_public_id'])&&function_exists('floor_equipment_placement_text'))$knowledge.="\n\n".floor_equipment_placement_text($pdo,$organizationId,$asset);
    return ['id'=>(string)$asset['public_id'],'name'=>(string)$asset['name'],'knowledge'=>$knowledge,'contacts'=>equipment_brain_asset_contacts($pdo,$organizationId,(int)$asset['id']),'serviceHistory'=>equipment_brain_asset_events($pdo,$organizationId,(int)$asset['id'],20)];
}
function brain_floor_plan(PDO $pdo,int $organizationId,string $planId=''):array
{
    $planId=preg_replace('/[^a-zA-Z0-9_-]/','',$planId)?:'';$assets=floor_equipment_plan_assets($pdo,$organizationId,$planId);$plans=[];
    foreach($assets as $asset){$pid=$asset['floorPlanId'];if(!isset($plans[$pid])){$plan=floor_equipment_plan_row($pdo,$organizationId,$pid);$plans[$pid]=['id'=>$pid,'name'=>$plan['name']??$pid,'widthFt'=>$plan?(float)$plan['width_ft']:null,'depthFt'=>$plan?(float)$plan['depth_ft']:null,'assets'=>[]];}$plans[$pid]['assets'][]=$asset;}
    return array_values($plans);
}
function brain_floor_plan_answer(PDO $pdo,int $organizationId,string $message):array
{
    $placements=floor_equipment_plan_assets($pdo,$organizationId,'');if(!$placements)return ['skill'=>'equipment.floor_plan','answer'=>'No equipment assets are currently placed on a saved floor plan.','data'=>[],'sources'=>[]];$normalized=mb_strtolower($message,'UTF-8');
    foreach($placements as $asset){$name=mb_strtolower($asset['name'],'UTF-8');if($name!==''&&str_contains($normalized,$name)){$plan=floor_equipment_plan_row($pdo,$organizationId,$asset['floorPlanId']);$answer=$asset['name'].' is on '.($plan['name']??$asset['floorPlanId']).' at approximately '.number_format((float)$asset['xFt'],1).' ft from the left and '.number_format((float)$asset['yFt'],1).' ft from the top, rotated '.number_format((float)$asset['rotationDeg'],0).'°. Its operational location is '.($asset['locationName']?:'not separately labeled').'.';return ['skill'=>'equipment.floor_plan','answer'=>$answer,'data'=>$asset,'sources'=>[$asset['id']]];}}
    $groups=brain_floor_plan($pdo,$organizationId,'');$lines=[];$sources=[];foreach($groups as $plan){$names=array_map(static fn(array $asset):string=>$asset['name'].' ['.$asset['operationalStatus'].']',$plan['assets']);$lines[]=$plan['name'].': '.implode(', ',$names);foreach($plan['assets'] as $asset)$sources[]=$asset['id'];}
    return ['skill'=>'equipment.floor_plan','answer'=>"Equipment currently placed on saved floor plans:\n- ".implode("\n- ",$lines),'data'=>$groups,'sources'=>array_values(array_unique($sources))];
}

function brain_wholesale_answer(PDO $pdo,int $organizationId,string $message):array
{
    $normalized=mb_strtolower($message,'UTF-8');
    if(preg_match('/\b(pipeline|stage|forecast|weighted|wholesale summary|opportunities)\b/u',$normalized)){
        $summary=restaurant_brain_wholesale_summary($pdo,$organizationId);if(!$summary)return ['skill'=>'wholesale.pipeline','answer'=>'There are no wholesale gelato opportunities in the pipeline yet.','data'=>[],'sources'=>[]];$lines=[];$total=0;$weighted=0;foreach($summary as $row){$lines[]=ucfirst((string)$row['pipeline_stage']).': '.(int)$row['lead_count'].' lead(s), $'.number_format((float)$row['value_total'],0).' value';if(!in_array($row['pipeline_stage'],['won','lost'],true)){$total+=(float)$row['value_total'];$weighted+=(float)$row['weighted_value'];}}return ['skill'=>'wholesale.pipeline','answer'=>"Wholesale gelato pipeline:\n- ".implode("\n- ",$lines).'\nOpen pipeline value: $'.number_format($total,0).'; weighted value: $'.number_format($weighted,0).'.','data'=>$summary,'sources'=>[]];
    }
    if(preg_match('/\b(follow.?up|overdue|due today|next contact)\b/u',$normalized)){
        $statement=$pdo->prepare("SELECT public_id,business_name,pipeline_stage,next_followup_at,contact_name,email FROM wholesale_leads WHERE organization_id=? AND archived_at IS NULL AND pipeline_stage NOT IN ('won','lost') AND next_followup_at IS NOT NULL AND next_followup_at<=DATE_ADD(NOW(),INTERVAL 7 DAY) ORDER BY next_followup_at LIMIT 20");$statement->execute([$organizationId]);$rows=$statement->fetchAll();if(!$rows)return ['skill'=>'wholesale.followups','answer'=>'No wholesale follow-ups are due in the next seven days.','data'=>[],'sources'=>[]];$lines=[];foreach($rows as $row)$lines[]=$row['business_name'].' — '.$row['pipeline_stage'].' — '.$row['next_followup_at'].' — '.$row['contact_name'];return ['skill'=>'wholesale.followups','answer'=>"Wholesale follow-ups due soon:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
    }
    $leads=restaurant_brain_wholesale_search($pdo,$organizationId,$message,12);if($leads){if(count($leads)===1)return ['skill'=>'wholesale.lead_context','answer'=>restaurant_brain_wholesale_text($pdo,$organizationId,$leads[0]),'data'=>$leads[0],'sources'=>[(string)$leads[0]['public_id']]];$lines=[];foreach($leads as $lead)$lines[]=$lead['business_name'].' — '.$lead['pipeline_stage'].' — '.($lead['estimated_monthly_volume']?:'volume TBD').' — '.($lead['flavors_interest']?:'no flavor note');return ['skill'=>'wholesale.search','answer'=>"Matching wholesale opportunities:\n- ".implode("\n- ",$lines),'data'=>$leads,'sources'=>array_column($leads,'public_id')];}
    return ['skill'=>'wholesale.search','answer'=>'I could not find a matching wholesale opportunity. New public wholesale requests appear in the wholesale pipeline automatically.','data'=>[],'sources'=>[]];
}

function brain_recipe_answer(PDO $pdo,int $organizationId,string $message):array
{
    $normalized=mb_strtolower($message,'UTF-8');$recipes=restaurant_brain_recipe_search($pdo,$organizationId,$message,20);
    if(preg_match('/\b(mapped|online source|online recipe|recipe source|unmapped)\b/u',$normalized)){
        $all=restaurant_brain_recipe_search($pdo,$organizationId,'',100);$filtered=array_values(array_filter($all,static function(array $r) use($normalized):bool{return str_contains($normalized,'unmapped')?($r['mapping_status']!=='mapped'):($r['mapping_status']==='mapped');}));if(!$filtered)return ['skill'=>'recipe.online_mappings','answer'=>str_contains($normalized,'unmapped')?'All current recipes are mapped or there are no recipes yet.':'No recipes have a confirmed online mapping yet.','data'=>[],'sources'=>[]];$lines=[];foreach(array_slice($filtered,0,20) as $r)$lines[]=$r['name'].' — '.$r['mapping_status'].($r['source_url']?' — '.$r['source_url']:'');return ['skill'=>'recipe.online_mappings','answer'=>"Recipe mapping status:\n- ".implode("\n- ",$lines),'data'=>$filtered,'sources'=>array_column($filtered,'public_id')];
    }
    if($recipes){$lower=mb_strtolower($message,'UTF-8');foreach($recipes as $recipe){if(str_contains($lower,mb_strtolower((string)$recipe['name'],'UTF-8')))return ['skill'=>'recipe.context','answer'=>restaurant_brain_recipe_text($recipe),'data'=>$recipe,'sources'=>[(string)$recipe['public_id']]];}$lines=[];foreach(array_slice($recipes,0,15) as $recipe)$lines[]=$recipe['name'].' — '.($recipe['category']?:'uncategorized').' — '.$recipe['mapping_status'];return ['skill'=>'recipe.search','answer'=>"Matching recipes:\n- ".implode("\n- ",$lines),'data'=>$recipes,'sources'=>array_column($recipes,'public_id')];}
    return ['skill'=>'recipe.search','answer'=>'I could not find that in the private recipe library. Add the recipe or upload a recipe image so AI can extract and map it.','data'=>[],'sources'=>[]];
}

function brain_equipment_answer(PDO $pdo,int $organizationId,string $message):array
{
    $normalized=mb_strtolower(trim($message),'UTF-8');
    if(preg_match('/\b(floor\s*plan|layout|where\s+(?:is|are)|located|positioned|placement)\b/u',$normalized))return brain_floor_plan_answer($pdo,$organizationId,$message);
    if(preg_match('/\b(overdue|due|maintenance|service soon|needs service|preventive)\b/u',$normalized)){$days=preg_match('/\b(\d{1,3})\s*days?\b/u',$normalized,$match)?max(0,min(365,(int)$match[1])):30;$due=equipment_brain_maintenance_due($pdo,$organizationId,$days);if(!$due)return ['skill'=>'equipment.maintenance_due','answer'=>"No maintenance is overdue or due within {$days} days based on the current equipment schedule.",'data'=>[],'sources'=>[]];$lines=[];foreach(array_slice($due,0,12) as $item){$when=$item['next_service_on']?:'unscheduled';$lines[]=$item['name'].' — '.$item['asset_type'].' — due '.$when.' — '.$item['criticality'].' criticality';}return ['skill'=>'equipment.maintenance_due','answer'=>"Equipment maintenance requiring attention:\n- ".implode("\n- ",$lines),'data'=>$due,'sources'=>array_column($due,'public_id')];}
    if(preg_match('/\b(contact|technician|repair company|service company|vendor|who.*call|warranty)\b/u',$normalized)){$assets=brain_asset_search($pdo,$organizationId,$message,5);$assetId=count($assets)===1?$assets[0]['id']:'';$contacts=brain_service_contacts($pdo,$organizationId,$assetId,'');if(!$contacts)return ['skill'=>'equipment.service_contacts','answer'=>'No matching equipment service contacts are recorded yet. Add a service company in the Equipment Catalog and link it to the asset.','data'=>[],'sources'=>[]];$lines=[];foreach(array_slice($contacts,0,10) as $contact)$lines[]=$contact['companyName'].($contact['contactName']?' — '.$contact['contactName']:'').($contact['specialty']?' — '.$contact['specialty']:'').($contact['phone']?' — '.$contact['phone']:'').($contact['emergencyPhone']?' — emergency '.$contact['emergencyPhone']:'');return ['skill'=>'equipment.service_contacts','answer'=>"Recorded service contacts:\n- ".implode("\n- ",$lines),'data'=>$contacts,'sources'=>array_column($contacts,'id')];}
    $assets=brain_asset_search($pdo,$organizationId,$message,12);if($assets){if(count($assets)===1){$context=brain_asset_context($pdo,$organizationId,$assets[0]['id']);return ['skill'=>'equipment.asset_context','answer'=>$context?mb_substr($context['knowledge'],0,7000,'UTF-8'):'Equipment record found.','data'=>$context?:$assets[0],'sources'=>[$assets[0]['id']]];}$lines=[];foreach($assets as $asset)$lines[]=$asset['name'].' — '.$asset['assetType'].($asset['brand']?' — '.$asset['brand']:'').($asset['model']?' '.$asset['model']:'').' — '.$asset['operationalStatus'];return ['skill'=>'equipment.search','answer'=>"Matching equipment records:\n- ".implode("\n- ",$lines),'data'=>$assets,'sources'=>array_column($assets,'id')];}
    return ['skill'=>'equipment.search','answer'=>'I could not find a matching equipment record.','data'=>[],'sources'=>[]];
}


function brain_menu_location(PDO $pdo,int $organizationId,string $message): ?array
{
    if(!restaurant_brain_table_ready($pdo,'locations'))return null;
    $q=$pdo->prepare("SELECT id,name,status,timezone FROM locations WHERE organization_id=? AND status='active' ORDER BY name,id");
    $q->execute([$organizationId]);$rows=$q->fetchAll();
    if(count($rows)===1)return $rows[0];
    $lower=mb_strtolower($message,'UTF-8');
    foreach($rows as $row){$name=mb_strtolower(trim((string)$row['name']),'UTF-8');if($name!==''&&str_contains($lower,$name))return $row;}
    return null;
}

function brain_menu_answer(PDO $pdo,int $organizationId,string $message): array
{
    if(!menu_operations_ready($pdo))return ['skill'=>'menu.summary','answer'=>'Menu Operations is not installed yet. Run Upgrade once to activate live menu availability and Agent Brain context.','data'=>[],'sources'=>[]];
    $normalized=mb_strtolower(trim($message),'UTF-8');$location=brain_menu_location($pdo,$organizationId,$message);$locationId=$location?(int)$location['id']:null;
    if(preg_match('/\b(recent|changed|changes|history|updated|who changed)\b/u',$normalized)){
        $events=menu_operations_recent_events($pdo,$organizationId,20);$lines=[];foreach($events as $event)$lines[]=$event['createdAt'].' — '.$event['summary'];
        return ['skill'=>'menu.recent_changes','answer'=>$events?"Recent menu changes:\n- ".implode("\n- ",$lines):'No Menu Operations changes have been recorded yet.','data'=>$events,'sources'=>array_column($events,'id')];
    }
    if(preg_match('/\b(sold.?out|86(?:d)?|eighty.?six|availability|available|unavailable)\b/u',$normalized)){
        if(!$location)return ['skill'=>'menu.availability','answer'=>'Tell me which restaurant location you mean so I can check its live item and size availability.','data'=>[],'sources'=>[]];
        $state=menu_operations_location_state($pdo,$organizationId,$locationId);$blocked=[];
        foreach($state as $item){
            if(!empty($item['status']['soldOut']))$blocked[]=$item['name'].' — item sold out'.(!empty($item['status']['reason'])?' ('.$item['status']['reason'].')':'');
            foreach($item['sizes'] as $size)if(!empty($size['status']['soldOut']))$blocked[]=$item['name'].' / '.$size['label'].' — sold out'.(!empty($size['status']['reason'])?' ('.$size['status']['reason'].')':'');
        }
        return ['skill'=>'menu.availability','answer'=>$blocked?('Live menu availability at '.$location['name'].":\n- ".implode("\n- ",$blocked)):('No item- or size-level 86s are active at '.$location['name'].'.'),'data'=>$state,'sources'=>['menu_operations-current']];
    }
    if(preg_match('/\b(summary|status|overview|how many|missing image|images)\b/u',$normalized)){
        $summary=menu_operations_summary($pdo,$organizationId,$locationId);$answer='Menu summary: '.$summary['published'].' published, '.$summary['draft'].' draft, '.$summary['paused'].' paused, '.$summary['soldOutItems'].' item-level 86(s), '.$summary['soldOutSizes'].' size-level 86(s), '.$summary['scheduledItems'].' scheduled item(s).';
        $img=$summary['missingImages'];$answer.=' Missing images: '.$img['menuItems']['missing'].' menu items, '.$img['ingredients']['missing'].' ingredients, '.$img['locations']['missing'].' locations.';
        return ['skill'=>'menu.summary','answer'=>$answer,'data'=>$summary,'sources'=>['menu_operations-current']];
    }
    $items=menu_operations_search($pdo,$organizationId,$message,20,$locationId);
    if(!$items)$items=menu_operations_search($pdo,$organizationId,'',20,$locationId);
    $lines=[];foreach($items as $item){$price=$item['minPrice']===null?'no active price':('$'.number_format((float)$item['minPrice'],2).(($item['maxPrice']!==null&&(float)$item['maxPrice']!==(float)$item['minPrice'])?'–$'.number_format((float)$item['maxPrice'],2):''));$lines[]=$item['name'].' — '.$item['category'].' — '.$price.(isset($item['operationalStatus'])&&!empty($item['operationalStatus']['soldOut'])?' — SOLD OUT':'');}
    return ['skill'=>'menu.search','answer'=>$lines?"Matching menu items:\n- ".implode("\n- ",$lines):'No matching menu items were found.','data'=>$items,'sources'=>['menu_operations-current']];
}

function brain_menu_ids(array $raw): array
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

function brain_answer(PDO $pdo,int $organizationId,string $message,array $user):array
{
    $normalized=mb_strtolower(trim($message),'UTF-8');if($normalized==='')return ['skill'=>'none','answer'=>'Ask me about the menu, sold-outs, pricing, recent menu changes, wholesale opportunities, recipes, equipment, maintenance, service contacts, or floor plans.','data'=>[],'sources'=>[]];
    $menuIntent=preg_match('/\b(menu|menu item|sold.?out|86(?:d)?|eighty.?six|food item|drink item|menu price|pizza size|modifier|topping|add.?on)\b/u',$normalized)===1;
    $wholesaleIntent=preg_match('/\b(wholesale|buyer|lead|prospect|sample|quote|private label|foodservice|pipeline|follow.?up|account opportunity)\b/u',$normalized)===1;
    $recipeIntent=preg_match('/\b(recipe|formula|ingredient|yield|method|instructions|online recipe|recipe image|recipe source|mapped recipe|unmapped)\b/u',$normalized)===1;
    $equipmentIntent=preg_match('/\b(equipment|oven|mixer|freezer|cooler|dish|machine|maintenance|repair|warranty|service|floor\s*plan|layout|located|placement)\b/u',$normalized)===1;
    if($menuIntent&&brain_can_menu($user))return brain_menu_answer($pdo,$organizationId,$message);
    if($wholesaleIntent&&brain_can_wholesale($user))return brain_wholesale_answer($pdo,$organizationId,$message);
    if($recipeIntent&&brain_can_recipes($user))return brain_recipe_answer($pdo,$organizationId,$message);
    if($equipmentIntent&&brain_can_equipment($user))return brain_equipment_answer($pdo,$organizationId,$message);
    if(brain_can_menu($user)){ $result=brain_menu_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    if(brain_can_wholesale($user)){ $result=brain_wholesale_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    if(brain_can_recipes($user)){ $result=brain_recipe_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    if(brain_can_equipment($user)){ $result=brain_equipment_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    $knowledge=equipment_brain_search($pdo,$organizationId,$message,8);if($knowledge){$excerpts=[];$sources=[];foreach($knowledge as $record){$excerpts[]=mb_substr((string)$record['content'],0,1400,'UTF-8');$sources[]=(string)$record['source_public_id'];}return ['skill'=>'knowledge.search','answer'=>implode("\n\n",$excerpts),'data'=>$knowledge,'sources'=>array_values(array_unique($sources))];}
    return ['skill'=>'knowledge.search','answer'=>'I could not find that in the private restaurant knowledge base yet. Add or update the relevant wholesale, recipe, or equipment record so I have the missing facts.','data'=>[],'sources'=>[]];
}

brain_require_any($user);
if(!equipment_brain_table_ready($pdo,'agent_knowledge_records'))app_json_response(['ok'=>false,'message'=>'Restaurant Agent knowledge migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'summary');
    if($action==='summary'){
        $payload=['ok'=>true,'skill'=>'restaurant.summary','domains'=>[]];
        if(brain_can_menu($user)&&menu_operations_ready($pdo)){$payload['domains']['menu']=['summary'=>menu_operations_summary($pdo,$organizationId),'recentChanges'=>menu_operations_recent_events($pdo,$organizationId,8)];}
        if(brain_can_equipment($user)&&equipment_brain_table_ready($pdo,'equipment_assets')){$payload['domains']['equipment']=['summary'=>equipment_brain_summary($pdo,$organizationId),'maintenanceDue'=>equipment_brain_maintenance_due($pdo,$organizationId,30)];}
        if(brain_can_wholesale($user)&&restaurant_brain_table_ready($pdo,'wholesale_leads')){$payload['domains']['wholesale']=['pipeline'=>restaurant_brain_wholesale_summary($pdo,$organizationId)];}
        if(brain_can_recipes($user)&&restaurant_brain_table_ready($pdo,'recipes')){$payload['domains']['recipes']=['count'=>count(restaurant_brain_recipe_search($pdo,$organizationId,'',100))];}
        app_json_response($payload);
    }
    if($action==='search'){$q=(string)($_GET['q']??'');app_json_response(['ok'=>true,'skill'=>'knowledge.search','results'=>equipment_brain_search($pdo,$organizationId,$q,12)]);}
    if($action==='floor_plan'&&brain_can_equipment($user))app_json_response(['ok'=>true,'skill'=>'equipment.floor_plan','plans'=>brain_floor_plan($pdo,$organizationId,(string)($_GET['planId']??''))]);
    if($action==='wholesale'&&brain_can_wholesale($user))app_json_response(['ok'=>true,'skill'=>'wholesale.search','leads'=>restaurant_brain_wholesale_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);
    if($action==='recipes'&&brain_can_recipes($user))app_json_response(['ok'=>true,'skill'=>'recipe.search','recipes'=>restaurant_brain_recipe_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);
    if($action==='menu'&&brain_can_menu($user))app_json_response(['ok'=>true,'skill'=>'menu.search','items'=>menu_operations_search($pdo,$organizationId,(string)($_GET['q']??''),30,((int)($_GET['locationId']??0))?:null)]);
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent skill.'],422);
}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'ask');
if($action==='ask'){$message=trim((string)($input['message']??''));if($message===''||mb_strlen($message,'UTF-8')>1600)app_json_response(['ok'=>false,'message'=>'Enter a restaurant operations question no longer than 1,600 characters.'],422);$result=brain_answer($pdo,$organizationId,$message,$user);app_audit($pdo,$organizationId,(int)$user['id'],'agent.restaurant_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,300,'UTF-8'),'sources'=>$result['sources']]);app_json_response(['ok'=>true]+$result);}
if($action==='run_skill'){
    $skill=(string)($input['skill']??'');$args=(array)($input['arguments']??[]);
    if(str_starts_with($skill,'menu.')&&!brain_can_menu($user))app_json_response(['ok'=>false,'message'=>'Menu view permission required.'],403);
    if(str_starts_with($skill,'equipment.')&&!brain_can_equipment($user))app_json_response(['ok'=>false,'message'=>'Equipment Agent permission required.'],403);
    if(str_starts_with($skill,'wholesale.')&&!brain_can_wholesale($user))app_json_response(['ok'=>false,'message'=>'Wholesale Agent permission required.'],403);
    if(str_starts_with($skill,'recipe.')&&!brain_can_recipes($user))app_json_response(['ok'=>false,'message'=>'Recipe Agent permission required.'],403);
    if($skill==='menu.summary')$result=['skill'=>$skill,'data'=>menu_operations_summary($pdo,$organizationId,((int)($args['locationId']??0))?:null)];
    elseif($skill==='menu.search')$result=['skill'=>$skill,'data'=>menu_operations_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20),((int)($args['locationId']??0))?:null)];
    elseif($skill==='menu.availability'){ $locationId=(int)($args['locationId']??0); if($locationId<1)throw new InvalidArgumentException('locationId is required.'); $result=['skill'=>$skill,'data'=>menu_operations_location_state($pdo,$organizationId,$locationId)]; }
    elseif($skill==='menu.recent_changes')$result=['skill'=>$skill,'data'=>menu_operations_recent_events($pdo,$organizationId,(int)($args['limit']??20))];
    elseif($skill==='menu.set_item_availability'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_set_item_status($pdo,$organizationId,(int)($args['itemId']??0),(int)($args['locationId']??0),!empty($args['soldOut']),(string)($args['reason']??''),isset($args['resumeAt'])?(string)$args['resumeAt']:null,(int)$user['id'])];}
    elseif($skill==='menu.set_size_availability'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_set_price_status($pdo,$organizationId,(int)($args['priceId']??0),(int)($args['locationId']??0),!empty($args['soldOut']),(string)($args['reason']??''),isset($args['resumeAt'])?(string)$args['resumeAt']:null,(int)$user['id'])];}
    elseif($skill==='menu.update_price'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_update_price($pdo,$organizationId,(int)($args['priceId']??0),(float)($args['amount']??-1),(int)$user['id'])];}
    elseif($skill==='menu.bulk_price'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedPrices'=>menu_operations_bulk_price($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(string)($args['mode']??''),(float)($args['value']??0),(int)$user['id'])]];}
    elseif($skill==='menu.schedule_save'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>menu_operations_save_schedule($pdo,$organizationId,(int)($args['itemId']??0),((int)($args['locationId']??0))?:null,(string)($args['channel']??'online_order'),is_array($args['rows']??null)?$args['rows']:[],(int)$user['id'])];}
    elseif($skill==='menu.reorder_categories'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);menu_operations_reorder_categories($pdo,$organizationId,is_array($args['ids']??null)?$args['ids']:[],(int)$user['id']);$result=['skill'=>$skill,'data'=>['updated'=>true]];}
    elseif($skill==='menu.reorder_items'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);menu_operations_reorder_items($pdo,$organizationId,(int)($args['categoryId']??0),is_array($args['ids']??null)?$args['ids']:[],(int)$user['id']);$result=['skill'=>$skill,'data'=>['updated'=>true]];}
    elseif($skill==='menu.bulk_move'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedItems'=>brain_menu_bulk_move($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(int)($args['categoryId']??0),(int)$user['id'])]];}
    elseif($skill==='menu.bulk_distribution'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedItems'=>brain_menu_bulk_distribution($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(string)($args['channel']??''),!empty($args['enabled']),(int)$user['id'])]];}
    elseif($skill==='menu.bulk_status'){if(!app_has_permission('menu.manage',$user))app_json_response(['ok'=>false,'message'=>'Menu manage permission required.'],403);$result=['skill'=>$skill,'data'=>['updatedItems'=>brain_menu_bulk_status($pdo,$organizationId,is_array($args['itemIds']??null)?$args['itemIds']:[],(string)($args['status']??''),(int)$user['id'])]];}
    elseif($skill==='equipment.search')$result=['skill'=>$skill,'data'=>brain_asset_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??12))];
    elseif($skill==='equipment.maintenance_due')$result=['skill'=>$skill,'data'=>equipment_brain_maintenance_due($pdo,$organizationId,(int)($args['days']??30))];
    elseif($skill==='equipment.service_contacts')$result=['skill'=>$skill,'data'=>brain_service_contacts($pdo,$organizationId,(string)($args['assetId']??''),(string)($args['specialty']??''))];
    elseif($skill==='equipment.asset_context')$result=['skill'=>$skill,'data'=>brain_asset_context($pdo,$organizationId,(string)($args['assetId']??''))];
    elseif($skill==='equipment.floor_plan')$result=['skill'=>$skill,'data'=>brain_floor_plan($pdo,$organizationId,(string)($args['planId']??''))];
    elseif($skill==='wholesale.search')$result=['skill'=>$skill,'data'=>restaurant_brain_wholesale_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20))];
    elseif($skill==='wholesale.pipeline')$result=['skill'=>$skill,'data'=>restaurant_brain_wholesale_summary($pdo,$organizationId)];
    elseif($skill==='recipe.search')$result=['skill'=>$skill,'data'=>restaurant_brain_recipe_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20))];
    elseif($skill==='recipe.context'){$recipe=restaurant_brain_recipe_by_public_id($pdo,$organizationId,(string)($args['recipeId']??''));$result=['skill'=>$skill,'data'=>$recipe,'answer'=>$recipe?restaurant_brain_recipe_text($recipe):'Recipe not found.'];}
    elseif($skill==='knowledge.search')$result=['skill'=>$skill,'data'=>equipment_brain_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??8))];
    else app_json_response(['ok'=>false,'message'=>'Unknown restaurant Agent skill.'],422);
    app_audit($pdo,$organizationId,(int)$user['id'],'agent.restaurant_skill_used','agent_skill',$skill,null,['arguments'=>$args]);app_json_response(['ok'=>true]+$result);
}
app_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain action.'],422);
