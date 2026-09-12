<?php
declare(strict_types=1);

function restaurant_brain_table_ready(PDO $pdo, string $table): bool
{
    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

function restaurant_brain_public_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(10));
}

function restaurant_brain_write_knowledge(PDO $pdo, int $organizationId, string $sourceType, string $sourcePublicId, string $title, string $content, ?int $userId): void
{
    if (!restaurant_brain_table_ready($pdo, 'agent_knowledge_records')) return;
    $publicId = $sourceType . '-' . $sourcePublicId;
    $statement = $pdo->prepare(
        "INSERT INTO agent_knowledge_records
         (organization_id, public_id, source_type, source_public_id, title, content, content_sha256, visibility, status, version, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'internal', 'active', 1, ?)
         ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), content_sha256=VALUES(content_sha256),
           visibility='internal', status='active', version=version+1, updated_by=VALUES(updated_by), updated_at=NOW(6)"
    );
    $statement->execute([$organizationId,$publicId,$sourceType,$sourcePublicId,$title,$content,hash('sha256',$content),$userId]);
}

function restaurant_brain_wholesale_row(PDO $pdo, int $organizationId, int $leadId): ?array
{
    $statement=$pdo->prepare('SELECT * FROM wholesale_leads WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');
    $statement->execute([$leadId,$organizationId]);$row=$statement->fetch();return $row?:null;
}

function restaurant_brain_wholesale_activities(PDO $pdo,int $organizationId,int $leadId,int $limit=12):array
{
    $limit=max(1,min(50,$limit));$statement=$pdo->prepare("SELECT a.*,u.display_name AS created_by_name FROM wholesale_lead_activities a LEFT JOIN users u ON u.id=a.created_by WHERE a.organization_id=? AND a.wholesale_lead_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT {$limit}");$statement->execute([$organizationId,$leadId]);return $statement->fetchAll();
}

function restaurant_brain_wholesale_text(PDO $pdo,int $organizationId,array $lead):string
{
    $sizes=json_decode((string)($lead['package_sizes_json']??'[]'),true)?:[];
    $lines=['Wholesale gelato lead: '.$lead['business_name'],'Pipeline stage: '.$lead['pipeline_stage'],'Contact: '.$lead['contact_name'].' / '.$lead['email'].(($lead['phone']??'')?' / '.$lead['phone']:''),'Business type: '.(($lead['business_type']??'')?:'not recorded'),'Location: '.(($lead['location_text']??'')?:'not recorded'),'Website: '.(($lead['website']??'')?:'not recorded'),'Estimated monthly volume: '.(($lead['estimated_monthly_volume']??'')?:'not recorded'),'Order frequency: '.(($lead['order_frequency']??'')?:'not recorded'),'Package sizes: '.($sizes?implode(', ',array_map('strval',$sizes)):'not recorded'),'Flavor interests: '.(($lead['flavors_interest']??'')?:'not recorded'),'Private label interest: '.((int)($lead['private_label_interest']??0)===1?'yes':'no'),'Freezer capacity: '.(($lead['freezer_capacity']??'')?:'not recorded'),'Fulfillment: '.(($lead['fulfillment_preference']??'')?:'not recorded'),'Desired start: '.(($lead['desired_start_date']??'')?:'not recorded'),'Current supplier: '.(($lead['current_supplier']??'')?:'not recorded'),'Estimated value: '.($lead['estimated_value']!==null?'$'.number_format((float)$lead['estimated_value'],2):'not recorded'),'Probability: '.(int)($lead['probability_percent']??0).'%','Next follow-up: '.(($lead['next_followup_at']??'')?:'not scheduled'),'Notes: '.(($lead['notes']??'')?:'none recorded')];
    $activities=restaurant_brain_wholesale_activities($pdo,$organizationId,(int)$lead['id'],8);if($activities){$lines[]='Recent pipeline activity:';foreach($activities as $activity)$lines[]='- '.$activity['created_at'].' '.$activity['activity_type'].': '.$activity['summary'].(($activity['details']??'')?' — '.$activity['details']:'');}
    return implode("\n",$lines);
}

function restaurant_brain_sync_wholesale(PDO $pdo,int $organizationId,int $leadId,?int $userId):void
{
    if(!restaurant_brain_table_ready($pdo,'wholesale_leads'))return;$lead=restaurant_brain_wholesale_row($pdo,$organizationId,$leadId);if(!$lead)return;restaurant_brain_write_knowledge($pdo,$organizationId,'wholesale_lead',(string)$lead['public_id'],'Wholesale: '.(string)$lead['business_name'],restaurant_brain_wholesale_text($pdo,$organizationId,$lead),$userId);
}

function restaurant_brain_wholesale_search(PDO $pdo,int $organizationId,string $query='',int $limit=20):array
{
    $limit=max(1,min(50,$limit));$query=trim($query);$like='%'.$query.'%';$statement=$pdo->prepare("SELECT l.*,u.display_name AS assigned_name FROM wholesale_leads l LEFT JOIN users u ON u.id=l.assigned_to WHERE l.organization_id=? AND l.archived_at IS NULL AND (?='' OR l.business_name LIKE ? OR l.contact_name LIKE ? OR l.email LIKE ? OR l.business_type LIKE ? OR l.flavors_interest LIKE ? OR l.notes LIKE ?) ORDER BY FIELD(l.pipeline_stage,'new','qualified','sample','quoted','negotiation','won','lost'),l.updated_at DESC LIMIT {$limit}");$statement->execute([$organizationId,$query,$like,$like,$like,$like,$like,$like]);return $statement->fetchAll();
}

