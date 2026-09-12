<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/recipe-intelligence.php';

$user = app_require_permission($_SERVER['REQUEST_METHOD']==='GET' ? 'recipes.view' : 'recipes.edit');
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
if (!restaurant_brain_table_ready($pdo,'recipes')) app_json_response(['ok'=>false,'message'=>'Recipe Library migration is not installed. Run upgrade.php.'],503);

function recipe_api_public(array $row): array
{
    return [
        'id'=>(string)$row['public_id'],'name'=>(string)$row['name'],'category'=>(string)($row['category']??''),'description'=>(string)($row['description']??''),
        'yieldQuantity'=>$row['yield_quantity']!==null?(float)$row['yield_quantity']:null,'yieldUnit'=>(string)($row['yield_unit']??''),
        'ingredients'=>json_decode((string)($row['ingredients_json']??'[]'),true)?:[],'instructions'=>json_decode((string)($row['instructions_json']??'[]'),true)?:[],
        'notes'=>(string)($row['notes']??''),'status'=>(string)$row['status'],'sourceTitle'=>(string)($row['source_title']??''),'sourceUrl'=>(string)($row['source_url']??''),
        'sourceDomain'=>(string)($row['source_domain']??''),'sourceConfidence'=>$row['source_confidence']!==null?(float)$row['source_confidence']:null,'mappingStatus'=>(string)$row['mapping_status'],
        'mappingNotes'=>(string)($row['mapping_notes']??''),'mappedAt'=>$row['mapped_at'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],
        'imageCount'=>isset($row['image_count'])?(int)$row['image_count']:0,
    ];
}

if ($_SERVER['REQUEST_METHOD']==='GET') {
    $action=(string)($_GET['action']??'list');
    if($action==='list'){
        $q=trim((string)($_GET['q']??''));$rows=restaurant_brain_recipe_search($pdo,$organizationId,$q,200);app_json_response(['ok'=>true,'recipes'=>array_map('recipe_api_public',$rows)]);
    }
    if($action==='detail'){
        $id=trim((string)($_GET['id']??''));$statement=$pdo->prepare("SELECT r.*,(SELECT COUNT(*) FROM recipe_images ri WHERE ri.recipe_id=r.id) AS image_count FROM recipes r WHERE r.organization_id=? AND r.public_id=? AND r.archived_at IS NULL LIMIT 1");$statement->execute([$organizationId,$id]);$row=$statement->fetch();if(!$row)app_json_response(['ok'=>false,'message'=>'Recipe not found.'],404);
        $images=$pdo->prepare("SELECT ri.id,ri.image_role,ri.is_primary,ri.extracted_text,ri.analysis_json,ri.mapping_status,ri.mapped_source_url,ri.mapped_source_title,ri.mapped_source_domain,ri.mapped_confidence,ri.created_at,f.original_name,f.mime_type,f.file_size FROM recipe_images ri INNER JOIN files f ON f.id=ri.file_id AND f.deleted_at IS NULL WHERE ri.organization_id=? AND ri.recipe_id=? ORDER BY ri.is_primary DESC,ri.created_at DESC");$images->execute([$organizationId,(int)$row['id']]);
        $mapped=array_map(static function(array $image):array{$analysis=json_decode((string)($image['analysis_json']??'{}'),true)?:null;return ['id'=>(int)$image['id'],'role'=>$image['image_role'],'primary'=>(bool)$image['is_primary'],'extractedText'=>(string)($image['extracted_text']??''),'analysis'=>$analysis,'mappingStatus'=>$image['mapping_status'],'mappedSourceUrl'=>(string)($image['mapped_source_url']??''),'mappedSourceTitle'=>(string)($image['mapped_source_title']??''),'mappedSourceDomain'=>(string)($image['mapped_source_domain']??''),'mappedConfidence'=>$image['mapped_confidence']!==null?(float)$image['mapped_confidence']:null,'fileName'=>$image['original_name'],'mimeType'=>$image['mime_type'],'fileSize'=>(int)$image['file_size'],'createdAt'=>$image['created_at'],'imageUrl'=>'recipe-image.php?id='.(int)$image['id']];},$images->fetchAll());
        app_json_response(['ok'=>true,'recipe'=>recipe_api_public($row),'images'=>$mapped]);
    }
    app_json_response(['ok'=>false,'message'=>'Unsupported recipe action.'],422);
}

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
$input=app_json_input();app_verify_request_csrf($input);$action=(string)($input['action']??'');

