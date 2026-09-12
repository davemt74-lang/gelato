<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/equipment-brain.php';
require __DIR__ . '/../includes/floor-equipment-brain.php';
require __DIR__ . '/../includes/restaurant-brain.php';
require __DIR__ . '/../includes/catering-brain.php';

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

function brain_can_catering(array $user): bool
{
    return app_has_permission('catering.agent', $user) && app_has_permission('catering.view', $user);
}

function brain_require_any(array $user): void
{
    if (!brain_can_equipment($user) && !brain_can_wholesale($user) && !brain_can_recipes($user) && !brain_can_catering($user)) {
        app_json_response(['ok'=>false,'message'=>'You do not have permission to use restaurant Agent skills.'],403);
    }
}

function brain_asset_search(PDO $pdo, int $organizationId, string $query, int $limit = 12): array
{
    $query = trim($query);
    $limit = max(1,min(30,$limit));
    $like = '%'.$query.'%';
    $statement = $pdo->prepare("SELECT public_id,name,asset_type,purpose,brand,manufacturer,model,serial_number,asset_tag,manufacture_year,operational_status,condition_status,criticality,location_name,next_service_on,last_service_on,maintenance_required,floor_plan_public_id,floor_plan_x_ft,floor_plan_y_ft,floor_plan_rotation_deg FROM equipment_assets WHERE organization_id=? AND archived_at IS NULL AND (?='' OR name LIKE ? OR asset_type LIKE ? OR purpose LIKE ? OR brand LIKE ? OR manufacturer LIKE ? OR model LIKE ? OR serial_number LIKE ? OR asset_tag LIKE ? OR location_name LIKE ?) ORDER BY FIELD(criticality,'critical','high','medium','low'),name LIMIT {$limit}");
    $statement->execute([$organizationId,$query,$like,$like,$like,$like,$like,$like,$like,$like,$like]);
    return array_map(static fn(array $row): array => [
        'id'=>(string)$row['public_id'],
        'name'=>(string)$row['name'],
        'assetType'=>(string)$row['asset_type'],
        'purpose'=>(string)($row['purpose']??''),
        'brand'=>(string)($row['brand']??''),
        'manufacturer'=>(string)($row['manufacturer']??''),
        'model'=>(string)($row['model']??''),
        'serialNumber'=>(string)($row['serial_number']??''),
        'assetTag'=>(string)($row['asset_tag']??''),
        'manufactureYear'=>$row['manufacture_year']!==null?(int)$row['manufacture_year']:null,
        'operationalStatus'=>(string)$row['operational_status'],
        'conditionStatus'=>(string)$row['condition_status'],
        'criticality'=>(string)$row['criticality'],
        'locationName'=>(string)($row['location_name']??''),
        'nextServiceOn'=>$row['next_service_on'],
        'lastServiceOn'=>$row['last_service_on'],
        'maintenanceRequired'=>(bool)$row['maintenance_required'],
        'floorPlanId'=>(string)($row['floor_plan_public_id']??''),
        'xFt'=>$row['floor_plan_x_ft']!==null?(float)$row['floor_plan_x_ft']:null,
        'yFt'=>$row['floor_plan_y_ft']!==null?(float)$row['floor_plan_y_ft']:null,
        'rotationDeg'=>(float)($row['floor_plan_rotation_deg']??0),
    ],$statement->fetchAll());
}

function brain_service_contacts(PDO $pdo,int $organizationId,string $assetPublicId='',string $specialty=''): array
{
    $params=[$organizationId];
    $join='';
    $where="c.organization_id=? AND c.archived_at IS NULL AND c.status='active'";
    if($assetPublicId!==''){
        $join=' INNER JOIN equipment_asset_service_contacts link ON link.service_contact_id=c.id INNER JOIN equipment_assets a ON a.id=link.equipment_asset_id ';
        $where.=' AND a.organization_id=? AND a.public_id=? AND a.archived_at IS NULL';
        $params[]=$organizationId;
        $params[]=$assetPublicId;
    }
    if($specialty!==''){
        $where.=' AND c.specialty LIKE ?';
        $params[]='%'.$specialty.'%';
    }
    $statement=$pdo->prepare("SELECT DISTINCT c.public_id,c.company_name,c.contact_name,c.specialty,c.phone,c.email,c.website,c.emergency_phone,c.is_preferred,c.is_warranty_provider FROM equipment_service_contacts c {$join} WHERE {$where} ORDER BY c.is_preferred DESC,c.is_warranty_provider DESC,c.company_name LIMIT 30");
    $statement->execute($params);
    return array_map(static fn(array $row): array => [
        'id'=>(string)$row['public_id'],'companyName'=>(string)$row['company_name'],'contactName'=>(string)($row['contact_name']??''),'specialty'=>(string)($row['specialty']??''),'phone'=>(string)($row['phone']??''),'email'=>(string)($row['email']??''),'website'=>(string)($row['website']??''),'emergencyPhone'=>(string)($row['emergency_phone']??''),'preferred'=>(bool)$row['is_preferred'],'warrantyProvider'=>(bool)$row['is_warranty_provider']
    ],$statement->fetchAll());
}

