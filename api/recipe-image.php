<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/recipe-intelligence.php';

$user = app_require_permission('recipes.edit');
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {header('Allow: POST');app_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);}
app_verify_request_csrf();
if (!restaurant_brain_table_ready($pdo,'recipe_images')) app_json_response(['ok'=>false,'message'=>'Recipe image migration is not installed. Run upgrade.php.'],503);

$recipePublicId=trim((string)($_POST['recipeId']??''));
$mapOnline=(string)($_POST['mapOnline']??'1')!=='0';
if($mapOnline&&!app_has_permission('recipes.ai_map',$user))app_json_response(['ok'=>false,'message'=>'You do not have permission to run AI recipe mapping.'],403);
$recipeStatement=$pdo->prepare('SELECT * FROM recipes WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');$recipeStatement->execute([$organizationId,$recipePublicId]);$recipe=$recipeStatement->fetch();if(!$recipe)app_json_response(['ok'=>false,'message'=>'Recipe not found.'],404);
if(!isset($_FILES['image'])||!is_array($_FILES['image']))app_json_response(['ok'=>false,'message'=>'Choose a recipe image to upload.'],422);
$file=$_FILES['image'];if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)app_json_response(['ok'=>false,'message'=>'The recipe image upload failed.'],422);
$size=(int)($file['size']??0);if($size<1||$size>10*1024*1024)app_json_response(['ok'=>false,'message'=>'Recipe images must be 10 MB or smaller.'],422);
$tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))app_json_response(['ok'=>false,'message'=>'The uploaded recipe image is invalid.'],422);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($allowed[$mime]))app_json_response(['ok'=>false,'message'=>'Use a JPEG, PNG, or WebP recipe image.'],422);
$bytes=file_get_contents($tmp);if(!is_string($bytes)||$bytes==='')app_json_response(['ok'=>false,'message'=>'The uploaded image could not be read.'],422);
$checksum=hash('sha256',$bytes);$stored='recipe-'.bin2hex(random_bytes(16)).'.'.$allowed[$mime];$relative='storage/recipe-images/'.$organizationId.'/'.$stored;$absolute=RESTAURANT_APP_ROOT.'/'.$relative;$directory=dirname($absolute);if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))app_json_response(['ok'=>false,'message'=>'Private recipe-image storage could not be created.'],500);
if(file_put_contents($absolute,$bytes,LOCK_EX)===false)app_json_response(['ok'=>false,'message'=>'The recipe image could not be stored.'],500);@chmod($absolute,0600);

try{
    $pdo->beginTransaction();
    $fileInsert=$pdo->prepare("INSERT INTO files (organization_id,storage_driver,storage_path,original_name,stored_name,mime_type,file_size,checksum_sha256,uploaded_by,visibility) VALUES (?,'local_private',?,?,?,?,?,?,?,'organization_private')");
    $fileInsert->execute([$organizationId,$relative,mb_substr((string)($file['name']??'recipe-image'),0,255,'UTF-8'),$stored,$mime,$size,$checksum,(int)$user['id']]);$fileId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE recipe_images SET is_primary=0 WHERE organization_id=? AND recipe_id=?')->execute([$organizationId,(int)$recipe['id']]);
    $imageInsert=$pdo->prepare("INSERT INTO recipe_images (organization_id,recipe_id,file_id,image_role,is_primary,mapping_status,created_by) VALUES (?,?,?,'source_recipe',1,'uploaded',?)");$imageInsert->execute([$organizationId,(int)$recipe['id'],$fileId,(int)$user['id']]);$imageId=(int)$pdo->lastInsertId();
    $pdo->commit();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();@unlink($absolute);app_json_response(['ok'=>false,'message'=>'The recipe image record could not be saved.'],500);}

$analysis=null;$mappingStatus='uploaded';$mappingError='';
if($mapOnline){
    try{
        $analysis=recipe_intelligence_map($pdo,$organizationId,$mime,$bytes,(string)$recipe['name']);$match=(array)($analysis['onlineMatch']??[]);$sourceUrl=recipe_intelligence_safe_external_url((string)($match['url']??''));$sourceTitle=mb_substr(trim((string)($match['title']??'')),0,500,'UTF-8');$confidence=max(0,min(1,(float)($match['confidence']??0)));$mappingStatus=$sourceUrl?'mapped':'needs_search';
        $updateImage=$pdo->prepare("UPDATE recipe_images SET extracted_text=?,analysis_json=?,mapping_status=?,mapped_source_url=?,mapped_source_title=?,mapped_source_domain=?,mapped_confidence=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");
        $updateImage->execute([(string)($analysis['extractedText']??''),json_encode($analysis,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$mappingStatus,$sourceUrl,$sourceTitle?:null,$sourceUrl?(string)(parse_url($sourceUrl,PHP_URL_HOST)?:''):null,$confidence,$imageId,$organizationId]);
        $recipeUpdate=$pdo->prepare("UPDATE recipes SET source_title=?,source_url=?,source_domain=?,source_confidence=?,mapping_status=?,mapping_notes=?,mapped_at=IF(?='mapped',NOW(6),mapped_at),mapped_by=IF(?='mapped',?,mapped_by),updated_by=?,updated_at=NOW(6) WHERE id=? AND organization_id=?");
        $recipeUpdate->execute([$sourceTitle?:null,$sourceUrl,$sourceUrl?(string)(parse_url($sourceUrl,PHP_URL_HOST)?:''):null,$confidence,$mappingStatus,mb_substr((string)($analysis['mappingNotes']??''),0,10000,'UTF-8')?:null,$mappingStatus,$mappingStatus,(int)$user['id'],(int)$user['id'],(int)$recipe['id'],$organizationId]);
        restaurant_brain_sync_recipe($pdo,$organizationId,(int)$recipe['id'],(int)$user['id']);
    }catch(Throwable $error){$mappingStatus='uploaded';$mappingError=$error->getMessage();}
}
app_audit($pdo,$organizationId,(int)$user['id'],'recipe.image_uploaded','recipe',$recipePublicId,null,['imageId'=>$imageId,'mapped'=>$mappingStatus,'provider'=>$analysis['provider']??null]);
app_json_response(['ok'=>true,'imageId'=>$imageId,'mappingStatus'=>$mappingStatus,'analysis'=>$analysis,'mappingError'=>$mappingError,'message'=>$analysis?($mappingStatus==='mapped'?'Recipe image analyzed and mapped to an online source.':'Recipe image analyzed; review the extraction and online-search status.'):'Recipe image uploaded.'],201);