if($action==='create'){
    $name=mb_substr(trim((string)($input['name']??'')),0,220,'UTF-8');if($name==='')$name='Untitled Recipe';$publicId=restaurant_brain_public_id('recipe');
    $statement=$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,status,created_by,updated_by) VALUES (?,?,?,?,?,'active',?,?)");$statement->execute([$organizationId,$publicId,$name,mb_substr(trim((string)($input['category']??'')),0,100,'UTF-8')?:null,mb_substr(trim((string)($input['description']??'')),0,5000,'UTF-8')?:null,(int)$user['id'],(int)$user['id']]);$id=(int)$pdo->lastInsertId();restaurant_brain_sync_recipe($pdo,$organizationId,$id,(int)$user['id']);app_audit($pdo,$organizationId,(int)$user['id'],'recipe.created','recipe',$publicId,null,['name'=>$name]);app_json_response(['ok'=>true,'id'=>$publicId,'message'=>'Recipe created.'],201);
}

$id=trim((string)($input['id']??''));$statement=$pdo->prepare('SELECT * FROM recipes WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$statement->execute([$organizationId,$id]);$recipe=$statement->fetch();if(!$recipe)app_json_response(['ok'=>false,'message'=>'Recipe not found.'],404);$recipeId=(int)$recipe['id'];

if($action==='update'){
    $name=mb_substr(trim((string)($input['name']??$recipe['name'])),0,220,'UTF-8');if($name==='')app_json_response(['ok'=>false,'message'=>'Recipe name is required.'],422);
    $category=mb_substr(trim((string)($input['category']??$recipe['category']??'')),0,100,'UTF-8');$description=mb_substr(trim((string)($input['description']??$recipe['description']??'')),0,10000,'UTF-8');$notes=mb_substr(trim((string)($input['notes']??$recipe['notes']??'')),0,20000,'UTF-8');
    $yieldQuantity=$input['yieldQuantity']??$recipe['yield_quantity'];if($yieldQuantity!==null&&$yieldQuantity!==''&&!is_numeric($yieldQuantity))app_json_response(['ok'=>false,'message'=>'Yield quantity must be numeric.'],422);$yieldUnit=mb_substr(trim((string)($input['yieldUnit']??$recipe['yield_unit']??'')),0,80,'UTF-8');
    $ingredients=array_values(array_slice((array)($input['ingredients']??json_decode((string)($recipe['ingredients_json']??'[]'),true)?:[]),0,250));$instructions=array_values(array_slice((array)($input['instructions']??json_decode((string)($recipe['instructions_json']??'[]'),true)?:[]),0,250));
    $sourceUrl=recipe_intelligence_safe_external_url((string)($input['sourceUrl']??$recipe['source_url']??''));$sourceTitle=mb_substr(trim((string)($input['sourceTitle']??$recipe['source_title']??'')),0,500,'UTF-8');$sourceDomain=$sourceUrl?(string)(parse_url($sourceUrl,PHP_URL_HOST)?:''):null;$sourceConfidence=$input['sourceConfidence']??$recipe['source_confidence'];$sourceConfidence=$sourceConfidence===null||$sourceConfidence===''?null:max(0,min(1,(float)$sourceConfidence));
    $mappingStatus=(string)($input['mappingStatus']??$recipe['mapping_status']??'unmapped');if(!in_array($mappingStatus,['unmapped','uploaded','analyzed','mapped','needs_review','needs_search'],true))$mappingStatus='unmapped';$mappingNotes=mb_substr(trim((string)($input['mappingNotes']??$recipe['mapping_notes']??'')),0,10000,'UTF-8');
    $update=$pdo->prepare("UPDATE recipes SET name=?,category=?,description=?,yield_quantity=?,yield_unit=?,ingredients_json=?,instructions_json=?,notes=?,source_title=?,source_url=?,source_domain=?,source_confidence=?,mapping_status=?,mapping_notes=?,mapped_at=IF(?='mapped',COALESCE(mapped_at,NOW(6)),mapped_at),mapped_by=IF(?='mapped',?,mapped_by),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");
    $update->execute([$name,$category?:null,$description?:null,$yieldQuantity===''?null:$yieldQuantity,$yieldUnit?:null,json_encode($ingredients,JSON_THROW_ON_ERROR),json_encode($instructions,JSON_THROW_ON_ERROR),$notes?:null,$sourceTitle?:null,$sourceUrl,$sourceDomain,$sourceConfidence,$mappingStatus,$mappingNotes?:null,$mappingStatus,$mappingStatus,(int)$user['id'],(int)$user['id'],$recipeId,$organizationId]);
    restaurant_brain_sync_recipe($pdo,$organizationId,$recipeId,(int)$user['id']);app_audit($pdo,$organizationId,(int)$user['id'],'recipe.updated','recipe',$id,null,['name'=>$name,'mappingStatus'=>$mappingStatus,'sourceUrl'=>$sourceUrl]);app_json_response(['ok'=>true,'message'=>'Recipe saved.']);
}

if($action==='apply_ai'){
    if(!app_has_permission('recipes.ai_map',$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to apply AI recipe mappings.'],403);
    $imageId=(int)($input['imageId']??0);$img=$pdo->prepare("SELECT ri.* FROM recipe_images ri WHERE ri.id=? AND ri.organization_id=? AND ri.recipe_id=? LIMIT 1");$img->execute([$imageId,$organizationId,$recipeId]);$image=$img->fetch();if(!$image)app_json_response(['ok'=>false,'message'=>'Recipe image mapping not found.'],404);$analysis=json_decode((string)($image['analysis_json']??'{}'),true);if(!is_array($analysis))app_json_response(['ok'=>false,'message'=>'This image does not have AI mapping data yet.'],422);
    $match=(array)($analysis['onlineMatch']??[]);$url=recipe_intelligence_safe_external_url((string)($match['url']??''));$title=mb_substr(trim((string)($match['title']??'')),0,500,'UTF-8');$confidence=max(0,min(1,(float)($match['confidence']??0)));$name=trim((string)($analysis['recipeName']??''))?:$recipe['name'];$category=trim((string)($analysis['category']??''))?:$recipe['category'];$description=trim((string)($analysis['description']??''))?:$recipe['description'];$ingredients=(array)($analysis['ingredients']??[]);$instructions=(array)($analysis['instructions']??[]);$yieldQuantity=$analysis['yieldQuantity']??$recipe['yield_quantity'];$yieldUnit=trim((string)($analysis['yieldUnit']??''))?:$recipe['yield_unit'];$mappingStatus=$url?'mapped':'needs_search';
    $update=$pdo->prepare("UPDATE recipes SET name=?,category=?,description=?,yield_quantity=?,yield_unit=?,ingredients_json=?,instructions_json=?,source_title=?,source_url=?,source_domain=?,source_confidence=?,mapping_status=?,mapping_notes=?,mapped_at=IF(?='mapped',NOW(6),mapped_at),mapped_by=?,updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");$update->execute([$name,$category?:null,$description?:null,$yieldQuantity,$yieldUnit,json_encode($ingredients,JSON_THROW_ON_ERROR),json_encode($instructions,JSON_THROW_ON_ERROR),$title?:null,$url,$url?(string)(parse_url($url,PHP_URL_HOST)?:''):null,$confidence,$mappingStatus,mb_substr((string)($analysis['mappingNotes']??''),0,10000,'UTF-8')?:null,$mappingStatus,(int)$user['id'],(int)$user['id'],$recipeId,$organizationId]);
    restaurant_brain_sync_recipe($pdo,$organizationId,$recipeId,(int)$user['id']);app_audit($pdo,$organizationId,(int)$user['id'],'recipe.ai_mapping_applied','recipe',$id,null,['imageId'=>$imageId,'sourceUrl'=>$url,'confidence'=>$confidence]);app_json_response(['ok'=>true,'message'=>$url?'AI recipe mapping applied.':'AI extraction applied; no credible live online source was available.']);
}

if($action==='archive'){
    $pdo->prepare("UPDATE recipes SET archived_at=NOW(6),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?")->execute([(int)$user['id'],$recipeId,$organizationId]);
    if(restaurant_brain_table_ready($pdo,'agent_knowledge_records'))$pdo->prepare("UPDATE agent_knowledge_records SET status='archived',version=version+1,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND source_type='recipe' AND source_public_id=?")->execute([(int)$user['id'],$organizationId,$id]);app_audit($pdo,$organizationId,(int)$user['id'],'recipe.archived','recipe',$id);app_json_response(['ok'=>true,'message'=>'Recipe archived.']);
}
app_json_response(['ok'=>false,'message'=>'Unsupported recipe action.'],422);