function restaurant_brain_wholesale_summary(PDO $pdo,int $organizationId):array
{
    if(!restaurant_brain_table_ready($pdo,'wholesale_leads'))return [];$statement=$pdo->prepare("SELECT pipeline_stage,COUNT(*) AS lead_count,COALESCE(SUM(estimated_value),0) AS value_total,COALESCE(SUM(estimated_value * probability_percent / 100),0) AS weighted_value FROM wholesale_leads WHERE organization_id=? AND archived_at IS NULL GROUP BY pipeline_stage");$statement->execute([$organizationId]);return $statement->fetchAll();
}

function restaurant_brain_recipe_row(PDO $pdo,int $organizationId,int $recipeId):?array
{
    $statement=$pdo->prepare('SELECT * FROM recipes WHERE id=? AND organization_id=? AND archived_at IS NULL LIMIT 1');$statement->execute([$recipeId,$organizationId]);$row=$statement->fetch();return $row?:null;
}

function restaurant_brain_recipe_text(array $recipe):string
{
    $ingredients=json_decode((string)($recipe['ingredients_json']??'[]'),true)?:[];$instructions=json_decode((string)($recipe['instructions_json']??'[]'),true)?:[];$ingredientLines=[];
    foreach($ingredients as $ingredient){if(is_array($ingredient))$ingredientLines[]=trim(implode(' ',array_filter([(string)($ingredient['quantity']??''),(string)($ingredient['unit']??''),(string)($ingredient['ingredient']??$ingredient['name']??''),(string)($ingredient['notes']??'')])));else $ingredientLines[]=trim((string)$ingredient);}
    $instructionLines=[];foreach($instructions as $index=>$instruction)$instructionLines[]=($index+1).'. '.trim(is_array($instruction)?(string)($instruction['text']??''):(string)$instruction);
    $lines=['Recipe: '.$recipe['name'],'Category: '.(($recipe['category']??'')?:'not recorded'),'Description: '.(($recipe['description']??'')?:'not recorded'),'Yield: '.(($recipe['yield_quantity']??'')!==''?rtrim(rtrim(number_format((float)$recipe['yield_quantity'],2,'.',''),'0'),'.').' '.($recipe['yield_unit']??''):'not recorded'),'Status: '.($recipe['status']??'active'),'Online mapping status: '.($recipe['mapping_status']??'unmapped'),'Online source: '.(($recipe['source_title']??'')?:'not mapped').(($recipe['source_url']??'')?' — '.$recipe['source_url']:''),'Mapping confidence: '.($recipe['source_confidence']!==null?number_format((float)$recipe['source_confidence']*100,0).'%':'not recorded')];
    if($ingredientLines)$lines[]="Ingredients:\n- ".implode("\n- ",array_filter($ingredientLines));if($instructionLines)$lines[]="Instructions:\n".implode("\n",array_filter($instructionLines));$lines[]='Recipe notes: '.(($recipe['notes']??'')?:'none recorded');$lines[]='Mapping notes: '.(($recipe['mapping_notes']??'')?:'none recorded');return implode("\n",$lines);
}

function restaurant_brain_sync_recipe(PDO $pdo,int $organizationId,int $recipeId,?int $userId):void
{
    if(!restaurant_brain_table_ready($pdo,'recipes'))return;$recipe=restaurant_brain_recipe_row($pdo,$organizationId,$recipeId);if(!$recipe)return;restaurant_brain_write_knowledge($pdo,$organizationId,'recipe',(string)$recipe['public_id'],'Recipe: '.(string)$recipe['name'],restaurant_brain_recipe_text($recipe),$userId);
}

function restaurant_brain_recipe_search(PDO $pdo,int $organizationId,string $query='',int $limit=20):array
{
    $limit=max(1,min(50,$limit));$query=trim($query);$like='%'.$query.'%';$statement=$pdo->prepare("SELECT r.*,(SELECT COUNT(*) FROM recipe_images ri WHERE ri.recipe_id=r.id) AS image_count FROM recipes r WHERE r.organization_id=? AND r.archived_at IS NULL AND (?='' OR r.name LIKE ? OR r.category LIKE ? OR r.description LIKE ? OR CAST(r.ingredients_json AS CHAR) LIKE ? OR r.notes LIKE ? OR r.source_title LIKE ?) ORDER BY r.updated_at DESC LIMIT {$limit}");$statement->execute([$organizationId,$query,$like,$like,$like,$like,$like,$like]);return $statement->fetchAll();
}

function restaurant_brain_recipe_by_public_id(PDO $pdo,int $organizationId,string $publicId):?array
{
    $statement=$pdo->prepare('SELECT * FROM recipes WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$statement->execute([$organizationId,$publicId]);$row=$statement->fetch();return $row?:null;
}