function brain_asset_context(PDO $pdo,int $organizationId,string $publicId): ?array
{
    $statement=$pdo->prepare('SELECT * FROM equipment_assets WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$organizationId,$publicId]);
    $asset=$statement->fetch();
    if(!$asset)return null;
    $knowledge=equipment_brain_asset_text($pdo,$organizationId,$asset);
    if(!empty($asset['floor_plan_public_id'])&&function_exists('floor_equipment_placement_text'))$knowledge.="\n\n".floor_equipment_placement_text($pdo,$organizationId,$asset);
    return ['id'=>(string)$asset['public_id'],'name'=>(string)$asset['name'],'knowledge'=>$knowledge,'contacts'=>equipment_brain_asset_contacts($pdo,$organizationId,(int)$asset['id']),'serviceHistory'=>equipment_brain_asset_events($pdo,$organizationId,(int)$asset['id'],20)];
}

function brain_floor_plan(PDO $pdo,int $organizationId,string $planId=''): array
{
    $planId=preg_replace('/[^a-zA-Z0-9_-]/','',$planId)?:'';
    $assets=floor_equipment_plan_assets($pdo,$organizationId,$planId);
    $plans=[];
    foreach($assets as $asset){
        $pid=$asset['floorPlanId'];
        if(!isset($plans[$pid])){
            $plan=floor_equipment_plan_row($pdo,$organizationId,$pid);
            $plans[$pid]=['id'=>$pid,'name'=>$plan['name']??$pid,'widthFt'=>$plan?(float)$plan['width_ft']:null,'depthFt'=>$plan?(float)$plan['depth_ft']:null,'assets'=>[]];
        }
        $plans[$pid]['assets'][]=$asset;
    }
    return array_values($plans);
}

function brain_floor_plan_answer(PDO $pdo,int $organizationId,string $message): array
{
    $placements=floor_equipment_plan_assets($pdo,$organizationId,'');
    if(!$placements)return ['skill'=>'equipment.floor_plan','answer'=>'No equipment assets are currently placed on a saved floor plan.','data'=>[],'sources'=>[]];
    $normalized=mb_strtolower($message,'UTF-8');
    foreach($placements as $asset){
        $name=mb_strtolower($asset['name'],'UTF-8');
        if($name!==''&&str_contains($normalized,$name)){
            $plan=floor_equipment_plan_row($pdo,$organizationId,$asset['floorPlanId']);
            $answer=$asset['name'].' is on '.($plan['name']??$asset['floorPlanId']).' at approximately '.number_format((float)$asset['xFt'],1).' ft from the left and '.number_format((float)$asset['yFt'],1).' ft from the top, rotated '.number_format((float)$asset['rotationDeg'],0).'°. Its operational location is '.($asset['locationName']?:'not separately labeled').'.';
            return ['skill'=>'equipment.floor_plan','answer'=>$answer,'data'=>$asset,'sources'=>[$asset['id']]];
        }
    }
    $groups=brain_floor_plan($pdo,$organizationId,'');
    $lines=[];$sources=[];
    foreach($groups as $plan){
        $names=array_map(static fn(array $asset): string => $asset['name'].' ['.$asset['operationalStatus'].']',$plan['assets']);
        $lines[]=$plan['name'].': '.implode(', ',$names);
        foreach($plan['assets'] as $asset)$sources[]=$asset['id'];
    }
    return ['skill'=>'equipment.floor_plan','answer'=>"Equipment currently placed on saved floor plans:\n- ".implode("\n- ",$lines),'data'=>$groups,'sources'=>array_values(array_unique($sources))];
}

function brain_wholesale_answer(PDO $pdo,int $organizationId,string $message): array
{
    $normalized=mb_strtolower($message,'UTF-8');
    if(preg_match('/\b(pipeline|stage|forecast|weighted|wholesale summary|opportunities)\b/u',$normalized)){
        $summary=restaurant_brain_wholesale_summary($pdo,$organizationId);
        if(!$summary)return ['skill'=>'wholesale.pipeline','answer'=>'There are no wholesale gelato opportunities in the pipeline yet.','data'=>[],'sources'=>[]];
        $lines=[];$total=0;$weighted=0;
        foreach($summary as $row){
            $lines[]=ucfirst((string)$row['pipeline_stage']).': '.(int)$row['lead_count'].' lead(s), $'.number_format((float)$row['value_total'],0).' value';
            if(!in_array($row['pipeline_stage'],['won','lost'],true)){$total+=(float)$row['value_total'];$weighted+=(float)$row['weighted_value'];}
        }
        return ['skill'=>'wholesale.pipeline','answer'=>"Wholesale gelato pipeline:\n- ".implode("\n- ",$lines).'\nOpen pipeline value: $'.number_format($total,0).'; weighted value: $'.number_format($weighted,0).'.','data'=>$summary,'sources'=>[]];
    }
    if(preg_match('/\b(follow.?up|overdue|due today|next contact)\b/u',$normalized)){
        $statement=$pdo->prepare("SELECT public_id,business_name,pipeline_stage,next_followup_at,contact_name,email FROM wholesale_leads WHERE organization_id=? AND archived_at IS NULL AND pipeline_stage NOT IN ('won','lost') AND next_followup_at IS NOT NULL AND next_followup_at<=DATE_ADD(NOW(),INTERVAL 7 DAY) ORDER BY next_followup_at LIMIT 20");
        $statement->execute([$organizationId]);
        $rows=$statement->fetchAll();
        if(!$rows)return ['skill'=>'wholesale.followups','answer'=>'No wholesale follow-ups are due in the next seven days.','data'=>[],'sources'=>[]];
        $lines=[];foreach($rows as $row)$lines[]=$row['business_name'].' — '.$row['pipeline_stage'].' — '.$row['next_followup_at'].' — '.$row['contact_name'];
        return ['skill'=>'wholesale.followups','answer'=>"Wholesale follow-ups due soon:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
    }
    $leads=restaurant_brain_wholesale_search($pdo,$organizationId,$message,12);
    if($leads){
        if(count($leads)===1)return ['skill'=>'wholesale.lead_context','answer'=>restaurant_brain_wholesale_text($pdo,$organizationId,$leads[0]),'data'=>$leads[0],'sources'=>[(string)$leads[0]['public_id']]];
        $lines=[];foreach($leads as $lead)$lines[]=$lead['business_name'].' — '.$lead['pipeline_stage'].' — '.($lead['estimated_monthly_volume']?:'volume TBD').' — '.($lead['flavors_interest']?:'no flavor note');
        return ['skill'=>'wholesale.search','answer'=>"Matching wholesale opportunities:\n- ".implode("\n- ",$lines),'data'=>$leads,'sources'=>array_column($leads,'public_id')];
    }
    return ['skill'=>'wholesale.search','answer'=>'I could not find a matching wholesale opportunity. New public wholesale requests appear in the wholesale pipeline automatically.','data'=>[],'sources'=>[]];
}

function brain_catering_answer(PDO $pdo,int $organizationId,string $message): array
{
    $normalized=mb_strtolower(trim($message),'UTF-8');
    if(preg_match('/\b(upcoming|next event|next events|event calendar|booked events|events? (?:this|next) (?:week|month)|next \d{1,3} days?)\b/u',$normalized)){
        $days=30;
        if(preg_match('/\bnext (\d{1,3}) days?\b/u',$normalized,$match))$days=max(1,min(365,(int)$match[1]));
        elseif(str_contains($normalized,'week'))$days=7;
        $rows=restaurant_brain_catering_upcoming($pdo,$organizationId,$days);
        if(!$rows)return ['skill'=>'catering.upcoming','answer'=>"No active catering events are scheduled in the next {$days} days.",'data'=>[],'sources'=>[]];
        $lines=[];
        foreach(array_slice($rows,0,20) as $row){
            $title=(string)(($row['event_name']??'')?:($row['company_name']??'')?:$row['contact_name']);
            $lines[]=$row['event_date'].' — '.$title.' — '.($row['guest_count']?:'guest count TBD').' guests — '.$row['pipeline_stage'];
        }
        return ['skill'=>'catering.upcoming','answer'=>"Upcoming catering events:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
    }
    if(preg_match('/\b(follow.?up|overdue|due today|next contact)\b/u',$normalized)){
        $statement=$pdo->prepare("SELECT public_id,event_name,company_name,contact_name,pipeline_stage,event_date,next_followup_at FROM catering_leads WHERE organization_id=? AND archived_at IS NULL AND pipeline_stage NOT IN ('completed','lost') AND next_followup_at IS NOT NULL AND next_followup_at<=DATE_ADD(NOW(),INTERVAL 7 DAY) ORDER BY next_followup_at LIMIT 20");
        $statement->execute([$organizationId]);
        $rows=$statement->fetchAll();
        if(!$rows)return ['skill'=>'catering.followups','answer'=>'No catering follow-ups are due in the next seven days.','data'=>[],'sources'=>[]];
        $lines=[];
        foreach($rows as $row){$title=(string)(($row['event_name']??'')?:($row['company_name']??'')?:$row['contact_name']);$lines[]=$title.' — '.$row['pipeline_stage'].' — follow up '.$row['next_followup_at'].($row['event_date']?' — event '.$row['event_date']:'');}
        return ['skill'=>'catering.followups','answer'=>"Catering follow-ups due soon:\n- ".implode("\n- ",$lines),'data'=>$rows,'sources'=>array_column($rows,'public_id')];
    }
    if(preg_match('/\b(pipeline|stage|forecast|weighted|catering summary|opportunities|sales funnel)\b/u',$normalized)){
        $summary=restaurant_brain_catering_summary($pdo,$organizationId);
        if(!$summary)return ['skill'=>'catering.pipeline','answer'=>'There are no catering opportunities in the pipeline yet.','data'=>[],'sources'=>[]];
        $lines=[];$total=0;$weighted=0;
        foreach($summary as $row){
            $lines[]=ucfirst((string)$row['pipeline_stage']).': '.(int)$row['lead_count'].' event(s), $'.number_format((float)$row['value_total'],0).' value';
            if(!in_array($row['pipeline_stage'],['completed','lost'],true)){$total+=(float)$row['value_total'];$weighted+=(float)$row['weighted_value'];}
        }
        return ['skill'=>'catering.pipeline','answer'=>"Catering pipeline:\n- ".implode("\n- ",$lines).'\nActive pipeline value: $'.number_format($total,0).'; weighted value: $'.number_format($weighted,0).'.','data'=>$summary,'sources'=>[]];
    }

    $leads=restaurant_brain_catering_search($pdo,$organizationId,$message,20);
    if(!$leads){
        $tokens=array_values(array_filter(preg_split('/[^\pL\pN]+/u',$normalized)?:[],static fn(string $token): bool => mb_strlen($token,'UTF-8')>=4&&!in_array($token,['catering','event','events','about','show','find','what','when','where','with','from','that','this','please'],true)));
        if($tokens){
            $all=restaurant_brain_catering_search($pdo,$organizationId,'',50);
            $leads=array_values(array_filter($all,static function(array $row) use($tokens): bool {
                $haystack=mb_strtolower(implode(' ',[(string)($row['event_name']??''),(string)($row['company_name']??''),(string)($row['contact_name']??''),(string)($row['event_type']??''),(string)($row['venue_name']??''),(string)($row['venue_address']??'')]),'UTF-8');
                foreach($tokens as $token)if(str_contains($haystack,$token))return true;
                return false;
            }));
        }
    }
    if($leads){
        if(count($leads)===1)return ['skill'=>'catering.event_context','answer'=>catering_brain_text($pdo,$organizationId,$leads[0]),'data'=>$leads[0],'sources'=>[(string)$leads[0]['public_id']]];
        $lines=[];
        foreach(array_slice($leads,0,15) as $lead){$title=(string)(($lead['event_name']??'')?:($lead['company_name']??'')?:$lead['contact_name']);$lines[]=$title.' — '.($lead['event_date']?:'date TBD').' — '.($lead['guest_count']?:'guest count TBD').' guests — '.$lead['pipeline_stage'];}
        return ['skill'=>'catering.search','answer'=>"Matching catering opportunities:\n- ".implode("\n- ",$lines),'data'=>$leads,'sources'=>array_column($leads,'public_id')];
    }
    return ['skill'=>'catering.search','answer'=>'I could not find a matching catering opportunity. New public catering requests appear in the catering pipeline automatically.','data'=>[],'sources'=>[]];
}

function brain_recipe_answer(PDO $pdo,int $organizationId,string $message): array
{
    $normalized=mb_strtolower($message,'UTF-8');
    $recipes=restaurant_brain_recipe_search($pdo,$organizationId,$message,20);
    if(preg_match('/\b(mapped|online source|online recipe|recipe source|unmapped)\b/u',$normalized)){
        $all=restaurant_brain_recipe_search($pdo,$organizationId,'',100);
        $filtered=array_values(array_filter($all,static function(array $r) use($normalized): bool {return str_contains($normalized,'unmapped')?($r['mapping_status']!=='mapped'):($r['mapping_status']==='mapped');}));
        if(!$filtered)return ['skill'=>'recipe.online_mappings','answer'=>str_contains($normalized,'unmapped')?'All current recipes are mapped or there are no recipes yet.':'No recipes have a confirmed online mapping yet.','data'=>[],'sources'=>[]];
        $lines=[];foreach(array_slice($filtered,0,20) as $r)$lines[]=$r['name'].' — '.$r['mapping_status'].($r['source_url']?' — '.$r['source_url']:'');
        return ['skill'=>'recipe.online_mappings','answer'=>"Recipe mapping status:\n- ".implode("\n- ",$lines),'data'=>$filtered,'sources'=>array_column($filtered,'public_id')];
    }
    if($recipes){
        $lower=mb_strtolower($message,'UTF-8');
        foreach($recipes as $recipe){if(str_contains($lower,mb_strtolower((string)$recipe['name'],'UTF-8')))return ['skill'=>'recipe.context','answer'=>restaurant_brain_recipe_text($recipe),'data'=>$recipe,'sources'=>[(string)$recipe['public_id']]];}
        $lines=[];foreach(array_slice($recipes,0,15) as $recipe)$lines[]=$recipe['name'].' — '.($recipe['category']?:'uncategorized').' — '.$recipe['mapping_status'];
        return ['skill'=>'recipe.search','answer'=>"Matching recipes:\n- ".implode("\n- ",$lines),'data'=>$recipes,'sources'=>array_column($recipes,'public_id')];
    }
    return ['skill'=>'recipe.search','answer'=>'I could not find that in the private recipe library. Add the recipe or upload a recipe image so AI can extract and map it.','data'=>[],'sources'=>[]];
}

function brain_equipment_answer(PDO $pdo,int $organizationId,string $message): array
{
    $normalized=mb_strtolower(trim($message),'UTF-8');
    if(preg_match('/\b(floor\s*plan|layout|where\s+(?:is|are)|located|positioned|placement)\b/u',$normalized))return brain_floor_plan_answer($pdo,$organizationId,$message);
    if(preg_match('/\b(overdue|due|maintenance|service soon|needs service|preventive)\b/u',$normalized)){
        $days=preg_match('/\b(\d{1,3})\s*days?\b/u',$normalized,$match)?max(0,min(365,(int)$match[1])):30;
        $due=equipment_brain_maintenance_due($pdo,$organizationId,$days);
        if(!$due)return ['skill'=>'equipment.maintenance_due','answer'=>"No maintenance is overdue or due within {$days} days based on the current equipment schedule.",'data'=>[],'sources'=>[]];
        $lines=[];foreach(array_slice($due,0,12) as $item){$when=$item['next_service_on']?:'unscheduled';$lines[]=$item['name'].' — '.$item['asset_type'].' — due '.$when.' — '.$item['criticality'].' criticality';}
        return ['skill'=>'equipment.maintenance_due','answer'=>"Equipment maintenance requiring attention:\n- ".implode("\n- ",$lines),'data'=>$due,'sources'=>array_column($due,'public_id')];
    }
    if(preg_match('/\b(contact|technician|repair company|service company|vendor|who.*call|warranty)\b/u',$normalized)){
        $assets=brain_asset_search($pdo,$organizationId,$message,5);
        $assetId=count($assets)===1?$assets[0]['id']:'';
        $contacts=brain_service_contacts($pdo,$organizationId,$assetId,'');
        if(!$contacts)return ['skill'=>'equipment.service_contacts','answer'=>'No matching equipment service contacts are recorded yet. Add a service company in the Equipment Catalog and link it to the asset.','data'=>[],'sources'=>[]];
        $lines=[];foreach(array_slice($contacts,0,10) as $contact)$lines[]=$contact['companyName'].($contact['contactName']?' — '.$contact['contactName']:'').($contact['specialty']?' — '.$contact['specialty']:'').($contact['phone']?' — '.$contact['phone']:'').($contact['emergencyPhone']?' — emergency '.$contact['emergencyPhone']:'');
        return ['skill'=>'equipment.service_contacts','answer'=>"Recorded service contacts:\n- ".implode("\n- ",$lines),'data'=>$contacts,'sources'=>array_column($contacts,'id')];
    }
    $assets=brain_asset_search($pdo,$organizationId,$message,12);
    if($assets){
        if(count($assets)===1){$context=brain_asset_context($pdo,$organizationId,$assets[0]['id']);return ['skill'=>'equipment.asset_context','answer'=>$context?mb_substr($context['knowledge'],0,7000,'UTF-8'):'Equipment record found.','data'=>$context?:$assets[0],'sources'=>[$assets[0]['id']]];}
        $lines=[];foreach($assets as $asset)$lines[]=$asset['name'].' — '.$asset['assetType'].($asset['brand']?' — '.$asset['brand']:'').($asset['model']?' '.$asset['model']:'').' — '.$asset['operationalStatus'];
        return ['skill'=>'equipment.search','answer'=>"Matching equipment records:\n- ".implode("\n- ",$lines),'data'=>$assets,'sources'=>array_column($assets,'id')];
    }
    return ['skill'=>'equipment.search','answer'=>'I could not find a matching equipment record.','data'=>[],'sources'=>[]];
}

function brain_scoped_knowledge_search(PDO $pdo,int $organizationId,string $query,array $user,int $limit=8): array
{
    $limit=max(1,min(30,$limit));
    $allDomains=brain_can_equipment($user)&&brain_can_wholesale($user)&&brain_can_recipes($user)&&brain_can_catering($user);
    $rows=equipment_brain_search($pdo,$organizationId,$query,$allDomains?$limit:50);
    if($allDomains)return array_slice($rows,0,$limit);
    $allowed=[];
    if(brain_can_equipment($user))$allowed[]='equipment_asset';
    if(brain_can_wholesale($user))$allowed[]='wholesale_lead';
    if(brain_can_recipes($user))$allowed[]='recipe';
    if(brain_can_catering($user))$allowed[]='catering_lead';
    $rows=array_values(array_filter($rows,static fn(array $row): bool => in_array((string)($row['source_type']??''),$allowed,true)));
    return array_slice($rows,0,$limit);
}

function brain_answer(PDO $pdo,int $organizationId,string $message,array $user): array
{
    $normalized=mb_strtolower(trim($message),'UTF-8');
    if($normalized==='')return ['skill'=>'none','answer'=>'Ask me about catering events, wholesale opportunities, recipes, equipment, maintenance, service contacts, or floor plans.','data'=>[],'sources'=>[]];
    $cateringIntent=preg_match('/\b(catering|catered|event|events|party|wedding|banquet|venue|guest count|tasting|proposal|deposit|booked event|booked events)\b/u',$normalized)===1;
    $wholesaleIntent=preg_match('/\b(wholesale|buyer|lead|prospect|sample|quote|private label|foodservice|pipeline|follow.?up|account opportunity)\b/u',$normalized)===1;
    $recipeIntent=preg_match('/\b(recipe|formula|ingredient|yield|method|instructions|online recipe|recipe image|recipe source|mapped recipe|unmapped)\b/u',$normalized)===1;
    $equipmentIntent=preg_match('/\b(equipment|oven|mixer|freezer|cooler|dish|machine|maintenance|repair|warranty|service|floor\s*plan|layout|located|placement)\b/u',$normalized)===1;
    if($cateringIntent&&brain_can_catering($user))return brain_catering_answer($pdo,$organizationId,$message);
    if($wholesaleIntent&&brain_can_wholesale($user))return brain_wholesale_answer($pdo,$organizationId,$message);
    if($recipeIntent&&brain_can_recipes($user))return brain_recipe_answer($pdo,$organizationId,$message);
    if($equipmentIntent&&brain_can_equipment($user))return brain_equipment_answer($pdo,$organizationId,$message);
    if(brain_can_catering($user)){ $result=brain_catering_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    if(brain_can_wholesale($user)){ $result=brain_wholesale_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    if(brain_can_recipes($user)){ $result=brain_recipe_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    if(brain_can_equipment($user)){ $result=brain_equipment_answer($pdo,$organizationId,$message); if(!empty($result['data']))return $result; }
    $knowledge=brain_scoped_knowledge_search($pdo,$organizationId,$message,$user,8);
    if($knowledge){
        $excerpts=[];$sources=[];
        foreach($knowledge as $record){$excerpts[]=mb_substr((string)$record['content'],0,1400,'UTF-8');$sources[]=(string)$record['source_public_id'];}
        return ['skill'=>'knowledge.search','answer'=>implode("\n\n",$excerpts),'data'=>$knowledge,'sources'=>array_values(array_unique($sources))];
    }
    return ['skill'=>'knowledge.search','answer'=>'I could not find that in the private restaurant knowledge base yet. Add or update the relevant catering, wholesale, recipe, or equipment record so I have the missing facts.','data'=>[],'sources'=>[]];
}

brain_require_any($user);
if(!equipment_brain_table_ready($pdo,'agent_knowledge_records'))app_json_response(['ok'=>false,'message'=>'Restaurant Agent knowledge migration is not installed. Run upgrade.php.'],503);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $action=(string)($_GET['action']??'summary');
    if($action==='summary'){
        $payload=['ok'=>true,'skill'=>'restaurant.summary','domains'=>[]];
        if(brain_can_equipment($user)&&equipment_brain_table_ready($pdo,'equipment_assets'))$payload['domains']['equipment']=['summary'=>equipment_brain_summary($pdo,$organizationId),'maintenanceDue'=>equipment_brain_maintenance_due($pdo,$organizationId,30)];
        if(brain_can_wholesale($user)&&restaurant_brain_table_ready($pdo,'wholesale_leads'))$payload['domains']['wholesale']=['pipeline'=>restaurant_brain_wholesale_summary($pdo,$organizationId)];
        if(brain_can_catering($user)&&restaurant_brain_table_ready($pdo,'catering_leads'))$payload['domains']['catering']=['pipeline'=>restaurant_brain_catering_summary($pdo,$organizationId),'upcoming'=>restaurant_brain_catering_upcoming($pdo,$organizationId,30)];
        if(brain_can_recipes($user)&&restaurant_brain_table_ready($pdo,'recipes'))$payload['domains']['recipes']=['count'=>count(restaurant_brain_recipe_search($pdo,$organizationId,'',100))];
        app_json_response($payload);
    }
    if($action==='search'){$q=(string)($_GET['q']??'');app_json_response(['ok'=>true,'skill'=>'knowledge.search','results'=>brain_scoped_knowledge_search($pdo,$organizationId,$q,$user,12)]);}
    if($action==='floor_plan'&&brain_can_equipment($user))app_json_response(['ok'=>true,'skill'=>'equipment.floor_plan','plans'=>brain_floor_plan($pdo,$organizationId,(string)($_GET['planId']??''))]);
    if($action==='wholesale'&&brain_can_wholesale($user))app_json_response(['ok'=>true,'skill'=>'wholesale.search','leads'=>restaurant_brain_wholesale_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);
    if($action==='catering'&&brain_can_catering($user))app_json_response(['ok'=>true,'skill'=>'catering.search','leads'=>restaurant_brain_catering_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);
    if($action==='recipes'&&brain_can_recipes($user))app_json_response(['ok'=>true,'skill'=>'recipe.search','recipes'=>restaurant_brain_recipe_search($pdo,$organizationId,(string)($_GET['q']??''),30)]);
    app_json_response(['ok'=>false,'message'=>'Unsupported Agent skill.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Allow: GET, POST');
    app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
}
$input=app_json_input();
app_verify_request_csrf($input);
$action=(string)($input['action']??'ask');

if($action==='ask'){
    $message=trim((string)($input['message']??''));
    if($message===''||mb_strlen($message,'UTF-8')>1600)app_json_response(['ok'=>false,'message'=>'Enter a restaurant operations question no longer than 1,600 characters.'],422);
    $result=brain_answer($pdo,$organizationId,$message,$user);
    app_audit($pdo,$organizationId,(int)$user['id'],'agent.restaurant_skill_used','agent_skill',$result['skill'],null,['message'=>mb_substr($message,0,300,'UTF-8'),'sources'=>$result['sources']]);
    app_json_response(['ok'=>true]+$result);
}

if($action==='run_skill'){
    $skill=(string)($input['skill']??'');
    $args=(array)($input['arguments']??[]);
    if(str_starts_with($skill,'equipment.')&&!brain_can_equipment($user))app_json_response(['ok'=>false,'message'=>'Equipment Agent permission required.'],403);
    if(str_starts_with($skill,'wholesale.')&&!brain_can_wholesale($user))app_json_response(['ok'=>false,'message'=>'Wholesale Agent permission required.'],403);
    if(str_starts_with($skill,'catering.')&&!brain_can_catering($user))app_json_response(['ok'=>false,'message'=>'Catering Agent permission required.'],403);
    if(str_starts_with($skill,'recipe.')&&!brain_can_recipes($user))app_json_response(['ok'=>false,'message'=>'Recipe Agent permission required.'],403);
    if($skill==='equipment.search')$result=['skill'=>$skill,'data'=>brain_asset_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??12))];
    elseif($skill==='equipment.maintenance_due')$result=['skill'=>$skill,'data'=>equipment_brain_maintenance_due($pdo,$organizationId,(int)($args['days']??30))];
    elseif($skill==='equipment.service_contacts')$result=['skill'=>$skill,'data'=>brain_service_contacts($pdo,$organizationId,(string)($args['assetId']??''),(string)($args['specialty']??''))];
    elseif($skill==='equipment.asset_context')$result=['skill'=>$skill,'data'=>brain_asset_context($pdo,$organizationId,(string)($args['assetId']??''))];
    elseif($skill==='equipment.floor_plan')$result=['skill'=>$skill,'data'=>brain_floor_plan($pdo,$organizationId,(string)($args['planId']??''))];
    elseif($skill==='wholesale.search')$result=['skill'=>$skill,'data'=>restaurant_brain_wholesale_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20))];
    elseif($skill==='wholesale.pipeline')$result=['skill'=>$skill,'data'=>restaurant_brain_wholesale_summary($pdo,$organizationId)];
    elseif($skill==='catering.search')$result=['skill'=>$skill,'data'=>restaurant_brain_catering_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20))];
    elseif($skill==='catering.pipeline')$result=['skill'=>$skill,'data'=>restaurant_brain_catering_summary($pdo,$organizationId)];
    elseif($skill==='catering.upcoming')$result=['skill'=>$skill,'data'=>restaurant_brain_catering_upcoming($pdo,$organizationId,(int)($args['days']??30))];
    elseif($skill==='recipe.search')$result=['skill'=>$skill,'data'=>restaurant_brain_recipe_search($pdo,$organizationId,(string)($args['query']??''),(int)($args['limit']??20))];
    elseif($skill==='recipe.context'){$recipe=restaurant_brain_recipe_by_public_id($pdo,$organizationId,(string)($args['recipeId']??''));$result=['skill'=>$skill,'data'=>$recipe,'answer'=>$recipe?restaurant_brain_recipe_text($recipe):'Recipe not found.'];}
    elseif($skill==='knowledge.search')$result=['skill'=>$skill,'data'=>brain_scoped_knowledge_search($pdo,$organizationId,(string)($args['query']??''),$user,(int)($args['limit']??8))];
    else app_json_response(['ok'=>false,'message'=>'Unknown restaurant Agent skill.'],422);
    app_audit($pdo,$organizationId,(int)$user['id'],'agent.restaurant_skill_used','agent_skill',$skill,null,['arguments'=>$args]);
    app_json_response(['ok'=>true]+$result);
}

app_json_response(['ok'=>false,'message'=>'Unsupported Agent Brain action.'],422);
